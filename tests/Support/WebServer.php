<?php

declare(strict_types=1);

namespace Epiclub\Tests\Support;

/**
 * Démarre automatiquement le serveur de dev (php -S) requis par les tests
 * d'intégration et de sécurité, puis l'arrête en fin de processus PHPUnit.
 *
 * Comportement :
 *  - Idempotent : plusieurs appels ne démarrent qu'un seul serveur.
 *  - Réutilisation : si un serveur répond déjà sur BASE_URL (serveur de dev
 *    lancé manuellement, CI…), il est réutilisé et jamais arrêté par les tests.
 *  - Hors localhost : si BASE_URL pointe vers un hôte distant, aucun serveur
 *    n'est démarré (laissé à la responsabilité de l'environnement).
 *  - Arrêt propre : le serveur démarré ici est tué via un shutdown handler
 *    enregistré une seule fois par processus.
 *
 * Usage, dans setUp() des tests HTTP :
 *    WebServer::ensureRunning($this->baseUrl);
 */
final class WebServer
{
    private static bool $checked = false;

    /** @var resource|null Processus démarré par ce processus PHPUnit */
    private static $process = null;

    /** @var array<int,resource> */
    private static array $pipes = [];

    /**
     * Garantit qu'un serveur HTTP répond sur $baseUrl.
     *
     * Ne retourne rien si un serveur répond déjà ou si un serveur local
     * a pu être démarré. Lève une RuntimeException explicite sinon.
     */
    public static function ensureRunning(string $baseUrl): void
    {
        if (self::$checked) {
            return;
        }

        $parts = parse_url($baseUrl);
        $host = $parts['host'] ?? 'localhost';
        $port = (int) ($parts['port'] ?? 80);

        if (!self::isLocalHost($host)) {
            // Hôte distant : l'environnement de test est géré à l'extérieur.
            self::$checked = true;
            return;
        }

        if (self::isListening($host, $port)) {
            // Serveur existant (dev manuel, CI) : on le réutilise et on
            // ne s'en approprie pas (pas d'arrêt à la fin des tests).
            self::$checked = true;
            return;
        }

        self::start($host, $port);
        self::waitUntilReady($host, $port);
        self::$checked = true;
    }

    // ==================================================================
    // Démarrage / arrêt
    // ==================================================================

    private static function start(string $host, int $port): void
    {
        $root = dirname(__DIR__, 2);
        $router = $root . '/public/router.php';

        if (!is_file($router)) {
            throw new \RuntimeException(
                "Router introuvable : $router. WebServer doit être utilisé depuis la racine du projet."
            );
        }

        $cmd = [
            PHP_BINARY,
            '-S',
            $host . ':' . $port,
            '-t',
            $root . '/public',
            $router,
        ];

        // stdout/stderr vers des pipes : relus uniquement en cas d'échec
        // de démarrage pour alimenter le message d'erreur.
        $pipes = [];
        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            null,
            ['suppress_errors' => false, 'bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException(
                "Impossible de démarrer le serveur de test (php -S $host:$port)."
            );
        }

        fclose($pipes[0]);

        self::$process = $process;
        self::$pipes = [$pipes[1], $pipes[2]];
        self::registerShutdown();
    }

    private static function waitUntilReady(string $host, int $port): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            if (!self::processAlive()) {
                throw new \RuntimeException(
                    "Le serveur de test (php -S $host:$port) s'est arrêté au démarrage.\n"
                    . "Sortie : " . self::readPipes()
                );
            }

            if (self::isListening($host, $port)) {
                return;
            }

            usleep(100_000);
        }

        throw new \RuntimeException(
            "Le serveur de test (php -S $host:$port) ne répond pas après 10 s.\n"
            . "Sortie : " . self::readPipes()
        );
    }

    private static function registerShutdown(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(static function (): void {
            // Ne tuer que ce que les tests ont démarré : un serveur
            // pré-existant (dev manuel) doit survivre à la suite de tests.
            if (is_resource(self::$process)) {
                proc_terminate(self::$process, 9);
                proc_close(self::$process);
                self::$process = null;
            }
            foreach (self::$pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::$pipes = [];
        });
    }

    // ==================================================================
    // Sondes
    // ==================================================================

    private static function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private static function isListening(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private static function processAlive(): bool
    {
        if (!is_resource(self::$process)) {
            return false;
        }
        $status = proc_get_status(self::$process);
        return $status['running'] ?? false;
    }

    private static function readPipes(): string
    {
        $output = '';
        foreach (self::$pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
                $output .= (string) stream_get_contents($pipe);
            }
        }
        $output = trim($output);
        return $output !== '' ? $output : '(aucune sortie)';
    }
}
