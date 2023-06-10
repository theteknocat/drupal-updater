<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class Update extends Command
{
    /**
     * Whether or not to use dry-run mode.
     *
     * @var bool
     */
    protected $isDryRun = false;

    /**
     * Whether or not to send an email notification.
     *
     * @var bool
     */
    protected $notify = false;

    /**
     * {@inheritdoc}
     */
    protected function configure()
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
            ->addArgument(
                'uri',
                InputArgument::OPTIONAL,
                'The URI of a specific site to update. Must match a URI in the drupalup.sites.yml file.'
            );
    }

    /**
     * {@inheritdoc}
     */
    public function announce(): void
    {
        parent::announce();
        if ($this->isDryRun) {
            $this->info('Dry-run mode - git changes <bold>will not be</bold> committed or pushed.');
        } else {
            $this->info('Git changes <bold>will be</bold> committed and pushed.');
        }
        if ($this->notify) {
            $this->info('Email notification <bold>will be</bold> sent on completion.');
        } else {
            $this->info('Email notification <bold>will not be</bold> sent on completion.');
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
     * Run the update command.
     *
     * @return int
     */
    protected function runUpdate(): int
    {
        $this->io->section('Starting updates...');
        $this->io->section('Updates complete.');
        return 0;
    }
}
