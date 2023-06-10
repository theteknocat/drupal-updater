<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;
use TheTeknocat\DrupalUp\Commands\Interfaces\CommandInterface;

/**
 * The base command class with common functions.
 */
abstract class Command extends BaseCommand implements CommandInterface
{
    /**
     * The config array, loaded from the yml file.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Possible config file path locations.
     */
    protected $configFilePaths = [
        '<home>/drupalup/',
        '<home>/',
        '/usr/local/etc/drupalup/',
        '/usr/local/etc/',
    ];

    /**
     * Path to configuration file.
     */
    protected $configFilePath = '';

    /**
     * Path to the sites list file.
     */
    protected $sitesFilePath = '';

    /**
     * List of the sites to update.
     */
    protected $siteList = [];

    /**
     * The input object.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * The output object.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * The logger object.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;


    /**
     * SymfonyStyle object instance.
     *
     * @var \Symfony\Component\Console\Style\SymfonyStyle
     */
    protected $io;

    /**
     * Execute the command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *   The input object.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   The output object.
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($this->input, $this->output);
        $this->logger = new ConsoleLogger($this->output);
        $this->loadConfiguration();
        if (!$this->validateConfiguration()) {
            return 1;
        }
        $this->setOptions();
        $this->announce();
        $commandMethod = 'run' . ucfirst($this->getName());
        return $this->{$commandMethod}();
    }

    /**
     * {@inheritdoc}
     */
    public function announce(): void
    {
        $app = $this->getApplication();
        $this->io->title($app->getName() . ' v' . $app->getVersion());
        $this->io->text('<fg=bright-blue>Command:</> ' . $this->getName());
        $this->io->newLine();
        $this->io->text('<fg=bright-blue>Config file:</>');
        $this->io->text('  ' . $this->configFilePath);
        $this->io->text('<fg=bright-blue>Sites list:</>');
        $this->io->text('  ' . $this->sitesFilePath);
        $this->io->text('<fg=bright-blue>Composer:</>');
        $this->io->text('  ' . $this->composer . ' (v' . $this->composerVersion . ')');
        $this->io->text('<fg=bright-blue>Log file:</>');
        $this->io->text('  ' . $this->config['log_file_path']);
        $this->io->newLine();
    }

    /**
     * Load the configuration file.
     *
     * @return void
     */
    protected function loadConfiguration(): void
    {
        $config_loaded = false;
        $sites_loaded = false;
        foreach ($this->configFilePaths as $base_path) {
            $base_path = str_replace('<home>', $_SERVER['HOME'], $base_path);
            $config_file_path = $base_path . 'drupalup.settings.yml';
            $sites_file_path = $base_path . 'drupalup.sites.yml';
            if (!$config_loaded && file_exists($config_file_path) && empty($this->config)) {
                $this->configFilePath = $config_file_path;
                $this->config = Yaml::parseFile($this->configFilePath);
                $config_loaded = true;
            }
            if (!$sites_loaded && file_exists($sites_file_path) && empty($this->config['sites'])) {
                $this->sitesFilePath = $sites_file_path;
                $this->siteList = Yaml::parseFile($this->sitesFilePath);
                $sites_loaded = true;
            }
        }
    }

    /**
     * Validate the configuration.
     *
     * @return bool
     *   TRUE if the configuration is valid, FALSE otherwise.
     */
    protected function validateConfiguration(): bool
    {
        $valid_config = true;
        if (empty($this->config)) {
            $this->log('No configuration file found or config file is empty.', LogLevel::ERROR);
            $valid_config = false;
        }
        if (empty($this->siteList)) {
            $this->log('No sites file found or sites file is empty.', LogLevel::ERROR);
            $valid_config = false;
        }

        $log_file_path = $this->logFilePath();
        if (!is_writable($log_file_path)) {
            $this->warning('Log file path is not writable. Log file will not be written.');
        }

        $validator = Validation::createValidator();

        // Validate mail configuration values.
        if (empty($this->config['mail']['notification_email'])) {
            $this->log('No notification email address defined.', LogLevel::ERROR);
            $valid_config = false;
        }

        if (empty($this->config['mail']['from_email'])) {
            $this->log('No from address email defined.', LogLevel::ERROR);
            $valid_config = false;
        } else {
            $violations = $validator->validate($this->config['mail']['from_email'], [
                new Email(),
            ]);
            if (count($violations) > 0) {
                $this->log('Invalid from email address defined: '
                    . $this->config['mail']['from_email'] . '.', LogLevel::ERROR);
                $valid_config = false;
            }
        }

        if (empty($this->config['mail']['from_email_name'])) {
            $this->log('No from email name defined.', LogLevel::ERROR);
            $valid_config = false;
        }

        if (empty($this->config['mail']['smtp_host'])) {
            $this->log('No SMTP host defined.', LogLevel::ERROR);
            $valid_config = false;
        }

        if (empty($this->config['mail']['smtp_port'])) {
            $this->log('No SMTP port defined.', LogLevel::ERROR);
            $valid_config = false;
        }

        $allowed_smtp_secure_methods = [
            'ssl',
            'tls',
        ];
        if (!empty($this->config['mail']['smtp_secure_method'])
            && !in_array($this->config['mail']['smtp_secure_method'], $allowed_smtp_secure_methods)) {
            $this->log('Invalid SMTP secure method defined: '
                . $this->config['mail']['smtp_secure_method'] . '.', LogLevel::ERROR);
            $valid_config = false;
        }

        // Validate the git config values.
        if (empty($this->config['git']['commit_author'])) {
            $this->log('No git commit author defined.', LogLevel::ERROR);
            $valid_config = false;
        } else {
            $author = explode(' ', $this->config['git']['commit_author']);
            $valid_author = true;
            if (count($author) < 2) {
                $valid_author = false;
            } else {
                $email = end($author);
                // Check that the email is wrapped in angle brackets.
                if (!preg_match('/^<[^>]+>$/', $email)) {
                    $valid_author = false;
                } else {
                    // Remove the angle brackets.
                    $email = substr($email, 1, -1);
                    $violations = $validator->validate($email, [
                        new Email(),
                    ]);
                    if (count($violations) > 0) {
                        $valid_author = false;
                    }
                }
            }
            if (!$valid_author) {
                $this->log(
                    'Git commit author must be in the format "Name <email@example.com>". Value provided was: '
                        . $this->config['git']['commit_author'] . '.',
                    LogLevel::ERROR
                );
                $valid_config = false;
            }
        }

        // Set default values where empty:
        if (empty($this->config['git']['main_branch'])) {
            $this->config['git']['main_branch'] = 'master';
        }
        if (empty($this->config['git']['update_branch'])) {
            $this->config['git']['update_branch'] = 'drupal-updates';
        }

        return $valid_config;
    }

    /**
     * Log a message.
     *
     * @param string $message
     *   The message to log.
     * @param string $logLevel
     *   The log level. Must be one of the \Psr\Log\LogLevel constants.
     *
     * @return void
     */
    protected function log(string $message, string $logLevel): void
    {
        $this->logger->log($logLevel, $message);
        $log_file_path = $this->logFilePath();
        if (is_writable($log_file_path)) {
            $log_file = $log_file_path . '/drupalup.log';
            file_put_contents(
                $log_file,
                '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($logLevel) . ': ' . $message . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    /**
     * Get the path to the log file.
     *
     * @return string
     *   The path to the log file.
     */
    private function logFilePath(): string
    {
        if (!empty($this->config['log_file_path'])) {
            return str_replace('~', $_SERVER['HOME'], $this->config['log_file_path']);
        }
        return '/var/log';
    }

    /**
     * Output a warning message.
     *
     * @param string $message
     *   The message to output.
     *
     * @return void
     */
    protected function warning(string $message): void
    {
        $this->io->writeln('<bg=yellow;fg=black>[warning]</> ' . $message);
    }

    /**
     * Output an info message.
     *
     * @param string $message
     *   The message to output.
     *
     * @return void
     */
    protected function info(string $message): void
    {
        $this->io->writeln('<bg=blue;fg=white>[info]</> ' . $message);
    }

    /**
     * Output a success message.
     *
     * @param string $message
     *   The message to output.
     *
     * @return void
     */
    protected function success(string $message): void
    {
        $this->io->writeln('<bg=green;fg=black>[success]</> ' . $message);
    }

    /**
     * Output a success message.
     *
     * @param string $message
     *   The message to output.
     *
     * @return void
     */
    protected function success(string $message): void
    {
        $this->output->writeln('<fg=green>[success]</> ' . $message);
    }
}
