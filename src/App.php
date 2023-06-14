<?php declare(strict_types = 1);

namespace TheTeknocat\DrupalUp;

use Symfony\Component\Console\Application as BaseApplication;

/**
 * The base app object class.
 */
class App extends BaseApplication
{
    const NAME = 'Drupal 8+ Updater';
    const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->add(new Commands\Update());
        $this->add(new Commands\Log());
        $this->add(new Commands\Rollback());

        $this->setDefaultCommand('update');
    }
}
