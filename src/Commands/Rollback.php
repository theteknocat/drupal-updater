<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Command to run Rollback updates locally.
 */
class Rollback extends Command
{
    /**
     * {@inheritdoc}
     */
    public function configureCommand(): Command
    {
        $this->setName('rollback')
            ->setDescription('Restore site to pre-update state.')
            ->setHelp('This command restores the local code to the main branch and imports the'
                . ' database back. Will only work if a database backup file exists.');
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
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(): void
    {
        // No options to set.
    }

    /**
     * {@inheritdoc}
     */
    public function runCommand(): int
    {
        $site = reset($this->sitesToProcess);
        $site->announce('Rollback');
        if (!$site->canDoRollback()) {
            throw new \Exception($site->cannotRollbackReason);
        } else {
            $this->warning('The site will be restored to it\'s pre-update state.');
            $this->io->newLine();
            if ($this->input->isInteractive()) {
                if ($site->multisitePartialBackupsOnly) {
                    // Ask the user if they want to continue with a y/n prompt.
                    $question = new ConfirmationQuestion(
                        $site->cannotRollbackReason
                            . ' Do you want to continue with the rollback anyway?',
                        false
                    );
                } else {
                    $question = new ConfirmationQuestion(
                        'Are you sure you want to continue?',
                        false
                    );
                }
                if (!$this->io->askQuestion($question)) {
                    $this->io->newLine();
                    $this->info('Rollback cancelled.');
                    $this->io->newLine();
                    return 0;
                }
            }
        }
        try {
            $site->doRollback();
            $this->io->newLine();
            $this->success('Rollback complete.');
            $this->io->newLine();
        } catch (\Exception $e) {
            $this->io->newLine();
            $errors[] = 'Rollback failed:';
            $errors[] = $e->getMessage();
            $this->handleProcessException($e, $errors);
            $errors[] = 'The site may now be in an unstable state and require manual intervention.';
            $this->log($errors, LogLevel::ERROR);
            return 1;
        }
        return 0;
    }
}
