<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\RegroupementLot;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-24] Regroupement d'équipements identiques en lot.
 */
final class RegroupementLotTest extends TestCase
{
    private const DEPART = [
        'id' => 4, 'acquisition_id' => 9, 'categorie_id' => 2, 'libelle' => 'Mousqueton', 'reference' => '24MOU001',
        'statut' => 0, 'controle_en_cours' => 0, 'nombre' => 3, 'nombre_initial' => 4,
    ];

    public function testIdentiques(): void
    {
        $autre = ['id' => 5, 'libelle' => ' Mousqueton '] + self::DEPART;

        self::assertTrue(RegroupementLot::identiques(self::DEPART, $autre));
        self::assertFalse(RegroupementLot::identiques(self::DEPART, self::DEPART), 'lui-même');
        foreach (['acquisition_id' => 8, 'categorie_id' => 3, 'libelle' => 'Mousqueton HMS'] as $champ => $valeur) {
            self::assertFalse(RegroupementLot::identiques(self::DEPART, [$champ => $valeur] + $autre), $champ);
        }
    }

    public function testEquipementRegroupable(): void
    {
        self::assertNull(RegroupementLot::erreurEquipement(self::DEPART, false));
        self::assertNull(RegroupementLot::erreurEquipement(['id' => 5] + self::DEPART, false, self::DEPART));
    }

    public function testEquipementNonRegroupable(): void
    {
        self::assertSame('contrôle en cours', RegroupementLot::erreurEquipement(['controle_en_cours' => 1] + self::DEPART, false));
        self::assertSame('inscrit sur un prêt', RegroupementLot::erreurEquipement(self::DEPART, true));
        self::assertStringContainsString('statut', (string) RegroupementLot::erreurEquipement(['statut' => 4] + self::DEPART, false));
        self::assertSame('statut différent', RegroupementLot::erreurEquipement(['statut' => 1] + self::DEPART, false, self::DEPART));
    }

    public function testRegrouperAdditionneEtArchive(): void
    {
        $manager = $this->createMock(EquipementManager::class);
        $manager->expects(self::once())->method('beginTransaction');
        $manager->expects(self::once())->method('save')->with(['id' => 4, 'nombre' => 6, 'nombre_initial' => 7]);
        $archives = [];
        $manager->method('delete')->willReturnCallback(function (int $id) use (&$archives) {
            $archives[] = $id;
            return true;
        });
        $manager->expects(self::once())->method('commit');

        (new RegroupementLot($manager))->regrouper(self::DEPART, [
            ['id' => 5, 'nombre' => 1, 'nombre_initial' => 1],
            ['id' => 6, 'nombre' => 2, 'nombre_initial' => 2],
        ]);

        self::assertSame([5, 6], $archives);
    }

    public function testEchecAnnuleLaTransaction(): void
    {
        $manager = $this->createMock(EquipementManager::class);
        $manager->method('delete')->willThrowException(new \RuntimeException('panne'));
        $manager->expects(self::once())->method('rollBack');
        $manager->expects(self::never())->method('commit');

        $this->expectException(\RuntimeException::class);
        (new RegroupementLot($manager))->regrouper(self::DEPART, [['id' => 5, 'nombre' => 1, 'nombre_initial' => 1]]);
    }
}
