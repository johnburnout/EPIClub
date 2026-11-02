<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Exception\MiseAJourException;
use Epiclub\Process\MiseAJourService;
use PHPUnit\Framework\TestCase;

/**
 * Déroulé de MiseAJourService sur une fausse installation (dossier
 * temporaire) et une fausse release, base SQLite qui imite les requêtes
 * MySQL de MigrationManager.
 */
final class MiseAJourServiceTest extends TestCase
{
    private string $racine;

    /** @var string[] */
    private array $etapes = [];

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/epiclub_maj_' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/migrations', 0777, true);
        mkdir($this->racine . '/vendor', 0777, true);
        mkdir($this->racine . '/public/images/equipements', 0777, true);
        file_put_contents($this->racine . '/version.txt', '2.3.0');
        file_put_contents($this->racine . '/vendor/autoload.php', '<?php // ancien');
        file_put_contents($this->racine . '/migrations/001_initial.sql', 'CREATE TABLE t1 (id INTEGER);');
        file_put_contents($this->racine . '/public/images/equipements/photo.jpg', 'photo du club');
        file_put_contents($this->racine . '/.env.local.php', '<?php return [];');
        $this->etapes = [];
    }

    protected function tearDown(): void
    {
        MiseAJourService::deleteDirectory($this->racine);
    }

    /**
     * @param array<string, string> $fichiers chemin dans l'archive => contenu
     */
    private function zip(array $fichiers): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'zip_maj_');
        $zip = new \ZipArchive();
        $zip->open($chemin, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($fichiers as $nom => $contenu) {
            $zip->addFromString($nom, $contenu);
        }
        $zip->close();
        $contenu = (string) file_get_contents($chemin);
        unlink($chemin);
        return $contenu;
    }

    private function releaseComplete(string $tag = 'v2.4.0'): string
    {
        return $this->zip([
            "epiclub-$tag/version.txt"                      => ltrim($tag, 'v'),
            "epiclub-$tag/app/Nouveau.php"                  => '<?php // nouveau',
            "epiclub-$tag/vendor/autoload.php"              => '<?php // nouveau vendor',
            "epiclub-$tag/migrations/001_initial.sql"       => 'CREATE TABLE t1 (id INTEGER);',
            "epiclub-$tag/migrations/020_nouvelle.sql"      => 'CREATE TABLE t20 (id INTEGER);',
            "epiclub-$tag/public/images/equipements/x.jpg"  => 'ne doit pas écraser les données',
            "epiclub-$tag/.env.local.php"                   => '<?php // ne doit pas être copié',
        ]);
    }

    private function fournisseur(?string $zip, string $tag = 'v2.4.0', ?string $sha = null): GitHubReleaseProviderInterface
    {
        $sha ??= $zip !== null ? hash('sha256', $zip) . '  epiclub-' . $tag . '.zip' : '';
        return new class($zip, $tag, $sha) implements GitHubReleaseProviderInterface {
            public int $telechargements = 0;

            public function __construct(private ?string $zip, private string $tag, private string $sha)
            {
            }

            public function getLatestRelease(): ?array
            {
                return $this->zip === null ? null : [
                    'tag' => $this->tag, 'zip_url' => 'https://exemple/zip', 'body' => '',
                    'bundle' => true, 'sha256_url' => 'https://exemple/sha',
                ];
            }

            public function downloadUrl(string $url): string
            {
                $this->telechargements++;
                return $url === 'https://exemple/sha' ? $this->sha : (string) $this->zip;
            }
        };
    }

    private function pdo(): \PDO
    {
        return new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                parent::exec("CREATE TABLE t1 (id INTEGER)");
            }

            public function exec(string $statement): int|false
            {
                return parent::exec((string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $statement));
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query), $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
            {
                if ($query === 'SHOW TABLES') {
                    $query = "SELECT name FROM sqlite_master WHERE type = 'table'";
                }
                return parent::query($query);
            }
        };
    }

    private function service(GitHubReleaseProviderInterface $releases, ?\Closure $sauvegarde = null, ?\PDO $pdo = null): MiseAJourService
    {
        $pdo ??= $this->pdo();
        return new MiseAJourService(
            $releases,
            $this->racine,
            $this->racine . '/var/tmp/update',
            static fn (): \PDO => $pdo,
            $sauvegarde ?? static fn (string $dossier): ?string => null,
        );
    }

    private function etape(): \Closure
    {
        return function (string $ligne): void {
            $this->etapes[] = $ligne;
        };
    }

    public function testMiseAJourComplete(): void
    {
        $pdo = $this->pdo();
        $sauvegardes = [];
        $resultat = $this->service(
            $this->fournisseur($this->releaseComplete()),
            static function (string $dossier) use (&$sauvegardes): string {
                $sauvegardes[] = $dossier;
                return '/tmp/db_test.sql';
            },
            $pdo,
        )->mettreAJour($this->etape());

        self::assertSame('2.3.0', $resultat['ancienne']);
        self::assertSame('v2.4.0', $resultat['version']);
        self::assertFalse($resultat['a_jour']);
        self::assertSame(['020_nouvelle'], $resultat['migrations']);
        self::assertSame('/tmp/db_test.sql', $resultat['sauvegarde']);
        self::assertCount(1, $sauvegardes);

        self::assertSame('v2.4.0', file_get_contents($this->racine . '/version.txt'));
        self::assertFileExists($this->racine . '/app/Nouveau.php');
        self::assertSame('<?php // nouveau vendor', file_get_contents($this->racine . '/vendor/autoload.php'));
        self::assertSame('photo du club', file_get_contents($this->racine . '/public/images/equipements/photo.jpg'));
        self::assertFileDoesNotExist($this->racine . '/public/images/equipements/x.jpg');
        self::assertSame('<?php return [];', file_get_contents($this->racine . '/.env.local.php'));
        self::assertDirectoryDoesNotExist($this->racine . '/var/tmp/update/extracted');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '020_nouvelle'")->fetchColumn());
        self::assertContains('Migration appliquée : 020_nouvelle', $this->etapes);
    }

    public function testDejaAJourNeTelechargeRien(): void
    {
        $releases = $this->fournisseur($this->releaseComplete('v2.3.0'), 'v2.3.0');
        $resultat = $this->service($releases)->mettreAJour($this->etape());

        self::assertTrue($resultat['a_jour']);
        self::assertSame(0, $releases->telechargements);
        self::assertSame('2.3.0', file_get_contents($this->racine . '/version.txt'));
    }

    public function testForcerReinstalleLaMemeVersion(): void
    {
        $resultat = $this->service($this->fournisseur($this->releaseComplete('v2.3.0'), 'v2.3.0'))->mettreAJour($this->etape(), true);
        self::assertFalse($resultat['a_jour']);
        self::assertFileExists($this->racine . '/app/Nouveau.php');
    }

    public function testVerifier(): void
    {
        $etat = $this->service($this->fournisseur($this->releaseComplete()))->verifier();
        self::assertSame(['actuelle' => '2.3.0', 'disponible' => 'v2.4.0', 'mise_a_jour_disponible' => true, 'archive_prete' => true], $etat);

        $hors = $this->service($this->fournisseur(null))->verifier();
        self::assertNull($hors['disponible']);
        self::assertFalse($hors['mise_a_jour_disponible']);
    }

    public function testGitHubInjoignable(): void
    {
        $this->expectExceptionCode(MiseAJourException::PREREQUIS);
        $this->service($this->fournisseur(null))->mettreAJour($this->etape());
    }

    public function testSommeDeControleDifferenteRienNestModifie(): void
    {
        try {
            $this->service($this->fournisseur($this->releaseComplete(), 'v2.4.0', str_repeat('0', 64)))->mettreAJour($this->etape());
            self::fail('Exception attendue');
        } catch (MiseAJourException $e) {
            self::assertSame(MiseAJourException::ARCHIVE_REJETEE, $e->getCode());
            self::assertFalse($e->modifie());
        }
        self::assertSame('2.3.0', file_get_contents($this->racine . '/version.txt'));
        self::assertFileDoesNotExist($this->racine . '/app/Nouveau.php');
    }

    public function testSauvegardeImpossibleRienNestModifie(): void
    {
        try {
            $this->service(
                $this->fournisseur($this->releaseComplete()),
                static fn (string $d): ?string => throw new \RuntimeException('disque plein'),
            )->mettreAJour($this->etape());
            self::fail('Exception attendue');
        } catch (MiseAJourException $e) {
            self::assertSame(MiseAJourException::SAUVEGARDE_IMPOSSIBLE, $e->getCode());
        }
        self::assertSame('2.3.0', file_get_contents($this->racine . '/version.txt'));
        self::assertFileDoesNotExist($this->racine . '/app/Nouveau.php');
        self::assertDirectoryDoesNotExist($this->racine . '/var/tmp/update/extracted');
    }

    public function testMigrationEnEchecInterrompue(): void
    {
        $zip = $this->zip([
            'epiclub-v2.4.0/vendor/autoload.php'          => '<?php',
            'epiclub-v2.4.0/app/Nouveau.php'              => '<?php',
            'epiclub-v2.4.0/migrations/020_cassee.sql'    => 'CECI N EST PAS DU SQL;',
        ]);
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $this->service($this->fournisseur($zip))->mettreAJour($this->etape());
            self::fail('Exception attendue');
        } catch (MiseAJourException $e) {
            self::assertSame(MiseAJourException::INTERROMPUE, $e->getCode());
            self::assertTrue($e->modifie());
        } finally {
            ini_set('error_log', (string) $errorLog);
        }
        // Les fichiers sont copiés, la version n'est pas changée.
        self::assertFileExists($this->racine . '/app/Nouveau.php');
        self::assertSame('2.3.0', file_get_contents($this->racine . '/version.txt'));
    }

    public function testSauvegardeSeulementSiMigrationEnAttente(): void
    {
        $dir = $this->racine . '/livrees';
        mkdir($dir);
        file_put_contents($dir . '/001_initial.sql', 'SELECT 1;');
        $backup = new \Epiclub\Engine\DatabaseBackup(new \Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper(), $this->racine . '/backups');

        self::assertNull(MiseAJourService::sauvegarderSiMigrationsEnAttente($dir, static fn (): array => ['001_initial'], $backup, null));
        file_put_contents($dir . '/020_nouvelle.sql', 'SELECT 1;');
        self::assertNotNull(MiseAJourService::sauvegarderSiMigrationsEnAttente($dir, static fn (): array => ['001_initial'], $backup, null));
    }
}
