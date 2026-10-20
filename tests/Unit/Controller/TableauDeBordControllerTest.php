<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\TableauDeBordController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableTableauDeBordController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tableau de bord : bandeau club, notification « EPI à renouveler »,
 * fermeture de la notification, réglages (logo, fond, durée).
 */
final class TableauDeBordControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    private string $imagesDir;
    private Session $session;
    /** @var ClubManager&MockObject */
    private ClubManager $clubManager;
    /** @var EquipementManager&MockObject */
    private EquipementManager $equipementManager;

    protected function setUp(): void
    {
        $this->imagesDir = sys_get_temp_dir() . '/epiclub-tdb-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->imagesDir, 0777, true);

        $this->clubManager = $this->createMock(ClubManager::class);
        $this->equipementManager = $this->createMock(EquipementManager::class);
        $this->connecter('ROLE_USER');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->imagesDir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->imagesDir);
    }

    // ==================================================================
    // index()
    // ==================================================================

    public function testIndexAfficheLaNotificationAvecLeLienVersLaListe(): void
    {
        $this->clubManager->method('findParameters')->willReturn([
            'id' => 1, 'nom' => 'CAF Test', 'activite' => 'Escalade',
            'logo' => null, 'image_fond' => null, 'alerte_renouvellement_mois' => 3,
        ]);
        $this->equipementManager->expects(self::once())
            ->method('statsARenouveler')
            ->with('2026-10-20', '2027-01-20')
            ->willReturn(['total' => 4, 'echus' => 1]);

        $response = $this->controller()->index(Request::create('/tableau_de_bord'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('CAF Test', $html);
        self::assertStringContainsString('4 EPI à renouveler', $html);
        self::assertStringContainsString('3 prochains mois', $html);
        self::assertStringContainsString('dont 1 déjà échu', $html);
        self::assertStringContainsString('fin_avant=2027-01-20&amp;order_by=date_fin&amp;order_dir=asc', $html);
        self::assertStringContainsString('href="/controles/scanner"', $html);
        self::assertStringContainsString(TableauDeBordController::LOGO_DEFAUT, $html);
        self::assertStringContainsString(TableauDeBordController::FOND_DEFAUT, $html);
    }

    public function testIndexUtiliseLaDureeEtLesImagesDuClub(): void
    {
        $this->clubManager->method('findParameters')->willReturn([
            'id' => 1, 'nom' => 'Club', 'logo' => 'logo-abc.webp',
            'image_fond' => 'fond-def.webp', 'alerte_renouvellement_mois' => 6,
        ]);
        $this->equipementManager->expects(self::once())
            ->method('statsARenouveler')
            ->with('2026-10-20', '2027-04-20')
            ->willReturn(['total' => 0, 'echus' => 0]);

        $html = (string) $this->controller()->index(Request::create('/tableau_de_bord'))->getContent();

        self::assertStringContainsString('/images/club/logo-abc.webp', $html);
        self::assertStringContainsString('/images/club/fond-def.webp', $html);
        self::assertStringNotContainsString('EPI à renouveler', $html, 'Aucun équipement → pas de notification');
    }

    public function testIndexNAffichePasLaNotificationFermee(): void
    {
        $this->clubManager->method('findParameters')->willReturn(['id' => 1, 'nom' => 'Club']);
        $this->session->set(TableauDeBordController::SESSION_ALERTE_MASQUEE, true);
        $this->equipementManager->expects(self::never())->method('statsARenouveler');

        $html = (string) $this->controller()->index(Request::create('/tableau_de_bord'))->getContent();

        self::assertStringNotContainsString('EPI à renouveler', $html);
    }

    public function testIndexResteAffichableSiLeCalculEchoue(): void
    {
        $this->clubManager->method('findParameters')->willReturn(null);
        $this->equipementManager->method('statsARenouveler')
            ->willThrowException(new \PDOException('Unknown column'));

        $logPrecedent = ini_set('error_log', '/dev/null');
        try {
            $response = $this->controller()->index(Request::create('/tableau_de_bord'));
        } finally {
            ini_set('error_log', (string) $logPrecedent);
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('EPI à renouveler', (string) $response->getContent());
    }

    // ==================================================================
    // masquerAlerte()
    // ==================================================================

    public function testMasquerAlerteEnAjaxRetourneDuJson(): void
    {
        $request = $this->post('/tableau_de_bord/masquer-alerte');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller()->masquerAlerte($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertTrue($this->session->get(TableauDeBordController::SESSION_ALERTE_MASQUEE));
    }

    public function testMasquerAlerteSansJavascriptRedirige(): void
    {
        $response = $this->controller()->masquerAlerte($this->post('/tableau_de_bord/masquer-alerte'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tableau_de_bord', $response->headers->get('Location'));
        self::assertTrue($this->session->get(TableauDeBordController::SESSION_ALERTE_MASQUEE));
    }

    // ==================================================================
    // reglages()
    // ==================================================================

    public function testReglagesGetAfficheLeFormulaire(): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1, 'alerte_renouvellement_mois' => 5]);
        $this->clubManager->expects(self::never())->method('save');

        $response = $this->controller()->reglages(Request::create('/admin/tableau-de-bord'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="5"', (string) $response->getContent());
    }

    #[DataProvider('dureesInvalides')]
    public function testReglagesRefuseUneDureeInvalide(string $duree): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1]);
        $this->clubManager->expects(self::never())->method('save');

        $response = $this->controller()->reglages(
            $this->post('/admin/tableau-de-bord', ['alerte_renouvellement_mois' => $duree])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('entre 1 et 36', (string) $response->getContent());
    }

    public static function dureesInvalides(): array
    {
        return [['0'], ['37'], ['-2'], ['abc'], ['2.5'], ['']];
    }

    public function testReglagesEnregistreLaDuree(): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1, 'alerte_renouvellement_mois' => 3]);
        $this->clubManager->expects(self::once())
            ->method('save')
            ->with(['id' => 1, 'alerte_renouvellement_mois' => 6]);

        $response = $this->controller()->reglages(
            $this->post('/admin/tableau-de-bord', ['alerte_renouvellement_mois' => '6'])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/tableau-de-bord', $response->headers->get('Location'));
    }

    public function testReglagesTeleverseUnLogoEtSupprimeLAncien(): void
    {
        $this->connecter('ROLE_ADMIN');
        file_put_contents($this->imagesDir . 'logo-ancien.webp', 'x');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1, 'logo' => 'logo-ancien.webp']);

        $enregistre = null;
        $this->clubManager->expects(self::once())->method('save')
            ->willReturnCallback(function (array $data) use (&$enregistre) {
                $enregistre = $data;
                return true;
            });

        $request = $this->post('/admin/tableau-de-bord', ['alerte_renouvellement_mois' => '3']);
        $request->files->set('logo', $this->imagePng());

        $response = $this->controller()->reglages($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^logo-[0-9a-f]{16}\.(webp|jpg)$/', $enregistre['logo']);
        self::assertFileExists($this->imagesDir . $enregistre['logo']);
        self::assertFileDoesNotExist($this->imagesDir . 'logo-ancien.webp');
        self::assertArrayNotHasKey('image_fond', $enregistre);
    }

    public function testReglagesRefuseUnFichierQuiNEstPasUneImage(): void
    {
        $this->connecter('ROLE_ADMIN');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1]);
        $this->clubManager->expects(self::never())->method('save');

        $faux = tempnam(sys_get_temp_dir(), 'tdb');
        file_put_contents($faux, '<html><script>alert(1)</script></html>');
        $request = $this->post('/admin/tableau-de-bord', ['alerte_renouvellement_mois' => '3']);
        $request->files->set('image_fond', new UploadedFile($faux, 'fond.html', 'text/html', null, true));

        $response = $this->controller()->reglages($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Type de fichier non autorisé', (string) $response->getContent());
        self::assertSame([], glob($this->imagesDir . '*'));
        @unlink($faux);
    }

    public function testReglagesRetourALaPhotoDeFondParDefaut(): void
    {
        $this->connecter('ROLE_ADMIN');
        file_put_contents($this->imagesDir . 'fond-ancien.webp', 'x');
        $this->clubManager->method('findParameters')->willReturn(['id' => 1, 'image_fond' => 'fond-ancien.webp']);
        $this->clubManager->expects(self::once())->method('save')
            ->with(['id' => 1, 'alerte_renouvellement_mois' => 3, 'image_fond' => null]);

        $this->controller()->reglages($this->post('/admin/tableau-de-bord', [
            'alerte_renouvellement_mois' => '3',
            'supprimer_image_fond' => '1',
        ]));

        self::assertFileDoesNotExist($this->imagesDir . 'fond-ancien.webp');
    }

    // ==================================================================
    // normaliserDuree()
    // ==================================================================

    #[DataProvider('durees')]
    public function testNormaliserDuree(mixed $valeur, int $attendu): void
    {
        self::assertSame($attendu, TableauDeBordController::normaliserDuree($valeur));
    }

    public static function durees(): array
    {
        return [
            'null → défaut' => [null, 3],
            'vide → défaut' => ['', 3],
            'texte → défaut' => ['abc', 3],
            'valeur BDD' => ['6', 6],
            'trop petit' => [0, 1],
            'trop grand' => [99, 36],
        ];
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function connecter(string $role): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'test', 'role' => $role]);
        $this->session->set('csrf_token', self::CSRF_TOKEN);
    }

    private function controller(): TestableTableauDeBordController
    {
        return new TestableTableauDeBordController(
            $this->session,
            $this->clubManager,
            $this->equipementManager,
            $this->imagesDir,
            new \DateTimeImmutable('2026-10-20'),
        );
    }

    private function post(string $uri, array $data = []): Request
    {
        return Request::create($uri, 'POST', $data + ['csrf_token' => self::CSRF_TOKEN]);
    }

    private function imagePng(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'tdb') . '.png';
        $img = imagecreatetruecolor(800, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        imagepng($img, $chemin);
        return new UploadedFile($chemin, 'logo.png', 'image/png', null, true);
    }
}
