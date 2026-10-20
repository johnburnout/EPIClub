<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Tests\Unit\Domain\Support\TestableReferenceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-20] Proposition de référence à la saisie d'une ligne
 * d'acquisition : n° de suivi suivant, en tenant compte des
 * équipements existants et des lignes en attente.
 */
final class ReferenceGeneratorSuggestTest extends TestCase
{
    private function generateur(
        ?array $categorie,
        array $refsEquipements,
        array $lignes,
        array $toutesCategories = [],
    ): TestableReferenceGenerator {
        $g = new TestableReferenceGenerator();

        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['ref_modele' => 'YYCATNNN']);
        $g->clubManagerMock = $club;

        $cat = $this->createMock(CategorieManager::class);
        $cat->method('findOneByCriteria')->willReturn($categorie);
        $cat->method('findId')->willReturn($categorie);
        $cat->method('findAll')->willReturn($toutesCategories);
        $g->categorieManagerMock = $cat;

        $eq = $this->createMock(EquipementManager::class);
        $eq->method('findReferencesMatching')->willReturn($refsEquipements);
        $g->equipementManagerMock = $eq;

        $lm = $this->createMock(AcquisitionLigneManager::class);
        $lm->method('findReferencesMatching')->willReturn($lignes);
        $g->acquisitionLigneManagerMock = $lm;

        return $g;
    }

    public function testPremiereReferenceDeLAnnee(): void
    {
        $g = $this->generateur(['id' => 3, 'libelle' => 'Cordes', 'code' => 'COR'], [], []);

        self::assertSame('26COR001', $g->suggest('Cordes', 2026));
    }

    public function testSuitLePlusGrandNumeroDesEquipements(): void
    {
        $g = $this->generateur(['id' => 3, 'libelle' => 'Cordes', 'code' => 'COR'], ['26COR001', '26COR007', '26COR003'], []);

        self::assertSame('26COR008', $g->suggest('Cordes', 2026));
    }

    public function testTientCompteDesLignesEnAttente(): void
    {
        $g = $this->generateur(
            ['id' => 3, 'libelle' => 'Cordes', 'code' => 'COR'],
            ['26COR002'],
            [
                // ligne en attente de 4 unités hors lot : réserve 005 à 008
                ['reference' => '26COR005', 'nombre' => 4, 'regrouper_en_lot' => 0, 'equipements_generes' => 0],
                // lot en attente : réserve un seul n°
                ['reference' => '26COR003', 'nombre' => 10, 'regrouper_en_lot' => 1, 'equipements_generes' => 0],
            ]
        );

        self::assertSame('26COR009', $g->suggest('Cordes', 2026));
    }

    public function testLigneDejaGenereeNeReserveQueSonNumero(): void
    {
        $g = $this->generateur(
            ['id' => 3, 'libelle' => 'Cordes', 'code' => 'COR'],
            [],
            [['reference' => '26COR004', 'nombre' => 5, 'regrouper_en_lot' => 0, 'equipements_generes' => 1]]
        );

        self::assertSame('26COR005', $g->suggest('Cordes', 2026));
    }

    public function testCategorieACreerUtiliseLeCodeSuggere(): void
    {
        $g = $this->generateur(null, [], [], [['id' => 1, 'code' => 'CAS']]);

        self::assertSame('26CA1001', $g->suggest('Casques', 2026));
    }

    public function testCompteurSiConforme(): void
    {
        $g = $this->generateur(['id' => 3, 'libelle' => 'Cordes', 'code' => 'COR'], [], []);

        self::assertSame(12, $g->compteurSiConforme('26COR012', 3, 2026));
        self::assertNull($g->compteurSiConforme('25COR012', 3, 2026), 'Autre année');
        self::assertNull($g->compteurSiConforme('26CAS012', 3, 2026), 'Autre catégorie');
        self::assertNull($g->compteurSiConforme('PETZL-123', 3, 2026), 'Référence libre');
        self::assertSame('26COR013', $g->referenceAuRang(3, 2026, 13));
    }
}
