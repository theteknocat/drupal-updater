<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Traits;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Provide some message output methods.
 *
 * The parent class is responsible for setting the SymfonyStyle object.
 */
trait OutputsMessages
{
    /**
     * The SymfonyStyle object.
     *
     * @var \Symfony\Component\Console\Style\SymfonyStyle
     */
    public SymfonyStyle $io;

    /**
     * Set the SymfonyStyle object.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     *   The SymfonyStyle object.
     *
     * @return void
     */
    protected function initIoStyle(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * Output a warning message.
     *
     * @param string $message
     *   The message to output.
     * @param bool $newline
     *   Whether to add a newline to the end of the message.
     *
     * @return void
     */
    public function warning(string $message, bool $newline = true): void
    {
        $prefix = ' <bg=yellow;fg=white>[warning]</>';
        $suffix = '';
        if (!empty($message)) {
            $prefix .= ' <fg=yellow;bg=default>';
            $suffix = '</>';
        }
        if (!$newline) {
            $suffix .= '...';
        }
        $this->io->write($prefix . $message . $suffix, $newline);
    }

    /**
     * Output an info message.
     *
     * @param string $message
     *   The message to output.
     * @param bool $newline
     *   Whether to add a newline to the end of the message.
     *
     * @return void
     */
    public function info(string $message, bool $newline = true): void
    {
        $prefix = ' <bg=blue;fg=white>[info]</>';
        $suffix = '';
        if (!empty($message)) {
            $prefix .= ' ';
        }
        if (!$newline) {
            $suffix .= '...';
        }
        $this->io->write($prefix . $message . $suffix, $newline);
    }

    /**
     * Output a success message.
     *
     * @param string $message
     *   The message to output.
     * @param bool $newline
     *   Whether to add a newline to the end of the message.
     *
     * @return void
     */
    public function success(string $message, bool $newline = true): void
    {
        $prefix = ' <bg=green;fg=white>[success]</>';
        $suffix = '';
        if (!empty($message)) {
            $prefix .= ' <fg=green;bg=default>';
            $suffix = '</>';
        }
        if (!$newline) {
            $suffix .= '...';
        }
        $this->io->write($prefix . $message . $suffix, $newline);
    }

    /**
     * Successful done indicator.
     *
     * @return void
     */
    public function doneSuccess(): void
    {
        $this->io->write(' <fg=green;bg=default>Done!</>', true);
    }

    /**
     * Error indicator.
     *
     * @return void
     */
    public function doneError(): void
    {
        $this->io->write(' <fg=red;bg=default>Error!</>', true);
    }
}
