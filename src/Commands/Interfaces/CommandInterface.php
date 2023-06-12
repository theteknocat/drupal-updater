<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Interfaces;

interface CommandInterface
{
    /**
     * Configure the specific command.
     *
     * This will be called by the parent class configure method,
     * which will provide any default arguments and options.
     *
     * This must provide the command name, description, and any
     * arguments and options specific to the command.
     *
     * @see \TheTeknocat\DrupalUp\Commands\Command::configure()
     *
     * @return \TheTeknocat\DrupalUp\Commands\Command
     *   An instance of this object.
     */
    public function configureCommand(): \TheTeknocat\DrupalUp\Commands\Command;

    /**
     * Whether or not the command uses the default options.
     *
     * This includes the --debug option and uri argument.
     *
     * @return bool
     */
    public function usesDefaultOptions(): bool;

    /**
     * Console announcement prior to running the command.
     *
     * @return void
     */
    public function announce(): void;

    /**
     * Set the options from the command input.
     *
     * @return void
     */
    public function setOptions(): void;

    /**
     * Run the actual command.
     *
     * This will be called by the parent class's run method.
     *
     * @return int
     *   The exit code.
     */
    public function runCommand(): int;
}
