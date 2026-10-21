<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\SauvegardeService;
use Epiclub\Exception\SauvegardeException;
use Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Sauvegarde / restauration : archive, vérifications et garde-fous.
 * La base est simulée (FakeDatabaseDumper) ; l'export SQL réel est
 * couvert par la suite Integration (MySqlDatabaseDumperIntegrationTest).
 */
final class SauvegardeServiceTest extends TestCase
{
    private string $racine;
    private string $images;
    private string $uploads;
    private string $sauvegardes;
    private FakeDatabaseDumper $base;
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        // Les échecs simulés sont journalisés : on garde la sortie propre.
        $this->errorLog = ini_set('error_log', '/dev/null');
        $this->racine = sys_get_temp_dir() . '/epiclub-sauvegarde-' . bin2hex(random_bytes(4));
        $this->images = $this->racine . '/public/images';
        $this->uploads = $this->racine . '/_storage/uploads';
        $this->sauvegardes = $this->racine . '/var/backups';

        $this->ecrire($this->images . '/.htaccess', 'Require all denied');
        $this->ecrire($this->images . '/.DS_Store', 'mac');
        $this->ecrire($this->images . '/Harnais-1.webp', 'image-categorie');
        $this->ecrire($this->images . '/equipements/photo-1.webp', 'photo-1');
        $this->ecrire($this->images . '/club/logo.png', 'logo');
        $this->ecrire($this->images . '/notes.txt', 'pas une image');
        $this->ecrire($this->uploads . '/factures/facture_1.pdf', '%PDF-1');

        $this->base = new FakeDatabaseDumper();
        $this->base->tables = [
            'club_equipement' => [['id' => 1, 'libelle' => 'Baudrier é']],
            'utilisateur'     => [['id' => 1, 'pseudo' => 'admin']],
        ];
        $this->base->migrations = ['001_initial', '002_x'];
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
        $this->supprimer($this->racine);
    }

    // ------------------------------------------------------------------
    // Création
    // ------------------------------------------------------------------

    public function testCreerProduitUneArchiveCompleteAvecManifeste(): void
    {
        $chemin = $this->service()->creer();

        self::assertFileExists($chemin);
        self::assertMatchesRegularExpression('/^epiclub-sauvegarde-\d{8}-\d{6}\.zip$/', basename($chemin));

        $zip = new ZipArchive();
        self::assertTrue($zip->open($chemin));
        $noms = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $noms[] = $zip->getNameIndex($i);
        }
        sort($noms);
        self::assertSame([
            'base/epiclub.sql',
            'fichiers/images/Harnais-1.webp',
            'fichiers/images/club/logo.png',
            'fichiers/images/equipements/photo-1.webp',
            'fichiers/uploads/factures/facture_1.pdf',
            'manifest.json',
        ], $noms, 'ni .htaccess, ni .DS_Store, ni type non autorisé');

        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        self::assertSame('EPIClub', $m['application']);
        self::assertSame('1.2.0', $m['version']);
        self::assertSame(['001_initial', '002_x'], $m['migrations']);
        self::assertSame(['club_equipement' => 1, 'utilisateur' => 1], $m['tables']);
        self::assertSame(SauvegardeService::empreinte('cle'), $m['empreinte_cle']);
        self::assertSame(hash('sha256', 'photo-1'), $m['fichiers']['fichiers/images/equipements/photo-1.webp']);
        self::assertNotSame('cle', $m['empreinte_cle'], 'la clé elle-même n\'est jamais écrite');
    }

    public function testLeDossierDesSauvegardesEstProtegeEtSansTemporaires(): void
    {
        $this->service()->creer();

        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->sauvegardes . '/.htaccess'));
        self::assertSame([], glob($this->sauvegardes . '/.tmp-*'));
        self::assertSame([], glob($this->sauvegardes . '/*.part'));
    }

    // ------------------------------------------------------------------
    // Analyse
    // ------------------------------------------------------------------

    public function testAnalyserAccepteUneArchiveFraiche(): void
    {
        $service = $this->service();
        $m = $service->analyser($service->creer());

        self::assertSame(4, $m['nb_fichiers']);
        self::assertSame('1.2.0', $m['version']);
    }

    public function testAnalyserAccepteUneVersionAnterieure(): void
    {
        $chemin = $this->service(version: '1.1.0')->creer();
        $m = $this->service(version: '1.2.0')->analyser($chemin);
        self::assertSame('1.1.0', $m['version']);
    }

    public function testAnalyserRefuseUneVersionPlusRecente(): void
    {
        $chemin = $this->service(version: '1.3.0')->creer();
        $this->attendreRefus('version plus récente');
        $this->service(version: 'v1.2.0')->analyser($chemin);
    }

    public function testAnalyserRefuseDesMigrationsInconnues(): void
    {
        $this->base->migrations[] = '099_futur';
        $chemin = $this->service()->creer();
        $this->attendreRefus('099_futur');
        $this->service()->analyser($chemin);
    }

    public function testAnalyserRefuseUneAutreCleDeChiffrement(): void
    {
        $chemin = $this->service(cle: 'ancienne')->creer();
        $this->attendreRefus('SECRET_KEY');
        $this->service(cle: 'nouvelle')->analyser($chemin);
    }

    public function testAnalyserRefuseUnFichierAltere(): void
    {
        $chemin = $this->service()->creer();
        $zip = new ZipArchive();
        $zip->open($chemin);
        $zip->addFromString('fichiers/images/equipements/photo-1.webp', 'falsifiée');
        $zip->close();

        $this->attendreRefus('somme de contrôle');
        $this->service()->analyser($chemin);
    }

    public function testAnalyserRefuseUneBaseAlteree(): void
    {
        $chemin = $this->service()->creer();
        $zip = new ZipArchive();
        $zip->open($chemin);
        $zip->addFromString('base/epiclub.sql', "-- fake\n{}");
        $zip->close();

        $this->attendreRefus('base/epiclub.sql');
        $this->service()->analyser($chemin);
    }

    public function testAnalyserRefuseUnFichierNonDeclare(): void
    {
        $chemin = $this->service()->creer();
        $zip = new ZipArchive();
        $zip->open($chemin);
        $zip->addFromString('fichiers/images/intrus.png', 'x');
        $zip->close();

        $this->attendreRefus('non déclarés');
        $this->service()->analyser($chemin);
    }

    public function testAnalyserRefuseUnFichierManquant(): void
    {
        $chemin = $this->service()->creer();
        $zip = new ZipArchive();
        $zip->open($chemin);
        $zip->deleteName('fichiers/images/club/logo.png');
        $zip->close();

        $this->attendreRefus('manquant');
        $this->service()->analyser($chemin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function entreesInterdites(): iterable
    {
        yield 'remontée de dossier' => ['fichiers/images/../../../app/x.png'];
        yield 'chemin absolu'       => ['/etc/x.png'];
        yield 'script PHP'          => ['fichiers/images/shell.php'];
        yield 'htaccess'            => ['fichiers/images/.htaccess'];
        yield 'dossier inconnu'     => ['fichiers/autre/x.png'];
        yield 'racine inconnue'     => ['app/Controller/X.php'];
        yield 'antislash'           => ['fichiers\\images\\x.png'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entreesInterdites')]
    public function testAnalyserRefuseLesEntreesInterdites(string $entree): void
    {
        $chemin = $this->service()->creer();
        $zip = new ZipArchive();
        $zip->open($chemin);
        $zip->addFromString($entree, 'x');
        $zip->close();

        $this->expectException(SauvegardeException::class);
        $this->service()->analyser($chemin);
    }

    public function testAnalyserRefuseUnFichierQuiNestPasUnZip(): void
    {
        $this->ecrire($this->sauvegardes . '/faux.zip', 'pas un zip');
        $this->attendreRefus('archive ZIP');
        $this->service()->analyser($this->sauvegardes . '/faux.zip');
    }

    public function testAnalyserRefuseUnZipSansManifeste(): void
    {
        @mkdir($this->sauvegardes, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->sauvegardes . '/autre.zip', ZipArchive::CREATE);
        $zip->addFromString('base/epiclub.sql', 'x');
        $zip->close();

        $this->attendreRefus('manifeste');
        $this->service()->analyser($this->sauvegardes . '/autre.zip');
    }

    // ------------------------------------------------------------------
    // Restauration
    // ------------------------------------------------------------------

    public function testRestaurerRemetBaseEtFichiersDansLEtatSauvegarde(): void
    {
        $service = $this->service();
        $chemin = $service->creer();

        // Modifications après la sauvegarde.
        $this->base->tables['club_equipement'][] = ['id' => 2, 'libelle' => 'Casque'];
        unlink($this->images . '/club/logo.png');
        $this->ecrire($this->images . '/equipements/photo-1.webp', 'remplacée');
        $this->ecrire($this->images . '/equipements/photo-2.webp', 'nouvelle');
        $this->ecrire($this->uploads . '/factures/facture_2.pdf', '%PDF-2');

        $resultat = $service->restaurer($chemin);

        self::assertCount(1, $this->base->tables['club_equipement']);
        self::assertSame('Baudrier é', $this->base->tables['club_equipement'][0]['libelle']);
        self::assertSame('logo', file_get_contents($this->images . '/club/logo.png'));
        self::assertSame('photo-1', file_get_contents($this->images . '/equipements/photo-1.webp'));
        self::assertFileDoesNotExist($this->images . '/equipements/photo-2.webp');
        self::assertFileDoesNotExist($this->uploads . '/factures/facture_2.pdf');

        // Fichiers hors périmètre conservés.
        self::assertSame('Require all denied', file_get_contents($this->images . '/.htaccess'));
        self::assertFileExists($this->images . '/notes.txt');

        // Filet : l'état d'avant la restauration est archivé et restaurable.
        self::assertMatchesRegularExpression('/-avant-restauration(-\d+)?\.zip$/', $resultat['filet']);
        self::assertSame(4, $resultat['manifeste']['nb_fichiers']);
        $filet = $service->analyser($service->chemin($resultat['filet']));
        self::assertSame(5, $filet['nb_fichiers']);
        self::assertSame(2, $filet['tables']['club_equipement']);
    }

    public function testRestaurerAppelleLaMiseANiveauDeLaBase(): void
    {
        $service = $this->service();
        $chemin = $service->creer();

        $appels = 0;
        $resultat = $service->restaurer($chemin, function () use (&$appels) {
            $appels++;
            return ['013_utilisateur_protege'];
        });

        self::assertSame(1, $appels);
        self::assertSame(['013_utilisateur_protege'], $resultat['migrations']);
    }

    public function testRestaurerNeToucheARienSiLArchiveEstRefusee(): void
    {
        $chemin = $this->service(cle: 'autre')->creer();
        $this->base->tables['club_equipement'][] = ['id' => 2];

        try {
            $this->service()->restaurer($chemin);
            self::fail('restauration acceptée');
        } catch (SauvegardeException) {
        }

        self::assertSame(0, $this->base->imports);
        self::assertCount(2, $this->base->tables['club_equipement']);
        self::assertCount(1, glob($this->sauvegardes . '/*.zip'), 'pas de filet créé');
    }

    public function testEchecPendantLaRestaurationRetablitLEtatPrecedent(): void
    {
        $service = $this->service();
        $chemin = $service->creer();

        $this->base->tables['club_equipement'][] = ['id' => 2, 'libelle' => 'Casque'];
        $this->ecrire($this->images . '/equipements/photo-2.webp', 'nouvelle');
        $this->base->echecsImport = 1;

        try {
            $service->restaurer($chemin);
            self::fail('l\'échec aurait dû être signalé');
        } catch (SauvegardeException $e) {
            self::assertStringContainsString('ont été rétablies', $e->getMessage());
        }

        self::assertSame(2, $this->base->imports, 'import échoué puis retour au filet');
        self::assertCount(2, $this->base->tables['club_equipement']);
        self::assertSame('nouvelle', file_get_contents($this->images . '/equipements/photo-2.webp'));
    }

    public function testEchecDuRetourAuFiletEstSignaleAvecLeNomDuFilet(): void
    {
        $service = $this->service();
        $chemin = $service->creer();
        $this->base->echecsImport = 2;

        $this->attendreRefus('avant-restauration');
        $service->restaurer($chemin);
    }

    public function testSeulesLesTroisDernieresSauvegardesAvantRestaurationSontGardees(): void
    {
        $service = $this->service();
        $chemin = $service->creer();
        for ($i = 0; $i < 5; $i++) {
            $service->restaurer($chemin);
        }

        $avant = array_filter($service->lister(), fn ($a) => $a['type'] === SauvegardeService::TYPE_AVANT_RESTAURATION);
        self::assertCount(3, $avant);
        self::assertFileExists($chemin, 'la sauvegarde manuelle n\'est pas concernée');
    }

    public function testRestaurerUneAncienneSauvegardeAvantRestaurationMalgreLaRotation(): void
    {
        $service = $this->service();
        $chemin = $service->creer();
        $filets = [];
        for ($i = 0; $i < 3; $i++) {
            $filets[] = $service->restaurer($chemin)['filet'];
        }

        // La plus ancienne des trois : la rotation ne doit pas la supprimer
        // avant qu'elle ait été appliquée.
        $resultat = $service->restaurer($service->chemin($filets[0]));
        self::assertNotSame('', $resultat['filet']);
    }

    public function testUneSeuleOperationALaFois(): void
    {
        $service = $this->service();
        $service->preparerDossier();
        $verrou = fopen($this->sauvegardes . '/.verrou', 'c');
        flock($verrou, LOCK_EX);

        try {
            $this->attendreRefus('déjà en cours');
            $service->creer();
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    // ------------------------------------------------------------------
    // Accès aux archives par leur nom
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function nomsInvalides(): iterable
    {
        yield 'remontée'      => ['../../.env.local.php'];
        yield 'sous-dossier'  => ['a/b.zip'];
        yield 'caché'         => ['.verrou'];
        yield 'pas un zip'    => ['sauvegarde.sql'];
        yield 'vide'          => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nomsInvalides')]
    public function testCheminRefuseLesNomsInvalides(string $nom): void
    {
        $this->expectException(SauvegardeException::class);
        $this->service()->chemin($nom);
    }

    public function testListerEtSupprimer(): void
    {
        $service = $this->service();
        $nom = basename($service->creer());
        $this->ecrire($this->sauvegardes . '/' . $service->nomTeleversement(), 'x');

        $types = array_column($service->lister(), 'type', 'nom');
        self::assertSame(SauvegardeService::TYPE_MANUELLE, $types[$nom]);
        self::assertContains(SauvegardeService::TYPE_TELEVERSEE, $types);
        self::assertArrayNotHasKey('.htaccess', $types);

        $service->supprimer($nom);
        self::assertFileDoesNotExist($this->sauvegardes . '/' . $nom);
    }

    public function testPurgerTeleversementsAnciens(): void
    {
        $service = $this->service();
        $ancien = $this->sauvegardes . '/televersee-20200101-000000-abcd1234.zip';
        $recent = $this->sauvegardes . '/' . $service->nomTeleversement();
        $this->ecrire($ancien, 'x');
        $this->ecrire($recent, 'x');
        touch($ancien, time() - 2 * 86400);

        $service->purgerTeleversements();

        self::assertFileDoesNotExist($ancien);
        self::assertFileExists($recent);
    }

    // ------------------------------------------------------------------

    private function service(string $version = '1.2.0', string $cle = 'cle'): SauvegardeService
    {
        return new SauvegardeService(
            $this->base,
            ['images' => $this->images, 'uploads' => $this->uploads],
            $this->sauvegardes,
            $version,
            SauvegardeService::empreinte($cle),
            ['001_initial', '002_x', '013_utilisateur_protege'],
        );
    }

    private function attendreRefus(string $fragment): void
    {
        $this->expectException(SauvegardeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($fragment, '/') . '/u');
    }

    private function ecrire(string $chemin, string $contenu): void
    {
        if (!is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0777, true);
        }
        file_put_contents($chemin, $contenu);
    }

    private function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }
}
