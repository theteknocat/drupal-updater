<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;
use TheTeknocat\DrupalUp\Commands\Interfaces\CommandInterface;
use TheTeknocat\DrupalUp\Commands\Models\Site;
use TheTeknocat\DrupalUp\Commands\Traits\OutputsMessages;

/**
 * The base command class with common functions.
 */
abstract class Command extends BaseCommand implements CommandInterface
{
    use OutputsMessages;

    /**
     * The minimum composer version required.
     *
     * @see https://www.drupal.org/docs/system-requirements/composer-requirements
     *
     * @var string
     */
    const MIN_COMPOSER_VERSION = '2.3.6';

    const CONFIG_FILE_PATHS = [
        '<home>/drupalup/',
        '<home>/',
        '/usr/local/etc/drupalup/',
        '/usr/local/etc/',
    ];

    /**
     * The config array, loaded from the yml file.
     *
     * @var array
     */
    protected array $config = [];

    /**
     * Path to configuration file.
     */
    protected string $configFilePath = '';

    /**
     * Path to the sites list file.
     */
    protected string $sitesFilePath = '';

    /**
     * List of the sites to update.
     */
    protected array $siteList = [];

    /**
     * The sites to process.
     *
     * @var \TheTeknocat\DrupalUp\Commands\Models\Site[]
     */
    protected array $sitesToProcess = [];

    /**
     * The input object.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected InputInterface $input;

    /**
     * The output object.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected OutputInterface $output;

    /**
     * Path to the git binary.
     *
     * @var string
     */
    protected string $git = '';

    /**
     * Path to the composer binary.
     *
     * @var string
     */
    protected string $composer = '';

    /**
     * The composer version. Used for display purposes.
     *
     * @var string
     */
    protected string $composerVersion = '';

    /**
     * Whether or not debugging is enabled.
     *
     * @var bool
     */
    public bool $isDebug = false;

    /**
     * Whether or not a log for this command has been started.
     *
     * If false, the first time a message is logged it will put
     * in a marker message to indicate the start of the log for
     * this command.
     *
     * @var bool
     */
    protected bool $logStarted = false;

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        // Call the specific command's configure method.
        $this->configureCommand();
        if ($this->acceptsUriArgument()) {
            // Add default arguments and options.
            $this->addArgument(
                // The uri argument is always optional, but if the specific
                // command requires it (via requiresUriArgument()), then
                // the user will be presented with a list to choose from.
                'uri',
                $this->requiresUriArgument() ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                'The URI of a specific site to run the command against.'
                    . ' Must match a URI in the drupalup.sites.yml file.'
            );
        }
        $this->addOption(
            'debug',
            'D',
            InputOption::VALUE_NONE,
            'Output additional information for debugging during operation.'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->initIoStyle($input, $output);
        // Show app name and version.
        $this->io->writeln($this->getApplication()->getLongVersion());
        $this->io->newLine();
        // Load the configuration.
        $this->loadConfiguration();
        // Check for required binaries and configuration.
        if (!$this->validateConfiguration() || !$this->locateGit() || !$this->locateComposer()) {
            // End the log for this command.
            $this->log(ucwords($this->getName()), 'END', true);
            throw new \RuntimeException('Unable to validate configuration and/or locate required binaries.'
                . ' See log for details.');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // If the select option was specified, or the command boths accepts
        // and requires the uri argument, then list all sites and request
        // input to choose one.
        if (($this->input->hasOption('select') && $this->input->getOption('select'))
            || ($this->acceptsUriArgument() && $this->requiresUriArgument())) {
            $this->io->section('Select site to ' . $this->getName() . ':');
            foreach ($this->siteList as $index => $site) {
                if (is_array($site['uri'])) {
                    $display_uri = implode(', ', $site['uri']);
                } else {
                    $display_uri = $site['uri'];
                }
                $this->io->writeln('[<fg=yellow>' . ($index + 1) . '</>]: ' . $display_uri);
            }
            $allowed_choices = array_keys($this->siteList);
            // Increment all the choices by 1:
            $allowed_choices = array_map(
                function ($value) {
                    return $value + 1;
                },
                $allowed_choices
            );
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
            $this->input->setArgument('uri', $this->siteList[($index - 1)]['uri']);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Set the options from input for the specific command.
        $this->setOptions();
        if ($this->input->hasOption('debug')) {
            $is_debug = $this->input->getOption('debug');
            if (!empty($is_debug)) {
                $this->isDebug = true;
            }
        }
        // Display the command announcement.
        $this->announce();
        // Set which sites to use for the command.
        if (!$this->setSitesToUse()) {
            // If no sites were set, then there was likely an error.
            return 1;
        }
        // Run the command.
        $result = $this->runCommand();
        if ($this->logStarted) {
            // If the log was started, add an end marker.
            $this->log(ucwords($this->getName()), 'END', true);
            $this->io->newLine();
            $this->info('There are items in the log to review. Run `drupalup log` to see them.');
            $this->io->newLine();
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function announce(): void
    {
        $this->io->text([
            '<fg=bright-blue>Config file:</> ' . $this->configFilePath,
            '<fg=bright-blue>Sites list:</>  ' . $this->sitesFilePath,
            '<fg=bright-blue>Composer:</>    ' . $this->composer . ' (v' . $this->composerVersion . ')',
            '<fg=bright-blue>Log file:</>    ' . $this->config['log_file_path'] . '/drupalup.log',
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
        foreach (self::CONFIG_FILE_PATHS as $base_path) {
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
     * Return configuration or a given config value.
     *
     * @param string $key
     *   The key to get from the configuration. Can be a dot separated string.
     *
     * @return mixed
     *   The configuration value.
     */
    public function getConfig(string $key = ''): mixed
    {
        if (empty($key)) {
            return $this->config;
        }
        // Allow the key to be a dot separated string.
        $key_parts = explode('.', $key);
        // Given each element of the key_parts array is a child of the previous,
        // traverse the config array to get the value. For example, if the key
        // is 'foo.bar.baz', the value of $config['foo']['bar']['baz'] will be
        // returned.
        $config = $this->config;
        foreach ($key_parts as $key_part) {
            if (isset($config[$key_part])) {
                $config = $config[$key_part];
            } else {
                $config = null;
                break;
            }
        }
        return $config;
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

        if (!isset($this->config['sanitize_databases_on_sync'])
            || !is_bool($this->config['sanitize_databases_on_sync'])) {
            $this->log('No sanitize_databases_on_sync value defined or not a boolean value.', LogLevel::ERROR);
            $valid_config = false;
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

        if (!isset($this->config['mail']['smtp_port'])) {
            $this->log('No SMTP port defined.', LogLevel::ERROR);
            $valid_config = false;
        } else {
            // Make sure the port is an integer.
            $this->config['mail']['smtp_port'] = (int) $this->config['mail']['smtp_port'];
        }

        if (!isset($this->config['mail']['use_tls']) || !is_bool($this->config['mail']['use_tls'])) {
            $this->log('No use_tls value defined or not a boolean (true/false) value.', LogLevel::ERROR);
            $valid_config = false;
        }

        // Validate the git config values.
        if (empty($this->config['git']['remote_key'])) {
            $this->log('No git remote key defined.', LogLevel::ERROR);
            $valid_config = false;
        }
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

        if ($this->config['git']['main_branch'] == $this->config['git']['update_branch']) {
            $this->log('Git main branch and update branch cannot be the same.', LogLevel::ERROR);
            $valid_config = false;
        }

        return $valid_config;
    }

    /**
     * Get the path to the git binary.
     *
     * @return string
     *   The path to the git binary.
     */
    public function git(): string
    {
        return $this->git;
    }

    /**
     * Locate the git executable.
     *
     * @return bool
     *   TRUE if git was located, FALSE otherwise.
     */
    protected function locateGit(): bool
    {
        if (!empty($this->config['git_path'])) {
            $this->git = $this->config['git_path'];
        } else {
            // Use Symfony ExecutableFinder to locate git.
            $exec_finder = new ExecutableFinder();
            $this->git = $exec_finder->find('git');
            if (empty($this->git)) {
                $this->log('Git not found.', LogLevel::ERROR);
                return false;
            }
        }
        // Call git --version to ensure it is working.
        $process = new Process([$this->git, '--version'], null, [
            'SHELL_VERBOSITY' => '0',
        ]);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->log('Unable to call git process: ' . $process->getErrorOutput(), LogLevel::ERROR);
            return false;
        }
        return true;
    }

    /**
     * Get the path to the composer binary.
     *
     * @return string
     *   The path to the composer binary.
     */
    public function composer(): string
    {
        return $this->composer;
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
                $this->log('Composer not found.', LogLevel::ERROR);
                return false;
            }
        }
        // Check composer version.
        $process = new Process([$this->composer, '--version'], null, [
            'SHELL_VERBOSITY' => '0',
        ]);
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
            $this->log('Unable to determine composer version: ' . $process->getErrorOutput(), LogLevel::ERROR);
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
     * @param bool $logFileOnly
     *   Whether to only log to the file, not to the console.
     *
     * @return void
     */
    public function log(string|array $message, string $logLevel, bool $logFileOnly = false): void
    {
        if (!$this->logStarted) {
            // If the log wasn't started yet, add a start marker.
            $this->logStarted = true;
            $this->log(ucwords($this->getName()), 'START', true);
        }
        if (!$logFileOnly) {
            if (method_exists($this->io, $logLevel)) {
                $this->io->{$logLevel}($message);
            } else {
                $this->io->writeln($message);
            }
        }
        $log_file_path = $this->logFilePath();
        if (is_writable($log_file_path)) {
            $log_file = $log_file_path . '/drupalup.log';
            if (!is_scalar($message)) {
                $message = json_encode($message);
            } else {
                // If the message contains line breaks, turn it into an array
                // and encode it as JSON.
                if (strpos($message, PHP_EOL) !== false) {
                    $message = json_encode(explode(PHP_EOL, $message));
                }
            }
            file_put_contents(
                $log_file,
                '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($logLevel) . ': ' . $message . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    /**
     * Log errors for a site.
     *
     * @param Site $site
     *   The site to log errors for.
     *
     * @return void
     */
    protected function logSiteErrors(Site $site): void
    {
        $errors = $site->getErrors();
        if (!empty($errors)) {
            $this->log(
                'Errors performing ' . $this->getName(). ' on ' . implode(', ', $site->getUris()) . ':',
                LogLevel::ERROR,
                true
            );
            $this->log($errors, LogLevel::ERROR, true);
        }
    }

    /**
     * Get the path to the log file.
     *
     * @return string
     *   The path to the log file.
     */
    protected function logFilePath(): string
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
     * @return bool
     *   TRUE if sites were found, FALSE otherwise.
     */
    protected function setSitesToUse(): bool
    {
        if (!$this->input->hasArgument('uri')) {
            // If the command doesn't have a URI argument then we don't need to do anything.
            return true;
        }
        $uri = $this->input->getArgument('uri');
        if (!empty($uri)) {
            // Find the URI in the siteList array and remove all others.
            $this->siteList = array_filter(
                $this->siteList,
                function ($site) use ($uri) {
                    if (is_array($uri)) {
                        return in_array($site['uri'], $uri);
                    }
                    return $site['uri'] === $uri;
                }
            );
        }
        if (!empty($this->siteList)) {
            $this->validateSites();
            if (empty($this->sitesToProcess)) {
                $this->log('Unable to load any sites to process.', LogLevel::ERROR);
                return false;
            }
        } else {
            $this->log('No sites found to process.', LogLevel::ERROR);
            return false;
        }
        return true;
    }

    /**
     * Load the sites to process.
     *
     * @return void
     */
    protected function validateSites(): void
    {
        $this->io->section('Validating ' . count($this->siteList) . ' site(s) to ' . $this->getName()
            . '. This may take a few minutes...');
        // Using the siteList array, load the sites to process
        // into the sitesToProcess array as Site objects.
        $sites_skipped = [];
        foreach ($this->io->progressIterate($this->siteList) as $site) {
            $site = new Site($site, $this);
            if ($site->hasErrors()) {
                $sites_skipped[] = $site;
                $this->log('Site skipped due to errors: ' . implode(', ', $site->getUris()), LogLevel::WARNING, true);
                $this->log($site->getErrors(), LogLevel::ERROR, true);
            } else {
                $this->sitesToProcess[] = $site;
            }
        }
        $this->info(count($this->sitesToProcess) . ' sites successfully validated.');
        if (!empty($sites_skipped)) {
            $this->io->newLine();
            $this->warning(
                'The following sites will be skipped due to validation errors (see log file for details):',
            );
            $this->io->newLine();
            $this->io->listing(array_map(fn ($site) => implode(', ', $site->getUris()), $sites_skipped));
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
            $this->log($message, LogLevel::DEBUG, true);
        }
    }

    /**
     * Handle process exceptions.
     *
     * If the exception has a getProcess method, get the last 5 lines of output
     * and add them to the errors array.
     *
     * @param \Exception $e
     *   The exception to handle.
     * @param array $errors
     *   The errors array to add to.
     *
     * @return void
     */
    protected function handleProcessException(\Exception $e, array &$errors): void
    {
        if (method_exists($e, 'getProcess')) {
            // Get the process from the exception:
            $process = call_user_func([$e, 'getProcess']);
            // Get the last 5 lines of output:
            $errors[] = 'Last 5 lines of output:';
            $output_array = explode(PHP_EOL, $process->getOutput());
            // Take the last 5 trimmed, non-empty lines:
            $output = array_slice(
                array_filter(
                    array_map('trim', $output_array),
                    fn ($line) => !empty($line)
                ),
                -5
            );
            // Add code blocks around the output lines.
            // This is for formatting in an email notification.
            array_unshift($output, '```');
            $output[] = '```';
            $errors = array_merge($errors, $output);
        }
    }
}
