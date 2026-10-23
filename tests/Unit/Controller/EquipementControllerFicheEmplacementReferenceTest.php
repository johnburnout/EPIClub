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
 * [2026-10-23] Depuis la fiche équipement :
 *  - un contrôleur (et plus) change l'emplacement ;
 *  - un super-administrateur change la référence (unicité vérifiée à la
 *    volée et côté serveur, mot de confirmation REFERENCE).
 */
final class EquipementControllerFicheEmplacementReferenceTest extends TestCase
{
    private Session $session;
    private ?array $enregistre = null;
    /** @var string[] Références déjà prises (hors équipement courant) */
    private array $prises = ['PRISE'];

    private function controller(string $role): TestableEquipementController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(
            fn (int $id) => $id === 4 ? ['id' => 4, 'reference' => 'REF-4', 'libelle' => 'Casque', 'emplacement_id' => 2] : null
        );
        $equipements->method('referenceExists')->willReturnCallback(
            fn (string $ref, ?int $sauf = null) => in_array($ref, $this->prises, true)
        );
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->enregistre = $e;
            return true;
        });
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findId')->willReturnCallback(
            fn (int $id) => in_array($id, [2, 3], true) ? ['id' => $id, 'libelle' => 'Armoire ' . $id] : null
        );

        return new TestableEquipementController(
            $this->session,
            $equipements,
            $this->createMock(CategorieManager::class),
            $emplacements,
            $this->createMock(AcquisitionManager::class),
        );
    }

    private function post(string $uri, array $data): Request
    {
        $request = Request::create($uri, 'POST', $data + ['csrf_token' => 'csrf']);
        $request->attributes->set('id', '4');
        return $request;
    }

    private function flashes(string $type): array
    {
        return $this->session->getFlashBag()->peek($type);
    }

    // ---------------- Emplacement ----------------

    public function testControleurDeplaceLEquipement(): void
    {
        $response = $this->controller('ROLE_CONTROLLEUR')
            ->changerEmplacement($this->post('/equipements/equipement_emplacement-4', ['emplacement_id' => '3']));

        self::assertSame(['id' => 4, 'emplacement_id' => 3], $this->enregistre);
        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
        self::assertStringContainsString('Armoire 3', $this->flashes('success')[0]);
    }

    public function testRetraitDeLEmplacement(): void
    {
        $this->controller('ROLE_CONTROLLEUR')
            ->changerEmplacement($this->post('/equipements/equipement_emplacement-4', ['emplacement_id' => '']));

        self::assertSame(['id' => 4, 'emplacement_id' => null], $this->enregistre);
    }

    public function testEmplacementInconnuRefuse(): void
    {
        $this->controller('ROLE_CONTROLLEUR')
            ->changerEmplacement($this->post('/equipements/equipement_emplacement-4', ['emplacement_id' => '99']));

        self::assertNull($this->enregistre);
        self::assertSame(['Emplacement invalide.'], $this->flashes('error'));
    }

    public function testEmplacementForgeRefuse(): void
    {
        $this->controller('ROLE_CONTROLLEUR')
            ->changerEmplacement($this->post('/equipements/equipement_emplacement-4', ['emplacement_id' => '3 OR 1=1']));

        self::assertNull($this->enregistre);
    }

    public function testUtilisateurNePeutPasDeplacer(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller('ROLE_USER')
            ->changerEmplacement($this->post('/equipements/equipement_emplacement-4', ['emplacement_id' => '3']));
    }

    public function testDeplacementSansCsrfRefuse(): void
    {
        $this->expectException(AccessDeniedException::class);
        $request = Request::create('/equipements/equipement_emplacement-4', 'POST', ['emplacement_id' => '3', 'csrf_token' => 'faux']);
        $request->attributes->set('id', '4');
        $this->controller('ROLE_CONTROLLEUR')->changerEmplacement($request);
    }

    // ---------------- Référence ----------------

    public function testSuperAdminChangeLaReference(): void
    {
        $response = $this->controller('ROLE_SUPER_ADMIN')->changerReference(
            $this->post('/equipements/equipement_reference-4', ['reference' => '  26COR042 ', 'confirmation' => 'REFERENCE'])
        );

        self::assertSame(['id' => 4, 'reference' => '26COR042'], $this->enregistre);
        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
    }

    public function testSansMotDeConfirmationRefuse(): void
    {
        foreach (['', 'reference', 'REF'] as $mot) {
            $this->controller('ROLE_SUPER_ADMIN')->changerReference(
                $this->post('/equipements/equipement_reference-4', ['reference' => '26COR042', 'confirmation' => $mot])
            );
            self::assertNull($this->enregistre, "confirmation « $mot »");
        }
    }

    public function testReferenceDejaPriseRefusee(): void
    {
        $this->controller('ROLE_SUPER_ADMIN')->changerReference(
            $this->post('/equipements/equipement_reference-4', ['reference' => 'PRISE', 'confirmation' => 'REFERENCE'])
        );

        self::assertNull($this->enregistre);
        self::assertStringContainsString('déjà attribuée', $this->flashes('error')[0]);
    }

    public function testReferenceTropLongueOuVideRefusee(): void
    {
        foreach (['', str_repeat('A', 33)] as $ref) {
            $this->controller('ROLE_SUPER_ADMIN')->changerReference(
                $this->post('/equipements/equipement_reference-4', ['reference' => $ref, 'confirmation' => 'REFERENCE'])
            );
            self::assertNull($this->enregistre);
        }
    }

    public function testAdminNePeutPasChangerLaReference(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller('ROLE_ADMIN')->changerReference(
            $this->post('/equipements/equipement_reference-4', ['reference' => '26COR042', 'confirmation' => 'REFERENCE'])
        );
    }

    public function testConflitDeContrainteUniqueGere(): void
    {
        $controller = $this->controller('ROLE_SUPER_ADMIN');
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn(['id' => 4, 'reference' => 'REF-4']);
        $equipements->method('referenceExists')->willReturn(false);
        $equipements->method('save')->willThrowException(new \PDOException('Duplicate entry', 23000));
        $controller = new TestableEquipementController(
            $this->session, $equipements,
            $this->createMock(CategorieManager::class),
            $this->createMock(EmplacementManager::class),
            $this->createMock(AcquisitionManager::class),
        );

        $response = $controller->changerReference(
            $this->post('/equipements/equipement_reference-4', ['reference' => 'COURSE', 'confirmation' => 'REFERENCE'])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('déjà attribuée', $this->flashes('error')[0]);
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
