<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Formulaire de modification d'un équipement : un
 * super-administrateur y modifie le lot et le nombre initial (après la
 * roue dentée, sans mot de confirmation). Lot décoché ⇒ nombre initial
 * et nombre actuel ramenés à 1.
 */
final class EquipementControllerFormLotTest extends TestCase
{
    private ?array $enregistre = null;
    /** Équipement id=4 : lot de 10 dont 7 restants par défaut. */
    private array $equipement = [
        'id' => 4, 'reference' => '24COR003', 'libelle' => 'Mousqueton', 'categorie_id' => null,
        'emplacement_id' => null, 'statut' => 0, 'nombre' => 7, 'nombre_initial' => 10, 'acquisition_id' => null,
    ];

    private function editer(string $role, array $post = []): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $id === 4 ? $this->equipement : null);
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->enregistre = $e;
            return true;
        });
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);
        $controller = new TestableEquipementController(
            $session, $equipements, $categories, $emplacements,
            $this->createMock(AcquisitionManager::class),
        );

        $request = $post === []
            ? Request::create('/equipements/equipement_modification-4')
            : Request::create('/equipements/equipement_modification-4', 'POST', $post + ['csrf_token' => 'csrf', 'statut' => '0']);
        $request->attributes->set('id', '4');
        return $controller->edit($request);
    }

    private function nonLot(): void
    {
        $this->equipement['nombre'] = 1;
        $this->equipement['nombre_initial'] = 1;
    }

    /** Balise <input> d'id donné dans le HTML. */
    private static function champ(string $html, string $id): string
    {
        self::assertSame(1, preg_match('/<input[^>]*\bid="' . $id . '"[^>]*>/', $html, $m), $id);
        return $m[0];
    }

    // ---------------- Formulaire ----------------

    public function testLotVerrouilleJusquALaRoueDentee(): void
    {
        $html = (string) $this->editer('ROLE_SUPER_ADMIN')->getContent();

        self::assertStringContainsString('Modifier la référence, la catégorie et le lot', $html);
        foreach (['lot', 'lot_defaut', 'nombre_initial'] as $id) {
            self::assertMatchesRegularExpression('/\sdisabled\b/', self::champ($html, $id), $id);
        }
        self::assertMatchesRegularExpression('/\schecked\b/', self::champ($html, 'lot'));
        self::assertStringContainsString('value="10"', self::champ($html, 'nombre_initial'));
        // Le nombre actuel reste modifiable sans la roue dentée.
        self::assertDoesNotMatchRegularExpression('/\sdisabled\b/', self::champ($html, 'nombre'));
    }

    public function testNonLotSuperAdminCaseDecocheeEtNombresMasques(): void
    {
        $this->nonLot();
        $html = (string) $this->editer('ROLE_SUPER_ADMIN')->getContent();

        self::assertDoesNotMatchRegularExpression('/\schecked\b/', self::champ($html, 'lot'));
        self::assertStringContainsString('class="mb-3 d-none" id="bloc-nombre-initial"', $html);
        self::assertStringContainsString('class="mb-3 d-none" id="bloc-nombre"', $html);
    }

    public function testAdminVoitLeNombreInitialEnLectureSeule(): void
    {
        $html = (string) $this->editer('ROLE_ADMIN')->getContent();

        self::assertStringNotContainsString('id="lot"', $html);
        self::assertStringNotContainsString('name="nombre_initial"', $html);
        self::assertStringContainsString('Nombre initial du lot', $html);
    }

    // ---------------- Enregistrement ----------------

    public function testLotDecocheRameneLesNombresAUn(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', ['lot' => '0', 'nombre' => '7']);

        self::assertSame(1, $this->enregistre['nombre_initial']);
        self::assertSame(1, $this->enregistre['nombre']);
    }

    public function testNombreInitialModifieSansMotDeConfirmation(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', ['lot' => '1', 'nombre_initial' => '12', 'nombre' => '9']);

        self::assertSame(12, $this->enregistre['nombre_initial']);
        self::assertSame(9, $this->enregistre['nombre']);
    }

    public function testNombreActuelPlafonneAuNouveauNombreInitial(): void
    {
        $response = $this->editer('ROLE_SUPER_ADMIN', ['lot' => '1', 'nombre_initial' => '5', 'nombre' => '7']);

        self::assertNull($this->enregistre);
        self::assertStringContainsString('entre 1 et 5', (string) $response->getContent());
    }

    public function testEquipementUniqueDevientUnLotComplet(): void
    {
        $this->nonLot();
        $this->editer('ROLE_SUPER_ADMIN', ['lot' => '1', 'nombre_initial' => '6']);

        self::assertSame(6, $this->enregistre['nombre_initial']);
        self::assertSame(6, $this->enregistre['nombre']);
    }

    public function testNombreInitialInvalideRefuse(): void
    {
        foreach (['1', '0', '', 'abc', '10000'] as $ni) {
            $response = $this->editer('ROLE_SUPER_ADMIN', ['lot' => '1', 'nombre_initial' => $ni, 'nombre' => '1']);
            self::assertNull($this->enregistre, "nombre initial « $ni »");
            $html = (string) $response->getContent();
            self::assertStringContainsString('Le nombre initial d&#039;un lot doit être compris entre 2 et 9999.', $html);
            // Champs rouverts au retour du serveur
            self::assertDoesNotMatchRegularExpression('/\sdisabled\b/', self::champ($html, 'nombre_initial'));
        }
    }

    public function testLotVerrouilleRienNeChange(): void
    {
        // Roue dentée non cliquée : ni « lot » ni « nombre_initial » postés.
        $this->editer('ROLE_SUPER_ADMIN', ['nombre' => '6']);

        self::assertArrayNotHasKey('nombre_initial', array_diff_assoc($this->enregistre, $this->equipement));
        self::assertSame(10, $this->enregistre['nombre_initial']);
        self::assertSame(6, $this->enregistre['nombre']);
    }

    public function testAdminNePeutPasModifierLeLot(): void
    {
        $this->editer('ROLE_ADMIN', ['lot' => '0', 'nombre_initial' => '50', 'nombre' => '7']);

        self::assertSame(10, $this->enregistre['nombre_initial']);
        self::assertSame(7, $this->enregistre['nombre']);
    }
}
