<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Photo groupée : une même photo affectée à plusieurs équipements
 * cochés dans la liste, et effacement des photos devenues orphelines
 * (y compris lors de la modification d'un seul équipement).
 */
final class EquipementControllerPhotoGroupeeTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    private string $publicDir;
    private Session $session;
    /** @var EquipementManager&MockObject */
    private EquipementManager $equipementManager;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/epiclub-photo-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/images/equipements', 0777, true);

        $this->equipementManager = $this->createMock(EquipementManager::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'admin', 'role' => 'ROLE_ADMIN']);
        $this->session->set('csrf_token', self::CSRF_TOKEN);
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

    public function testAppliqueLaMemePhotoAuxEquipementsCoches(): void
    {
        $this->creerPhoto('ancienne-partagee.webp');
        $this->creerPhoto('ancienne-ailleurs.webp');

        $this->equipementManager->expects(self::once())
            ->method('findPhotosByIds')
            ->with([3, 5, 8])
            ->willReturn([
                ['id' => 3, 'photo' => '/images/equipements/ancienne-partagee.webp'],
                ['id' => 5, 'photo' => '/images/equipements/ancienne-partagee.webp'],
                ['id' => 8, 'photo' => '/images/equipements/ancienne-ailleurs.webp'],
            ]);

        $photoEnregistree = null;
        $this->equipementManager->expects(self::once())
            ->method('updatePhotoForIds')
            ->willReturnCallback(function (array $ids, string $photo) use (&$photoEnregistree) {
                self::assertSame([3, 5, 8], $ids);
                $photoEnregistree = $photo;
                return 3;
            });

        // ancienne-partagee n'est plus utilisée ; ancienne-ailleurs l'est
        // encore par un équipement non sélectionné.
        $this->equipementManager->method('countPhotoReferences')
            ->willReturnMap([
                ['/images/equipements/ancienne-partagee.webp', 0],
                ['/images/equipements/ancienne-ailleurs.webp', 1],
            ]);

        $request = $this->post(['ids' => ['3', '5', '8', '5', 'abc', '-1'], 'retour' => 'categorie=2&page=3&action=export']);
        $request->files->set('photo', $this->imagePng());

        $response = $this->controller()->photoGroupee($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/equipements?categorie=2&page=3', $response->headers->get('Location'));
        self::assertMatchesRegularExpression('#^/images/equipements/[0-9a-f]+\.(webp|jpg)$#', $photoEnregistree);
        self::assertFileExists($this->publicDir . $photoEnregistree, 'Photo enregistrée une seule fois');
        self::assertCount(2, glob($this->publicDir . '/images/equipements/*'));
        self::assertFileDoesNotExist($this->publicDir . '/images/equipements/ancienne-partagee.webp');
        self::assertFileExists($this->publicDir . '/images/equipements/ancienne-ailleurs.webp');
        self::assertSame(['Photo appliquée à 3 équipements.'], $this->session->getFlashBag()->get('success'));
    }

    public function testSansSelectionRienNEstFait(): void
    {
        $this->equipementManager->expects(self::never())->method('updatePhotoForIds');

        $request = $this->post(['retour' => '']);
        $request->files->set('photo', $this->imagePng());

        $response = $this->controller()->photoGroupee($request);

        self::assertSame('/equipements', $response->headers->get('Location'));
        self::assertSame(['Aucun équipement sélectionné.'], $this->session->getFlashBag()->get('error'));
        self::assertSame([], glob($this->publicDir . '/images/equipements/*'));
    }

    public function testSansPhotoRienNEstFait(): void
    {
        $this->equipementManager->expects(self::never())->method('updatePhotoForIds');

        $this->controller()->photoGroupee($this->post(['ids' => ['1', '2']]));

        self::assertSame(['Choisissez une photo à appliquer.'], $this->session->getFlashBag()->get('error'));
    }

    public function testFichierQuiNEstPasUneImageRefuse(): void
    {
        $this->equipementManager->method('findPhotosByIds')->willReturn([['id' => 1, 'photo' => null]]);
        $this->equipementManager->expects(self::never())->method('updatePhotoForIds');

        $faux = tempnam(sys_get_temp_dir(), 'pg');
        file_put_contents($faux, '<?php echo "x";');
        $request = $this->post(['ids' => ['1']]);
        $request->files->set('photo', new UploadedFile($faux, 'photo.php', 'image/png', null, true));

        $logPrecedent = ini_set('error_log', '/dev/null');
        try {
            $this->controller()->photoGroupee($request);
        } finally {
            ini_set('error_log', (string) $logPrecedent);
            @unlink($faux);
        }

        self::assertStringContainsString("Erreur lors du traitement de l'image", $this->session->getFlashBag()->get('error')[0]);
        self::assertSame([], glob($this->publicDir . '/images/equipements/*'));
    }

    public function testEquipementsInexistantsRefuses(): void
    {
        $this->equipementManager->method('findPhotosByIds')->willReturn([]);
        $this->equipementManager->expects(self::never())->method('updatePhotoForIds');

        $request = $this->post(['ids' => ['999']]);
        $request->files->set('photo', $this->imagePng());

        $this->controller()->photoGroupee($request);

        self::assertSame([], glob($this->publicDir . '/images/equipements/*'));
        self::assertNotEmpty($this->session->getFlashBag()->get('error'));
    }

    public function testListeAfficheLesCasesPourLAdmin(): void
    {
        $this->equipementManager->method('findAll')->willReturn([
            ['id' => 7, 'reference' => 'REF-7', 'libelle' => 'Casque Petzl', 'statut' => 0],
            ['id' => 8, 'reference' => 'REF-8', 'libelle' => 'Baudrier', 'statut' => 0],
        ]);

        $html = (string) $this->controller()->list(Request::create('/equipements?q=casque'))->getContent();

        self::assertStringContainsString('id="form-photo-groupee"', $html);
        self::assertStringContainsString('name="ids[]"', $html);
        self::assertStringContainsString('value="7" form="form-photo-groupee"', $html);
        self::assertStringNotContainsString('REF-8', $html, 'Recherche par libellé (insensible à la casse)');
        self::assertStringContainsString('name="retour" value="q=casque"', $html);
    }

    public function testListeSansCasesPourUnUtilisateur(): void
    {
        $this->session->set('user', ['id' => 2, 'username' => 'u', 'role' => 'ROLE_USER']);
        $this->equipementManager->method('findAll')->willReturn([
            ['id' => 7, 'reference' => 'REF-7', 'libelle' => 'Casque', 'statut' => 0],
        ]);

        $html = (string) $this->controller()->list(Request::create('/equipements'))->getContent();

        self::assertStringNotContainsString('id="form-photo-groupee"', $html);
        self::assertStringNotContainsString('name="ids[]"', $html);
    }

    /**
     * Modification d'un seul équipement : la photo remplacée n'est
     * effacée que si plus aucun équipement ne l'utilise.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('referencesRestantes')]
    public function testEditNEffaceLAnciennePhotoQueSiOrpheline(int $references, bool $effacee): void
    {
        $this->creerPhoto('partagee.webp');
        $this->equipementManager->method('findId')->willReturn([
            'id' => 4, 'reference' => 'REF-4', 'photo' => '/images/equipements/partagee.webp', 'nombre_initial' => 1,
        ]);
        $this->equipementManager->expects(self::once())->method('save');
        $this->equipementManager->method('countPhotoReferences')
            ->with('/images/equipements/partagee.webp')
            ->willReturn($references);

        $request = Request::create('/equipements/equipement_modification-4', 'POST', [
            'csrf_token' => self::CSRF_TOKEN,
            'statut' => '0',
        ]);
        $request->attributes->set('id', '4');
        $request->files->set('photo', $this->imagePng());

        $response = $this->controller()->edit($request);

        self::assertSame(302, $response->getStatusCode());
        if ($effacee) {
            self::assertFileDoesNotExist($this->publicDir . '/images/equipements/partagee.webp');
        } else {
            self::assertFileExists($this->publicDir . '/images/equipements/partagee.webp');
        }
    }

    public static function referencesRestantes(): array
    {
        return [
            'encore utilisée ailleurs' => [1, false],
            'plus utilisée' => [0, true],
        ];
    }

    // ==================================================================

    private function controller(): TestableEquipementController
    {
        $categorieManager = $this->createMock(CategorieManager::class);
        $categorieManager->method('findAll')->willReturn([]);
        $emplacementManager = $this->createMock(EmplacementManager::class);
        $emplacementManager->method('findAll')->willReturn([]);

        return new TestableEquipementController(
            $this->session,
            $this->equipementManager,
            $categorieManager,
            $emplacementManager,
            $this->createMock(AcquisitionManager::class),
            $this->publicDir,
        );
    }

    private function post(array $data): Request
    {
        return Request::create('/equipements/photo-groupee', 'POST', $data + ['csrf_token' => self::CSRF_TOKEN]);
    }

    private function creerPhoto(string $nom): void
    {
        file_put_contents($this->publicDir . '/images/equipements/' . $nom, 'x');
    }

    private function imagePng(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'pg') . '.png';
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        imagepng($img, $chemin);
        return new UploadedFile($chemin, 'photo.png', 'image/png', null, true);
    }
}
