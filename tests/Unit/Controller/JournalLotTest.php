<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\JournalActiviteManager;
use PHPUnit\Framework\TestCase;

/** [2026-11-07] Libellés du journal d'activité pour les acquisitions et les lots. */
final class JournalLotTest extends TestCase
{
    public function testLibelles(): void
    {
        self::assertSame('acquisition n° 12 (facture FAC-1)', JournalActiviteManager::libelleAcquisition(['id' => 12, 'facture_reference' => 'FAC-1']));
        self::assertSame('acquisition n° 12', JournalActiviteManager::libelleAcquisition(['id' => '12']));
        self::assertSame('« 26BAU1 » (Baudrier)', JournalActiviteManager::libelleEquipement(['reference' => '26BAU1', 'libelle' => 'Baudrier']));
        self::assertSame('« 26BAU1 »', JournalActiviteManager::libelleEquipement(['reference' => '26BAU1', 'libelle' => ' ']));
    }

    public function testUneEntreeParAcquisitionImportee(): void
    {
        self::assertSame([
            'Import : acquisition n° 30 (facture FAC-9) créée et validée (3 lignes, 12 équipements générés)',
            'Import : acquisition n° 31 (facture FAC-10) créée et validée (1 ligne, 1 équipement généré)',
        ], \Epiclub\Controller\AcquisitionImportController::actionsJournalImport(['acquisitions' => [
            ['id' => 30, 'facture_reference' => 'FAC-9', 'nb_lignes' => 3, 'equipements_generes' => 12],
            ['id' => 31, 'facture_reference' => 'FAC-10', 'nb_lignes' => 1, 'equipements_generes' => 1],
        ]]));
    }

    public function testNouveauxTypesDisponiblesDansLeFiltre(): void
    {
        self::assertSame('Acquisition', JournalActiviteManager::TYPES['acquisition']);
        self::assertSame('Équipement', JournalActiviteManager::TYPES['equipement']);
        self::assertLessThanOrEqual(16, max(array_map('strlen', array_keys(JournalActiviteManager::TYPES))), 'colonne type VARCHAR(16)');
    }

    /**
     * @return iterable<string, array{array{nombre:int, nombre_initial:int}, array<string, int>, ?string}>
     */
    public static function changements(): iterable
    {
        yield 'rien'                => [['nombre' => 5, 'nombre_initial' => 5], ['nombre' => 5, 'nombre_initial' => 5], null];
        yield 'nombre actuel'       => [['nombre' => 5, 'nombre_initial' => 5], ['nombre' => 3, 'nombre_initial' => 5], 'Modification du lot (nombre actuel 5 → 3)'];
        yield 'transformation'      => [['nombre' => 1, 'nombre_initial' => 1], ['nombre' => 4, 'nombre_initial' => 4], 'Transformation en lot (nombre initial 1 → 4, nombre actuel 1 → 4)'];
        yield 'retour à l\'unité'   => [['nombre' => 2, 'nombre_initial' => 4], ['nombre' => 1, 'nombre_initial' => 1], 'Lot ramené à un élément unique (nombre initial 4 → 1, nombre actuel 2 → 1)'];
        yield 'champs non postés'   => [['nombre' => 2, 'nombre_initial' => 4], [], null];
    }

    /**
     * @param array{nombre:int, nombre_initial:int} $avant
     * @param array<string, int> $apres
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changements')]
    public function testChangementDeLot(array $avant, array $apres, ?string $attendu): void
    {
        self::assertSame($attendu, EquipementController::changementLot($avant, $apres));
    }
}
