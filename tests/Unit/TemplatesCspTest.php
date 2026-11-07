<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-11-07] Garde de la CSP script-src (sans
 * 'unsafe-inline') : un attribut on*= ou un <script> inline sans nonce
 * serait silencieusement bloqué par le navigateur (bouton sans effet,
 * confirmation sautée…). Utiliser data-confirm, data-auto-submit…
 * (public/assets/js/app.js) et <script nonce="{{ csp_nonce }}">.
 */
final class TemplatesCspTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function gabarits(): iterable
    {
        $racine = dirname(__DIR__, 2) . '/templates';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.twig') && !str_starts_with(substr($f->getPathname(), strlen($racine) + 1), 'email/')) {
                yield substr($f->getPathname(), strlen($racine) + 1) => [$f->getPathname()];
            }
        }
    }

    #[DataProvider('gabarits')]
    public function testAucunAttributOnEvenement(string $fichier): void
    {
        $source = (string) file_get_contents($fichier);
        preg_match_all('/<[a-zA-Z][^>]*\son[a-z]+\s*=/s', $source, $m);
        self::assertSame([], $m[0], 'attribut on*= interdit par la CSP');
        self::assertDoesNotMatchRegularExpression('/href\s*=\s*["\']\s*javascript:/i', $source);
    }

    #[DataProvider('gabarits')]
    public function testChaqueScriptInlinePorteLeNonce(string $fichier): void
    {
        preg_match_all('/<script\b[^>]*>/i', (string) file_get_contents($fichier), $m);
        $sansNonce = array_values(array_filter(
            $m[0],
            static fn (string $b) => !preg_match('/\ssrc\s*=/', $b) && !str_contains($b, 'nonce="{{ csp_nonce }}"')
        ));
        self::assertSame([], $sansNonce, 'script inline sans nonce : bloqué par la CSP');
    }

    public function testLeGabaritPrincipalChargeAppJs(): void
    {
        self::assertStringContainsString(
            '<script src="/assets/js/app.js"></script>',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig')
        );
    }
}
