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
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-23] Résumé des filtres du PDF de liste : un paramètre
 * « categorie » / « emplacement » non numérique (dont l'option
 * « Non défini » = emplacement=null) ne fait plus échouer le PDF.
 */
final class EquipementControllerPdfListeFiltresTest extends TestCase
{
    private function filtres(array $valeurs): string
    {
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findId')->willReturnCallback(
            fn (int $id) => $id === 1 ? ['id' => 1, 'libelle' => 'Casques'] : null
        );
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findId')->willReturnCallback(
            fn (int $id) => $id === 2 ? ['id' => 2, 'libelle' => 'Armoire'] : null
        );
        $controller = new TestableEquipementController(
            new Session(new MockArraySessionStorage()),
            $this->createMock(EquipementManager::class),
            $categories,
            $emplacements,
            $this->createMock(AcquisitionManager::class),
        );

        return (new \ReflectionMethod(TestableEquipementController::class, 'renderPdfListHtml'))
            ->invoke($controller, [], $valeurs + [
                'categorie' => null, 'epi' => null, 'en_service' => null,
                'emplacement' => null, 'dernier_controle' => null, 'acquisition' => null,
            ]);
    }

    public function testIdsNumeriquesAffichentLesLibelles(): void
    {
        $html = $this->filtres(['categorie' => '1', 'emplacement' => '2']);

        self::assertStringContainsString('Catégorie : Casques', $html);
        self::assertStringContainsString('Emplacement : Armoire', $html);
    }

    public function testEmplacementNonDefini(): void
    {
        self::assertStringContainsString('Emplacement : Non défini', $this->filtres(['emplacement' => 'null']));
    }

    public function testValeursNonNumeriquesAfficheesTellesQuelles(): void
    {
        $html = $this->filtres(['categorie' => 'abc', 'emplacement' => '2x']);

        self::assertStringContainsString('Catégorie : abc', $html);
        self::assertStringContainsString('Emplacement : 2x', $html);
    }

    public function testAucunFiltre(): void
    {
        self::assertStringContainsString('Aucun filtre', $this->filtres([]));
    }
}
