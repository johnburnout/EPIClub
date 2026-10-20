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
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-20] Date de mise en service dans la fiche équipement :
 * initialisée à la date de facture, modifiable une seule fois, jamais
 * antérieure à la facture.
 */
final class EquipementControllerDateMiseEnServiceTest extends TestCase
{
    private const CSRF = 'csrf';

    private ?array $enregistre = null;

    private function editer(?string $dmsActuelle, array $post): \Symfony\Component\HttpFoundation\Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'a', 'role' => 'ROLE_ADMIN']);
        $session->set('csrf_token', self::CSRF);

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn([
            'id' => 4, 'reference' => 'REF-4', 'acquisition_id' => 42, 'nombre_initial' => 1,
            'date_mise_en_service' => $dmsActuelle,
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

    public function testPremiereModificationAccepteeApresLaFacture(): void
    {
        $response = $this->editer('2026-03-15', ['date_mise_en_service' => '2026-04-01']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('2026-04-01', $this->enregistre['date_mise_en_service']);
    }

    public function testDateAnterieureALaFactureRefusee(): void
    {
        $response = $this->editer('2026-03-15', ['date_mise_en_service' => '2026-03-14']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->enregistre);
        self::assertStringContainsString('antérieure à la date de facture (15/03/2026)', (string) $response->getContent());
    }

    public function testDateDejaModifieeEstVerrouilleeCoteServeur(): void
    {
        $response = $this->editer('2026-04-01', ['date_mise_en_service' => '2026-05-01']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('2026-04-01', $this->enregistre['date_mise_en_service'], 'Valeur postée ignorée');
    }

    public function testChampVideConserveLaDate(): void
    {
        $this->editer('2026-03-15', ['date_mise_en_service' => '']);

        self::assertSame('2026-03-15', $this->enregistre['date_mise_en_service']);
    }

    public function testFormulaireAfficheDateAchatEtBorne(): void
    {
        $html = (string) $this->editer('2026-03-15', [])->getContent();

        self::assertStringContainsString('value="15/03/2026"', $html, 'Date d\'achat = date de facture');
        self::assertStringContainsString('min="2026-03-15"', $html);
        self::assertStringContainsString("qu'<strong>une seule fois</strong>", $html);
    }

    public function testFormulaireVerrouille(): void
    {
        $html = (string) $this->editer('2026-04-01', [])->getContent();

        self::assertStringContainsString('ne peut plus l\'être', $html);
        self::assertStringNotContainsString('name="date_mise_en_service"', $html);
    }

    #[DataProvider('verrous')]
    public function testRegleDeVerrouillage(?string $dms, ?string $facture, bool $attendu): void
    {
        self::assertSame($attendu, EquipementController::dateMiseEnServiceVerrouillee(['date_mise_en_service' => $dms], $facture));
    }

    public static function verrous(): array
    {
        return [
            'vide' => [null, '2026-03-15', false],
            'égale à la facture' => ['2026-03-15', '2026-03-15', false],
            'déjà modifiée' => ['2026-04-01', '2026-03-15', true],
            'sans facture connue' => ['2026-04-01', null, true],
        ];
    }
}
