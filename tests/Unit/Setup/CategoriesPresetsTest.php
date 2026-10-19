<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fous sur les listes préparées de catégories de l'installeur
 * (décision produit 2026-10-19) : structure complète et codes uniques,
 * car ils alimentent le référencement automatique.
 */
final class CategoriesPresetsTest extends TestCase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $presets;

    protected function setUp(): void
    {
        $this->presets = require dirname(__DIR__, 3) . '/setup/includes/categories_presets.php';
    }

    public function testChaqueActiviteAUneListeNonVide(): void
    {
        self::assertArrayHasKey('Escalade', $this->presets);
        self::assertArrayHasKey('Alpinisme', $this->presets);

        foreach ($this->presets as $activite => $categories) {
            self::assertNotEmpty($categories, "Liste vide pour « $activite »");
        }
    }

    public function testChaqueCategorieEstComplete(): void
    {
        foreach ($this->presets as $activite => $categories) {
            foreach ($categories as $categorie) {
                self::assertNotEmpty($categorie['libelle'] ?? '', "Libellé manquant ($activite)");
                self::assertMatchesRegularExpression(
                    '/^[A-Z0-9]{2,8}$/',
                    $categorie['code'] ?? '',
                    "Code invalide pour « {$categorie['libelle']} » ($activite)"
                );
                self::assertContains($categorie['est_epi'] ?? null, [0, 1]);
                self::assertContains($categorie['duree_max_utilisation'] ?? null, [0, 1]);
            }
        }
    }

    public function testLesCodesSontUniquesParActivite(): void
    {
        foreach ($this->presets as $activite => $categories) {
            $codes = array_column($categories, 'code');
            self::assertSame(
                count($codes),
                count(array_unique($codes)),
                "Codes en double dans la liste « $activite »"
            );
        }
    }

    public function testLesLibellesSontUniquesParActivite(): void
    {
        foreach ($this->presets as $activite => $categories) {
            $libelles = array_column($categories, 'libelle');
            self::assertSame(
                count($libelles),
                count(array_unique($libelles)),
                "Libellés en double dans la liste « $activite »"
            );
        }
    }
}
