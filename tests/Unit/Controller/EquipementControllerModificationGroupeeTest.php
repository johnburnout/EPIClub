<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-20] Modification groupée des équipements cochés dans la
 * liste : champs permis par le rôle, appliqués seulement si cochés,
 * photo partagée, règles de la date de mise en service par équipement.
 * Couvre aussi la conservation des photos partagées dans la fiche.
 */
final class EquipementControllerModificationGroupeeTest extends TestCase
{
    private const CSRF = 'csrf';

    private string $publicDir;
    private Session $session;
    /** @var EquipementManager&MockObject */
    private EquipementManager $equipementManager;
    /** @var array<int, array<string, mixed>> */
    private array $enregistres = [];

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/epiclub-groupe-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/images/equipements', 0777, true);
        $this->equipementManager = $this->createMock(EquipementManager::class);
        $this->equipementManager->method('save')->willReturnCallback(function (array $e) {
            $this->enregistres[] = $e;
            return true;
        });
        $this->connecter('ROLE_ADMIN');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->publicDir . '/images/equipements/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->publicDir . '/images/equipements');
        @rmdir($this->publicDir . '/images');
        @rmdir($this->publicDir);
    }

    // ==================================================================
    // Formulaire
    // ==================================================================

    public function testFormulaireAdminSansLibelle(): void
    {
        $this->equipementManager->method('findByIds')->with([3, 5])->willReturn($this->equipements());

        $html = (string) $this->controller()->modificationGroupee(
            Request::create('/equipements/modification-groupee', 'GET', ['ids' => ['3', '5', '5', 'x'], 'retour' => 'q=casque'])
        )->getContent();

        self::assertStringContainsString('2 équipements sélectionnés', $html);
        self::assertStringContainsString('name="appliquer[statut]"', $html);
        self::assertStringContainsString('name="appliquer[photo]"', $html);
        self::assertStringNotContainsString('name="appliquer[libelle]"', $html);
        self::assertStringContainsString('href="/equipements?q=casque"', $html);
    }

    public function testFormulaireSuperAdminAvecLibelle(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $html = (string) $this->controller()->modificationGroupee(
            Request::create('/equipements/modification-groupee', 'GET', ['ids' => ['3', '5']])
        )->getContent();

        self::assertStringContainsString('name="appliquer[libelle]"', $html);
    }

    public function testSansSelectionRetourALaListe(): void
    {
        $response = $this->controller()->modificationGroupee(Request::create('/equipements/modification-groupee'));

        self::assertSame('/equipements', $response->headers->get('Location'));
        self::assertSame(['Aucun équipement sélectionné.'], $this->session->getFlashBag()->get('error'));
    }

    // ==================================================================
    // Application
    // ==================================================================

    public function testSeulsLesChampsCochesSontAppliques(): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $response = $this->post([
            'appliquer' => ['statut' => '1', 'emplacement_id' => '1'],
            'statut' => '1', 'emplacement_id' => '2',
            'date_fin_utilisation' => '2030-01-01', // non coché → ignoré
            'retour' => 'page=2',
        ]);

        self::assertSame('/equipements?page=2', $response->headers->get('Location'));
        self::assertSame([
            ['id' => 3, 'statut' => 1, 'emplacement_id' => 2],
            ['id' => 5, 'statut' => 1, 'emplacement_id' => 2],
        ], $this->enregistres);
        self::assertSame(['2 équipements modifiés.'], $this->session->getFlashBag()->get('success'));
    }

    public function testAucunChampCocheRefuse(): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $response = $this->post(['statut' => '1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->enregistres);
        self::assertStringContainsString('au moins un champ', (string) $response->getContent());
    }

    #[DataProvider('saisiesInvalides')]
    public function testSaisieInvalideRienNEstEnregistre(array $post, string $message): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $response = $this->post($post);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->enregistres);
        self::assertStringContainsString($message, (string) $response->getContent());
    }

    public static function saisiesInvalides(): array
    {
        return [
            'statut' => [['appliquer' => ['statut' => '1'], 'statut' => '9'], 'Statut invalide.'],
            'emplacement inconnu' => [['appliquer' => ['emplacement_id' => '1'], 'emplacement_id' => '99'], 'Emplacement invalide.'],
            'date' => [['appliquer' => ['date_fin_utilisation' => '1'], 'date_fin_utilisation' => '2030-02-31'], 'Date invalide.'],
            'photo absente' => [['appliquer' => ['photo' => '1']], 'Choisissez une photo à appliquer.'],
        ];
    }

    public function testLibelleIgnorePourUnAdmin(): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $this->post(['appliquer' => ['libelle' => '1', 'statut' => '1'], 'libelle' => 'Forgé', 'statut' => '0']);

        foreach ($this->enregistres as $e) {
            self::assertArrayNotHasKey('libelle', $e);
        }
    }

    public function testLibelleModifieParUnSuperAdmin(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $this->post(['appliquer' => ['libelle' => '1'], 'libelle' => ' Casque Boreo ']);

        self::assertSame(['Casque Boreo', 'Casque Boreo'], array_column($this->enregistres, 'libelle'));
    }

    public function testRemarquesAjouteesALaSuite(): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());

        $this->post(['appliquer' => ['remarques' => '1'], 'remarques' => 'Révisé', 'remarques_mode' => 'ajouter']);

        self::assertSame(["Ancienne note\nRévisé", 'Révisé'], array_column($this->enregistres, 'remarques'));
    }

    public function testDateDeMiseEnServiceSelonLesReglesDeLaFiche(): void
    {
        $this->equipementManager->method('findByIds')->willReturn([
            // date = facture → modifiable
            ['id' => 3, 'reference' => 'R3', 'acquisition_id' => 42, 'date_mise_en_service' => '2026-03-15'],
            // déjà modifiée → verrouillée pour un admin
            ['id' => 5, 'reference' => 'R5', 'acquisition_id' => 42, 'date_mise_en_service' => '2026-04-01'],
            // facture postérieure à la date demandée → ignoré
            ['id' => 6, 'reference' => 'R6', 'acquisition_id' => 43, 'date_mise_en_service' => null],
        ]);

        $this->post(['appliquer' => ['date_mise_en_service' => '1'], 'date_mise_en_service' => '2026-05-01']);

        self::assertSame([['id' => 3, 'date_mise_en_service' => '2026-05-01']], $this->enregistres);
        $avertissement = $this->session->getFlashBag()->get('warning')[0];
        self::assertStringContainsString('R5 (date déjà modifiée)', $avertissement);
        self::assertStringContainsString('R6 (facture du 01/06/2026)', $avertissement);
    }

    public function testDateDeMiseEnServiceSuperAdminSansVerrou(): void
    {
        $this->connecter('ROLE_SUPER_ADMIN');
        $this->equipementManager->method('findByIds')->willReturn([
            ['id' => 5, 'reference' => 'R5', 'acquisition_id' => 42, 'date_mise_en_service' => '2026-04-01'],
        ]);

        $this->post(['appliquer' => ['date_mise_en_service' => '1'], 'date_mise_en_service' => '2026-05-01']);

        self::assertSame([['id' => 5, 'date_mise_en_service' => '2026-05-01']], $this->enregistres);
    }

    public function testPhotoPartageeEtAnciennesPhotosOrphelinesEffacees(): void
    {
        file_put_contents($this->publicDir . '/images/equipements/partagee.webp', 'x');
        file_put_contents($this->publicDir . '/images/equipements/ailleurs.webp', 'x');
        $this->equipementManager->method('findByIds')->willReturn([
            ['id' => 3, 'reference' => 'R3', 'photo' => '/images/equipements/partagee.webp'],
            ['id' => 5, 'reference' => 'R5', 'photo' => '/images/equipements/partagee.webp'],
            ['id' => 8, 'reference' => 'R8', 'photo' => '/images/equipements/ailleurs.webp'],
        ]);
        $this->equipementManager->method('countPhotoReferences')->willReturnMap([
            ['/images/equipements/partagee.webp', 0],
            ['/images/equipements/ailleurs.webp', 1],
        ]);

        $request = $this->requete(['appliquer' => ['photo' => '1']]);
        $request->files->set('photo', $this->imagePng());
        $this->controller()->modificationGroupee($request);

        $photos = array_unique(array_column($this->enregistres, 'photo'));
        self::assertCount(1, $photos, 'Une seule photo partagée');
        self::assertCount(3, $this->enregistres);
        self::assertFileExists($this->publicDir . reset($photos));
        self::assertFileDoesNotExist($this->publicDir . '/images/equipements/partagee.webp');
        self::assertFileExists($this->publicDir . '/images/equipements/ailleurs.webp');
    }

    public function testFichierQuiNEstPasUneImageRefuse(): void
    {
        $this->equipementManager->method('findByIds')->willReturn($this->equipements());
        $faux = tempnam(sys_get_temp_dir(), 'grp');
        file_put_contents($faux, '<?php echo 1;');
        $request = $this->requete(['appliquer' => ['photo' => '1']]);
        $request->files->set('photo', new UploadedFile($faux, 'x.php', 'image/png', null, true));

        $log = ini_set('error_log', '/dev/null');
        try {
            $response = $this->controller()->modificationGroupee($request);
        } finally {
            ini_set('error_log', (string) $log);
            @unlink($faux);
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->enregistres);
        self::assertSame([], glob($this->publicDir . '/images/equipements/*'));
    }

    // ==================================================================
    // Liste et fiche
    // ==================================================================

    public function testListeAfficheLaSelectionPourLAdmin(): void
    {
        $this->equipementManager->method('findAll')->willReturn([
            ['id' => 7, 'reference' => 'REF-7', 'libelle' => 'Casque', 'statut' => 0],
        ]);

        $html = (string) $this->controller()->list(Request::create('/equipements?q=casque'))->getContent();

        self::assertStringContainsString('action="/equipements/modification-groupee"', $html);
        self::assertStringContainsString('value="7" form="form-selection"', $html);
        self::assertStringContainsString('name="retour" value="q=casque"', $html);
    }

    public function testListeSansSelectionPourUnUtilisateur(): void
    {
        $this->connecter('ROLE_USER');
        $this->equipementManager->method('findAll')->willReturn([
            ['id' => 7, 'reference' => 'REF-7', 'libelle' => 'Casque', 'statut' => 0],
        ]);

        $html = (string) $this->controller()->list(Request::create('/equipements'))->getContent();

        self::assertStringNotContainsString('name="ids[]"', $html);
    }

    #[DataProvider('referencesRestantes')]
    public function testFicheNEffaceLAnciennePhotoQueSiOrpheline(int $references, bool $effacee): void
    {
        file_put_contents($this->publicDir . '/images/equipements/partagee.webp', 'x');
        $this->equipementManager->method('findId')->willReturn([
            'id' => 4, 'reference' => 'REF-4', 'photo' => '/images/equipements/partagee.webp', 'nombre_initial' => 1,
        ]);
        $this->equipementManager->method('countPhotoReferences')->willReturn($references);

        $request = Request::create('/equipements/equipement_modification-4', 'POST', ['csrf_token' => self::CSRF, 'statut' => '0']);
        $request->attributes->set('id', '4');
        $request->files->set('photo', $this->imagePng());
        $this->controller()->edit($request);

        self::assertSame(!$effacee, is_file($this->publicDir . '/images/equipements/partagee.webp'));
    }

    public static function referencesRestantes(): array
    {
        return ['encore utilisée' => [1, false], 'plus utilisée' => [0, true]];
    }

    // ==================================================================

    private function connecter(string $role): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'u', 'role' => $role]);
        $this->session->set('csrf_token', self::CSRF);
    }

    private function equipements(): array
    {
        return [
            ['id' => 3, 'reference' => 'R3', 'libelle' => 'Casque', 'remarques' => 'Ancienne note', 'acquisition_id' => 42],
            ['id' => 5, 'reference' => 'R5', 'libelle' => 'Casque', 'remarques' => null, 'acquisition_id' => 42],
        ];
    }

    private function controller(): TestableEquipementController
    {
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([['id' => 2, 'libelle' => 'Local'], ['id' => 3, 'libelle' => 'Car']]);
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturnCallback(
            fn(int $id) => ['id' => $id, 'facture_date' => $id === 43 ? '2026-06-01' : '2026-03-15']
        );

        return new TestableEquipementController(
            $this->session, $this->equipementManager, $categories, $emplacements, $acquisitions, $this->publicDir
        );
    }

    private function requete(array $post): Request
    {
        return Request::create('/equipements/modification-groupee', 'POST',
            $post + ['ids' => ['3', '5', '6', '8'], 'csrf_token' => self::CSRF]);
    }

    private function post(array $post): Response
    {
        return $this->controller()->modificationGroupee($this->requete($post));
    }

    private function imagePng(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'grp') . '.png';
        $img = imagecreatetruecolor(200, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 90, 200));
        imagepng($img, $chemin);
        return new UploadedFile($chemin, 'photo.png', 'image/png', null, true);
    }
}
