<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands;

use Psr\Log\LogLevel;

/**
 * Command to display last command log entries.
 */
class Log extends Command
{
    /**
     * The log entries.
     *
     * @var array
     */
    protected array $log = [];

    /**
     * {@inheritdoc}
     */
    public function configureCommand(): Command
    {
        $this->setName('log')
            ->setDescription('Displays the log entry for the most recent command.')
            ->setHelp('Run this command to display all the log entries for the last command that was run.');
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function acceptsUriArgument(): bool
    {
        return false;
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
        $this->readLog();

        if (empty($this->log)) {
            $this->info('No log entries found.');
            return 0;
        }
        $this->io->section('Log Entries for the most recent command:');
        $this->info('Timezone for displayed dates and times: ' . date_default_timezone_get());
        $this->io->newLine();
        // Now loop through the log entries and display them in a table.
        $this->io->table(
            ['Timestamp', 'Type', 'Message'],
            $this->log
        );

        return 0;
    }

    /**
     * Read the log file into an array.
     *
     * @return void
     */
    protected function readLog(): void
    {
        $logFile = $this->logFilePath() . '/drupalup.log';
        if (file_exists($logFile)) {
            $log = file($logFile);
            // Trim the log entries.
            $log = array_map('trim', $log);
            // Remove empty entries.
            $log = array_filter($log);
            $this->parseLogEntries($log);
        }
    }

    /**
     * Parse the log entries.
     *
     * @param array $log
     *   The raw log entries from the log file.
     *
     * @return void
     */
    protected function parseLogEntries(array $log): void
    {
        // Convert each log entry into an associative array.
        $parsedLog = [];
        $logLevelColors = [
            LogLevel::EMERGENCY => 'red',
            LogLevel::ALERT     => 'red',
            LogLevel::CRITICAL  => 'red',
            LogLevel::ERROR     => 'red',
            LogLevel::WARNING   => 'yellow',
            LogLevel::NOTICE    => 'yellow',
            LogLevel::INFO      => 'blue',
            LogLevel::DEBUG     => 'cyan',
            'start'             => 'green',
            'end'               => 'green',
        ];
        foreach ($log as $entry) {
            $entry = trim($entry);
            // Parse the entry using a regular expression based on the following format:
            // [2020-12-31 23:59:59] INFO: Message
            $pattern = '/^\[(?P<timestamp>.*)\] (?P<level>[^:]+): (?P<message>.*)$/';
            preg_match($pattern, $entry, $matches);
            if (!empty($matches)) {
                // The timestamp is in the format YYYY-MM-DD HH:MM:SS.
                // We want to display the date and time separately.
                // We can use the DateTime class to parse the timestamp.
                $dateTime = new \DateTime($matches['timestamp']);
                $date = $dateTime->format('Y M d H:i:s');
                $message = json_decode($matches['message'], true) ?? $matches['message'];
                if (!is_scalar($message)) {
                    // If the message is not a scalar value, then it is an array or object.
                    // We want to display it as a json string.
                    $message = json_encode($message, JSON_PRETTY_PRINT);
                }
                $levelColor = $logLevelColors[strtolower($matches['level'])] ?? '';
                $levelText = $matches['level'];
                if (!empty($levelColor)) {
                    $levelText = '<fg=' . $levelColor . '>' . $levelText . '</>';
                }
                $parsedLog[] = [
                    'datetime'  => $date,
                    'level' => $levelText,
                    // The message may be a string or a json object, so parse that accordingly.
                    'message' => $message,
                ];
            }
        }
        // The log entries are divided into sections for each command that was run.
        // The first entry in each set has a level of 'START' that contains the command name.
        // The last entry in each set has a level of 'END' that also contains the command name.
        // We want to display the entries for the most recent command, so we need to find the
        // entries between the last 'START' and 'END' entries.
        $lastStart = 0;
        $lastEnd = 0;
        foreach ($parsedLog as $key => $entry) {
            if (strpos($entry['level'], 'START') !== false) {
                $lastStart = $key;
            }
            if (strpos($entry['level'], 'END') !== false) {
                $lastEnd = $key;
            }
        }
        $this->log = array_slice($parsedLog, $lastStart, $lastEnd - $lastStart + 1);
    }
}
