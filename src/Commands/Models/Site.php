<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Models;

use Symfony\Component\Process\Process;
use TheTeknocat\DrupalUp\Commands\Command;

/**
 * Model an individual site to run a given command against.
 */
class Site
{
    /**
     * The URIs of the site.
     *
     * @var array
     */
    protected array $uris = [];

    /**
     * Whether or not the site is a multisite.
     *
     * This will be true if the site has multiple
     * production aliases.
     *
     * @var bool
     */
    protected bool $isMultisite = false;

    /**
     * The path to the site.
     *
     * @var string
     */
    protected string $path;

    /**
     * The site's prod alias name to match.
     *
     * @var string
     */
    protected string $prodAliasNameMatch;

    /**
     * The path to the site's drush executable.
     *
     * @var string
     */
    protected string $drushPath;

    /**
     * The site's status(es) obtained from drush status.
     *
     * If the site is a multisite, this will be an array
     * of status arrays keyed by the site's URIs.
     *
     * @var array
     */
    protected array $siteStatuses = [];

    /**
     * The site's aliases obtained from drush sa.
     *
     * @var array
     */
    protected array $siteAliases = [];

    /**
     * List of errors that occurred while processing the site.
     *
     * @var array
     */
    protected array $errors = [];

    /**
     * Instance of the current command object.
     *
     * @var \TheTeknocat\DrupalUp\Commands\Command
     */
    protected Command $command;

    /**
     * The results of the command run against the site.
     *
     * @var array
     */
    protected array $commandResults = [];

    /**
     * Whether or not to apply git changes.
     *
     * True by default. Can be changed using setApplyGitChanges() method
     * if the calling command needs to, for example when the update command
     * has been called in dry-run mode.
     *
     * Note that this only applies to commits and pushes. Repository cleaning
     * at the start of a command is always performed.
     *
     * @var bool
     */
    protected bool $applyGitChanges = true;

    /**
     * Construct a site object and initialize its properties.
     *
     * @param array $siteInfo
     *   An array of site information.
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     *   The SymfonyStyle object.
     */
    public function __construct(array $siteInfo, Command $command)
    {
        $this->command = $command;
        if (!$this->validateSiteInfo($siteInfo)) {
            return;
        }
        // Change to the site's directory.
        chdir($this->path);
        $this->findDrush();
        if (!$this->hasDrush()) {
            return;
        }
        $this->getSiteAliases();
        $this->getSiteStatuses();
    }

    /**
     * Validate the provided site info.
     *
     * @param array $siteInfo
     *   An array of site information.
     *
     * @return bool
     *   Whether or not the site info is valid.
     */
    protected function validateSiteInfo($siteInfo): bool
    {
        $this->command->debug([
            'Validating site info.',
            print_r($siteInfo, true),
        ]);
        if (!empty($siteInfo['uri'])) {
            if (is_array($siteInfo['uri'])) {
                $this->uris = $siteInfo['uri'];
                $this->isMultisite = true;
            } else {
                $this->uris = [$siteInfo['uri']];
            }
        } else {
            $this->errors[] = 'No URI(s) provided.';
        }
        if (!empty($siteInfo['path'])) {
            $this->path = $siteInfo['path'];
            if (!file_exists($this->path) || !is_dir($this->path)) {
                $this->errors[] = 'The path provided is not a directory that exists.';
            }
        } else {
            $this->errors[] = 'No path provided.';
        }
        if (!empty($siteInfo['prod_alias_name_match'])) {
            $this->prodAliasNameMatch = $siteInfo['prod_alias_name_match'];
        } else {
            $this->errors[] = 'No prod alias name match provided.';
        }
        $this->command->debug(array_merge(['Error messages:'], $this->errors));
        return (empty($this->errors));
    }

    /**
     * Announce the site being processed.
     *
     * @param string $actionName
     *   The name of the action being performed.
     *
     * @return void
     */
    public function announce(string $actionName): void
    {
        $this->command->io->section($actionName . ' Site: ' . reset($this->uris));
        $messages = [
            '<fg=bright-blue>Site root:</>    ' . $this->path,
            '<fg=bright-blue>Multisite:</>    ' . ($this->isMultisite ? 'yes' : 'no'),
            '<fg=bright-blue>Drush path:</>   ' . $this->drushPath,
        ];
        if (empty($this->siteAliases)) {
            $aliases = ['None found'];
        } else {
            $aliases = array_values($this->siteAliases);
        }
        if ($this->isMultisite) {
            $messages[] = '<fg=bright-blue>URIs:</>         ' . implode(', ', $this->uris);
            $messages[] = '<fg=bright-blue>Prod aliases:</> ' . implode(', ', $aliases);
        } else {
            $messages[] = '<fg=bright-blue>URI:</>          ' . reset($this->uris);
            $messages[] = '<fg=bright-blue>Prod alias:</>   ' . reset($aliases);
        }
        $this->command->io->text($messages);
        $this->command->io->newLine();
    }

    /**
     * Whether or not the site has a drush executable.
     *
     * @return bool
     *   TRUE if the site has a drush executable, FALSE otherwise.
     */
    public function hasDrush(): bool
    {
        return !empty($this->drushPath);
    }

    /**
     * Whether or not the site has errors.
     *
     * @return bool
     *   TRUE if the site has errors, FALSE otherwise.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get the site's errors.
     *
     * @return array
     *   An array of errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the URIs for the site.
     *
     * @return array
     *   An array of URIs.
     */
    public function getUris(): array
    {
        return $this->uris;
    }

    /**
     * Whether or not the site status was obtainable.
     *
     * If the site status was not obtainable, there is a problem
     * with the site or the drush executable and the command will
     * not be able to continue.
     *
     * @return bool
     *   TRUE if the site status was obtainable, FALSE otherwise.
     */
    public function hasStatus(): bool
    {
        return !empty($this->siteStatuses);
    }

    /**
     * Whether or not the site has aliases.
     *
     * @return bool
     *   TRUE if the site has aliases, FALSE otherwise.
     */
    public function hasAliases(): bool
    {
        return !empty($this->siteAliases);
    }

    /**
     * Set whether or not to apply git changes.
     *
     * @param bool $value
     *   TRUE to apply git changes, FALSE otherwise.
     *
     * @return void
     */
    public function setApplyGitChanges(bool $value): void
    {
        $this->applyGitChanges = $value;
    }

    /**
     * Rollback the site.
     *
     * @return bool
     *   TRUE if the site was rolled back successfully, FALSE otherwise.
     */
    public function rollback(): bool
    {
        $this->announce('Rollback');
        return true;
    }

    /**
     * Find the drush executable.
     *
     * @return void
     */
    protected function findDrush(): void
    {
        // We expect drush to exist in the vendor/bin directory.
        $drush = $this->path . '/vendor/bin/drush';
        if (@file_exists($drush) && @is_executable($drush)) {
            $this->drushPath = $drush;
        } else {
            $this->errors[] = 'Drush executable not found in ' . $drush;
        }
    }

    /**
     * Get the site's status.
     *
     * @return void
     */
    protected function getSiteStatuses(): void
    {
        foreach ($this->uris as $uri) {
            $process = $this->runDrushCommand('status', [
                '--format=json',
                '--uri=' . $uri,
            ]);
            if ($process->isSuccessful()) {
                $this->siteStatuses[$uri] = json_decode($process->getOutput(), true);
            } else {
                // Put the error in the errors array.
                $this->errors[] = 'Failed to obtain status for ' . $uri . ': ' . $process->getErrorOutput();
            }
        }
    }

    /**
     * Get the site's aliases.
     *
     * @return void
     */
    protected function getSiteAliases(): void
    {
        // Use Symfony process to run drush site:alias and capture the output.
        $process = $this->runDrushCommand('site:alias', ['--format=json']);
        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            $this->command->debug('Drush site:alias result: ' . $output);
            if (empty($output)) {
                return;
            }
            $aliases = json_decode($output, true);
            $this->command->debug('All site aliases: ' . print_r($aliases, true));
            // Only keep the aliases whose key contains the prodAliasNameMatch
            // and whose uri value matches one of the site's uris.
            $aliases = array_filter($aliases, function ($key) {
                return strpos($key, $this->prodAliasNameMatch) !== false;
            }, ARRAY_FILTER_USE_KEY);
            $aliases = array_filter($aliases, function ($value) {
                // See if the value contains one of the uris:
                $match = false;
                foreach ($this->uris as $uri) {
                    if (strpos($value['uri'], $uri) !== false) {
                        $match = true;
                        break;
                    }
                }
                return $match;
            });
            // Now organize the aliases by uri.
            foreach ($aliases as $alias => $aliasInfo) {
                foreach ($this->uris as $uri) {
                    if (strpos($aliasInfo['uri'], $uri) !== false) {
                        $this->siteAliases[$uri] = $alias;
                    }
                }
            }
            $this->command->debug('Filtered site aliases: ' . print_r($this->siteAliases, true));
        } else {
            // Put the error in the errors array.
            $this->errors[] = 'Failed to obtain site aliases: ' . $process->getErrorOutput();
        }
    }

    /**
     * Determine if the codebase is clean.
     *
     * @param string|array $allowedBranches
     *   The allowed git branches.
     *
     * @return bool
     *   TRUE if the codebase is clean, FALSE otherwise.
     */
    public function ensureCleanGitRepo(string|array $allowedBranches): bool
    {
        if (is_string($allowedBranches)) {
            $allowedBranches = [$allowedBranches];
        }
        $this->command->info('Checking for valid git repository and clean codebase...');
        $this->command->io->newLine();
        $this->command->io->progressStart(4);
        // First just make sure we actually have a git repo.
        $process = new Process([$this->command->git(), 'status', '--short']);
        $process->run();
        $this->command->io->progressAdvance();
        if (!$process->isSuccessful()) {
            $this->errors[] = 'The site does not have a git repository:';
            $this->errors[] = $process->getErrorOutput();
            $this->command->io->progressFinish();
            return false;
        }
        $output = $process->getOutput();
        if (!empty($output)) {
            $this->errors[] = 'The site codebase contains uncommitted changes:';
            $log_output = array_map('trim', explode("\n", trim($output)));
            $this->errors = array_merge($this->errors, $log_output);
            $this->command->io->progressFinish();
            return false;
        }
        $this->command->io->progressAdvance();
        if (!$this->isOnAllowedBranch($allowedBranches)) {
            $this->errors[] = 'The current working copy of the site is not on an allowed branch: '
                . implode(', ', $allowedBranches);
            $this->command->io->progressFinish();
            return false;
        }
        $this->command->io->progressAdvance();
        // Now ensure the current branch is up-to-date.
        $process = new Process([$this->command->git(), 'pull']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->errors[] = 'Failed to pull the latest changes from the git repository:';
            $this->errors[] = $process->getErrorOutput();
            $this->command->io->progressFinish();
            return false;
        }
        $this->command->io->progressAdvance();
        $this->command->io->progressFinish();
        $this->command->success('Codebase is clean!');
        $this->command->io->newLine();
        return true;
    }

    /**
     * Determine if the current git branch is allowed.
     *
     * @param string|array $allowedBranches
     *   The allowed git branches.
     *
     * @return bool
     *   TRUE if the current git branch is allowed, FALSE otherwise.
     */
    public function isOnAllowedBranch(string|array $allowedBranches): bool
    {
        $process = new Process([$this->command->git(), 'branch', '--show-current']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->errors[] = 'Failed to determine the current git branch.';
            return false;
        }
        $currentBranch = trim($process->getOutput());
        if (is_array($allowedBranches)) {
            return in_array($currentBranch, $allowedBranches);
        }
        return $currentBranch == $allowedBranches;
    }

    /**
     * Sync the production database for the site.
     *
     * Will only sync the database if the site has a prod alias.
     *
     * @return bool
     *   TRUE if the database was synced successfully, FALSE otherwise.
     */
    public function syncProdDatabase(): void
    {
        if (empty($this->siteAliases)) {
            $this->command->warning('Database cannot be synchronized from production.');
            $this->command->io->newLine();
        } elseif (count($this->siteAliases) < count($this->uris)) {
            // Issue a warning about the missing aliases.
            $this->command->warning('Database cannot be synchronized from production for the following URIs:');
            $this->command->io->newLine();
            $this->command->io->listing(array_diff($this->uris, array_keys($this->siteAliases)));
            $this->command->io->newLine();
        }
        foreach ($this->siteAliases as $uri => $alias) {
            $errors = false;
            $this->command->info('Sync and sanitize production database from ' . $alias . ' for ' . $uri . '...');
            $this->command->io->newLine();
            $process = $this->runDrushCommand('sql-sync', [
                $alias,
                '@self',
                '--uri=' . $uri,
            ], 300, true);
            if ($process->isSuccessful()) {
                $this->command->success('Sync complete, Sanitizing...');
                $this->command->io->newLine();
                $this->command->io->progressStart(3);
                // Run the drush sql-sanitize command.
                $process2 = $this->runDrushCommand('sql-sanitize', [
                    '--uri=' . $uri,
                ]);
                if ($process2->isSuccessful()) {
                    $this->command->io->progressAdvance();
                    // Now rebuild the cache and import configuration.
                    // We won't bother checking the success of the cache rebuild.
                    $this->runDrushCommand('cr', [
                        '--uri=' . $uri,
                    ]);
                    $this->command->io->progressAdvance();
                    $process3 = $this->runDrushCommand('cim', [
                        '--uri=' . $uri,
                    ]);
                    if ($process3->isSuccessful()) {
                        $this->command->io->progressAdvance();
                        $this->commandResults[$uri]['messages'][] = 'Database synced and sanitized from '
                            . $alias . ' for ' . $uri;
                    } else {
                        $errors = true;
                        // Put the error in the errors array.
                        $this->errors[] = 'Failed to import configuration for ' . $uri . ': '
                            . $process3->getErrorOutput();
                    }
                } else {
                    $errors = true;
                    // Put the error in the errors array.
                    $this->errors[] = 'Failed to sanitize database from ' . $alias . ' '
                    . $uri . ': ' . $process2->getErrorOutput();
                }
                $this->command->io->progressFinish();
                if (!$errors) {
                    $this->command->success('Sanitization complete!');
                    $this->command->io->newLine();
                }
            } else {
                // Put the error in the errors array.
                $this->errors[] = 'Failed to sync database from ' . $alias . ' '
                    . $uri . ': ' . $process->getErrorOutput();
            }
        }
    }

    /**
     * Backup the database for the site.
     *
     * @return void
     */
    public function backupDatabase(): void
    {
        foreach ($this->uris as $uri) {
            $this->command->info('Backup database for ' . $uri . '...');
            $this->command->io->newLine();
            // Run the drush sql-dump command.
            $backup_directory = $this->backupDirectory($uri);
            if (!$backup_directory) {
                $this->errors[] = 'Failed to establish backup directory for ' . $uri;
                break;
            }
            $process = $this->runDrushCommand('sql-dump', [
                '--result-file=' . $backup_directory . '/db-backup.sql',
                '--uri=' . $uri,
            ]);
            if ($process->isSuccessful()) {
                $this->commandResults[$uri]['messages'][] = 'Database backed up to '
                    . $backup_directory . '/' . $uri . '.sql';
                $this->command->success('Database backed up to:');
                $this->command->io->text($backup_directory . '/db-backup.sql');
                $this->command->io->newLine();
            } else {
                // Put the error in the errors array.
                $this->errors[] = 'Failed to backup database for ' . $uri . ': '
                    . $process->getErrorOutput();
            }
        }
    }

    /**
     * Determine the backup directory for a given URI.
     *
     * @param string $uri
     *   The URI of the site.
     *
     * @return string|false
     *   The backup directory, or FALSE if it could not be created.
     */
    protected function backupDirectory(string $uri): string|false
    {
        $full_db_backup_path = $this->siteStatuses[$uri]['root']
        . '/' . $this->siteStatuses[$uri]['files'] . '/database-backups'
        . '/' . $uri;
        if (!is_dir($full_db_backup_path)) {
            // If the directory does not exist, create it.
            if (!mkdir($full_db_backup_path, 0755, true)) {
                $this->errors[] = 'Could not create directory for database backup: ' . $full_db_backup_path;
                return false;
            }
        }
        return $full_db_backup_path;
    }

    /**
     * Run a drush command and return the process.
     *
     * Note that the --root and --yes options are always added.
     *
     * @param string $command
     *   The drush command to run.
     * @param array $options
     *   An array of options to pass to the command.
     * @param int $timeout
     *   The timeout for the command.
     *
     * @return \Symfony\Component\Process\Process
     *   An instance of the Symfony Process object.
     */
    protected function runDrushCommand(
        string $command,
        array $options = [],
        int $timeout = 60,
        bool $streamOutput = false
    ): Process {
        $options = array_merge(
            // Start with the drush executable and the command.
            [$this->drushPath, $command],
            // Add the options.
            $options,
            // Add the root and yes options.
            ['--root=' . $this->path, '--yes']
        );
        $process = new Process($options);
        $process->setTimeout($timeout);
        if ($streamOutput) {
            $process->run(function ($type, $buffer) {
                // Trim the buffer and break on newlines:
                $buffer_lines = explode("\n", trim($buffer));
                foreach ($buffer_lines as $line) {
                    $this->command->io->text('  <fg=blue>|</> ' . trim($line));
                }
            });
            $this->command->io->newLine();
        } else {
            $process->run();
        }
        return $process;
    }
}
