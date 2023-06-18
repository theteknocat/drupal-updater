<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Traits;

use Psr\Log\LogLevel;
use Symfony\Component\Process\Process;
use TheTeknocat\DrupalUp\Commands\Command;

/**
 * Provide methods for executing external processes.
 */
trait ExecutesExternalProcesses
{
    /**
     * Determine whether or not to apply git changes.
     *
     * @return bool
     */
    protected function applyGitChanges(): bool
    {
        // A trait can't implement and interface, so we have to throw an exception
        // if the class using this trait doesn't implement the method.
        throw new \Exception('You must implement the applyGitChanges() method in the class'
            . ' that is using the ExecutesExternalProcesses trait.');
    }

    /**
     * Get the command object.
     *
     * @return \TheTeknocat\DrupalUp\Commands\Command
     */
    protected function getCommandObject(): Command
    {
        // A trait can't implement and interface, so we have to throw an exception
        // if the class using this trait doesn't implement the method.
        throw new \Exception('You must implement the getCommandObject() method in the class'
            . ' that is using the ExecutesExternalProcesses trait.');
    }

    /**
     * Run a drush command and return the process.
     *
     * Note that the --root and --yes options are automatically added.
     *
     * @param string $command
     *   The drush command to run.
     * @param array $options
     *   An array of options to pass to the command.
     * @param int $timeout
     *   The timeout for the command.
     * @param bool $streamOutput
     *   Whether to stream the output to the console. If true, the output will
     *   only be streamed to the console if using the --verbose option.
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
        return $this->runProcess($options, $timeout, $streamOutput);
    }

    /**
     * Run a composer command and return the process.
     *
     * @param string $command
     *   The composer command to run.
     * @param array $options
     *   An array of options to pass to the command.
     * @param int $timeout
     *   The timeout for the command.
     * @param bool $streamOutput
     *   Whether to stream the output to the console. If true, the output will
     *   only be streamed to the console if using the --verbose option.
     *
     * @return \Symfony\Component\Process\Process
     *   An instance of the Symfony Process object.
     */
    protected function runComposerCommand(
        string $command,
        array $options = [],
        int $timeout = 60,
        bool $streamOutput = false
    ): Process {
        $options = array_merge(
            // Start with the composer executable and the command.
            [$this->getCommandObject()->composer(), $command],
            // Add the options.
            $options,
            // Add the working directory option.
            ['--working-dir=' . $this->path]
        );
        return $this->runProcess($options, $timeout, $streamOutput);
    }

    /**
     * Run a git command and return the process.
     *
     * @param string $command
     *   The git command to run.
     * @param array $options
     *   An array of options to pass to the command.
     * @param int $timeout
     *   The timeout for the command.
     * @param bool $streamOutput
     *   Whether to stream the output to the console. If true, the output will
     *   only be streamed to the console if using the --verbose option.
     *
     * @return \Symfony\Component\Process\Process
     *   An instance of the Symfony Process object.
     */
    protected function runGitCommand(
        string $command,
        array $options = [],
        int $timeout = 60,
        bool $streamOutput = false
    ): Process {
        $options = array_merge(
            // Start with the git executable and the command.
            [$this->getCommandObject()->git(), $command],
            // Add the options.
            $options
        );
        if (!$this->applyGitChanges() && ($command == 'commit' || $command == 'push')) {
            $options[] = '--dry-run';
            $options[] = '-v';
        }
        $logOutput = !$this->applyGitChanges() && (
            $command == 'commit' || $command == 'push' ||
            ($command == 'checkout' && in_array('-b', $options)) ||
            ($command == 'branch' && in_array('-D', $options))
        );
        if ($logOutput) {
            $display_options = array_slice($options, 2);
            $this->getCommandObject()->log(
                'Executing git ' . $command . ' ' . implode(' ', $display_options),
                LogLevel::DEBUG,
                true
            );
        }
        return $this->runProcess($options, $timeout, $streamOutput, $logOutput);
    }

    /**
     * Run a process and return the process object.
     *
     * @param array $options
     *   An array of options to pass to the command.
     * @param int $timeout
     *   The timeout for the command.
     * @param bool $streamOutput
     *   Whether to stream the output to the console. If true, the output will
     *   only be streamed to the console if using the --verbose option.
     * @param bool $logOutput
     *   Whether to log the output.
     *
     * @return \Symfony\Component\Process\Process
     *   An instance of the Symfony Process object.
     */
    protected function runProcess(
        array $options,
        int $timeout = 60,
        bool $streamOutput = false,
        bool $logOutput = false
    ): Process {
        chdir($this->path);
        $process = new Process($options, null, [
            'SHELL_VERBOSITY' => '0',
        ]);
        $process->setTimeout($timeout);
        try {
            if ($streamOutput || $logOutput) {
                if ($logOutput) {
                    $this->getCommandObject()->log(
                        'Command output:',
                        LogLevel::DEBUG,
                        true
                    );
                }
                $logLines = [];
                $process->run(function ($type, $buffer) use ($streamOutput, $logOutput, &$logLines) {
                    // Trim the buffer and break on newlines:
                    $buffer_lines = explode(PHP_EOL, trim($buffer));
                    foreach ($buffer_lines as $line) {
                        $line = rtrim($line);
                        if ($streamOutput && $this->getCommandObject()->io->isVerbose()) {
                            $this->getCommandObject()->io->text(' <fg=green>></> ' . $line);
                        }
                        if ($logOutput) {
                            $logLines[] = $line;
                        }
                    }
                });
                if ($streamOutput) {
                    $this->getCommandObject()->io->newLine();
                }
                if ($logOutput) {
                    $this->getCommandObject()->log(
                        $logLines,
                        LogLevel::DEBUG,
                        true
                    );
                }
            } else {
                $process->run();
            }
        } catch (\Exception $e) {
            $this->setFailed();
            // Re-throw the same exception again.
            throw $e;
        }
        return $process;
    }
}
