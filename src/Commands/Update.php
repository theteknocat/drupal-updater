<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\InputOption;
use TheTeknocat\DrupalUp\Commands\Models\Site;

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
                'Run the update in dry-run mode. Git changes will'
                    . ' not be committed or pushed. Email notification will be sent if enabled.'
            )
            ->addOption(
                'notify',
                'N',
                InputOption::VALUE_NONE,
                'Send an email notification on completion.'
            )
            ->addOption(
                'list',
                'L',
                InputOption::VALUE_NONE,
                'List all sites and request input on which one to update. Ignored if a uri is provided.'
            );
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function announce(): void
    {
        parent::announce();
        if ($this->isDryRun) {
            $this->info('Dry-run mode - git changes <options=bold>will not be</> committed or pushed.');
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
        $notify = $this->input->getOption('notify');
        $this->notify = !empty($notify);
    }

    /**
     * {@inheritdoc}
     */
    public function runCommand(): int
    {
        if (!empty($this->sitesToProcess)) {
            foreach ($this->sitesToProcess as $site) {
                $this->updateSite($site);
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

        if (!$site->ensureCleanGitRepo($this->config['git']['main_branch'])) {
            $this->log($site->getErrors(), LogLevel::ERROR);
            $this->warning('Site ' . implode(', ', $site->getUris()) . ' is not on the '
                . $this->config['git']['main_branch']
                . ' branch and/or has uncommitted changes. Skipping.');
            return false;
        }

        $site->syncProdDatabase();

        if ($site->hasErrors()) {
            $this->log($site->getErrors(), LogLevel::ERROR, true);
            $this->warning('Errors occurred syncing database: ' . implode(', ', $site->getUris())
                    . '. See the log file for details.');
            return false;
        }

        $site->backupDatabase();

        if ($site->hasErrors()) {
            $this->log($site->getErrors(), LogLevel::ERROR, true);
            $this->warning('Errors occurred backing up database: ' . implode(', ', $site->getUris())
                    . '. See the log file for details.');
            return false;
        }

        return true;
    }
}
