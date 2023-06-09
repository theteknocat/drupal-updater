<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Models;

use Symfony\Component\Console\Output\OutputInterface;
use TheTeknocat\DrupalUp\Commands\Command;
use TheTeknocat\DrupalUp\Commands\Traits\ExecutesExternalProcesses;

/**
 * Model an individual site to run a given command against.
 *
 * Also provides all the methods needed for running any command
 * against the site.
 */
class Site
{
    use ExecutesExternalProcesses;

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
     * The contents of the site's composer.json file.
     *
     * @var object
     */
    protected object $composerFileContents;

    /**
     * The contents of the site's composer.lock file.
     *
     * @var object
     */
    protected object $composerLockContents;

    /**
     * The contents of the site's composer.json file after the update.
     *
     * @var object
     */
    protected object $composerLockAfterContents;

    /**
     * Whether or not the composer file runs the drush cr command.
     *
     * @var bool
     */
    protected bool $composerRebuildCaches = false;

    /**
     * Whether or not the composer file runs the drush updb command.
     *
     * @var bool
     */
    protected bool $composerUpdateDatabase = false;

    /**
     * If a site cannot be rolled back, this is the reason why.
     *
     * @var string
     */
    public string $cannotRollbackReason = '';

    /**
     * Whether or not the site is a multisite that only has partial backups.
     *
     * @var bool
     */
    public bool $multisitePartialBackupsOnly = false;

    /**
     * List of backed up database files for the site.
     *
     * @var array
     */
    protected $dbBackupFiles = [];

    /**
     * The directories where DB backups are stored for each URI.
     *
     * @var array
     */
    protected $dbBackupDirectories = [];

    /**
     * The full path and filenames of DB backups for each URI.
     *
     * @var array
     */
    protected $dbBackupFilenames = [];

    /**
     * An array of the URIs that have backup files.
     *
     * @var array|null
     */
    protected $urisWithBackups = null;

    /**
     * Whether or not to skip syncing the DB.
     *
     * @var bool
     */
    protected $skipDbSync = false;

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
        $this->findDrush();
        if (!$this->hasDrush()) {
            return;
        }
        $this->getSiteAliases();
        $this->getSiteStatuses();
    }

    /**
     * {@inheritdoc}
     */
    protected function applyGitChanges(): bool
    {
        return $this->applyGitChanges;
    }

    /**
     * {@inheritdoc}
     */
    protected function getCommandObject(): Command
    {
        return $this->command;
    }

    /**
     * Return the command results array.
     *
     * @return array
     */
    public function getCommandResults(): array
    {
        return $this->commandResults;
    }

    /**
     * Return the DB backup files for the site.
     *
     * @return array
     */
    public function getDbBackupFiles(): array
    {
        return $this->dbBackupFiles;
    }

    /**
     * Whether or not the site is a multisite.
     *
     * @return bool
     */
    public function isMultisite(): bool
    {
        return $this->isMultisite;
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
        $this->command->debug('Validating site info.');
        $this->command->debug($siteInfo);
        if (!empty($siteInfo['uri'])) {
            if (is_array($siteInfo['uri'])) {
                $this->uris = $siteInfo['uri'];
                $this->isMultisite = true;
            } else {
                $this->uris = [$siteInfo['uri']];
            }
        } else {
            $this->setError('No URI(s) provided.');
        }
        if (!empty($siteInfo['path'])) {
            $this->path = $siteInfo['path'];
            if (!file_exists($this->path) || !is_dir($this->path)) {
                $this->setError('The path provided is not a directory that exists.');
            }
        } else {
            $this->setError('No path provided.');
        }
        if (!empty($siteInfo['prod_alias_name_match'])) {
            $this->prodAliasNameMatch = $siteInfo['prod_alias_name_match'];
        } else {
            $this->setError('No prod alias name match provided.');
        }
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
        // Get the drupal version for the first URI.
        $drupal_version = $this->siteStatuses[reset($this->uris)]['drupal-version'];
        $messages = [
            '<fg=bright-blue>Drupal version:</> ' . $drupal_version,
            '<fg=bright-blue>Site root:</>      ' . $this->path,
            '<fg=bright-blue>Multisite:</>      ' . ($this->isMultisite ?
                'Yes (' . (count($this->uris)) . ' sites)' : 'No'),
        ];
        if (empty($this->siteAliases)) {
            $aliases = ['None found'];
        } else {
            $aliases = array_values($this->siteAliases);
        }
        if ($this->isMultisite) {
            $messages[] = '<fg=bright-blue>All URIs:</>       ' . implode(', ', $this->uris);
            $messages[] = '<fg=bright-blue>Prod aliases:</>   ' . implode(', ', $aliases);
        } else {
            $messages[] = '<fg=bright-blue>Prod alias:</>     ' . reset($aliases);
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
     * Add an error to the site.
     *
     * @param string $error
     *   The error to add.
     *
     * @return void
     */
    public function setError(string $error): void
    {
        $this->errors[] = $error;
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
     * Get the site's path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
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
     * Set whether or not to skip the database sync.
     *
     * @param bool $value
     *   TRUE to skip the database sync, FALSE otherwise.
     *
     * @return void
     */
    public function setSkipDbSync(bool $value): void
    {
        $this->skipDbSync = $value;
    }

    /**
     * Determine whether or not a rollback can be performed.
     *
     * Requires that there is a git repository, the site is on an allowed branch,
     * and that there is a database backup file.
     *
     * @return bool
     */
    public function canDoRollback(): bool
    {
        $hasGitRepo = file_exists($this->path . '/.git/config');
        if (!$hasGitRepo) {
            $this->cannotRollbackReason = 'The site does not have a git repository.';
            return false;
        }
        if (!$this->isOnAllowedBranch([
            $this->command->getConfig('git.main_branch'),
            $this->command->getConfig('git.update_branch'),
            ])) {
            $this->cannotRollbackReason = 'The site is not on one of the allowed branches ('
                . $this->command->getConfig('git.main_branch') . ' or '
                . $this->command->getConfig('git.update_branch') . ').';
            return false;
        }
        $urisWithBackups = $this->urisWithBackups();
        if (empty($urisWithBackups)) {
            $this->cannotRollbackReason = 'The site does not have a database backup file.';
            return false;
        } elseif (count($urisWithBackups) < count($this->uris)) {
            $this->cannotRollbackReason = 'There are only backup files for the following uris: '
                . implode(', ', array_keys($urisWithBackups));
            $this->multisitePartialBackupsOnly = true;
        }
        return true;
    }

    /**
     * Compile a list of uris that have DB backup files.
     */
    protected function urisWithBackups(): array
    {
        if ($this->urisWithBackups === null) {
            $this->urisWithBackups = [];
            foreach ($this->uris as $uri) {
                $this->urisWithBackups[$uri] = false;
                if (!$this->backupDirectory($uri)) {
                    continue;
                }
                $db_backup_file = $this->backupFilename($uri);
                if (file_exists($db_backup_file)) {
                    $this->urisWithBackups[$uri] = true;
                }
            }
        }
        return $this->urisWithBackups;
    }

    /**
     * Rollback the site.
     *
     * @return void
     */
    public function doRollback(): void
    {
        $this->command->info('Reset git repository to HEAD and switch to the '
            . $this->command->getConfig('git.main_branch') . ' branch', false);
        // First thing is to reset the git repository to HEAD.
        $process = $this->runGitCommand('reset', ['--hard', 'HEAD']);
        if (!$process->isSuccessful()) {
            $this->command->doneError();
            throw new \Exception('Unable to reset git repository to HEAD.');
        }
        // Ensure the repo is clean.
        $process = $this->runGitCommand('clean', ['-f', '-d']);
        if (!$process->isSuccessful()) {
            $this->command->doneError();
            throw new \Exception('Unable to clean git repository.');
        }
        // Next make sure we are on the main branch.
        $process = $this->runGitCommand('checkout', [$this->command->getConfig('git.main_branch')]);
        if (!$process->isSuccessful()) {
            $this->command->doneError();
            throw new \Exception('Unable to switch to the ' . $this->command->getConfig('git.main_branch')
                . ' branch.');
        }
        $this->command->doneSuccess();
        $this->command->io->newLine();
        // Next import the database backup using drush sql:query.
        $urisWithBackups = $this->urisWithBackups();
        $this->command->info('Import database backup file(s). This may take a few minutes.');
        $this->command->io->newLine();
        foreach ($this->command->io->progressIterate($urisWithBackups) as $uri => $hasBackup) {
            if (!$hasBackup) {
                continue;
            }
            $db_backup_file = $this->backupFilename($uri);
            $process = $this->runDrushCommand('sql:query', [
                '--file=' . $db_backup_file,
                '--uri=' . $uri,
            ], 180);
            if (!$process->isSuccessful()) {
                $this->command->io->newLine(2);
                throw new \Exception('Could not import database backup file for ' . $uri
                    . '. ' . $process->getErrorOutput());
            }
        }
        // Next we need to run composer install.
        $this->command->info('Run composer install to rollback codebase. This may take a'
            . ' few minutes', $this->command->io->isVerbose());
        if ($this->command->io->isVerbose()) {
            $this->command->io->newLine();
        }
        // Delete the folders that are created by composer.
        $firstStatus = reset($this->siteStatuses);
        // All URIs will have the same root. We just need the docroot folder name.
        $docroot = basename($firstStatus['root']);
        $process = $this->runProcess([
            'rm',
            '-rf',
            'vendor',
            $docroot . '/core',
            $docroot . '/modules/contrib',
            $docroot . '/themes/contrib',
            $docroot . '/libraries',
        ]);
        if (!$process->isSuccessful()) {
            if (!$this->command->io->isVerbose()) {
                $this->command->doneError();
            }
            $this->command->io->newLine();
            throw new \Exception('Could not delete vendor, core, modules/contrib, and themes/contrib folders.');
        }
        $this->readComposerFiles();
        $process = $this->runComposerCommand('install', [
            '--no-interaction',
        ], 300, true);
        if (!$process->isSuccessful()) {
            if (!$this->command->io->isVerbose()) {
                $this->command->doneError();
            }
            $this->command->io->newLine();
            throw new \Exception('Could not run composer install. ' . $process->getErrorOutput());
        }
        if (!$this->composerRebuildCaches) {
            $this->runDrushCommand('cr');
        }
        if (!$this->composerUpdateDatabase) {
            $this->runDrushCommand('updb');
            $this->runDrushCommand('cr');
        }
        // Reset the git repo again, to revert any scaffold files.
        $this->runGitCommand('reset', ['--hard', 'HEAD']);
        if (!$this->command->io->isVerbose()) {
            $this->command->doneSuccess();
        }
        $this->command->io->newLine();
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
            $this->setError('Drush executable not found in ' . $drush);
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
                // Make sure we have some of the keys we need.
                if (empty($this->siteStatuses[$uri]['root']) || empty($this->siteStatuses[$uri]['files'])) {
                    $this->setError('Site status for ' . $uri . ' is missing "root" and/or "files" keys.'
                        . ' The site may be in an unstable state, possibly with corrupt or missing database'
                        . ' content, and cannot be processed.');
                    unset($this->siteStatuses[$uri]);
                }
            } else {
                // Put the error in the errors array.
                $this->setError('Failed to obtain status for ' . $uri . ': ' . $process->getErrorOutput());
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
            if (empty($output)) {
                return;
            }
            $aliases = json_decode($output, true);
            $this->command->debug('All site aliases:');
            $this->command->debug($aliases);
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
            $this->command->debug('Filtered site aliases:');
            $this->command->debug($this->siteAliases);
        } else {
            // Put the error in the errors array.
            $this->setError('Failed to obtain site aliases: ' . $process->getErrorOutput());
        }
    }

    /**
     * Determine if the codebase is clean.
     *
     * @param string|array $allowedBranches
     *   The allowed git branches.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function ensureCleanGitRepo(string|array $allowedBranches): void
    {
        if (is_string($allowedBranches)) {
            $allowedBranches = [$allowedBranches];
        }
        $this->command->info('Check for valid git repository and clean codebase', false);
        // First just make sure we actually have a git repo.
        $process = $this->runGitCommand('status', ['--short']);
        if (!$process->isSuccessful()) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setError('The site does not have a git repository:');
            $this->setError($process->getErrorOutput());
            $this->setFailed();
            throw new \Exception('Unable to validate git repository. See log for details.');
        }
        $output = $process->getOutput();
        if (!empty($output)) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setError('The site codebase contains uncommitted changes:');
            $log_output = array_map('trim', explode(PHP_EOL, trim($output)));
            foreach ($log_output as $line) {
                $this->setError($line);
            }
            $this->setFailed();
            throw new \Exception('Git repository is not clean. See log for details.');
        }
        if (!$this->isOnAllowedBranch($allowedBranches)) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setError('The current working copy of the site is not on an allowed branch: '
                . implode(', ', $allowedBranches));
            $this->setFailed();
            throw new \Exception('Failed to validate current branch. See log for details.');
        }
        // Now ensure the current branch is up-to-date.
        $process = $this->runGitCommand('pull');
        if (!$process->isSuccessful()) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setError('Failed to pull the latest changes from the git repository:');
            $this->setError($process->getErrorOutput());
            $this->setFailed();
            throw new \Exception('Failed to refresh git repository. See log for details.');
        }
        $this->command->doneSuccess();
        $this->command->io->newLine();
    }

    /**
     * Setup a clean branch in which to do Drupal updates.
     */
    public function setupCleanUpdateBranch(): void
    {
        $update_branch = $this->command->getConfig('git.update_branch');
        $remote_key = $this->command->getConfig('git.remote_key');

        $this->command->info('Setup clean new branch for updates: ' . $update_branch, false);
        // Delete the local branch, if present.
        $process = $this->runGitCommand('branch', ['--list', $update_branch]);
        if ($process->isSuccessful()) {
            // Check the output to see if the branch is present.
            $output = $process->getOutput();
            if (strpos($output, $update_branch) !== false) {
                // The branch is present, so delete it.
                $process = $this->runGitCommand('branch', ['-D', $update_branch]);
                if ($this->applyGitChanges && !$process->isSuccessful()) {
                    $this->command->doneError();
                    $this->setFailed();
                    throw new \Exception('Failed to delete local branch ' . $update_branch . ': '
                        . $process->getErrorOutput());
                }
            }
        }
        // Delete the remote branch, if present.
        $process = $this->runGitCommand('ls-remote', ['--heads', $remote_key, $update_branch]);
        $output = $process->getOutput();
        if (!empty($output)) {
            $process = $this->runGitCommand('push', [$remote_key, '--delete', $update_branch]);
            if ($this->applyGitChanges && !$process->isSuccessful()) {
                $this->command->doneError();
                $this->command->io->newLine();
                $this->setFailed();
                throw new \Exception('Failed to delete local branch ' . $update_branch . ': '
                . $process->getErrorOutput());
            }
        }
        // Checkout a fresh new drupal-updates branch and push it to the remote
        // repository.
        $process = $this->runGitCommand('checkout', ['-b', $update_branch]);
        if ($this->applyGitChanges && !$process->isSuccessful()) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setFailed();
            throw new \Exception('Failed to create local branch ' . $update_branch . ': '
            . $process->getErrorOutput());
        }
        $process = $this->runGitCommand('push', ['-u', $remote_key, $update_branch]);
        if ($this->applyGitChanges && !$process->isSuccessful()) {
            $this->command->doneError();
            $this->command->io->newLine();
            $this->setFailed();
            throw new \Exception('Failed to push local branch ' . $update_branch . ' to remote: '
            . $process->getErrorOutput());
        }
        $this->command->doneSuccess();
        $this->command->io->newLine();
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
        $process = $this->runGitCommand('branch', ['--show-current']);
        if (!$process->isSuccessful()) {
            $this->setError('Failed to determine the current git branch.');
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
     * @throws \Exception
     */
    public function syncProdDatabase(): void
    {
        if ($this->skipDbSync) {
            $this->command->info('Skip database sync from production.');
            $this->command->io->newLine();
            return;
        }
        $success = true;
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
        if (!$this->command->io->isVerbose()) {
            // If not in verbose mode, show a progress bar.
            $this->command->info('Sync'
                . ($this->command->getConfig('sanitize_databases_on_sync') ? ' and sanitize' : '')
                . ' production database(s). This may take a few minutes.');
            $this->command->io->newLine();
            $this->command->io->progressStart(count($this->siteAliases));
        }
        foreach ($this->siteAliases as $uri => $alias) {
            $errors = false;
            if ($this->command->io->isVerbose()) {
                $this->command->info('Sync'
                    . ($this->command->getConfig('sanitize_databases_on_sync') ? ' and sanitize' : '')
                    . ' production database from ' . $alias . ' for ' . $uri
                    . '. This may take a few minutes', false);
                $this->command->io->newLine();
            }
            $process = $this->runDrushCommand('sql-sync', [
                $alias,
                '@self',
                '--uri=' . $uri,
            ], 300, true);
            if ($process->isSuccessful()) {
                if ($this->command->getConfig('sanitize_databases_on_sync')) {
                    // Run the drush sql-sanitize command.
                    $process2 = $this->runDrushCommand('sql-sanitize', [
                        '--uri=' . $uri,
                    ]);
                    if (!$process2->isSuccessful()) {
                        $errors = true;
                        // Put the error in the errors array.
                        $this->setError('Failed to sanitize database from ' . $alias . ' for '
                            . $uri . ': ' . $process2->getErrorOutput());
                    }
                }
                if (!$errors) {
                    // Now rebuild the cache and import configuration.
                    // We won't bother checking the success of the cache rebuild.
                    $this->runDrushCommand('cr', [
                        '--uri=' . $uri,
                    ]);
                    $process3 = $this->runDrushCommand('cim', [
                        '--uri=' . $uri,
                    ]);
                    if (!$process3->isSuccessful()) {
                        $errors = true;
                        // Put the error in the errors array.
                        $this->setError('Failed to import configuration for ' . $uri . ': '
                            . $process3->getErrorOutput());
                    }
                }
                $success = (!$errors);
            } else {
                // Put the error in the errors array.
                $this->setError('Failed to sync database from ' . $alias . ' '
                    . $uri . ': ' . $process->getErrorOutput());
                $success = false;
            }
            if ($this->command->io->isVerbose()) {
                if ($success) {
                    $this->command->doneSuccess();
                } else {
                    $this->command->doneError();
                }
                $this->command->io->newLine();
            } else {
                $this->command->io->progressAdvance();
            }
        }
        if (!$this->command->io->isVerbose()) {
            $this->command->io->progressFinish();
        }
        if (!$success) {
            $this->setFailed();
            throw new \Exception('Database synchronization failed.');
        }
    }

    /**
     * Backup the database for the site.
     *
     * @throws \Exception
     */
    public function backupDatabase(): void
    {
        $success = true;
        if (!$this->command->io->isVerbose()) {
            // If not in verbose mode, show a progress bar.
            $this->command->info('Backup database(s). This may take a few minutes.');
            $this->command->io->newLine();
            $this->command->io->progressStart(count($this->uris));
        }
        foreach ($this->uris as $uri) {
            if ($this->command->io->isVerbose()) {
                $this->command->info('Backup database for ' . $uri, false);
            }
            // Run the drush sql-dump command.
            if (!$this->backupDirectory($uri)) {
                $this->command->doneError();
                $this->command->io->newLine();
                $this->setError('Failed to establish backup directory for ' . $uri);
                $success = false;
                break;
            }
            $process = $this->runDrushCommand('sql-dump', [
                '--result-file=' . $this->backupFilename($uri),
                '--uri=' . $uri,
            ]);
            if ($process->isSuccessful()) {
                $this->dbBackupFiles[] = $this->backupFilename($uri);
                if ($this->command->io->isVerbose()) {
                    $this->command->doneSuccess();
                    $this->command->success('Database backed up to:');
                    $this->command->io->text($this->backupFilename($uri));
                    $this->command->io->newLine();
                }
            } else {
                $this->command->doneError();
                $this->command->io->newLine();
                // Put the error in the errors array.
                $this->setError('Failed to backup database for ' . $uri . ': '
                . $process->getErrorOutput());
                $success = false;
            }
            if (!$this->command->io->isVerbose()) {
                $this->command->io->progressAdvance();
            }
        }
        if (!$this->command->io->isVerbose()) {
            $this->command->io->progressFinish();
        }
        if (!$success) {
            $this->setFailed();
            throw new \Exception('Database backup failed.');
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
        if (!isset($this->dbBackupDirectories[$uri])) {
            $full_db_backup_path = $this->siteStatuses[$uri]['root']
            . '/' . $this->siteStatuses[$uri]['files'] . '/database-backups';
            // Assume it's good by default.
            $this->dbBackupDirectories[$uri] = $full_db_backup_path;
            if (!is_dir($full_db_backup_path)) {
                // If the directory does not exist, try to create it.
                if (!mkdir($full_db_backup_path, 0755, true)) {
                    $this->setError('Could not create directory for database backup: ' . $full_db_backup_path);
                    $this->dbBackupDirectories[$uri] = false;
                }
            }
        }
        return $this->dbBackupDirectories[$uri];
    }

    /**
     * Determine and return the backup filename for a given URI.
     *
     * This method should not be called without first calling the
     * backupDirectory() method and checking its return value.
     * Otherwise it will throw an exception and cause the script to
     * exit.
     *
     * @param string $uri
     *   The URI of the site.
     *
     * @throws \Exception
     *
     * @return string
     *   The backup filename with full path.
     */
    protected function backupFilename(string $uri): string
    {
        if (empty($this->dbBackupFilenames[$uri])) {
            $backup_directory = $this->backupDirectory($uri);
            if (!$backup_directory) {
                $this->setFailed();
                throw new \Exception('Backup directory cannot be determined.');
            } else {
                // First convert the URI to a clean filename.
                $uri_for_filename = str_replace(['http://', 'https://', '.'], ['', '', '-'], $uri);
                $this->dbBackupFilenames[$uri] = $this->backupDirectory($uri)
                    . '/db-backup-' . $uri_for_filename . '.sql';
            }
        }
        return $this->dbBackupFilenames[$uri];
    }

    /**
     * Run a composer update on the site.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function doComposerUpdate(): void
    {
        $this->command->info(
            'Run composer updates. This will take a few minutes',
            $this->command->io->isVerbose()
        );
        if ($this->command->io->isVerbose()) {
            $this->command->io->newLine();
        }
        $this->readComposerFiles();

        // We run the composer updates twice as occassionally the first run
        // will not update all packages properly, or changes to packages may
        // result in some being removed inadvertently.
        if (!$this->runComposerUpdates()) {
            if (!$this->command->io->isVerbose()) {
                $this->command->doneError();
                $this->command->io->newLine();
            }
            return;
        }
        $this->runComposerUpdates();

        if ($this->command->io->isVerbose()) {
            $this->command->success('Composer update task completed.');
        } else {
            $this->command->doneSuccess();
        }
        $this->command->io->newLine();

        $result = $this->checkComposerChanges();

        if (empty($result['new_packages']) && empty($result['updated_packages'])) {
            // Nothing was updated.
            $this->command->info('No composer changes were detected.');
            $this->command->io->newLine();
            // Reset the git repo and revert to the master branch.
            $this->runGitCommand('reset', [
                '--hard',
                'HEAD',
            ]);
            $this->runGitCommand('checkout', [
                'master',
            ]);
            $this->command->info('Git repository reset to master.');
            $this->command->io->newLine();

            // Set status messages.
            $this->commandResults['core_status'] = 'unchanged';
            $this->commandResults['other_status'] = 'unchanged';
            $this->commandResults['status'] = 'unchanged';
            return;
        }

        $this->command->info('Run additional tasks to complete the update. This may take a few minutes', false);
        if (!$this->composerRebuildCaches) {
            $this->runDrushCommand('cr');
        }
        if (!$this->composerUpdateDatabase) {
            $this->runDrushCommand('updb');
            $this->runDrushCommand('cr');
        }

        $update_result = $this->compilePackageUpdateInfo($result['updated_packages'] ?? [], 'updated');
        $other_updates = ($update_result['module']
            || $update_result['theme']
            || $update_result['library']
            || $update_result['profile']
            || $update_result['other']);
        $this->compilePackageUpdateInfo($result['new_packages'] ?? [], 'added');

        // Get any files to diff and/or revert.
        $post_update_diff_files = $this->command->getConfig('post_update_diff_files');
        $post_update_revert_files = $this->command->getConfig('post_update_revert_files');
        // Remove any files from the diff list that are in the revert list.
        if (is_array($post_update_diff_files) && is_array($post_update_revert_files)) {
            $post_update_diff_files = array_diff($post_update_diff_files, $post_update_revert_files);
        }
        if (is_array($post_update_diff_files) && !empty($post_update_diff_files)) {
            $this->runDiff($post_update_diff_files);
        }
        if (is_array($post_update_revert_files) && !empty($post_update_revert_files)) {
            $this->runDiffAndRevert($post_update_revert_files);
        }
        $this->command->doneSuccess();
        $this->command->io->newLine();

        // Now set status messages.
        $this->commandResults['core_status'] = $update_result['core'] ? 'success' : 'unchanged';
        $this->commandResults['other_status'] = $other_updates ? 'success' : 'unchanged';
        $this->commandResults['status'] = ($update_result['core'] == $other_updates) ? 'success' : 'mixed';

        $commit_result = $this->commitChangesAndPush($update_result, $other_updates);
        if ($commit_result) {
            // If there is a post update script, run it.
            $post_update_script = $this->command->getConfig('post_update_script');
            if (!empty($post_update_script)) {
                $this->runPostUpdateScript($post_update_script);
            }
        } else {
            $this->command->warning('Errors occurred committing and pushing the changes. See log for details.');
        }
    }

    /**
     * Run a diff on a set of files.
     *
     * @param array $files
     *   The files to diff.
     *
     * @return void
     */
    protected function runDiff(array $files): void
    {
        foreach ($files as $file) {
            $diff = $this->getFileDiff($file);
            if (empty($diff)) {
                // No diff, so nothing to do.
                continue;
            }
            $this->commandResults['messages'][] = "**The file *" . $file . "* "
                . " was modified by the update process and committed with the changes.** "
                . "Git diff follows for reference." . PHP_EOL . PHP_EOL;
            $this->commandResults['messages'][] = "```" . PHP_EOL
                . $diff . PHP_EOL . "```" . PHP_EOL;
        }
    }

    /**
     * Run a diff and revert on a set of files.
     *
     * @param array $files
     *   The files to revert.
     *
     * @return void
     */
    protected function runDiffAndRevert(array $files): void
    {
        foreach ($files as $file) {
            $diff = $this->getFileDiff($file);
            if (empty($diff)) {
                // No diff, so nothing to do.
                continue;
            }
            $this->commandResults['messages'][] = "**The file *" . $file . "* "
                . " was modified by the update process and reverted before committing.** "
                . "Git diff follows for reference." . PHP_EOL . PHP_EOL;
            $this->commandResults['messages'][] = "```" . PHP_EOL
                . $diff . PHP_EOL . "```" . PHP_EOL;
            // Now revert the file.
            $process = $this->runGitCommand('checkout', [$file]);
        }
    }

    /**
     * Run and get a diff on a file.
     *
     * @param string $file
     *   The file to diff.
     *
     * @return string|bool
     *   The diff, or false if there was an error.
     */
    protected function getFileDiff(string $file): string|bool
    {
        // Replace the [docroot] token (if found) in the filename
        // with the actual docroot folder name.
        $file = str_replace('[docroot]', basename($this->siteStatuses[$this->uris[0]]['root']), $file);
        // Now run a git diff on the file.
        $process = $this->runGitCommand('diff', [$file]);
        if (!$process->isSuccessful()) {
            $this->setError('Git diff failed: ' . $process->getErrorOutput());
            return false;
        }
        $diff = $process->getOutput();
        if (empty($diff)) {
            // No diff, so nothing to do.
            return false;
        }
        return $diff;
    }

    /**
     * Commit changes to git repo and push to remote.
     *
     * @param array $update_result
     *   The update result array.
     * @param bool $has_other_updates
     *   Whether there are other (non-core) updates.
     *
     * @return bool
     *   Whether the commit and push was successful.
     */
    protected function commitChangesAndPush(array $update_result, bool $has_other_updates): bool
    {
        $commit_message = "Drupal ";
        if ($update_result['core']) {
            $commit_message .= "Core";
        }
        if ($has_other_updates) {
            if ($update_result['core']) {
                $commit_message .= ' and ';
            }
            $update_types = array_keys(array_filter($update_result));
            // Exclude "core" from the list.
            $update_types = array_filter($update_types, function ($type) {
                return $type != 'core';
            });
            $commit_message .= implode('/', $update_types);
        }
        $commit_message .= " updates";

        if (!empty($this->commandResults['messages'])) {
            $commit_message .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $this->commandResults['messages']);
        }
        $this->command->info(
            'Commit changes to ' . $this->command->getConfig('git.update_branch'). ' and push to '
                . $this->command->getConfig('git.remote_key')
                . ($this->command->io->isVerbose() ? '...' : ''),
            $this->command->io->isVerbose()
        );
        if ($this->command->io->isVerbose()) {
            $this->command->io->newLine();
            $this->command->info('Commit message:');
            $this->command->io->write($commit_message);
            $this->command->io->newLine();
        }
        $this->runGitCommand('add', ['-A']);
        $process = $this->runGitCommand('commit', [
            '--author=' . escapeshellarg($this->command->getConfig('git.commit_author')),
            '--message', $commit_message,
        ]);
        if (!$process->isSuccessful() && $this->applyGitChanges) {
            // Only note errors if we are actually applying the changes.
            $this->commandResults['messages'][] = 'Git commit failed. Any error output from git follows.'
                . PHP_EOL;
            $this->commandResults['messages'][] = $process->getErrorOutput() . PHP_EOL;
            if (!$this->command->io->isVerbose()) {
                $this->command->doneError();
                $this->command->io->newLine();
            }
            $this->setError('Git commit failed: ' . $process->getErrorOutput());
            return false;
        }
        $process = $this->runGitCommand('push');
        if (!$process->isSuccessful() && $this->applyGitChanges) {
            if (!$this->command->io->isVerbose()) {
                $this->command->doneError();
                $this->command->io->newLine();
            }
            // Only note errors if we are actually applying the changes.
            $this->commandResults['messages'][] = 'Git push failed. Any error output from git follows.'
                . PHP_EOL;
            $this->commandResults['messages'][] = $process->getErrorOutput() . PHP_EOL;
            $this->setError('Git push failed: ' . $process->getErrorOutput());
            return false;
        }
        $this->command->doneSuccess();
        return true;
    }

    /**
     * Execute the composer update command and handle errors.
     *
     * @return bool
     */
    protected function runComposerUpdates(): bool
    {
        $process = $this->runComposerCommand('update', [
            '--no-interaction',
        ], 300, true);
        if (!$process->isSuccessful()) {
            $this->setFailed();

            $this->commandResults['messages'][] = 'Composer update failed. Any error output from composer follows.'
                . PHP_EOL;
            $this->commandResults['messages'][] = $process->getErrorOutput() . PHP_EOL;

            // Reset the git repo and revert to the master branch.
            $this->runGitCommand('reset', [
                '--hard',
                'HEAD',
            ]);
            $this->runGitCommand('checkout', [
                'master',
            ]);

            $this->setError('Composer update failed: ' . $process->getErrorOutput());

            return false;
        }
        return true;
    }

    /**
     * Read the composer files for the site.
     *
     * @return void
     */
    protected function readComposerFiles(): void
    {
        $composerFile = file_get_contents($this->path . '/composer.json');
        $composerLock = file_get_contents($this->path . '/composer.lock');
        if (!$composerFile || !$composerLock) {
            $this->setFailed();
            throw new \Exception('Could not read composer files.');
        }
        $this->composerFileContents = json_decode($composerFile);
        $this->composerLockContents = json_decode($composerLock);
        // Check to see if $this->composerFileContents has post-install-cmd or
        // post-update-cmd scripts that contain drush cr and/or drush updb.
        // If it does, then we don't need to run those commands separately.
        if (!empty($this->composerFileContents->scripts)) {
            foreach ($this->composerFileContents->scripts as $script_type => $scripts) {
                if ($script_type == 'post-install-cmd' || $script_type == 'post-update-cmd') {
                    if (!is_array($scripts)) {
                        $scripts = [$scripts];
                    }
                    foreach ($scripts as $script) {
                        if (str_contains($script, 'drush cr') && !$this->composerRebuildCaches) {
                            $this->composerRebuildCaches = true;
                        }
                        if (str_contains($script, 'drush updb') && !$this->composerUpdateDatabase) {
                            $this->composerUpdateDatabase = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Check for changes in the composer.lock file.
     *
     * @return array
     *   An array of changes, grouped into new_packages and updated_packages.
     */
    protected function checkComposerChanges(): array
    {
        $composerLock = file_get_contents($this->path . '/composer.lock');
        $this->composerLockAfterContents = json_decode($composerLock);
        $result = [
            'new_packages' => [],
            'updated_packages' => [],
        ];
        $packages_before = [];
        $packages_after = [];
        foreach ($this->composerLockContents->packages as $package_info) {
            $packages_before[$package_info->name] = [
                'name' => $package_info->name,
                'type' => $package_info->type,
                'version' => $package_info->version,
            ];
        }
        foreach ($this->composerLockAfterContents->packages as $package_info) {
            $packages_after[$package_info->name] = [
                'name' => $package_info->name,
                'type' => $package_info->type,
                'version' => $package_info->version,
            ];
        }
        $package_names_before = array_keys($packages_before);
        $package_names_after = array_keys($packages_after);
        $new_packages = array_diff($package_names_after, $package_names_before);
        if (!empty($new_packages)) {
            foreach ($new_packages as $new_package_name) {
                $result['new_packages'][$new_package_name] = $packages_after[$new_package_name];
            }
        }

        // Now find updated packages:
        foreach ($packages_after as $package_name => $package_info) {
            if (!in_array($package_name, $new_packages)) {
                // If not a new package, check to see if it has
                // been updated.
                if (!empty($packages_before[$package_name])
                    && $packages_before[$package_name]['version'] != $package_info['version']) {
                    $updated_package_info = $package_info;
                    $updated_package_info['old_version'] = $packages_before[$package_name]['version'];
                    $result['updated_packages'][$package_name] = $updated_package_info;
                }
            }
        }
        ksort($result['new_packages']);
        ksort($result['updated_packages']);
        return $result;
    }

    /**
     * Compile new/update info for a set of packages.
     *
     * @param array $packages
     *   The packages array.
     * @param string $install_action
     *   The type of install ('new' or 'updated').
     *
     * @return array
     *   The installation success info.
     */
    protected function compilePackageUpdateInfo(array $packages, string $install_action): array
    {
        $result = [
            'core'    => false,
            'module'  => false,
            'theme'   => false,
            'library' => false,
            'profile' => false,
            'other'   => false,
        ];
        if (empty($packages)) {
            return array_filter($result);
        }
        $core_message = "";
        $modules_message = "";
        $themes_message = "";
        $libraries_message = "";
        $profiles_message = "";
        $others_message = "";
        $module_package_types = [
            'drupal-module',
            'drupal-custom-module',
        ];
        $theme_package_types = [
            'drupal-theme',
            'drupal-custom-theme',
        ];
        $library_package_types = [
            'drupal-library',
            'drupal-custom-library',
        ];
        $profile_package_types = [
            'drupal-profile',
            'drupal-custom-profile',
        ];
        foreach ($packages as $package) {
            if ($package['type'] == 'drupal-core' && empty($core_message)) {
                // This item is unique and will always be updated, never new.
                $result['core'] = true;
                $core_message = "**Drupal Core was updated from " . $package['old_version']
                    . " to " . $package['version'] . "**" . PHP_EOL;
            } elseif (in_array($package['type'], $module_package_types)) {
                if (empty($modules_message)) {
                    $result['module'] = true;
                    $modules_message = "**The following Drupal modules were " . $install_action . ":**"
                        . PHP_EOL . PHP_EOL;
                }
                $modules_message .= "* `" . $package['name'] . "` (";
                if (isset($package['old_version'])) {
                    $modules_message .= $package['old_version'] . " => ";
                }
                $modules_message .= $package['version'] . ")" . PHP_EOL;
            } elseif (in_array($package['type'], $theme_package_types)) {
                if (empty($themes_message)) {
                    $result['theme'] = true;
                    $themes_message = "**The following Drupal themes were " . $install_action . ":**"
                        . PHP_EOL . PHP_EOL;
                }
                $themes_message .= "* `" . $package['name'] . "` (";
                if (isset($package['old_version'])) {
                    $themes_message .= $package['old_version'] . " => ";
                }
                $themes_message .= $package['version'] . ")" . PHP_EOL;
            } elseif (in_array($package['type'], $library_package_types)) {
                if (empty($libraries_message)) {
                    $result['library'] = true;
                    $libraries_message = "**The following Drupal libraries were " . $install_action . ":**"
                        . PHP_EOL . PHP_EOL;
                }
                $libraries_message .= "* `" . $package['name'] . "` (";
                if (isset($package['old_version'])) {
                    $libraries_message .= $package['old_version'] . " => ";
                }
                $libraries_message .= $package['version'] . ")" . PHP_EOL;
            } elseif (in_array($package['type'], $profile_package_types)) {
                if (empty($profiles_message)) {
                    $result['profile'] = true;
                    $profiles_message = "**The following Drupal profiles were " . $install_action . ":**"
                        . PHP_EOL . PHP_EOL;
                }
                $profiles_message .= "* `" . $package['name'] . "` (";
                if (isset($package['old_version'])) {
                    $profiles_message .= $package['old_version'] . " => ";
                }
                $profiles_message .= $package['version'] . ")" . PHP_EOL;
            } else {
                if (empty($others_message)) {
                    $result['other'] = true;
                    $others_message = "**The following other dependencies were " . $install_action . ":**"
                        . PHP_EOL . PHP_EOL;
                }
                $others_message .= "* `" . $package['name'] . "` (";
                if (isset($package['old_version'])) {
                    $others_message .= $package['old_version'] . " => ";
                }
                $others_message .= $package['version'] . ")" . PHP_EOL;
            }
        }
        if (!empty($core_message)) {
            $this->commandResults['messages'][] = $core_message;
        }
        if (!empty($modules_message)) {
            $this->commandResults['messages'][] = $modules_message;
        }
        if (!empty($themes_message)) {
            $this->commandResults['messages'][] = $themes_message;
        }
        if (!empty($libraries_message)) {
            $this->commandResults['messages'][] = $libraries_message;
        }
        if (!empty($profiles_message)) {
            $this->commandResults['messages'][] = $profiles_message;
        }
        if (!empty($others_message)) {
            $this->commandResults['messages'][] = $others_message;
        }
        return $result;
    }

    /**
     * Set the command results to failed.
     *
     * @return void
     */
    protected function setFailed(): void
    {
        $this->commandResults['core_status']  = 'failed';
        $this->commandResults['other_status'] = 'failed';
        $this->commandResults['status']       = 'failed';
    }
}
