<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ProxyDeConfiance;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * [SÉCURITÉ — audit 2026-11-08 R2] Proxys de confiance (TRUSTED_PROXIES) :
 * IP client et HTTPS lus dans X-Forwarded-* uniquement pour eux.
 */
final class ProxyDeConfianceTest extends TestCase
{
    private const PROXY = '10.0.0.5';
    private const CLIENT = '203.0.113.7';

    protected function tearDown(): void
    {
        // Réglage global de Symfony : ne pas contaminer les autres tests.
        Request::setTrustedProxies([], 0);
    }

    public function testSansConfigurationAucunProxy(): void
    {
        self::assertSame([], ProxyDeConfiance::configurer([]));

        $request = $this->requete(self::PROXY, ['HTTP_X_FORWARDED_FOR' => self::CLIENT, 'HTTP_X_FORWARDED_PROTO' => 'https']);
        self::assertSame(self::PROXY, $request->getClientIp());
        self::assertFalse(ProxyDeConfiance::enHttps($request));
    }

    public function testChaineSepareeParDesVirgules(): void
    {
        self::assertSame(
            ['127.0.0.1', '10.0.0.0/8', '2001:db8::/32', 'PRIVATE_SUBNETS'],
            ProxyDeConfiance::depuisConfig(['TRUSTED_PROXIES' => ' 127.0.0.1, 10.0.0.0/8 ,,2001:db8::/32, PRIVATE_SUBNETS'])
        );
    }

    public function testTableauAccepte(): void
    {
        self::assertSame(
            ['192.168.1.1', 'REMOTE_ADDR'],
            ProxyDeConfiance::depuisConfig(['TRUSTED_PROXIES' => ['192.168.1.1', 'REMOTE_ADDR', '192.168.1.1']])
        );
    }

    public function testEntreesInvalidesIgnorees(): void
    {
        $journal = ini_set('error_log', '/dev/null');
        try {
            self::assertSame(
                ['10.0.0.1'],
                ProxyDeConfiance::depuisConfig(['TRUSTED_PROXIES' => 'proxy.local, 10.0.0.1, 10.0.0.0/33, 10.0.0.0/x, 999.1.1.1, ::1/129'])
            );
            self::assertSame([], ProxyDeConfiance::depuisConfig(['TRUSTED_PROXIES' => 42]));
        } finally {
            ini_set('error_log', (string) $journal);
        }
    }

    public function testProxyDeConfianceDonneIpClientEtHttps(): void
    {
        ProxyDeConfiance::configurer(['TRUSTED_PROXIES' => '10.0.0.0/24']);

        $request = $this->requete(self::PROXY, [
            'HTTP_X_FORWARDED_FOR' => self::CLIENT,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame(self::CLIENT, $request->getClientIp());
        self::assertTrue(ProxyDeConfiance::enHttps($request));
        self::assertSame('https://epiclub.example', $request->getSchemeAndHttpHost());
    }

    public function testIpUsurpeeEnTeteDeChaineIgnoree(): void
    {
        ProxyDeConfiance::configurer(['TRUSTED_PROXIES' => self::PROXY]);

        // Le client a lui-même envoyé X-Forwarded-For: 1.2.3.4 ; le proxy
        // ajoute l'adresse réelle à la suite.
        $request = $this->requete(self::PROXY, ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, ' . self::CLIENT]);

        self::assertSame(self::CLIENT, $request->getClientIp());
    }

    public function testClientDirectNePeutPasUsurper(): void
    {
        ProxyDeConfiance::configurer(['TRUSTED_PROXIES' => self::PROXY]);

        $request = $this->requete(self::CLIENT, [
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame(self::CLIENT, $request->getClientIp());
        self::assertFalse(ProxyDeConfiance::enHttps($request));
    }

    public function testHostTransmisParLeProxyIgnore(): void
    {
        ProxyDeConfiance::configurer(['TRUSTED_PROXIES' => self::PROXY]);

        $request = $this->requete(self::PROXY, ['HTTP_X_FORWARDED_HOST' => 'attaquant.example']);

        self::assertSame('epiclub.example', $request->getHost());
    }

    public function testHttpsDirect(): void
    {
        self::assertTrue(ProxyDeConfiance::enHttps($this->requete(self::CLIENT, ['HTTPS' => 'on'])));
        self::assertFalse(ProxyDeConfiance::enHttps($this->requete(self::CLIENT, ['HTTPS' => 'off'])));
        // Repli historique : port 443 sans variable HTTPS.
        self::assertTrue(ProxyDeConfiance::enHttps($this->requete(self::CLIENT, ['SERVER_PORT' => 443])));
    }

    public function testProxyEnHttpSurPort443NestPasHttps(): void
    {
        ProxyDeConfiance::configurer(['TRUSTED_PROXIES' => self::PROXY]);

        $request = $this->requete(self::PROXY, ['SERVER_PORT' => 443, 'HTTP_X_FORWARDED_PROTO' => 'http']);

        self::assertFalse(ProxyDeConfiance::enHttps($request));
    }

    /** @param array<string, mixed> $serveur */
    private function requete(string $adresse, array $serveur = []): Request
    {
        return new Request(server: array_merge([
            'REMOTE_ADDR' => $adresse,
            'HTTP_HOST' => 'epiclub.example',
            'SERVER_PORT' => 80,
        ], $serveur));
    }
}
