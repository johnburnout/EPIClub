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

        // 🔧 NOUVEAU : Version de l'application (depuis version.txt)
        $versionFile = __DIR__ . '/../../version.txt';
        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
        } else {
            $version = '0.0.0';
        }
        $this->addGlobal('app_version', $version);
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
        if (!isset($context['app_version'])) {
            // Fallback si la variable globale n'est pas passée
            $versionFile = __DIR__ . '/../../version.txt';
            if (file_exists($versionFile)) {
                $context['app_version'] = trim(file_get_contents($versionFile));
            } else {
                $context['app_version'] = '0.0.0';
            }
        }

        return parent::render($name, $context);
    }
}