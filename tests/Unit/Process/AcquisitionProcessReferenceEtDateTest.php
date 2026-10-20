<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Tests\Unit\Process\Support\TestableAcquisitionProcess;
use PHPUnit\Framework\TestCase;

/**
 * [2026-10-20] Validation d'une acquisition :
 *  - date de mise en service = date de facture ;
 *  - référence de ligne conforme au modèle → référence de la 1re unité,
 *    n° suivants pour les autres (repli auto si un n° est déjà pris) ;
 *  - référence libre / forcée → comportement inchangé.
 */
final class AcquisitionProcessReferenceEtDateTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $enregistres = [];

    private function executer(array $ligne, ReferenceGenerator $generateur, array $referencesPrises = []): void
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->method('findId')->willReturn($ligne);

        $acquisitionManager = $this->createMock(AcquisitionManager::class);
        $acquisitionManager->method('findId')->willReturn(['id' => 42, 'facture_date' => '2026-03-15']);

        $categorieManager = $this->createMock(CategorieManager::class);
        $categorieManager->method('findId')->willReturn(['id' => 3, 'est_epi' => 1]);

        $equipementManager = $this->createMock(EquipementManager::class);
        $equipementManager->method('codeExists')->willReturn(false);
        $equipementManager->method('referenceExists')
            ->willReturnCallback(fn(string $r) => in_array($r, $referencesPrises, true));
        $equipementManager->method('save')->willReturnCallback(function (array $e) {
            $this->enregistres[] = $e;
            return count($this->enregistres);
        });

        $process = new TestableAcquisitionProcess(
            $acquisitionManager,
            $ligneManager,
            $this->createMock(FournisseurManager::class),
            $categorieManager,
            $equipementManager,
            $generateur,
        );

        self::assertTrue($process->create_equipement_process(10));
    }

    private function ligne(array $surcharges = []): array
    {
        return $surcharges + [
            'id' => 10, 'acquisition_id' => 42, 'categorie_id' => 3,
            'reference' => '26COR004', 'designation' => 'Corde 9,8 mm',
            'nombre' => 3, 'regrouper_en_lot' => 0, 'equipements_generes' => 0,
            'reference_forcee' => null,
        ];
    }

    /** Générateur simulant le modèle YYCATNNN (catégorie COR). */
    private function generateur(?int $compteur, string $auto = '26COR099'): ReferenceGenerator
    {
        $g = $this->createMock(ReferenceGenerator::class);
        $g->method('compteurSiConforme')->willReturn($compteur);
        $g->method('referenceAuRang')->willReturnCallback(
            fn(int $cat, int $annee, int $n) => sprintf('%sCOR%03d', substr((string) $annee, -2), $n)
        );
        $g->method('generate')->willReturn($auto);
        return $g;
    }

    public function testDateDeMiseEnServiceEgaleALaDateDeFacture(): void
    {
        $this->executer($this->ligne(), $this->generateur(4));

        self::assertCount(3, $this->enregistres);
        foreach ($this->enregistres as $e) {
            self::assertSame('2026-03-15', $e['date_mise_en_service']);
        }
    }

    public function testReferenceProposeeDevientCelleDesUnitesSuccessives(): void
    {
        $this->executer($this->ligne(), $this->generateur(4));

        self::assertSame(['26COR004', '26COR005', '26COR006'], array_column($this->enregistres, 'reference'));
    }

    public function testNumeroDejaPrisRemplaceParUneReferenceAuto(): void
    {
        $this->executer($this->ligne(), $this->generateur(4, '26COR010'), ['26COR005']);

        self::assertSame(['26COR004', '26COR010', '26COR006'], array_column($this->enregistres, 'reference'));
    }

    public function testLotPrendLaReferenceProposee(): void
    {
        $this->executer($this->ligne(['regrouper_en_lot' => 1]), $this->generateur(4));

        self::assertSame(['26COR004'], array_column($this->enregistres, 'reference'));
        self::assertSame('2026-03-15', $this->enregistres[0]['date_mise_en_service']);
    }

    public function testReferenceForceeInchangee(): void
    {
        $g = $this->generateur(null);
        $this->executer($this->ligne(['reference_forcee' => 'CASQUE-A', 'nombre' => 2]), $g);

        self::assertSame(['CASQUE-A-1', 'CASQUE-A-2'], array_column($this->enregistres, 'reference'));
    }

    public function testReferenceNonConformeSansForceeDonneLaReferenceAuto(): void
    {
        $this->executer($this->ligne(['reference' => 'IMP-FAC-1', 'nombre' => 1]), $this->generateur(null, '26COR012'));

        self::assertSame(['26COR012'], array_column($this->enregistres, 'reference'));
    }
}
