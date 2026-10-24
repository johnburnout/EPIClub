<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\DissociationLot;
use Epiclub\Domain\EquipementManager;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-24] Dissociation d'un lot : répartition, équipements
 * dissociables, écriture transactionnelle.
 */
final class DissociationLotTest extends TestCase
{
    private const LOT = [
        'id' => 4, 'acquisition_id' => 9, 'categorie_id' => 2, 'reference' => '24COR003', 'libelle' => 'Mousqueton',
        'code' => '2024-009-001-001', 'statut' => 1, 'remarques' => 'vu', 'date_dernier_controle' => '2026-01-10',
        'controle_en_cours' => 0, 'emplacement_id' => 3, 'date_mise_en_service' => '2024-05-14',
        'date_fin_utilisation' => null, 'nombre' => 10, 'nombre_initial' => 12, 'est_epi' => 1, 'photo' => '/p.webp',
        'duree_max_utilisation' => 1, 'textile_remplacable' => 0, 'deleted_at' => null, 'date_egare' => null,
    ];

    // ---------------- Répartition ----------------

    public function testRepartitionValide(): void
    {
        self::assertSame([4, 3, 3], DissociationLot::parties('4, 3, 3', 10));
        self::assertSame([4, 3, 3], DissociationLot::parties(' 4+3 ;3 ', 10));
        self::assertSame(array_fill(0, 10, 1), DissociationLot::parties(implode(',', array_fill(0, 10, '1')), 10));
    }

    public function testRepartitionInvalide(): void
    {
        foreach (['', '10', '4, 3', '4, 3, 4', '5, 0, 5', '5, -1, 6', '5, 2.5, 2.5', '5, a, 5'] as $saisie) {
            self::assertIsString(DissociationLot::parties($saisie, 10), "« $saisie »");
        }
        self::assertStringContainsString('(7)', (string) DissociationLot::parties('4, 3', 10));
    }

    // ---------------- Équipements dissociables ----------------

    public function testEquipementDissociable(): void
    {
        self::assertNull(DissociationLot::erreurEquipement(self::LOT));
    }

    public function testEquipementNonDissociable(): void
    {
        $cas = [
            'pas un lot' => ['nombre' => 1, 'nombre_initial' => 1],
            'un seul élément restant' => ['nombre' => 1],
            'contrôle en cours' => ['controle_en_cours' => 1],
            'prêté' => ['statut' => 3],
            'égaré' => ['statut' => 4],
            'hors service' => ['statut' => 2],
        ];
        foreach ($cas as $nom => $modif) {
            self::assertNotNull(DissociationLot::erreurEquipement($modif + self::LOT), $nom);
        }
    }

    // ---------------- Écriture ----------------

    public function testDissocierEcritOrigineEtNouveauxEquipements(): void
    {
        $sauves = [];
        $manager = $this->createMock(EquipementManager::class);
        $manager->method('codeExists')->willReturnCallback(fn (string $c) => $c === '2024-009-001-001-3');
        $manager->method('save')->willReturnCallback(function (array $e) use (&$sauves) {
            $sauves[] = $e;
            return (string) (100 + count($sauves));
        });
        $manager->expects(self::once())->method('beginTransaction');
        $manager->expects(self::once())->method('commit');
        $manager->expects(self::never())->method('rollBack');

        $ids = (new DissociationLot($manager))->dissocier(self::LOT, [4, 3, 1, 2], ['24COR010', '24COR011', '24COR012']);

        self::assertSame([102, 103, 104], $ids);
        self::assertSame(['id' => 4, 'nombre' => 4, 'nombre_initial' => 4], $sauves[0]);
        self::assertSame(
            [['24COR010', '2024-009-001-001-2', 3], ['24COR011', '2024-009-001-001-4', 1], ['24COR012', '2024-009-001-001-5', 2]],
            array_map(fn ($e) => [$e['reference'], $e['code'], $e['nombre']], array_slice($sauves, 1))
        );
        $nouveau = $sauves[1];
        self::assertSame(3, $nouveau['nombre_initial']);
        self::assertSame(0, $nouveau['controle_en_cours']);
        foreach (['acquisition_id', 'categorie_id', 'libelle', 'statut', 'remarques', 'date_dernier_controle',
                  'emplacement_id', 'date_mise_en_service', 'photo', 'est_epi', 'duree_max_utilisation'] as $champ) {
            self::assertSame(self::LOT[$champ], $nouveau[$champ], $champ);
        }
        self::assertArrayNotHasKey('id', $nouveau);
        self::assertArrayNotHasKey('deleted_at', $nouveau);
    }

    public function testEchecAnnuleLaTransaction(): void
    {
        $manager = $this->createMock(EquipementManager::class);
        $manager->method('codeExists')->willReturn(false);
        $manager->method('save')->willReturnOnConsecutiveCalls(
            true,
            $this->throwException(new \PDOException('Duplicate entry', 23000))
        );
        $manager->expects(self::once())->method('rollBack');
        $manager->expects(self::never())->method('commit');

        $this->expectException(\PDOException::class);
        (new DissociationLot($manager))->dissocier(self::LOT, [5, 5], ['24COR010']);
    }

    public function testUneReferenceParNouvelEquipement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DissociationLot($this->createMock(EquipementManager::class)))->dissocier(self::LOT, [5, 3, 2], ['24COR010']);
    }
}
