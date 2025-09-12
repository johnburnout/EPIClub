<?php

namespace Epiclub\Engine;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigRenderer extends Environment
{
    protected array $options = ['debug' => true];

    public function __construct()
    {
        if (file_exists(__DIR__ . '/../../config/twig.php')) {
            $config = require(__DIR__ . '/../../config/twig.php');
            $this->options = array_merge($this->options, $config['options']);
        }

        $loader = new FilesystemLoader(__DIR__ . '/../../templates');

        parent::__construct($loader, $this->options);

        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'dev') {
            $this->addExtension(new \Twig\Extension\DebugExtension());
        }
    }
}
