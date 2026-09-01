<?php

namespace Epiclub\Engine;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class TwigRenderer extends Environment
{
    protected array $options = ['debug' => true];
    private ?Session $session = null;

    public function __construct(?Session $session = null)
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

        $this->session = $session ?? new Session();

        // ⬇️ AJOUT : fonction Twig csrf_token()
        $this->addFunction(new TwigFunction('csrf_token', function() {
            if (!$this->session->has('csrf_token')) {
                $this->session->set('csrf_token', bin2hex(random_bytes(32)));
            }
            return $this->session->get('csrf_token');
        }));

        // Variables globales
        if ($this->session->has('user')) {
            $this->addGlobal('_user', $this->session->get('user'));
        }
        $this->addGlobal('session', $this->session);
        $this->addGlobal('csrf_token', $this->session->get('csrf_token'));
    }

    /**
     * Rendu d'un template
     */
    public function render($name, array $context = []): string
    {
        if (!isset($context['_user']) && $this->session->has('user')) {
            $context['_user'] = $this->session->get('user');
        }
        if (!isset($context['session'])) {
            $context['session'] = $this->session;
        }
        if (!isset($context['csrf_token'])) {
            $context['csrf_token'] = $this->session->get('csrf_token');
        }

        return parent::render($name, $context);
    }
}