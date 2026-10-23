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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Export CSV « données » : fputcsv() avec escape: '' —
 * plus de dépréciation PHP 8.4+, et guillemets doublés selon la
 * RFC 4180 même quand une valeur contient « \" ».
 */
final class EquipementControllerCsvExportTest extends TestCase
{
    private function exporter(array $equipements): string
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => 'ROLE_USER']);
        $manager = $this->createMock(EquipementManager::class);
        $manager->method('findAll')->willReturn($equipements);
        $controller = new TestableEquipementController(
            $session,
            $manager,
            $this->createMock(CategorieManager::class),
            $this->createMock(EmplacementManager::class),
            $this->createMock(AcquisitionManager::class),
        );

        $response = $controller->listDataCsv(new Request());

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        return (string) $response->getContent();
    }

    public function testEnTeteEtLigneSimple(): void
    {
        $csv = $this->exporter([['id' => 1, 'reference' => 'C-001', 'libelle' => 'Casque', 'statut' => 1]]);
        $lignes = explode("\n", rtrim($csv, "\n"));

        self::assertStringStartsWith("\xEF\xBB\xBFRéférence;Nom;Catégorie;", $lignes[0]);
        self::assertStringStartsWith('C-001;Casque;', $lignes[1]);
    }

    public function testGuillemetsEtAntislashSelonRfc4180(): void
    {
        $libelle = 'Corde 10\" "rouge"; neuve';
        $csv = $this->exporter([['id' => 1, 'reference' => 'C-002', 'libelle' => $libelle, 'statut' => 1]]);

        // Chaque guillemet est doublé, l'antislash reste un caractère ordinaire.
        self::assertStringContainsString('C-002;"Corde 10\"" ""rouge""; neuve";', $csv);

        $ligne = explode("\n", $csv)[1];
        self::assertSame($libelle, str_getcsv($ligne, ';', '"', '')[1]);
    }
}
