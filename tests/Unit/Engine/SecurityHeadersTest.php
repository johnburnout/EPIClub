<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\Csp;
use Epiclub\Engine\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersTest extends TestCase
{
    public function testPoseLesEnTetesDeSecurite(): void
    {
        $r = SecurityHeaders::apply(new Response('ok'));

        self::assertSame(
            "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; "
            . "script-src 'self' 'nonce-" . Csp::nonce() . "' https://cdn.jsdelivr.net",
            $r->headers->get('Content-Security-Policy')
        );
        self::assertStringNotContainsString('unsafe-inline', (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-eval', (string) $r->headers->get('Content-Security-Policy'));
        self::assertFalse($r->headers->has('Strict-Transport-Security'), 'pas de HSTS en HTTP');
        self::assertSame('SAMEORIGIN', $r->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        self::assertStringContainsString('camera=(self)', (string) $r->headers->get('Permissions-Policy'));
    }

    /** [audit 2026-11-07] Nonce imprévisible, stable pendant la requête, renouvelé ensuite. */
    public function testNonceParRequete(): void
    {
        Csp::reinitialiser();
        $premier = Csp::nonce();
        self::assertSame($premier, Csp::nonce(), 'même nonce pour le rendu et l\'en-tête');
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]{24}$#', $premier);

        Csp::reinitialiser();
        self::assertNotSame($premier, Csp::nonce());
    }

    /** Un PDF garde la CSP minimale (visionneuse intégrée du navigateur). */
    public function testUnPdfGardeLaCspMinimale(): void
    {
        $r = SecurityHeaders::apply(new Response('%PDF', 200, ['Content-Type' => 'application/pdf']));

        self::assertSame("frame-ancestors 'self'", $r->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    /** [audit 2026-11-07] HSTS en HTTPS uniquement. */
    public function testHstsEnHttps(): void
    {
        $r = SecurityHeaders::apply(new Response('ok'), true);

        self::assertSame('max-age=31536000', $r->headers->get('Strict-Transport-Security'));
    }

    public function testSappliqueAussiAuxRedirections(): void
    {
        $r = SecurityHeaders::apply(new RedirectResponse('/'));

        self::assertSame('SAMEORIGIN', $r->headers->get('X-Frame-Options'));
    }

    public function testNEcrasePasUnEnTetePoseParLeControleur(): void
    {
        $r = new Response('ok');
        $r->headers->set('Content-Security-Policy', "default-src 'none'");

        SecurityHeaders::apply($r);

        self::assertSame("default-src 'none'", $r->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function testFrontControllerAppliqueLesEnTetes(): void
    {
        // Les deux envois de public/index.php passent par SecurityHeaders.
        $index = (string) file_get_contents(__DIR__ . '/../../../public/index.php');

        self::assertSame(2, substr_count($index, '->send()'));
        self::assertSame(2, substr_count($index, 'SecurityHeaders::apply($response, $enHttps)->send()'));
    }
}
