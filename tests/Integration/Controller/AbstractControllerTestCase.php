<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

use Epiclub\Tests\Integration\Support\DatabaseFixture;
use Epiclub\Tests\Integration\Support\HttpTestClient;
use PHPUnit\Framework\TestCase;

/**
 * Classe de base pour les tests d'intégration des contrôleurs.
 */
abstract class AbstractControllerTestCase extends TestCase
{
    protected HttpTestClient $client;
    protected DatabaseFixture $fixture;

    protected function setUp(): void
    {
        $baseUrl = getenv('BASE_URL') ?: 'http://localhost:8000';
        $this->client = new HttpTestClient($baseUrl);

        // Initialiser la fixture EN PREMIER
        $this->fixture = new DatabaseFixture($this->createPdo());
        $this->fixture->cleanupTestData();

        // Vérifier que le serveur HTTP est accessible
        try {
            $response = $this->client->get('/');
            if ($response['status'] === 0) {
                self::markTestSkipped('Serveur HTTP non accessible.');
                return;
            }
        } catch (\RuntimeException $e) {
            self::markTestSkipped(
                "Serveur HTTP non accessible sur $baseUrl. "
                . 'Lancez "php -S localhost:8000 -t public" avant les tests. '
                . 'Erreur : ' . $e->getMessage()
            );
            return;
        }

        // Login admin (username 'admin', mot de passe 'adminadmin')
        $this->client->loginAsAdmin('admin', 'adminadmin');
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanupTestData();
        }
    }

    private function createPdo(): \PDO
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $config = file_exists($envFile) ? (include $envFile) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return new \PDO(
            'mysql:host=' . ($config['DB_HOST'] ?? '127.0.0.1')
            . ';dbname=' . ($config['DB_NAME'] ?? 'epiclub')
            . ';charset=utf8mb4',
            $config['DB_USER'] ?? 'root',
            $config['DB_PASS'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    // ==================================================================
    // Assertions HTTP
    // ==================================================================

    /**
     * Assertion : la réponse est une redirection vers le chemin attendu.
     */
    protected function assertRedirectsTo(string $expectedPath, array $response): void
    {
        self::assertContains(
            $response['status'],
            [301, 302, 303, 307, 308],
            sprintf(
                "Statut attendu 3xx, reçu %d. Body: %s",
                $response['status'],
                substr(strip_tags($response['body']), 0, 200)
            )
        );

        $location = $response['headers']['location'] ?? '';
        self::assertSame(
            $expectedPath,
            $location,
            "Redirection attendue vers '$expectedPath', reçu '$location'"
        );
    }

    /**
     * Assertion : la page suivante contient un message (flash).
     */
    protected function assertFlashContains(string $expectedSubstring, array $redirectResponse): void
    {
        $redirectPath = $redirectResponse['headers']['location'] ?? '/';
        $nextPage = $this->client->get($redirectPath);

        // Décoder les entités HTML
        $body = html_entity_decode($nextPage['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertStringContainsString(
            $expectedSubstring,
            $body,
            sprintf(
                "Le message '%s' n'apparaît pas dans la page %s. Body: %s",
                $expectedSubstring,
                $redirectPath,
                substr(strip_tags($body), 0, 500)
            )
        );
    }

    /**
     * Assertion : la réponse contient une erreur de formulaire.
     */
    protected function assertPageContainsError(string $errorMessage, array $response): void
    {
        self::assertSame(
            200,
            $response['status'],
            "La page devrait être rendue (200) avec l'erreur, reçu : {$response['status']}"
        );

        // Décoder les entités HTML (&#039; → ')
        $body = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertStringContainsString(
            $errorMessage,
            $body,
            sprintf(
                "L'erreur '%s' n'apparaît pas dans la page. Body: %s",
                $errorMessage,
                substr(strip_tags($body), 0, 500)
            )
        );
    }
}