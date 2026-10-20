<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [Tableau de bord] Filtre « fin_avant » de la liste des équipements :
 * cible du lien de la notification « EPI à renouveler ».
 */
final class EquipementControllerFinAvantTest extends TestCase
{
    private function lister(string $query): string
    {
        $equipementManager = $this->createMock(EquipementManager::class);
        $equipementManager->method('findAll')->willReturn([
            ['id' => 1, 'reference' => 'REF-ECHU', 'libelle' => 'Échu', 'date_fin_utilisation' => '2026-01-10', 'statut' => 0],
            ['id' => 2, 'reference' => 'REF-PROCHE', 'libelle' => 'Proche', 'date_fin_utilisation' => '2026-12-01', 'statut' => 0],
            ['id' => 3, 'reference' => 'REF-LOIN', 'libelle' => 'Loin', 'date_fin_utilisation' => '2030-01-01', 'statut' => 0],
            ['id' => 4, 'reference' => 'REF-SANS', 'libelle' => 'Sans date', 'date_fin_utilisation' => null, 'statut' => 0],
            ['id' => 5, 'reference' => 'REF-HS', 'libelle' => 'Réformé', 'date_fin_utilisation' => '2026-02-01', 'statut' => 2],
        ]);
        $categorieManager = $this->createMock(CategorieManager::class);
        $categorieManager->method('findAll')->willReturn([]);
        $emplacementManager = $this->createMock(EmplacementManager::class);
        $emplacementManager->method('findAll')->willReturn([]);

        $controller = (new TestableEquipementControllerBuilder($this))
            ->withEquipementManager($equipementManager)
            ->withCategorieManager($categorieManager)
            ->withEmplacementManager($emplacementManager)
            ->build();

        return (string) $controller->list(Request::create('/equipements?' . $query))->getContent();
    }

    public function testFiltreEtTriParDateDeFinCroissante(): void
    {
        $html = $this->lister('fin_avant=2027-01-20&order_by=date_fin&order_dir=asc');

        self::assertStringContainsString('REF-ECHU', $html);
        self::assertStringContainsString('REF-PROCHE', $html);
        self::assertStringNotContainsString('REF-LOIN', $html);
        self::assertStringNotContainsString('REF-SANS', $html);
        self::assertStringNotContainsString('REF-HS', $html, 'Les équipements « Hors service » sont exclus');
        self::assertLessThan(strpos($html, 'REF-PROCHE'), strpos($html, 'REF-ECHU'), 'Tri par date de fin croissante');
        self::assertStringContainsString('name="fin_avant" value="2027-01-20"', $html, 'Filtre conservé dans le formulaire');
        self::assertStringContainsString('20/01/2027', $html);
    }

    public function testDateMalFormeeIgnoree(): void
    {
        $html = $this->lister('fin_avant=2027-02-31');

        self::assertStringContainsString('REF-LOIN', $html);
        self::assertStringNotContainsString('name="fin_avant"', $html);
    }
}
