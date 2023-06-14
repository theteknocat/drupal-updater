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
     *
     * @return void
     */
    public function warning(string $message): void
    {
        $this->io->writeln('<bg=yellow;fg=black>[warning]</> <fg=yellow;bg=default>' . $message . '</>');
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
        $this->io->writeln('<bg=green;fg=black>[success]</> <fg=green;bg=default>' . $message . '</>');
    }
}
