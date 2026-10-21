<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersTest extends TestCase
{
    public function testPoseLesEnTetesDeSecurite(): void
    {
        $r = SecurityHeaders::apply(new Response('ok'));

        self::assertSame("frame-ancestors 'self'", $r->headers->get('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $r->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        self::assertStringContainsString('camera=(self)', (string) $r->headers->get('Permissions-Policy'));
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
        self::assertSame(2, substr_count($index, 'SecurityHeaders::apply($response)->send()'));
    }
}
