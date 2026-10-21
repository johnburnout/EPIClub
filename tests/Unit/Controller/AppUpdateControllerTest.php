<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUpdateController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [VAGUE 10] Tests Unit d'AppUpdateController.
 *
 * Le GitHubReleaseProviderInterface est mocké, ce qui permet de tester
 * index() sans dépendre du réseau.
 *
 * ⚠️ Session mockée sans user['id'] → évite updateLastActivity() → PDO.
 */
final class AppUpdateControllerTest extends TestCase
{
    public function testIndexRendersUpdateAvailableWhenNewerReleaseExists(): void
    {
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v0.99.0',
                'zip_url' => 'https://example.com/zip',
                'body'    => 'Release notes for v0.99.0',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('v0.99.0', $content);
    }

    public function testIndexAcceptsReleaseWithoutBody(): void
    {
        // ⚠️ Fix #45 : une Release avec body vide ne doit PAS
        // provoquer le message « Impossible de contacter GitHub. »
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v0.99.0',
                'zip_url' => 'https://example.com/zip',
                'body'    => '',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('v0.99.0', $content);
        self::assertStringNotContainsString('Impossible de contacter GitHub', $content);
    }

    public function testIndexRendersErrorWhenProviderReturnsNull(): void
    {
        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn(null);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('Impossible de contacter GitHub', $content);
    }

    public function testIndexReturns200WhenSameVersion(): void
    {
        $currentVersion = trim(file_get_contents(__DIR__ . '/../../../version.txt'));

        $provider = $this->makeProviderMock();
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'tag'     => 'v' . $currentVersion,
                'zip_url' => 'https://example.com/zip',
                'body'    => 'Same version',
            ]);

        $controller = $this->makeControllerWith($provider);

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // [SÉCURITÉ — audit C1] Rejet des archives malveillantes
    // ==================================================================

    public function testPerformRejectsMaliciousZip(): void
    {
        // Zip contenant une entrée « zip slip » : ../public/shell.php
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('../public/shell.php', '<?php echo "pwned";');
        $zip->addFromString('EPIClub-1.2.3/version.txt', 'v9.9.9');
        $zip->close();
        $maliciousZip = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag'     => 'v9.9.9',
            'zip_url' => 'https://example.com/malicious.zip',
            'body'    => 'Malicious release',
        ]);
        $provider->method('downloadUrl')->willReturn($maliciousZip);

        $controller = $this->makeControllerWith($provider);

        $request = Request::create('/admin/update/perform', 'POST', [
            'csrf_token' => 'token_test',
        ]);
        $response = $controller->perform($request);

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString('rejeté', $content);
        // L'extraction ne doit pas avoir eu lieu.
        self::assertDirectoryDoesNotExist(
            __DIR__ . '/../../../var/tmp/update/extracted'
        );
    }

    /**
     * [v1.2.0-beta.2] Incident v1.2.0-beta.1 : une archive de sources
     * (sans vendor/) ne doit jamais être installée — le classmap
     * « authoritative » en place ignorerait les nouvelles classes.
     */
    public function testPerformRefuseUneArchiveSansVendorSansRienModifier(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('EPIClub-9.9.9/version.txt', '9.9.9');
        $zip->addFromString('EPIClub-9.9.9/app/Nouvelle.php', '<?php');
        $zip->close();
        $contenu = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag' => 'v9.9.9', 'zip_url' => 'https://api.github.com/repos/o/r/zipball/v9.9.9',
            'body' => '', 'bundle' => false, 'sha256_url' => null,
        ]);
        $provider->method('downloadUrl')->willReturn($contenu);
        $versionAvant = file_get_contents(__DIR__ . '/../../../version.txt');

        $response = $this->makeControllerWith($provider)->perform(
            Request::create('/admin/update/perform', 'POST', ['csrf_token' => 'token_test'])
        );

        self::assertStringContainsString('pas encore disponible', (string) $response->getContent());
        self::assertFileDoesNotExist(__DIR__ . '/../../../app/Nouvelle.php');
        self::assertSame($versionAvant, file_get_contents(__DIR__ . '/../../../version.txt'));
        self::assertDirectoryDoesNotExist(__DIR__ . '/../../../var/tmp/update/extracted');
    }

    // ==================================================================
    // [v1.4.0] Sauvegarde SQL avant migrations
    // ==================================================================

    public function testPasDeSauvegardeSansMigrationEnAttente(): void
    {
        $dir = $this->dossierMigrations(['001_initial', '002_x']);
        $controller = $this->makeControllerWith($this->makeProviderMock());
        $controller->migrationsAppliqueesOverride = ['001_initial', '002_x'];
        $controller->databaseBackupOverride = new \Epiclub\Engine\DatabaseBackup(
            new \Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper(), $dir . '/backups'
        );

        self::assertNull($controller->sauvegarderAvantMigrationsForTest($dir . '/migrations'));
        self::assertDirectoryDoesNotExist($dir . '/backups');
    }

    public function testSauvegardeQuandUneMigrationEstEnAttente(): void
    {
        $dir = $this->dossierMigrations(['001_initial', '002_x', '015_nouvelle']);
        $controller = $this->makeControllerWith($this->makeProviderMock());
        $controller->migrationsAppliqueesOverride = ['001_initial', '002_x'];
        $controller->databaseBackupOverride = new \Epiclub\Engine\DatabaseBackup(
            new \Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper(), $dir . '/backups'
        );

        $fichier = $controller->sauvegarderAvantMigrationsForTest($dir . '/migrations');

        self::assertNotNull($fichier);
        self::assertFileExists($fichier);
        self::assertMatchesRegularExpression('#/backups/db_\d{4}-\d{2}-\d{2}_\d{6}\.sql$#', $fichier);
    }

    public function testPerformAnnuleToutSiLaSauvegardeEchoue(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('EPIClub-9.9.9/version.txt', '9.9.9');
        $zip->addFromString('EPIClub-9.9.9/vendor/autoload.php', '<?php');
        $zip->addFromString('EPIClub-9.9.9/migrations/999_future.sql', 'SELECT 1;');
        $zip->addFromString('EPIClub-9.9.9/app/ZzNeDoitPasEtreCopie.php', '<?php');
        $zip->close();
        $contenu = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag' => 'v9.9.9', 'zip_url' => 'https://api.github.com/repos/o/r/zipball/v9.9.9',
            'body' => '', 'bundle' => false, 'sha256_url' => null,
        ]);
        $provider->method('downloadUrl')->willReturn($contenu);

        $enPanne = new class implements \Epiclub\Engine\DatabaseDumperInterface {
            public function exporter(string $fichierSql): array
            {
                throw new \RuntimeException('disque plein');
            }

            public function importer(string $fichierSql): void
            {
            }

            public function migrationsAppliquees(): array
            {
                return [];
            }
        };
        $controller = $this->makeControllerWith($provider);
        $controller->migrationsAppliqueesOverride = [];
        $controller->databaseBackupOverride = new \Epiclub\Engine\DatabaseBackup($enPanne, sys_get_temp_dir() . '/epiclub_bk_' . bin2hex(random_bytes(4)));
        $versionAvant = file_get_contents(__DIR__ . '/../../../version.txt');

        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $html = (string) $controller->perform(
                Request::create('/admin/update/perform', 'POST', ['csrf_token' => 'token_test'])
            )->getContent();
        } finally {
            ini_set('error_log', (string) $errorLog);
        }

        self::assertStringContainsString('mise à jour annulée', html_entity_decode($html, ENT_QUOTES));
        self::assertFileDoesNotExist(__DIR__ . '/../../../app/ZzNeDoitPasEtreCopie.php');
        self::assertSame($versionAvant, file_get_contents(__DIR__ . '/../../../version.txt'));
        self::assertDirectoryDoesNotExist(__DIR__ . '/../../../var/tmp/update/extracted');
    }

    /** @param string[] $versions */
    private function dossierMigrations(array $versions): string
    {
        $dir = sys_get_temp_dir() . '/epiclub_maj_' . bin2hex(random_bytes(4));
        mkdir($dir . '/migrations', 0777, true);
        foreach ($versions as $v) {
            file_put_contents($dir . '/migrations/' . $v . '.sql', 'SELECT 1;');
        }
        return $dir;
    }

    public function testIndexNeProposePasUneVersionSansArchiveComplete(): void
    {
        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag' => 'v99.0.0', 'zip_url' => 'https://api.github.com/repos/o/r/zipball/v99.0.0',
            'body' => '', 'bundle' => false, 'sha256_url' => null,
        ]);

        $html = (string) $this->makeControllerWith($provider)->index(new Request())->getContent();

        self::assertStringContainsString('en cours de préparation', $html);
        self::assertStringNotContainsString('/admin/update/perform', $html);
    }

    public function testPerformRejectsZipWithAbsolutePath(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('/etc/cron.d/backdoor', 'cron content');
        $zip->close();
        $maliciousZip = file_get_contents($zipPath);
        unlink($zipPath);

        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag'     => 'v9.9.9',
            'zip_url' => 'https://example.com/malicious.zip',
            'body'    => 'Malicious release',
        ]);
        $provider->method('downloadUrl')->willReturn($maliciousZip);

        $controller = $this->makeControllerWith($provider);

        $request = Request::create('/admin/update/perform', 'POST', [
            'csrf_token' => 'token_test',
        ]);
        $response = $controller->perform($request);

        self::assertStringContainsString('rejeté', $response->getContent());
    }

    // ==================================================================
    // [audit 2026-10-19 F2] Copie de public/ lors de la mise à jour
    //
    // Reproduction : avant le correctif, 'public' figurait dans
    // EXCLUDED_DIRS — index.php, .htaccess et assets d'une nouvelle
    // release n'étaient jamais déployés.
    // ==================================================================

    public function testCopyFilesMetAJourLeDocrootPublic(): void
    {
        [$source, $cible] = $this->arborescencesDeTest();

        $this->makeControllerWith($this->makeProviderMock())->copyFilesForTest($source, $cible);

        self::assertStringEqualsFile($cible . '/public/index.php', '<?php // v2');
        self::assertStringEqualsFile($cible . '/public/.htaccess', 'Require all granted # v2');
        self::assertStringEqualsFile($cible . '/public/assets/css/nouveau.css', 'body{}');
        self::assertStringEqualsFile($cible . '/public/images/.htaccess', 'Require all denied # v2');
        self::assertStringEqualsFile($cible . '/app/Fichier.php', '<?php // v2');

        $this->supprimer(dirname($source));
    }

    public function testCopyFilesNeTouchePasAuxDonneesUtilisateur(): void
    {
        [$source, $cible] = $this->arborescencesDeTest();

        $this->makeControllerWith($this->makeProviderMock())->copyFilesForTest($source, $cible);

        // Photo existante : non écrasée
        self::assertStringEqualsFile($cible . '/public/images/equipements/photo.webp', 'PHOTO DU CLUB');
        // Images livrées par la release (photos de démo suivies par git) : non ajoutées
        self::assertFileDoesNotExist($cible . '/public/images/equipements/demo.webp');
        self::assertFileDoesNotExist($cible . '/public/images/Harnais-demo.webp');
        self::assertFileDoesNotExist($cible . '/public/images/qrcodes/equipement_1.png');
        // Exclusions historiques toujours actives
        self::assertStringEqualsFile($cible . '/.env.local.php', '<?php return ["SECRET" => "prod"];');
        self::assertStringEqualsFile($cible . '/version.txt', 'v1.0.0');
        self::assertFileDoesNotExist($cible . '/var/tmp/x');
        self::assertFileDoesNotExist($cible . '/vendor/autoload.php');
        self::assertFileDoesNotExist($cible . '/setup/install.php');

        $this->supprimer(dirname($source));
    }

    #[DataProvider('cheminsProvider')]
    public function testIsExcluded(string $chemin, bool $exclu): void
    {
        self::assertSame($exclu, $this->makeControllerWith($this->makeProviderMock())->isExcludedForTest($chemin));
    }

    public static function cheminsProvider(): array
    {
        return [
            'index.php'                    => ['public/index.php', false],
            'public/.htaccess'             => ['public/.htaccess', false],
            'assets'                       => ['public/assets/js/html5-qrcode.min.js', false],
            'images/.htaccess'             => ['public/images/.htaccess', false],
            'sous-dossier .htaccess'       => ['public/images/equipements/.htaccess', false],
            'dossier images'               => ['public/images', true],
            'photo équipement'             => ['public/images/equipements/a.webp', true],
            'image catégorie'              => ['public/images/Harnais-1.webp', true],
            'qr code'                      => ['public/images/qrcodes/equipement_1.png', true],
            'faux préfixe images'          => ['public/imagesX/a.php', false],
            'env'                          => ['.env.local.php', true],
            'vendor'                       => ['vendor/autoload.php', true],
            'setup'                        => ['setup/install.php', true],
            'app'                          => ['app/bootstrap.php', false],
        ];
    }

    /**
     * Crée une release extraite (source) et une installation existante (cible).
     *
     * @return array{0: string, 1: string}
     */
    private function arborescencesDeTest(): array
    {
        $base = sys_get_temp_dir() . '/epiclub_maj_' . bin2hex(random_bytes(4));
        $source = $base . '/release';
        $cible = $base . '/installation';

        $release = [
            'public/index.php'                       => '<?php // v2',
            'public/.htaccess'                       => 'Require all granted # v2',
            'public/assets/css/nouveau.css'          => 'body{}',
            'public/images/.htaccess'                => 'Require all denied # v2',
            'public/images/Harnais-demo.webp'        => 'DEMO',
            'public/images/equipements/photo.webp'   => 'PHOTO DE DEMO',
            'public/images/equipements/demo.webp'    => 'DEMO',
            'public/images/qrcodes/equipement_1.png' => 'QR',
            'app/Fichier.php'                        => '<?php // v2',
            '.env.local.php'                         => '<?php return ["SECRET" => "release"];',
            'version.txt'                            => 'v2.0.0',
            'var/tmp/x'                              => 'x',
            'vendor/autoload.php'                    => '<?php',
            'setup/install.php'                      => '<?php',
        ];
        $installation = [
            'public/index.php'                     => '<?php // v1',
            'public/.htaccess'                     => 'Require all granted # v1',
            'public/images/equipements/photo.webp' => 'PHOTO DU CLUB',
            'app/Fichier.php'                      => '<?php // v1',
            '.env.local.php'                       => '<?php return ["SECRET" => "prod"];',
            'version.txt'                          => 'v1.0.0',
        ];

        foreach ([$source => $release, $cible => $installation] as $racine => $fichiers) {
            foreach ($fichiers as $chemin => $contenu) {
                @mkdir(dirname($racine . '/' . $chemin), 0700, true);
                file_put_contents($racine . '/' . $chemin, $contenu);
            }
        }

        return [$source, $cible];
    }

    private function supprimer(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    // ==================================================================
    // [audit 2026-10-19 F1] Archive complète : somme de contrôle + vendor/
    // ==================================================================

    public function testPerformRejetteUneArchiveCompleteDontLaSommeDiffere(): void
    {
        $zip = $this->zipValide();
        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag' => 'v9.9.9', 'zip_url' => 'https://github.com/x/y/releases/download/v9.9.9/epiclub-v9.9.9.zip',
            'body' => '', 'bundle' => true,
            'sha256_url' => 'https://github.com/x/y/releases/download/v9.9.9/epiclub-v9.9.9.zip.sha256',
        ]);
        $provider->method('downloadUrl')->willReturnCallback(
            fn (string $url) => str_ends_with($url, '.sha256')
                ? str_repeat('0', 64) . '  epiclub-v9.9.9.zip'
                : $zip
        );

        $response = $this->makeControllerWith($provider)->perform(
            Request::create('/admin/update/perform', 'POST', ['csrf_token' => 'token_test'])
        );

        self::assertStringContainsString('somme de contrôle', $response->getContent());
        self::assertDirectoryDoesNotExist(__DIR__ . '/../../../var/tmp/update/extracted');
    }

    public function testPerformRejetteUneArchiveCompleteSansSomme(): void
    {
        $zip = $this->zipValide();
        $provider = $this->makeProviderMock();
        $provider->method('getLatestRelease')->willReturn([
            'tag' => 'v9.9.9', 'zip_url' => 'https://github.com/x/y/releases/download/v9.9.9/epiclub-v9.9.9.zip',
            'body' => '', 'bundle' => true, 'sha256_url' => null,
        ]);
        $provider->method('downloadUrl')->willReturn($zip);

        $response = $this->makeControllerWith($provider)->perform(
            Request::create('/admin/update/perform', 'POST', ['csrf_token' => 'token_test'])
        );

        self::assertStringContainsString('somme de contrôle', $response->getContent());
        self::assertDirectoryDoesNotExist(__DIR__ . '/../../../var/tmp/update/extracted');
    }

    public function testVerifierSommeDeControleAccepteLaBonneSomme(): void
    {
        $zip = $this->zipValide();
        $provider = $this->makeProviderMock();
        $provider->method('downloadUrl')->willReturn(hash('sha256', $zip) . "  epiclub-v9.9.9.zip\n");

        $this->makeControllerWith($provider)->verifierSommeDeControleForTest(
            ['bundle' => true, 'sha256_url' => 'https://github.com/x.sha256'],
            $zip
        );
        $this->addToAssertionCount(1); // aucune exception
    }

    public function testVerifierSommeDeControleToléréePourUnZipballDeSources(): void
    {
        $provider = $this->makeProviderMock();
        $provider->expects(self::never())->method('downloadUrl');

        $this->makeControllerWith($provider)->verifierSommeDeControleForTest(
            ['bundle' => false, 'sha256_url' => null],
            'contenu'
        );
        $this->addToAssertionCount(1);
    }

    public function testRemplacerVendorBasculeDUnBloc(): void
    {
        $base = sys_get_temp_dir() . '/epiclub_vendor_' . bin2hex(random_bytes(4));
        mkdir($base . '/release/vendor/composer', 0700, true);
        file_put_contents($base . '/release/vendor/autoload.php', '<?php // v2');
        file_put_contents($base . '/release/vendor/composer/nouveau.php', '<?php');
        mkdir($base . '/installation/vendor/obsolete', 0700, true);
        file_put_contents($base . '/installation/vendor/autoload.php', '<?php // v1');
        file_put_contents($base . '/installation/vendor/obsolete/vieux.php', '<?php');

        $this->makeControllerWith($this->makeProviderMock())
            ->remplacerVendorForTest($base . '/release/vendor', $base . '/installation/vendor');

        self::assertStringEqualsFile($base . '/installation/vendor/autoload.php', '<?php // v2');
        self::assertFileExists($base . '/installation/vendor/composer/nouveau.php');
        // Aucun fichier de l'ancienne version ne subsiste
        self::assertFileDoesNotExist($base . '/installation/vendor/obsolete/vieux.php');
        self::assertSame(['vendor'], array_values(array_diff(scandir($base . '/installation'), ['.', '..'])));

        $this->supprimer($base);
    }

    public function testRemplacerVendorRefuseUnVendorIncompletEtNeTouchePasALExistant(): void
    {
        $base = sys_get_temp_dir() . '/epiclub_vendor_' . bin2hex(random_bytes(4));
        mkdir($base . '/release/vendor', 0700, true);          // pas d'autoload.php
        mkdir($base . '/installation/vendor', 0700, true);
        file_put_contents($base . '/installation/vendor/autoload.php', '<?php // v1');

        try {
            $this->makeControllerWith($this->makeProviderMock())
                ->remplacerVendorForTest($base . '/release/vendor', $base . '/installation/vendor');
            self::fail('Un vendor/ incomplet doit être refusé.');
        } catch (\RuntimeException) {
        }

        self::assertStringEqualsFile($base . '/installation/vendor/autoload.php', '<?php // v1');
        $this->supprimer($base);
    }

    private function zipValide(): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'zip_test_');
        $zip = new \ZipArchive();
        $zip->open($chemin, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('epiclub-v9.9.9/version.txt', 'v9.9.9');
        $zip->addFromString('epiclub-v9.9.9/vendor/autoload.php', '<?php');
        $zip->close();
        $contenu = (string) file_get_contents($chemin);
        unlink($chemin);
        return $contenu;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(
        GitHubReleaseProviderInterface $provider,
    ): TestableAppUpdateController {
        return new TestableAppUpdateController(
            $this->makeSession(),
            $provider,
        );
    }

    private function makeProviderMock(): GitHubReleaseProviderInterface
    {
        return (new MockBuilder($this, GitHubReleaseProviderInterface::class))
            ->getMock();
    }

    private function makeSession(): Session
    {
        $session = (new MockBuilder($this, Session::class))->getMock();
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'csrf_token' ? 'token_test' : null
        );
        $session->method('has')->willReturn(false);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}