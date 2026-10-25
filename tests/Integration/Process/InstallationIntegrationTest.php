<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Process;

use Epiclub\Engine\ConfigFile;
use Epiclub\Exception\InstallationException;
use Epiclub\Process\InstallationService;
use PHPUnit\Framework\TestCase;

/**
 * Installation complète sur un vrai MySQL : InstallationService::installer(),
 * puis bin/installer.php et bin/sauvegarder.php lancés comme le ferait la
 * console d'hébergement (processus séparé, codes de sortie, --json).
 *
 * ⚠️ La base de développement n'est jamais touchée : tout se passe dans
 * une base jetable `<DB_NAME>_test_installation`, supprimée à la fin
 * (droit CREATE DATABASE requis — sinon le test est ignoré). La
 * configuration est écrite dans un fichier temporaire (--fichier-config).
 */
final class InstallationIntegrationTest extends TestCase
{
    private ?\PDO $serveur = null;
    private string $base = '';
    private string $dossier = '';
    /** @var array<string, mixed> */
    private array $config = [];

    protected function setUp(): void
    {
        $this->config = include ConfigFile::defaultPath();
        $this->base = (string) ($this->config['DB_NAME'] ?? 'epiclub') . '_test_installation';
        $this->dossier = sys_get_temp_dir() . '/epiclub-installation-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);

        try {
            $this->serveur = InstallationService::connexion(
                (string) $this->config['DB_HOST'],
                (string) $this->config['DB_USER'],
                (string) $this->config['DB_PASS']
            );
            $this->serveur->exec('DROP DATABASE IF EXISTS `' . $this->base . '`');
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->serveur?->exec('DROP DATABASE IF EXISTS `' . $this->base . '`');
        $this->supprimer($this->dossier);
    }

    private function racine(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private function parametres(?string $motDePasse = 'mot-de-passe-solide'): array
    {
        return [
            'site_name' => 'EPIClub Test',
            'root_url'  => 'https://epi.club-test.fr',
            'db_host'   => (string) $this->config['DB_HOST'],
            'db_name'   => $this->base,
            'db_user'   => (string) $this->config['DB_USER'],
            'db_pass'   => (string) $this->config['DB_PASS'],
            'admin'     => ['nom' => 'Dupont', 'prenom' => 'Jean', 'username' => 'jdupont', 'email' => 'j@club-test.fr', 'password' => $motDePasse],
            'club'      => ['nom' => 'Club Test', 'email' => 'club@club-test.fr', 'activite' => 'Spéléologie', 'phone' => '0102030405'],
        ];
    }

    private function pdoBase(): \PDO
    {
        return InstallationService::connexion(
            (string) $this->config['DB_HOST'],
            (string) $this->config['DB_USER'],
            (string) $this->config['DB_PASS'],
            $this->base
        );
    }

    public function testInstallationCompletePuisRefusDUneSeconde(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        $etapes = [];
        $resultat = (new InstallationService($this->racine()))->installer(
            $this->parametres(),
            $fichier,
            function (string $ligne) use (&$etapes): void {
                $etapes[] = $ligne;
            }
        );

        self::assertContains('018_tickets', $resultat['migrations']);
        self::assertNull($resultat['mot_de_passe_genere']);
        self::assertGreaterThan(0, $resultat['categories']);
        self::assertContains('Installation terminée.', $etapes);

        $pdo = $this->pdoBase();
        $admin = $pdo->query('SELECT username, role, est_protege, password FROM utilisateur')->fetchAll();
        self::assertCount(1, $admin);
        self::assertSame('ROLE_SUPER_ADMIN', $admin[0]['role']);
        self::assertSame(1, (int) $admin[0]['est_protege']);
        self::assertTrue(password_verify('mot-de-passe-solide', $admin[0]['password']));

        $club = $pdo->query('SELECT nom, activite, phone FROM club')->fetch();
        self::assertSame(['nom' => 'Club Test', 'activite' => 'Spéléologie', 'phone' => '0102030405'], $club);
        self::assertSame(
            count((new InstallationService($this->racine()))->categoriesProposees('Spéléologie')),
            (int) $pdo->query('SELECT COUNT(*) FROM categorie')->fetchColumn()
        );

        $config = InstallationService::lireConfig($fichier);
        self::assertSame($this->base, $config['DB_NAME']);
        self::assertSame('https://epi.club-test.fr', $config['ROOT_URL']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $config['SECRET_KEY']);
        self::assertSame('false', $config['MAILER_ENABLED']);
        self::assertNotEmpty($config['SETUP_COMPLETE']);
        self::assertSame('0600', substr(sprintf('%o', fileperms($fichier)), -4));

        // Même base, configuration neuve (sans SETUP_COMPLETE) : la base
        // non vide est refusée, rien n'est écrasé.
        try {
            (new InstallationService($this->racine()))->installer($this->parametres(), $this->dossier . '/autre.php');
            self::fail('InstallationException attendue');
        } catch (InstallationException $e) {
            self::assertSame(InstallationException::DEJA_INSTALLE, $e->getCode());
            self::assertStringContainsString('contient déjà', $e->getMessage());
        }
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn());
    }

    public function testUneBaseExistanteEtVideEstUtiliseeSansEtreRecreee(): void
    {
        // Cas Virtualmin : la base est créée par l'hébergeur, l'utilisateur
        // MySQL du domaine n'a pas forcément le droit CREATE.
        $this->serveur->exec('CREATE DATABASE `' . $this->base . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $etapes = [];
        (new InstallationService($this->racine()))->installerBase(
            $this->serveur,
            $this->base,
            function (string $ligne) use (&$etapes): void {
                $etapes[] = $ligne;
            }
        );

        self::assertNotContains('Base « ' . $this->base . ' » créée.', $etapes);
        self::assertContains('Migration appliquée : 001_initial', $etapes);
    }

    public function testCommandesInstallerPuisSauvegarder(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        [$code, $sortie] = $this->lancer('bin/installer.php', [
            '--json', '--admin-mot-de-passe-aleatoire',
            '--site-name=EPIClub Test', '--root-url=https://epi.club-test.fr',
            '--db-host=' . $this->config['DB_HOST'], '--db-name=' . $this->base, '--db-user=' . $this->config['DB_USER'],
            '--admin-username=jdupont', '--admin-email=j@club-test.fr', '--admin-nom=Dupont', '--admin-prenom=Jean',
            '--club-nom=Club Test', '--club-email=club@club-test.fr', '--activite=Escalade', '--sans-categories',
            '--fichier-config=' . $fichier,
        ], ['EPICLUB_DB_PASS' => (string) $this->config['DB_PASS']]);

        self::assertSame(0, $code, $sortie);
        $json = json_decode($sortie, true);
        self::assertTrue($json['succes']);
        self::assertSame(0, $json['categories']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $json['mot_de_passe_genere']);
        $hash = $this->pdoBase()->query('SELECT password FROM utilisateur')->fetchColumn();
        self::assertTrue(password_verify($json['mot_de_passe_genere'], (string) $hash));

        // Seconde installation : refus, code 9.
        [$code] = $this->lancer('bin/installer.php', [
            '--quiet', '--admin-mot-de-passe-aleatoire',
            '--site-name=X', '--root-url=https://x.fr', '--db-name=' . $this->base, '--db-user=x',
            '--admin-username=x', '--admin-email=x@x.fr', '--admin-nom=X', '--admin-prenom=X',
            '--club-nom=X', '--club-email=x@x.fr', '--fichier-config=' . $fichier,
        ]);
        self::assertSame(9, $code);

        // Sauvegarde planifiée de l'instance installée.
        $sauvegardes = $this->dossier . '/backups';
        [$code, $sortie] = $this->lancer('bin/sauvegarder.php', [
            '--json', '--garder=1', '--fichier-config=' . $fichier, '--dossier=' . $sauvegardes,
        ]);
        self::assertSame(0, $code, $sortie);
        $json = json_decode($sortie, true);
        self::assertStringEndsWith('-planifiee.zip', $json['fichier']);
        self::assertFileExists($json['fichier']);

        [$code, $sortie] = $this->lancer('bin/sauvegarder.php', [
            '--json', '--sql-seul', '--fichier-config=' . $fichier, '--dossier=' . $sauvegardes,
        ]);
        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('CREATE TABLE', (string) file_get_contents(json_decode($sortie, true)['fichier']));

        // Instance non installée : code 3.
        [$code] = $this->lancer('bin/sauvegarder.php', ['--quiet', '--fichier-config=' . $this->dossier . '/absent.php']);
        self::assertSame(3, $code);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     * @return array{int, string} Code de sortie, sortie standard.
     */
    private function lancer(string $script, array $arguments, array $env = []): array
    {
        $processus = proc_open(
            array_merge([PHP_BINARY, $this->racine() . '/' . $script], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubes,
            $this->racine(),
            array_merge(getenv(), $env)
        );
        self::assertIsResource($processus);
        $sortie = (string) stream_get_contents($tubes[1]);
        $erreurs = (string) stream_get_contents($tubes[2]);
        fclose($tubes[1]);
        fclose($tubes[2]);
        $code = proc_close($processus);

        return [$code, $sortie . $erreurs];
    }

    private function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterateur as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }
}
