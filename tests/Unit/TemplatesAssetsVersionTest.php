<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * [v3.0.2] Fichiers statiques versionnés : chaque script ou feuille de
 * style de /assets/ est chargé avec ?v={{ app_version }}.
 *
 * Incident de la v3.0.0 : passkey.js (nouvelle fonction definirTextes)
 * était servi sans version dans l'URL ; un navigateur qui gardait
 * l'ancien fichier en cache levait une erreur et la passkey n'était plus
 * proposée (connexion et « Sécurité du compte »).
 */
final class TemplatesAssetsVersionTest extends TestCase
{
    private const RACINE = __DIR__ . '/../../templates';

    public function testChaqueFichierStatiqueEstVersionne(): void
    {
        $sansVersion = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fichier) {
            if ($fichier->getExtension() !== 'twig') {
                continue;
            }
            $contenu = (string) file_get_contents($fichier->getPathname());
            preg_match_all('#(?:src|href)="(/assets/(?:js|css)/[^"]+)"#', $contenu, $m);
            foreach ($m[1] as $url) {
                if (!str_contains($url, '?v={{ app_version }}')) {
                    $sansVersion[] = $fichier->getFilename() . ' : ' . $url;
                }
            }
        }
        self::assertSame([], $sansVersion, 'Fichier statique sans ?v={{ app_version }} : un ancien fichier en cache casserait la page après une mise à jour');
    }

    public function testLesTextesDePasskeyToleréntUnAncienPasskeyJsEnCache(): void
    {
        $gabarit = (string) file_get_contents(self::RACINE . '/_passkey_textes.twig');
        self::assertStringContainsString("typeof EpiclubPasskey.definirTextes === 'function'", $gabarit);
    }
}
