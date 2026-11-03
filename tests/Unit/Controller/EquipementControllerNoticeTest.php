<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-11-03] Lien vers la notice du fabricant sur la fiche équipement
 * (colonne notice_url, migration 021).
 */
final class EquipementControllerNoticeTest extends TestCase
{
    private const CSRF = 'csrf';

    private ?array $enregistre = null;

    private function editer(?string $noticeActuelle, array $post): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'a', 'role' => 'ROLE_ADMIN']);
        $session->set('csrf_token', self::CSRF);

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn([
            'id' => 4, 'reference' => 'REF-4', 'acquisition_id' => 42, 'nombre_initial' => 1,
            'date_mise_en_service' => '2026-03-15', 'notice_url' => $noticeActuelle,
        ]);
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->enregistre = $e;
            return true;
        });

        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 42, 'facture_date' => '2026-03-15']);
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);

        $controller = new TestableEquipementController($session, $equipements, $categories, $emplacements, $acquisitions);

        $method = $post === [] ? 'GET' : 'POST';
        $request = Request::create('/equipements/equipement_modification-4', $method, $post + ($post === [] ? [] : ['csrf_token' => self::CSRF, 'statut' => '0']));
        $request->attributes->set('id', '4');

        return $controller->edit($request);
    }

    public function testLienEnregistre(): void
    {
        $response = $this->editer(null, ['notice_url' => '  https://fabricant.example/notice.pdf ']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://fabricant.example/notice.pdf', $this->enregistre['notice_url']);
    }

    public function testChampVideEffaceLeLien(): void
    {
        $this->editer('https://fabricant.example/notice.pdf', ['notice_url' => '']);

        self::assertArrayHasKey('notice_url', $this->enregistre);
        self::assertNull($this->enregistre['notice_url']);
    }

    public function testLienInvalideRefuseEtSaisieConservee(): void
    {
        $response = $this->editer(null, ['notice_url' => 'javascript://x%0Aalert(1)']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->enregistre);
        $html = (string) $response->getContent();
        self::assertStringContainsString('http:// ou https://', $html);
        self::assertStringContainsString('value="javascript://x%0Aalert(1)"', $html);
    }

    public function testFormulaireAfficheLeLienActuel(): void
    {
        $html = (string) $this->editer('https://fabricant.example/notice.pdf', [])->getContent();

        self::assertStringContainsString('name="notice_url"', $html);
        self::assertStringContainsString('value="https://fabricant.example/notice.pdf"', $html);
    }

    #[DataProvider('urls')]
    public function testValidation(string $url, ?string $erreurAttendue): void
    {
        $erreur = EquipementController::erreurNoticeUrl($url);

        if ($erreurAttendue === null) {
            self::assertNull($erreur);
        } else {
            self::assertNotNull($erreur);
            self::assertStringContainsString($erreurAttendue, $erreur);
        }
    }

    public static function urls(): array
    {
        return [
            'vide'            => ['', null],
            'https'           => ['https://www.petzl.com/notice.pdf', null],
            'http'            => ['http://exemple.fr/doc?id=3', null],
            'pas une URL'     => ['notice.pdf', "n'est pas une URL valide"],
            'javascript:'     => ['javascript:alert(1)', "n'est pas une URL valide"],
            'javascript://'   => ['javascript://x%0Aalert(1)', 'http:// ou https://'],
            'ftp'             => ['ftp://exemple.fr/notice.pdf', 'http:// ou https://'],
            'trop longue'     => ['https://exemple.fr/' . str_repeat('a', 2048), 'ne doit pas dépasser 2048'],
        ];
    }
}
