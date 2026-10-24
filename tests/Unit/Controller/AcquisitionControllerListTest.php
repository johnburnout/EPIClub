<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liste des acquisitions : tri (ID, référence facture, date, fournisseur),
 * filtres et pagination.
 */
final class AcquisitionControllerListTest extends TestCase
{
    private function lister(string $query): string
    {
        $acquisitionManager = $this->createMock(AcquisitionManager::class);
        $acquisitionManager->method('findAll')->willReturn([
            ['id' => 1, 'facture_reference' => 'FAC-C', 'facture_date' => '2026-03-01', 'est_validee' => 1,
             'fournisseur_id' => 2, 'fournisseur' => ['id' => 2, 'nom' => 'Zèbre Sport']],
            ['id' => 2, 'facture_reference' => 'FAC-A', 'facture_date' => '2026-01-15', 'est_validee' => 0,
             'fournisseur_id' => 1, 'fournisseur' => ['id' => 1, 'nom' => 'Alpin Shop']],
            ['id' => 3, 'facture_reference' => 'FAC-B', 'facture_date' => '2026-02-10', 'est_validee' => 1,
             'fournisseur_id' => 1, 'fournisseur' => ['id' => 1, 'nom' => 'Alpin Shop']],
        ]);
        $fournisseurManager = $this->createMock(FournisseurManager::class);
        $fournisseurManager->method('findAll')->willReturn([
            ['id' => 1, 'nom' => 'Alpin Shop'],
            ['id' => 2, 'nom' => 'Zèbre Sport'],
        ]);

        $controller = (new TestableAcquisitionControllerBuilder($this))
            ->withAcquisitionManager($acquisitionManager)
            ->withFournisseurManager($fournisseurManager)
            ->build();

        return (string) $controller->list(Request::create('/admin/acquisitions?' . $query))->getContent();
    }

    /** @param list<string> $references */
    private static function assertOrdre(array $references, string $html): void
    {
        $positions = array_map(fn($r) => strpos($html, '<td>' . $r . '</td>'), $references);
        self::assertNotContains(false, $positions);
        $tries = $positions;
        sort($tries);
        self::assertSame($tries, $positions, 'Ordre attendu : ' . implode(', ', $references));
    }

    public function testTriParDefautParIdCroissant(): void
    {
        self::assertOrdre(['FAC-C', 'FAC-A', 'FAC-B'], $this->lister(''));
    }

    public function testTriParReferenceFacture(): void
    {
        self::assertOrdre(['FAC-A', 'FAC-B', 'FAC-C'], $this->lister('order_by=facture_reference'));
    }

    public function testTriParDateDecroissante(): void
    {
        self::assertOrdre(['FAC-C', 'FAC-B', 'FAC-A'], $this->lister('order_by=facture_date&order_dir=desc'));
    }

    public function testTriParFournisseurPuisId(): void
    {
        self::assertOrdre(['FAC-A', 'FAC-B', 'FAC-C'], $this->lister('order_by=fournisseur'));
    }

    public function testCleDeTriInconnueRetombeSurId(): void
    {
        self::assertOrdre(['FAC-C', 'FAC-A', 'FAC-B'], $this->lister('order_by=password'));
    }

    public function testFiltreFournisseurEtStatut(): void
    {
        $html = $this->lister('fournisseur=1&statut=validee');

        self::assertStringContainsString('<td>FAC-B</td>', $html);
        self::assertStringNotContainsString('<td>FAC-A</td>', $html);
        self::assertStringNotContainsString('<td>FAC-C</td>', $html);
    }

    public function testFiltrePeriodeEtRecherche(): void
    {
        $html = $this->lister('date_du=2026-02-01&date_au=2026-03-31&q=fac-c');

        self::assertStringContainsString('<td>FAC-C</td>', $html);
        self::assertStringNotContainsString('<td>FAC-B</td>', $html);
        self::assertStringContainsString('name="q" id="q" value="fac-c"', $html, 'Recherche conservée dans le formulaire');
    }

    public function testAucunResultatAvecFiltre(): void
    {
        $html = $this->lister('q=introuvable');

        self::assertStringContainsString('Aucune acquisition ne correspond aux filtres.', $html);
    }

    public function testPaginationConserveLesFiltres(): void
    {
        $html = $this->lister('limit=1&page=2&order_by=facture_date');

        self::assertStringContainsString('<td>FAC-B</td>', $html);
        self::assertStringNotContainsString('<td>FAC-A</td>', $html);
        self::assertStringContainsString('Page 2 sur 3', $html);
        self::assertStringContainsString('order_by=facture_date', $html);
    }
}
