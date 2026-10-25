<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-25] Liste des équipements filtrée par fournisseur (celui de
 * leur acquisition), depuis la liste ou la fiche d'un fournisseur.
 */
final class EquipementControllerFiltreFournisseurTest extends TestCase
{
    private function liste(string $query): string
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => 'ROLE_USER']);
        $session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findAll')->willReturn([
            ['id' => 1, 'reference' => 'EQ-ALPIN-1', 'libelle' => 'Casque', 'acquisition_id' => 10, 'statut' => 0, 'nombre' => 1, 'nombre_initial' => 1],
            ['id' => 2, 'reference' => 'EQ-ALPIN-2', 'libelle' => 'Corde', 'acquisition_id' => 11, 'statut' => 0, 'nombre' => 1, 'nombre_initial' => 1],
            ['id' => 3, 'reference' => 'EQ-AUTRE-1', 'libelle' => 'Longe', 'acquisition_id' => 12, 'statut' => 0, 'nombre' => 1, 'nombre_initial' => 1],
        ]);
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findAll')->willReturn([
            ['id' => 10, 'fournisseur_id' => 7], ['id' => 11, 'fournisseur_id' => 7], ['id' => 12, 'fournisseur_id' => 8],
        ]);
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);
        $fournisseurs = $this->createMock(FournisseurManager::class);
        $fournisseurs->method('findId')->willReturnCallback(fn (int $id) => $id === 7 ? ['id' => 7, 'nom' => 'Alpin Shop'] : null);

        $controller = new TestableEquipementController($session, $equipements, $categories, $emplacements, $acquisitions);
        $controller->fournisseurManagerOverride = $fournisseurs;

        return (string) $controller->list(Request::create('/equipements?' . $query))->getContent();
    }

    public function testFiltreParFournisseur(): void
    {
        $html = $this->liste('fournisseur=7');

        self::assertStringContainsString('EQ-ALPIN-1', $html);
        self::assertStringContainsString('EQ-ALPIN-2', $html);
        self::assertStringNotContainsString('EQ-AUTRE-1', $html);
        self::assertStringContainsString('Équipements du fournisseur <strong>Alpin Shop</strong>', $html);
        // Le filtre est conservé par le formulaire et la pagination
        self::assertStringContainsString('<input type="hidden" name="fournisseur" value="7">', $html);
    }

    public function testSansFiltreOuValeurInvalide(): void
    {
        foreach (['', 'fournisseur=abc', 'fournisseur=0'] as $query) {
            $html = $this->liste($query);
            self::assertStringContainsString('EQ-AUTRE-1', $html, $query);
            self::assertStringNotContainsString('Équipements du fournisseur', $html, $query);
        }
    }
}
