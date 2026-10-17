<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUpdateController;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;

/**
 * [REFACTOR VAGUE 10] Sous-classe de test d'AppUpdateController.
 *
 * Permet d'injecter un GitHubReleaseProviderInterface mocké pour
 * tester index() et perform() sans dépendre du réseau.
 *
 * [#101] Accepte aussi un ConfigProvider injecté (fixture fichier
 * temporaire) et capture les paramètres de connexion PDO pour prouver
 * que le DSN/credentials viennent de la config — sans jamais ouvrir
 * de connexion réelle.
 */
final class TestableAppUpdateController extends AppUpdateController
{
    /** @var array{dsn: string, user: mixed, pass: mixed}|null */
    public ?array $capturedPdoArgs = null;

    public function __construct(
        Session $session,
        private readonly GitHubReleaseProviderInterface $provider,
        private ?ConfigProvider $configProviderMock = null,
    ) {
        parent::__construct($session);
    }

    protected function githubReleaseProvider(): GitHubReleaseProviderInterface
    {
        return $this->provider;
    }

    protected function configProvider(): ConfigProvider
    {
        return $this->configProviderMock ?? parent::configProvider();
    }

    public function databaseDsnForTest(): string
    {
        return $this->databaseDsn();
    }

    public function databasePdoForTest(): \PDO
    {
        return $this->databasePdo();
    }

    protected function databasePdo(): \PDO
    {
        $config = $this->configProvider();
        $this->capturedPdoArgs = [
            'dsn'  => $this->databaseDsn(),
            'user' => $config->get('DB_USER'),
            'pass' => $config->get('DB_PASS'),
        ];
        // Court-circuite la connexion réelle : le test ne vérifie que
        // la construction des paramètres, pas MySQL.
        throw new \PDOException('Connexion court-circuitée (test #101)');
    }
}
