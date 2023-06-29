<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use League\CommonMark\CommonMarkConverter;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use TheTeknocat\DrupalUp\Commands\Models\Site;

/**
 * Command to run Drupal updates.
 */
class Update extends Command
{
    /**
     * Whether or not to use dry-run mode.
     *
     * @var bool
     */
    protected bool $isDryRun = false;

    /**
     * Whether or not to send an email notification.
     *
     * @var bool
     */
    protected bool $notify = false;

    /**
     * Map short statuses to longer, friendly ones.
     */
    protected $friendlyStatuses = [
        'failed'    => 'Update failed',
        'unchanged' => 'No update required',
        'success'   => 'Update successfully completed',
        'mixed'     => 'Mixed',
    ];

    /**
     * {@inheritdoc}
     */
    public function configureCommand(): Command
    {
        $this->setName('update')
            ->setDescription('Updates Drupal core and modules.')
            ->setHelp('This command allows you to update Drupal core and modules.')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Run the update in dry-run mode. Git changes will not be committed or pushed.'
                    . ' Email notification will still be sent if enabled.'
            )
            ->addOption(
                'notify',
                'N',
                InputOption::VALUE_NONE,
                'Send an email notification on completion. Only needed if the always_notify'
                    . ' config setting is omitted or set to false.'
            )
            ->addOption(
                'select',
                's',
                InputOption::VALUE_NONE,
                'List all sites for the user to select which one to update. Ignored if a uri is provided.'
                    . ' If the  --no-interaction option is provided, it has no effect and all sites will be updated.'
            );
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function acceptsUriArgument(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresUriArgument(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function announce(): void
    {
        parent::announce();
        if ($this->isDryRun) {
            $this->info('Local git changes <options=bold>will not be</> committed or pushed.');
            $this->info('Commit and push commands will be run with --dry-run and -v options and the results logged.');
            $this->warning('The log may show errors, like if a ' . $this->config['git']['update_branch']
                . ' branch already exists in the remote repository. These errors can be ignored.');
        } else {
            $this->info('Git changes <options=bold>will be</> committed and pushed.');
        }
        if ($this->notify) {
            $this->info('Email notification <options=bold>will be</> sent on completion.');
        } else {
            $this->info('Email notification <options=bold>will not be</> sent on completion.');
        }
        $this->io->newLine();
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(): void
    {
        $is_dry_run = $this->input->getOption('dry-run');
        $this->isDryRun = !empty($is_dry_run);
        $notify = $this->config['always_notify'] ?? $this->input->getOption('notify') ?? false;
        $this->notify = !empty($notify);
    }

    /**
     * {@inheritdoc}
     */
    public function runCommand(): int
    {
        if (!empty($this->sitesToProcess)) {
            foreach ($this->sitesToProcess as $site) {
                $result = $this->updateSite($site);
                if ($result) {
                    $this->io->newLine();
                    $this->success('Update of ' . implode(', ', $site->getUris()) . ' completed successfully.');
                    $this->io->newLine();
                } else {
                    $this->io->newLine();
                    $this->warning('Errors occurred updating ' . implode(', ', $site->getUris()) . '.');
                    $this->io->newLine();
                }
            }
        }
        return 0;
    }

    /**
     * Update a single site.
     *
     * @param \TheTeknocat\DrupalUp\Commands\Models\Site $site
     *   The site object.
     *
     * @return bool
     *   Whether or not the site was updated successfully.
     */
    protected function updateSite(Site $site): bool
    {
        // Set whether or not to apply git changes based on dry-run mode.
        $site->setApplyGitChanges(!$this->isDryRun);

        // Announce the command.
        $site->announce('Update');

        $success = true;
        try {
            $site->ensureCleanGitRepo($this->config['git']['main_branch']);
            $site->syncProdDatabase();
            $site->backupDatabase();
            $site->setupCleanUpdateBranch();
            $site->doComposerUpdate();
        } catch (\Exception $e) {
            $this->io->newLine();
            $errors[] = 'Errors occurred during update:';
            $errors[] = $e->getMessage();
            $this->handleProcessException($e, $errors);
            $errors[] = 'The site may now be in an unstable state and require manual intervention.';
            $this->io->warning($errors);
            foreach ($errors as $error) {
                $site->setError($error);
            }
            $success = false;
        }

        $this->logSiteErrors($site);

        $this->summariseAndNotify($site);

        return $success;
    }

    /**
     * Summarise, log and notify of each site's update status.
     *
     * @param \TheTeknocat\DrupalUp\Commands\Models\Site $site
     *   The site object.
     */
    protected function summariseAndNotify(Site $site): void
    {
        if (!$this->notify) {
            $this->info('Email notifications disabled.');
            return;
        }
        $this->io->newLine();
        $summary = $this->buildSummaryMessage($site);
        if (empty($summary)) {
            $this->info('Nothing to report - site was unchanged and no errors occurred.');
            return;
        }
        $this->info('Summarise results and send email notification', false);
        $this->emailNotification($summary['subject'], $summary['message']);
        $this->doneSuccess();
    }

    /**
     * Build the summary message for a given site.
     *
     * @param \TheTeknocat\DrupalUp\Commands\Models\Site $site
     *   The site object.
     *
     * @return array|null
     *   An array with subject and message, or null if no results.
     */
    private function buildSummaryMessage(Site $site): array|null
    {
        $commandResults = $site->getCommandResults();
        $errors = $site->getErrors();

        if (empty($errors) && (empty($commandResults) || $commandResults['status'] == 'unchanged')) {
            return null;
        }

        $display_status = $commandResults['status'];
        if ($display_status == 'mixed' && $commandResults['core_status'] != 'failed'
            && $commandResults['module_status'] != 'failed') {
            $display_status = 'success';
        }

        $subject = "Drupal update " . $display_status . " for " . implode(', ', $site->getUris());
        if ($site->isMultiSite()) {
            $subject .= " (multisite)";
        }
        $message = "This email provides a summary of the Drupal updates attempted for:" . PHP_EOL . PHP_EOL;
        foreach ($site->getUris() as $uri) {
            $full_url = 'http://' . $uri;
            $message .= "* [" . $uri . "](" . $full_url . ")" . PHP_EOL;
        }
        $message .= PHP_EOL . "**File path:** " . $site->getPath() . PHP_EOL . PHP_EOL;
        if ($commandResults['status'] == 'mixed') {
            $message .= "**Core update status:** "
            . $this->friendlyStatuses[$commandResults['core_status']] . PHP_EOL;
            $message .= "**Module update status:** "
            . $this->friendlyStatuses[$commandResults['module_status']] . PHP_EOL;
        } else {
            $message .= "**Status:** " . $this->friendlyStatuses[$display_status] . PHP_EOL;
        }
        if ($this->isDryRun) {
            $message .= "**Dry-run only:** No git changes were committed or pushed." . PHP_EOL . PHP_EOL;
        }
        if ($display_status == 'success') {
            $message .= PHP_EOL . "The completed updates have been pushed to the remote *"
                . $this->config['git']['remote_key'] . "* in a new branch "
                . "called *" . $this->config['git']['update_branch'] . "*. "
                . "It is now your responsibilty to merge the updates with "
                . "the *" . $this->config['git']['main_branch'] . "* branch and roll out to production." . PHP_EOL;
        }
        if (!empty($commandResults['messages'])) {
            $message .= PHP_EOL . "**The following messages were generated during the update process:**" . PHP_EOL
                . PHP_EOL . '---' . PHP_EOL . PHP_EOL;
            $message .= implode(PHP_EOL, $commandResults['messages']) . PHP_EOL;
        }

        if (!empty($errors)) {
            $message .= PHP_EOL . "**The following errors were generated during the update process:**" . PHP_EOL
                . PHP_EOL . '---' . PHP_EOL . PHP_EOL;
            $message .= implode(PHP_EOL, $errors) . PHP_EOL;
        }
        $message .= PHP_EOL . "---" . PHP_EOL . "--End of Line--" . PHP_EOL;

        return [
            'subject' => $subject,
            'message' => $message,
        ];
    }

    /**
     * Send an email notification using Symfony mailer.
     *
     * @param string @subject
     *   The message subject.
     * @param string $message
     *   The message body.
     */
    protected function emailNotification(string $subject, string $message): void
    {
        // The following config variables can be used:
        // - $this->config['mail']['from_email'] - the email address to send from. Required.
        // - $this->config['mail']['from_email_name'] - the name to send from. Required.
        // - $this->config['mail']['notification_email'] - the email address to send to. Required.
        // - $this->config['mail']['smtp_host'] - the SMTP host to use. Required.
        // - $this->config['mail']['smtp_port'] - the SMTP port to use. Required.
        // - $this->config['mail']['smtp_user'] - the SMTP username to use. Optional.
        // - $this->config['mail']['smtp_password'] - the SMTP password to use. Optional.
        // - $this->config['mail']['use_tls'] - whether or not to use TLS. Required.

        // Use the default mailer with the ESMTP transport.
        $transport = new EsmtpTransport(
            $this->config['mail']['smtp_host'],
            (int) $this->config['mail']['smtp_port'],
            (bool) $this->config['mail']['use_tls']
        );
        if (!empty($this->config['mail']['smtp_user'])) {
            $transport->setUsername($this->config['mail']['smtp_user']);
        }
        if (!empty($this->config['mail']['smtp_password'])) {
            $transport->setPassword($this->config['mail']['smtp_password']);
        }
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from($this->config['mail']['from_email'])
            ->to($this->config['mail']['notification_email'])
            ->subject($subject)
            ->text($message)
            ->html($this->markdownToHtml($message));

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->log('Error sending email notification: ' . $e->getMessage(), LogLevel::ERROR);
        }
    }

    /**
     * Converts Markdown to HTML.
     *
     * Takes care of single linebreaks the way you would expect.
     *
     * @param string $src_text
     *   The source text in Markdown format to convert.
     *
     * @return string
     *   The text converted to HTML.
     */
    protected function markdownToHtml(string $src_text): string
    {
        $src_text = trim($src_text);
        // Split the $src_text into an array on PHP_EOL.
        $bits = explode(PHP_EOL, $src_text);
        // Trim all array values (gets rid of any extra breaks).
        $bits = array_map(function ($value) {
            return trim($value);
        }, $bits);
        // Add spaces to the end of any lines not followed by an empty line.
        // This forces every soft return to cause markdown to add a <br> tag.
        foreach ($bits as $index => &$bit) {
            $bit = trim($bit);
            if (!empty($bit) && !empty($bits[$index + 1])) {
                $bit .= "  ";
            }
        }
        // Glue back together with line breaks.
        $src_text = implode(PHP_EOL, $bits);
        // Convert to HTML with Markdown and return.
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        return (string) $converter->convert($src_text);
    }
}
