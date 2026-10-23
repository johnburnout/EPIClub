<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-23] Formulaire de modification d'un équipement :
 *  - un contrôleur y modifie l'emplacement (et rien d'autre) ;
 *  - un super-administrateur y modifie la référence (unicité vérifiée à
 *    la volée et côté serveur, mot de confirmation REFERENCE).
 */
final class EquipementControllerFormEmplacementReferenceTest extends TestCase
{
    private Session $session;
    private ?array $enregistre = null;
    /** @var string[] Références déjà prises par d'autres équipements */
    private array $prises = ['PRISE'];
    private ?\Throwable $erreurSave = null;

    private function controller(string $role): TestableEquipementController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $id === 4 ? [
            'id' => 4, 'reference' => 'REF-4', 'libelle' => 'Casque', 'emplacement_id' => 2,
            'statut' => 0, 'nombre_initial' => 1, 'remarques' => 'rien', 'acquisition_id' => null,
        ] : null);
        $equipements->method('referenceExists')->willReturnCallback(
            fn (string $ref, ?int $sauf = null) => in_array($ref, $this->prises, true)
        );
        $equipements->method('save')->willReturnCallback(function (array $e) {
            if ($this->erreurSave !== null) {
                throw $this->erreurSave;
            }
            $this->enregistre = $e;
            return true;
        });
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findId')->willReturnCallback(
            fn (int $id) => in_array($id, [2, 3], true) ? ['id' => $id, 'libelle' => 'Armoire ' . $id] : null
        );
        $emplacements->method('findAll')->willReturn([
            ['id' => 2, 'libelle' => 'Armoire 2'], ['id' => 3, 'libelle' => 'Armoire 3'],
        ]);
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);

        return new TestableEquipementController(
            $this->session, $equipements, $categories, $emplacements,
            $this->createMock(AcquisitionManager::class),
        );
    }

    private function editer(string $role, array $post = [], string $csrf = 'csrf'): Response
    {
        $request = $post === []
            ? Request::create('/equipements/equipement_modification-4')
            : Request::create('/equipements/equipement_modification-4', 'POST', $post + ['csrf_token' => $csrf]);
        $request->attributes->set('id', '4');
        return $this->controller($role)->edit($request);
    }

    private function flashes(string $type): array
    {
        return $this->session->getFlashBag()->peek($type);
    }

    // ---------------- Contrôleur : emplacement seul ----------------

    public function testControleurOuvreLeFormulaireEnModeEmplacementSeul(): void
    {
        $html = (string) $this->editer('ROLE_CONTROLLEUR')->getContent();

        self::assertStringContainsString("uniquement l'<strong>emplacement</strong>", $html);
        self::assertStringContainsString('<fieldset disabled>', $html);
        self::assertStringContainsString('name="emplacement_id"', $html);
    }

    public function testAdminNEstPasEnModeEmplacementSeul(): void
    {
        $html = (string) $this->editer('ROLE_ADMIN')->getContent();

        self::assertStringNotContainsString('<fieldset disabled>', $html);
        self::assertStringNotContainsString("uniquement l'<strong>emplacement</strong>", $html);
    }

    public function testControleurDeplaceLEquipement(): void
    {
        $response = $this->editer('ROLE_CONTROLLEUR', ['emplacement_id' => '3']);

        self::assertSame(['id' => 4, 'emplacement_id' => 3], $this->enregistre);
        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
        self::assertStringContainsString('Armoire 3', $this->flashes('success')[0]);
    }

    public function testControleurRetireLEmplacement(): void
    {
        $this->editer('ROLE_CONTROLLEUR', ['emplacement_id' => '']);

        self::assertSame(['id' => 4, 'emplacement_id' => null], $this->enregistre);
    }

    public function testControleurNeModifiePasLesAutresChamps(): void
    {
        $this->editer('ROLE_CONTROLLEUR', [
            'emplacement_id' => '3', 'statut' => '1', 'remarques' => 'forgé',
            'libelle' => 'forgé', 'reference' => 'FORGE', 'confirmation_reference' => 'REFERENCE',
        ]);

        self::assertSame(['id' => 4, 'emplacement_id' => 3], $this->enregistre);
    }

    public function testEmplacementInconnuOuForgeRefuse(): void
    {
        foreach (['99', '3 OR 1=1'] as $valeur) {
            $response = $this->editer('ROLE_CONTROLLEUR', ['emplacement_id' => $valeur]);
            self::assertNull($this->enregistre, $valeur);
            self::assertSame('/equipements/equipement_modification-4', $response->headers->get('Location'));
        }
    }

    public function testUtilisateurNAccedePasAuFormulaire(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->editer('ROLE_USER');
    }

    public function testControleurSansCsrfRefuse(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->editer('ROLE_CONTROLLEUR', ['emplacement_id' => '3'], 'faux');
    }

    // ---------------- Super-administrateur : référence ----------------

    public function testChampReferenceEditableSeulementPourLeSuperAdmin(): void
    {
        self::assertStringContainsString('name="reference"', (string) $this->editer('ROLE_SUPER_ADMIN')->getContent());
        self::assertStringNotContainsString('name="reference"', (string) $this->editer('ROLE_ADMIN')->getContent());
    }

    public function testSuperAdminChangeLaReference(): void
    {
        $response = $this->editer('ROLE_SUPER_ADMIN', [
            'statut' => '0', 'reference' => '  26COR042 ', 'confirmation_reference' => 'REFERENCE',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('26COR042', $this->enregistre['reference']);
        self::assertStringContainsString('26COR042', $this->flashes('success')[0]);
    }

    public function testReferenceInchangeeSansMotDeConfirmation(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', ['statut' => '0', 'reference' => 'REF-4', 'remarques' => 'ok']);

        self::assertSame('REF-4', $this->enregistre['reference']);
        self::assertSame('ok', $this->enregistre['remarques']);
    }

    public function testSansMotDeConfirmationRefuse(): void
    {
        foreach (['', 'reference', 'REF'] as $mot) {
            $response = $this->editer('ROLE_SUPER_ADMIN', [
                'statut' => '0', 'reference' => '26COR042', 'confirmation_reference' => $mot,
            ]);
            self::assertNull($this->enregistre, "confirmation « $mot »");
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('saisissez le mot REFERENCE', (string) $response->getContent());
            // La saisie est conservée dans le formulaire
            self::assertStringContainsString('value="26COR042"', (string) $response->getContent());
        }
    }

    public function testReferenceDejaPriseRefusee(): void
    {
        $response = $this->editer('ROLE_SUPER_ADMIN', [
            'statut' => '0', 'reference' => 'PRISE', 'confirmation_reference' => 'REFERENCE',
        ]);

        self::assertNull($this->enregistre);
        self::assertStringContainsString('déjà attribuée', (string) $response->getContent());
    }

    public function testReferenceVideOuTropLongueRefusee(): void
    {
        foreach (['', str_repeat('A', 33)] as $ref) {
            $this->editer('ROLE_SUPER_ADMIN', ['statut' => '0', 'reference' => $ref, 'confirmation_reference' => 'REFERENCE']);
            self::assertNull($this->enregistre);
        }
    }

    public function testAdminNePeutPasChangerLaReference(): void
    {
        $this->editer('ROLE_ADMIN', ['statut' => '0', 'reference' => 'FORGE', 'confirmation_reference' => 'REFERENCE']);

        self::assertSame('REF-4', $this->enregistre['reference']);
    }

    public function testConflitDeContrainteUniqueGere(): void
    {
        $this->erreurSave = new \PDOException('Duplicate entry', 23000);

        $response = $this->editer('ROLE_SUPER_ADMIN', [
            'statut' => '0', 'reference' => 'COURSE', 'confirmation_reference' => 'REFERENCE',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('déjà attribuée', (string) $response->getContent());
    }

    // ---------------- Vérification à la volée ----------------

    private function disponible(string $role, string $reference): array
    {
        $response = $this->controller($role)->referenceDisponible(
            Request::create('/equipements/reference-disponible', 'GET', ['id' => '4', 'reference' => $reference])
        );
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getContent(), true);
    }

    public function testVerificationALaVolee(): void
    {
        self::assertTrue($this->disponible('ROLE_SUPER_ADMIN', 'LIBRE')['disponible']);
        self::assertFalse($this->disponible('ROLE_SUPER_ADMIN', 'PRISE')['disponible']);
        self::assertSame("C'est la référence actuelle.", $this->disponible('ROLE_SUPER_ADMIN', 'REF-4')['message']);
        self::assertFalse($this->disponible('ROLE_SUPER_ADMIN', '')['disponible']);
        self::assertFalse($this->disponible('ROLE_SUPER_ADMIN', str_repeat('A', 33))['disponible']);
    }

    public function testVerificationReserveeAuSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->disponible('ROLE_ADMIN', 'LIBRE');
    }

    public function testFormatReference(): void
    {
        self::assertNull(EquipementController::erreurFormatReference('26COR001'));
        self::assertNull(EquipementController::erreurFormatReference(str_repeat('É', 32)));
        self::assertNotNull(EquipementController::erreurFormatReference("A\tB"));
        self::assertNotNull(EquipementController::erreurFormatReference("\xC3"));
    }
}
