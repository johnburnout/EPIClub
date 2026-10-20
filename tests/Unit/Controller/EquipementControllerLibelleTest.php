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
 * [2026-10-20] Libellé d'un équipement : défini à l'acquisition,
 * modifiable uniquement par un super-administrateur.
 */
final class EquipementControllerLibelleTest extends TestCase
{
    private ?array $enregistre = null;

    private function editer(string $role, array $post = []): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn([
            'id' => 4, 'reference' => 'REF-4', 'libelle' => 'Casque', 'acquisition_id' => 42,
            'nombre_initial' => 1, 'date_mise_en_service' => '2026-03-15',
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
        $request = $post === []
            ? Request::create('/equipements/equipement_modification-4')
            : Request::create('/equipements/equipement_modification-4', 'POST', $post + ['csrf_token' => 'csrf', 'statut' => '0']);
        $request->attributes->set('id', '4');

        return $controller->edit($request);
    }

    public function testSuperAdminModifieLeLibelle(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', ['libelle' => '  Casque Petzl Boreo  ']);

        self::assertSame('Casque Petzl Boreo', $this->enregistre['libelle']);
    }

    public function testSuperAdminLibelleVideRefuse(): void
    {
        $response = $this->editer('ROLE_SUPER_ADMIN', ['libelle' => '   ']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->enregistre);
        self::assertStringContainsString('Le libellé est obligatoire.', (string) $response->getContent());
    }

    public function testAdminNePeutPasModifierLeLibelle(): void
    {
        $this->editer('ROLE_ADMIN', ['libelle' => 'Forgé']);

        self::assertSame('Casque', $this->enregistre['libelle']);
    }

    public function testChampEditableSeulementPourLeSuperAdmin(): void
    {
        self::assertStringContainsString('name="libelle" id="libelle" value="Casque"', (string) $this->editer('ROLE_SUPER_ADMIN')->getContent());
        self::assertStringNotContainsString('name="libelle" id="libelle" value="Casque"', (string) $this->editer('ROLE_ADMIN')->getContent());
    }
}
