<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp\Commands\Interfaces;

interface CommandInterface
{
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
}
