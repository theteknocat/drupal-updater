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
     * Whether or not the command accepts the URI argument.
     *
     * @return bool
     */
    public function acceptsUriArgument(): bool;

    /**
     * Whether or not the command requires the URI argument.
     *
     * Ignored if acceptsUriArgument() returns false. Otherwise,
     * if this returns true and a uri was not provided via as
     * an argument the command will ask the user to choose one
     * from the list of sites in the druplaup.sites.yml file.
     *
     * @return bool
     */
    public function requiresUriArgument(): bool;

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
