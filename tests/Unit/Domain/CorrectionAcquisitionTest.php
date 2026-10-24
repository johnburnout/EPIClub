<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CorrectionAcquisition;
use Epiclub\Domain\EquipementManager;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-24] Correction de la référence et de la date d'une acquisition :
 * dates des équipements réalignées, dates manuelles réinterrogées.
 */
final class CorrectionAcquisitionTest extends TestCase
{
    /** Facture du 14/05/2024 : fin de vie calculée = 31/05/2034. */
    private const EQUIPEMENTS = [
        // Dates calculées : suivent la facture.
        ['id' => 1, 'reference' => 'A', 'libelle' => 'Casque', 'date_mise_en_service' => '2024-05-14', 'date_fin_utilisation' => '2034-05-31'],
        // Sans péremption, mise en service vide.
        ['id' => 2, 'reference' => 'B', 'libelle' => 'Tapis', 'date_mise_en_service' => null, 'date_fin_utilisation' => null],
        // Dates saisies à la main.
        ['id' => 3, 'reference' => 'C', 'libelle' => 'Corde', 'date_mise_en_service' => '2024-06-01', 'date_fin_utilisation' => '2030-01-31'],
    ];

    private function lignes(): array
    {
        return CorrectionAcquisition::classer(self::EQUIPEMENTS, '2024-05-14 00:00:00');
    }

    public function testClassement(): void
    {
        $l = $this->lignes();

        self::assertSame([false, false], [$l[0]['mes_manuelle'], $l[0]['fin_manuelle']]);
        self::assertSame([false, false], [$l[1]['mes_manuelle'], $l[1]['fin_manuelle']]);
        self::assertSame([true, true], [$l[2]['mes_manuelle'], $l[2]['fin_manuelle']]);
    }

    public function testDatesCalculeesReallignees(): void
    {
        $modifs = CorrectionAcquisition::nouvellesDates($this->lignes(), '2024-04-02',
            ['3' => '2024-06-01'], ['3' => '2030-01-31']);

        self::assertSame([
            1 => ['date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => '2034-04-30'],
            2 => ['date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => null],
        ], $modifs);
    }

    public function testDatesManuellesReinterrogees(): void
    {
        $modifs = CorrectionAcquisition::nouvellesDates($this->lignes(), '2024-04-02',
            ['3' => '2024-04-02'], ['3' => '2034-04-30']);

        self::assertSame(['date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => '2034-04-30'], $modifs[3]);
    }

    public function testDateManuelleAbsenteOuInvalideRefusee(): void
    {
        self::assertStringContainsString('à confirmer', (string) CorrectionAcquisition::nouvellesDates(
            $this->lignes(), '2024-04-02', [], ['3' => '2030-01-31']));
        self::assertStringContainsString('à confirmer', (string) CorrectionAcquisition::nouvellesDates(
            $this->lignes(), '2024-04-02', ['3' => '2024-06-01'], ['3' => '31/01/2030']));
    }

    public function testMiseEnServiceAvantLaNouvelleDateRefusee(): void
    {
        $erreur = CorrectionAcquisition::nouvellesDates($this->lignes(), '2024-07-01',
            ['3' => '2024-06-01'], ['3' => '2030-01-31']);

        self::assertIsString($erreur);
        self::assertStringContainsString('ne peut pas précéder', $erreur);
    }

    public function testReference(): void
    {
        self::assertNull(CorrectionAcquisition::erreurReference('FAC-2024-001'));
        foreach (['', str_repeat('A', 33), "A\tB", "\xC3"] as $ref) {
            self::assertNotNull(CorrectionAcquisition::erreurReference($ref));
        }
    }

    public function testAppliquerEnTransaction(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->expects(self::once())->method('save')
            ->with(['id' => 9, 'facture_reference' => 'FAC-2', 'facture_date' => '2024-04-02']);
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->expects(self::once())->method('beginTransaction');
        $equipements->expects(self::once())->method('save')
            ->with(['id' => 1, 'date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => null]);
        $equipements->expects(self::once())->method('commit');

        (new CorrectionAcquisition($acquisitions, $equipements))->appliquer(9, 'FAC-2', '2024-04-02',
            [1 => ['date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => null]]);
    }

    public function testEchecSurLAcquisitionAnnuleLesEquipements(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('save')->willThrowException(new \PDOException('Duplicate entry', 23000));
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->expects(self::once())->method('rollBack');
        $equipements->expects(self::never())->method('commit');

        $this->expectException(\PDOException::class);
        (new CorrectionAcquisition($acquisitions, $equipements))->appliquer(9, 'FAC-2', '2024-04-02', []);
    }
}
