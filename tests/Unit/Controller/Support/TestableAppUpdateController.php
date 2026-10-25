<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUpdateController;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\VerrouInstance;
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

    /** [audit 2026-10-19 F2] Accès de test à la copie de la release. */
    public function copyFilesForTest(string $source, string $target): void
    {
        $this->copyFiles($source, $target);
    }

    /** [audit 2026-10-19 F1] */
    public function verifierSommeDeControleForTest(array $release, string $zip): void
    {
        $this->verifierSommeDeControle($release, $zip);
    }

    public function remplacerVendorForTest(string $nouveau, string $cible): void
    {
        $this->remplacerVendor($nouveau, $cible);
    }

    public function isExcludedForTest(string $path): bool
    {
        return $this->isExcluded($path);
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

    /** [v1.4.0] Migrations « appliquées » simulées (null = parent). */
    public ?array $migrationsAppliqueesOverride = null;

    /** [v1.4.0] Sauvegarde injectée (null = parent). */
    public ?DatabaseBackup $databaseBackupOverride = null;

    /** Verrou injecté (null = verrou dans un dossier temporaire). */
    public ?VerrouInstance $verrouOverride = null;

    protected function verrouInstance(): VerrouInstance
    {
        return $this->verrouOverride
            ?? new VerrouInstance(sys_get_temp_dir() . '/epiclub-test-verrou-' . getmypid() . '.lock');
    }

    protected function migrationsAppliquees(): array
    {
        return $this->migrationsAppliqueesOverride ?? parent::migrationsAppliquees();
    }

    protected function databaseBackup(): DatabaseBackup
    {
        return $this->databaseBackupOverride ?? parent::databaseBackup();
    }

    public function sauvegarderAvantMigrationsForTest(string $dossier): ?string
    {
        return $this->sauvegarderAvantMigrations($dossier);
    }

    /**
     * [audit 2026-10-19 S5/Q2] Pas de synchronisation de session en test
     * Unit : l'implémentation parente ouvre une vraie connexion MySQL.
     */
    protected function updateLastActivity()
    {
    }
}
