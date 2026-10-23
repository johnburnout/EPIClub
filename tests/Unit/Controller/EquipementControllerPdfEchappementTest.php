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
 * [2026-10-23] Les valeurs insérées dans le HTML des PDF (fiche, liste)
 * sont échappées : un libellé ou un paramètre d'URL contenant du HTML
 * n'altère pas la mise en page.
 */
final class EquipementControllerPdfEchappementTest extends TestCase
{
    private const PIEGE = '<b>Casque & "co"</b>';
    private const ECHAPPE = '&lt;b&gt;Casque &amp; &quot;co&quot;&lt;/b&gt;';

    private function controller(): TestableEquipementController
    {
        return new TestableEquipementController(
            new Session(new MockArraySessionStorage()),
            $this->createMock(EquipementManager::class),
            $this->createMock(CategorieManager::class),
            $this->createMock(EmplacementManager::class),
            $this->createMock(AcquisitionManager::class),
        );
    }

    private function rendre(string $methode, array $arguments): string
    {
        return (new \ReflectionMethod(TestableEquipementController::class, $methode))
            ->invoke($this->controller(), ...$arguments);
    }

    public function testFicheEchappeLesValeurs(): void
    {
        $html = $this->rendre('renderPdfHtml', [[
            'reference' => self::PIEGE,
            'categorie' => ['libelle' => self::PIEGE],
            'emplacement' => ['libelle' => self::PIEGE],
            'remarques' => self::PIEGE,
        ], 'data:image/png;base64,', null]);

        self::assertStringNotContainsString(self::PIEGE, $html);
        self::assertSame(4, substr_count($html, self::ECHAPPE));
    }

    public function testListeEchappeLignesEtFiltres(): void
    {
        $html = $this->rendre('renderPdfListHtml', [
            [['id' => 4, 'reference' => self::PIEGE, 'libelle' => self::PIEGE]],
            // Le libellé du filtre « acquisition » reprend le paramètre d'URL brut
            ['categorie' => null, 'epi' => null, 'en_service' => null,
             'emplacement' => null, 'dernier_controle' => null, 'acquisition' => self::PIEGE],
        ]);

        self::assertStringNotContainsString(self::PIEGE, $html);
        self::assertSame(3, substr_count($html, self::ECHAPPE));
    }
}
