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
    protected $uris = [];

    /**
     * Whether or not the site is a multisite.
     *
     * This will be true if the site has multiple
     * production aliases.
     *
     * @var bool
     */
    protected $isMultisite = false;

    /**
     * The path to the site.
     *
     * @var string
     */
    protected $path;

    /**
     * The site's prod alias name to match.
     *
     * @var string
     */
    protected $prodAliasNameMatch;

    /**
     * The path to the site's drush executable.
     *
     * @var string
     */
    protected $drushPath;

    /**
     * The site's status(es) obtained from drush status.
     *
     * If the site is a multisite, this will be an array
     * of status arrays keyed by the site's URIs.
     *
     * @var array
     */
    protected $siteStatuses = [];

    /**
     * The site's aliases obtained from drush sa.
     *
     * @var array
     */
    protected $siteAliases = [];

    /**
     * The site's prod alias names.
     *
     * @var array
     */
    protected $siteAliasNames = [];

    /**
     * List of errors that occurred while processing the site.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Instance of the current command object.
     *
     * @var \TheTeknocat\DrupalUp\Commands\Command
     */
    protected $command;

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
            '<fg=bright-blue>Site root:</>      ' . $this->path,
            '<fg=bright-blue>Multisite:</>      ' . ($this->isMultisite ? 'yes' : 'no'),
            '<fg=bright-blue>Drush path:</>     ' . $this->drushPath,
        ];
        if (empty($this->siteAliases)) {
            $aliases = ['None found'];
        } else {
            $aliases = implode(', ', array_keys($this->siteAliases));
        }
        if ($this->isMultisite) {
            $messages[] = '<fg=bright-blue>URIs:</>           ' . implode(', ', $this->uris);
            $messages[] = '<fg=bright-blue>Prod alias(es):</> ' . implode(', ', $aliases);
        } else {
            $messages[] = '<fg=bright-blue>URI:</>            ' . reset($this->uris);
            $messages[] = '<fg=bright-blue>Prod alias:</>     ' . reset($aliases);
        }
        $this->command->io->text($messages);
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
     * Update the site.
     *
     * @return bool
     *   TRUE if the site was updated successfully, FALSE otherwise.
     */
    public function update(): bool
    {
        $this->announce('Update');
        return true;
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
            $this->siteAliases = json_decode($process->getOutput(), true);
            $this->command->debug('All site aliases: ' . print_r($this->siteAliases, true));
            // Only keep the aliases whose key contains the prodAliasNameMatch
            // and whose uri value matches one of the site's uris.
            $this->siteAliases = array_filter($this->siteAliases, function ($key) {
                return strpos($key, $this->prodAliasNameMatch) !== false;
            }, ARRAY_FILTER_USE_KEY);
            $this->siteAliases = array_filter($this->siteAliases, function ($value) {
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
            $this->command->debug('Filtered site aliases: ' . print_r($this->siteAliases, true));
            // Store the alias names for convenience.
            $this->siteAliasNames = array_keys($this->siteAliases);
        } else {
            // Put the error in the errors array.
            $this->errors[] = 'Failed to obtain site aliases: ' . $process->getErrorOutput();
        }
    }

    /**
     * Run a drush command and return the process.
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function runDrushCommand(string $command, array $options = []): \Symfony\Component\Process\Process
    {
        $options = array_merge([$this->drushPath, $command, '--root=' . $this->path], $options);
        $process = new Process($options);
        $process->run();
        return $process;
    }
}
