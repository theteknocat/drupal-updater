<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Traits;

/**
 * Provide some message output methods.
 *
 * The parent class must have an $io property set to the
 * SymfonyStyle object.
 */
trait MessagesTrait
{
    /**
     * The SymfonyStyle object.
     *
     * @var \Symfony\Component\Console\Style\SymfonyStyle
     */
    public $io;

    /**
     * Output a warning message.
     *
     * @param string $message
     *   The message to output.
     *
     * @return void
     */
    public function warning(string $message): void
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
    public function info(string $message): void
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
    public function success(string $message): void
    {
        $this->io->writeln('<bg=green;fg=black>[success]</> ' . $message);
    }

    /**
     * Output a debug message.
     *
     * @param string|array $message
     *   The message to output.
     *
     * @return void
     */
    public function debugOutput(string|array $message): void
    {
        $this->io->block($message, 'DEBUG', 'bg=black;fg=yellow', ' ', true);
    }
}
