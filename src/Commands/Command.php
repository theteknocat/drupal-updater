<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;
use TheTeknocat\DrupalUp\Commands\Interfaces\CommandInterface;
use TheTeknocat\DrupalUp\Commands\Models\Site;
use TheTeknocat\DrupalUp\Commands\Traits\MessagesTrait;

/**
 * The base command class with common functions.
 */
abstract class Command extends BaseCommand implements CommandInterface
{
    use MessagesTrait;

    /**
     * The minimum composer version required.
     *
     * @see https://www.drupal.org/docs/system-requirements/composer-requirements
     *
     * @var string
     */
    const MIN_COMPOSER_VERSION = '2.3.6';

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
     * The sites to process.
     *
     * @var \TheTeknocat\DrupalUp\Commands\Models\Site[]
     */
    protected $sitesToProcess = [];

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
     * Path to the composer binary.
     *
     * @var string
     */
    protected $composer = '';

    /**
     * The composer version. Used for display purposes.
     *
     * @var string
     */
    protected $composerVersion = '';

    /**
     * Whether or not debugging is enabled.
     *
     * @var bool
     */
    public $isDebug = false;

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        // Call the specific command's configure method.
        $this->configureCommand()
            // Add default arguments and options.
            ->addArgument(
                'uri',
                InputArgument::OPTIONAL,
                'The URI of a specific site to update. Must match a URI in the drupalup.sites.yml file.'
            )
            ->addOption(
                'debug',
                'D',
                InputOption::VALUE_NONE,
                'Output additional information for debugging during operation.'
            );
    }

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
        $this->loadConfiguration();
        if (!$this->validateConfiguration()) {
            return 1;
        }
        if (!$this->locateComposer()) {
            return 1;
        }
        // Set the options from input for the specific command.
        $this->setOptions();
        $is_debug = $this->input->getOption('debug');
        if (!empty($is_debug)) {
            $this->isDebug = true;
        }
        // Display the command announcement.
        $this->announce();
        // Set which sites to use for the command.
        $this->setSitesToUse();
        // Run the command.
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
        $this->io->text([
            '<fg=bright-blue>Config file:</> ' . $this->configFilePath,
            '<fg=bright-blue>Sites list:</>  ' . $this->sitesFilePath,
            '<fg=bright-blue>Composer:</>    ' . $this->composer . ' (v' . $this->composerVersion . ')',
            '<fg=bright-blue>Log file:</>    ' . $this->config['log_file_path'],
        ]);
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
     * Locate composer and ensure it is the right version.
     *
     * @return bool
     *   TRUE if composer is found and the correct version, FALSE otherwise.
     */
    protected function locateComposer(): bool
    {
        if (!empty($this->config['composer_path'])) {
            $this->composer = $this->config['composer_path'];
        } else {
            // Use Symfony ExecutableFinder to locate composer.
            $exec_finder = new ExecutableFinder();
            $this->composer = $exec_finder->find('composer');
            if (empty($this->composer)) {
                $this->io->error('Composer not found.');
                return false;
            }
        }
        // Check composer version.
        $process = new Process([$this->composer, '--version']);
        $process->run();
        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            if (preg_match('/^Composer version ([0-9\.]+) .*$/', $version, $matches)) {
                $this->composerVersion = $matches[1];
                if (version_compare($this->composerVersion, self::MIN_COMPOSER_VERSION, '<')) {
                    $this->log(
                        'Composer version ' . $this->composerVersion . ' found. Version '
                            . self::MIN_COMPOSER_VERSION . ' or greater is required.',
                        LogLevel::ERROR
                    );
                    return false;
                }
            } else {
                $this->log('Unable to determine composer version.', LogLevel::ERROR);
                return false;
            }
        } else {
            $this->log('Unable to determine composer version.', LogLevel::ERROR);
            return false;
        }
        return true;
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
    public function log(string|array $message, string $logLevel): void
    {
        if (method_exists($this->io, $logLevel)) {
            $this->io->{$logLevel}($message);
        } else {
            $this->io->writeln($message);
        }
        $log_file_path = $this->logFilePath();
        if (is_writable($log_file_path)) {
            $log_file = $log_file_path . '/drupalup.log';
            if (!is_scalar($message)) {
                $message = json_encode($message);
            }
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
     * Determine which sites to run the command on.
     *
     * If a URI is provided, remove all other sites from the list.
     * If the list option was specified, list all sites and request input.
     *
     * @return void
     */
    protected function setSitesToUse(): void
    {
        $uri = $this->input->getArgument('uri');
        if (!empty($uri)) {
            // Find the URI in the siteList array and remove all others.
            $this->siteList = array_filter(
                $this->siteList,
                function ($site) use ($uri) {
                    return $site['uri'] === $uri;
                }
            );
        } else {
            // If the list option was specified, list all sites and request input.
            // The input should collect the index of the site to update.
            // If the command requested is "rollback" then this is required.
            if ($this->getName() == 'rollback' || $this->input->getOption('list')) {
                $this->io->section('Available sites:');
                foreach ($this->siteList as $index => $site) {
                    $this->io->writeln('[<fg=yellow>' . $index . '</>]: ' . $site['uri']);
                }
                $allowed_choices = array_keys($this->siteList);
                $index = $this->io->ask(
                    'Enter the number of the site to ' . $this->getName(),
                    null,
                    function ($answer) use ($allowed_choices) {
                        if (!is_numeric($answer)) {
                            throw new \RuntimeException('Please enter a number.');
                        }
                        if (!in_array($answer, $allowed_choices)) {
                            throw new \RuntimeException('Invalid site number.');
                        }
                        return $answer;
                    }
                );
                $this->siteList = [$this->siteList[$index]];
            }
        }
        if (!empty($this->siteList)) {
            $this->loadSitesToProcess();
            if (empty($this->sitesToProcess)) {
                $this->log('Unable to load any sites to process. See messages above for details.', LogLevel::ERROR);
            }
        } else {
            $this->log('No sites found to process.', LogLevel::ERROR);
        }
    }

    /**
     * Load the sites to process.
     *
     * @return void
     */
    protected function loadSitesToProcess(): void
    {
        $this->io->section('Loading sites to process...');
        // Using the siteList array, load the sites to process
        // into the sitesToProcess array as Site objects.
        foreach ($this->siteList as $site) {
            $site = new Site($site, $this);
            if ($site->hasErrors()) {
                $messages = array_merge(['Skipping site: ' . implode(', ', $site->getUris())], $site->getErrors());
                $this->log($messages, LogLevel::WARNING);
                $this->io->newLine();
            } else {
                $this->sitesToProcess[] = $site;
            }
        }
    }

    /**
     * Output a debug message if in debug mode.
     *
     * @param string|array $message
     *   The message to output.
     *
     * @return void
     */
    public function debug(string|array $message): void
    {
        if ($this->isDebug) {
            $this->debugOutput($message);
        }
    }
}
