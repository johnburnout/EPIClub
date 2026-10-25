<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Exception\InstallationException;
use Epiclub\Process\InstallationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles d'installation partagées par l'assistant web et bin/installer.php.
 * Sans base : la partie MySQL est couverte par
 * tests/Integration/Process/InstallationIntegrationTest.php.
 */
final class InstallationServiceTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/epiclub-installation-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dossier) ?: [], ['.', '..']) as $f) {
            unlink($this->dossier . '/' . $f);
        }
        rmdir($this->dossier);
    }

    private function service(): InstallationService
    {
        return new InstallationService(dirname(__DIR__, 3));
    }

    // ------------------------------------------------------------------
    // Prérequis
    // ------------------------------------------------------------------

    public function testPrerequisSatisfaits(): void
    {
        $r = $this->service()->verifierPrerequis(
            ['php_version' => '>=8.4', 'php_extensions' => ['gd'], 'upload_limits' => ['post_max_size' => '12M']],
            '8.4.1',
            static fn () => true,
            static fn () => '64M',
        );
        self::assertSame(['erreurs' => [], 'avertissements' => []], $r);
    }

    public function testPrerequisBloquantsEtAvertissements(): void
    {
        $r = $this->service()->verifierPrerequis(
            ['php_version' => '>=8.4', 'php_extensions' => ['gd', 'pdo_mysql'], 'upload_limits' => ['upload_max_filesize' => '12M']],
            '8.3.9',
            static fn (string $ext) => $ext === 'gd',
            static fn () => '2M',
        );
        self::assertCount(2, $r['erreurs']);
        self::assertStringContainsString('PHP 8.4 ou supérieur', $r['erreurs'][0]);
        self::assertStringContainsString('sudo apt install php-pdo-mysql', $r['erreurs'][1]);
        self::assertCount(1, $r['avertissements']);
        self::assertStringContainsString('« upload_max_filesize » est à 2M', $r['avertissements'][0]);
    }

    public function testLesPrerequisDuProjetSontLisibles(): void
    {
        $r = $this->service()->verifierPrerequis();
        self::assertArrayHasKey('erreurs', $r);
        self::assertArrayHasKey('avertissements', $r);
    }

    public function testTailleIni(): void
    {
        self::assertSame(0, InstallationService::tailleIni(''));
        self::assertSame(512 * 1024, InstallationService::tailleIni('512K'));
        self::assertSame(12 * 1024 * 1024, InstallationService::tailleIni('12m'));
        self::assertSame(1024 ** 3, InstallationService::tailleIni('1G'));
        self::assertSame(2048, InstallationService::tailleIni('2048'));
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function testValiderSite(): void
    {
        self::assertSame([], InstallationService::validerSite('EPIClub', 'https://epi.club.fr'));
        self::assertSame([
            'Le nom du site est requis.',
            "L'URL racine doit être une URL valide (ex: https://mon-site.com).",
        ], InstallationService::validerSite(' ', 'pas une url'));
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function bases(): array
    {
        return [
            'valide'            => ['localhost:3306', 'club_x', 'clubx', 0],
            'hôte invalide'     => ['local host', 'club_x', 'clubx', 1],
            'base avec tiret'   => ['localhost', 'club-x', 'clubx', 1],
            'base trop longue'  => ['localhost', str_repeat('a', 65), 'clubx', 1],
            'injection'         => ['localhost', 'x`; DROP DATABASE y; --', 'clubx', 1],
            'tout vide'         => ['', '', '', 3],
        ];
    }

    #[DataProvider('bases')]
    public function testValiderBase(string $hote, string $base, string $utilisateur, int $nbErreurs): void
    {
        self::assertCount($nbErreurs, InstallationService::validerBase($hote, $base, $utilisateur));
    }

    public function testValiderAdmin(): void
    {
        $admin = ['nom' => 'Dupont', 'prenom' => 'Jean', 'username' => 'jdupont', 'email' => 'j@club.fr', 'password' => 'douze-car-ok'];
        self::assertSame([], InstallationService::validerAdmin($admin));
        self::assertSame([], InstallationService::validerAdmin($admin, 'douze-car-ok'));
        self::assertSame(['Les deux mots de passe ne correspondent pas.'], InstallationService::validerAdmin($admin, 'autre'));

        $erreurs = InstallationService::validerAdmin(['username' => str_repeat('u', 33), 'email' => 'x', 'password' => 'court']);
        self::assertContains('Le nom est requis.', $erreurs);
        self::assertContains('Le nom d\'utilisateur est trop long (32 caractères max).', $erreurs);
        self::assertContains('Une adresse email valide est requise.', $erreurs);
        self::assertContains('Le mot de passe doit contenir au moins 12 caractères.', $erreurs);
    }

    public function testValiderClub(): void
    {
        self::assertSame([], InstallationService::validerClub(['nom' => 'Club', 'email' => 'c@club.fr', 'activite' => 'Spéléologie']));
        self::assertSame([], InstallationService::validerClub(['nom' => 'Club', 'email' => 'c@club.fr', 'activite' => '']));
        $erreurs = InstallationService::validerClub(['nom' => '', 'email' => 'x', 'activite' => 'Pétanque']);
        self::assertCount(3, $erreurs);
        self::assertStringContainsString('Activité inconnue : Pétanque', $erreurs[2]);
    }

    public function testSmtpFacultatif(): void
    {
        self::assertSame([], InstallationService::validerSmtp('', '', ''));
        self::assertSame(
            ['Le port SMTP doit être un nombre.', 'Le nom d\'utilisateur SMTP est requis.'],
            InstallationService::validerSmtp('smtp.club.fr', 'abc', '')
        );
        self::assertSame(['MAILER_DSN' => '', 'MAILER_ENABLED' => 'false'], InstallationService::parametresSmtp('', '', '', ''));
        self::assertSame([
            'MAILER_DSN'     => 'smtp://moi%40club.fr:p%40ss%3A1@smtp.club.fr:587',
            'MAILER_FROM'    => 'admin@smtp.club.fr',
            'MAILER_NAME'    => 'EPIClub',
            'MAILER_ENABLED' => 'true',
        ], InstallationService::parametresSmtp('smtp.club.fr', '587', 'moi@club.fr', 'p@ss:1'));
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function testFichierConfigCreeEn0600EtCompleteSansPerte(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        InstallationService::ecrireConfig($fichier, ['DB_NAME' => "club'x", 'SITE_NAME' => 'Club X']);
        InstallationService::ecrireConfig($fichier, ['DB_PASS' => 'sécret$']);

        self::assertSame('0600', substr(sprintf('%o', fileperms($fichier)), -4));
        self::assertSame(
            ['DB_NAME' => "club'x", 'SITE_NAME' => 'Club X', 'DB_PASS' => 'sécret$'],
            InstallationService::lireConfig($fichier)
        );
    }

    public function testLireConfigAbsenteOuInvalide(): void
    {
        self::assertSame([], InstallationService::lireConfig($this->dossier . '/absent.php'));
        file_put_contents($this->dossier . '/invalide.php', '<?php return "x";');
        self::assertSame([], InstallationService::lireConfig($this->dossier . '/invalide.php'));
    }

    public function testCleDeChiffrementJamaisRegeneree(): void
    {
        self::assertSame([], InstallationService::cleDeChiffrement(['SECRET_KEY' => 'existante']));

        $cle = InstallationService::cleDeChiffrement([]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cle['SECRET_KEY']);
        self::assertSame('AES-256-CBC', $cle['CIPHER_METHOD']);
    }

    public function testMarquerTerminee(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        InstallationService::marquerTerminee($fichier);
        self::assertNotEmpty(InstallationService::lireConfig($fichier)['SETUP_COMPLETE']);
        self::assertArrayNotHasKey('SETUP_EN_COURS', InstallationService::lireConfig($fichier), 'Ajouté seulement s\'il existait');
    }

    public function testMarquerTermineeLeveLAssistantEnCours(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        InstallationService::ecrireConfig($fichier, ['SETUP_EN_COURS' => '1']);
        InstallationService::marquerTerminee($fichier);

        $config = InstallationService::lireConfig($fichier);
        self::assertNotEmpty($config['SETUP_COMPLETE']);
        self::assertEmpty($config['SETUP_EN_COURS']);
    }

    public function testCategoriesProposeesSelonActivite(): void
    {
        $speleo = $this->service()->categoriesProposees('Spéléologie');
        $defaut = $this->service()->categoriesProposees('Inconnue');
        self::assertNotEmpty($speleo);
        self::assertSame($this->service()->categoriesProposees('Escalade'), $defaut);
    }

    // ------------------------------------------------------------------
    // installer() : refus avant toute modification
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function parametres(): array
    {
        return [
            'site_name' => 'EPIClub', 'root_url' => 'https://epi.club.fr',
            'db_host' => '127.0.0.1', 'db_name' => 'club_x', 'db_user' => 'clubx', 'db_pass' => '',
            'admin' => ['nom' => 'Dupont', 'prenom' => 'Jean', 'username' => 'jdupont', 'email' => 'j@club.fr', 'password' => 'douze-car-ok'],
            'club' => ['nom' => 'Club X', 'email' => 'c@club.fr', 'activite' => 'Escalade'],
        ];
    }

    public function testInstallerRefuseDesParametresInvalidesAvecLaRubrique(): void
    {
        $p = $this->parametres();
        $p['db_user'] = '';
        $p['admin']['username'] = '';
        $fichier = $this->dossier . '/.env.local.php';

        try {
            $this->service()->installer($p, $fichier);
            self::fail('InstallationException attendue');
        } catch (InstallationException $e) {
            self::assertSame(InstallationException::USAGE, $e->getCode());
            self::assertSame([
                "Base : Le nom d'utilisateur est requis.",
                "Super-administrateur : Le nom d'utilisateur est requis.",
            ], $e->details());
        }
        self::assertFileDoesNotExist($fichier, 'Rien ne doit être écrit');
    }

    public function testInstallerRefuseUneInstanceDejaInstallee(): void
    {
        $fichier = $this->dossier . '/.env.local.php';
        InstallationService::marquerTerminee($fichier);

        $this->expectException(InstallationException::class);
        $this->expectExceptionCode(InstallationException::DEJA_INSTALLE);
        $this->service()->installer($this->parametres(), $fichier);
    }

    public function testInstallerGenereUnMotDePasseSiAbsent(): void
    {
        // La génération a lieu avant la validation : un mot de passe absent
        // n'est donc pas une erreur, contrairement au reste.
        $p = $this->parametres();
        $p['admin']['password'] = null;
        $p['club']['email'] = '';

        try {
            $this->service()->installer($p, $this->dossier . '/.env.local.php');
            self::fail('InstallationException attendue');
        } catch (InstallationException $e) {
            self::assertSame(['Club : L\'adresse email du club est requise et doit être valide.'], $e->details());
        }
    }
}
