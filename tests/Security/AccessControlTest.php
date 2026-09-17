<?php
// tests/Security/AccessControlTest.php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

class AccessControlTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim(getenv('BASE_URL') ?: 'http://localhost:8080', '/');
    }

    /**
     * Toute route protégée doit rediriger un anonyme vers /se_connecter
     * ou renvoyer 401/403.
     *
     * @dataProvider \Tests\Security\RouteProvider::protectedRoutesProvider
     */
    public function test_anonymous_is_redirected_to_login(string $concretePath, string $originalPath): void
    {
        $response = $this->requestWithoutAuth($concretePath);

        $acceptable = [301, 302, 303, 307, 308, 401, 403];

        $this->assertContains(
            $response['status'],
            $acceptable,
            sprintf(
                "Route protégée '%s' (testée sur '%s') accessible à un anonyme : statut %d reçu.\nContenu : %s",
                $originalPath,
                $concretePath,
                $response['status'],
                substr(strip_tags($response['body']), 0, 200)
            )
        );

        if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
            $location = $response['headers']['location'] ?? '';
            $this->assertStringContainsString(
                '/se_connecter',
                $location,
                "La route '$originalPath' devrait rediriger vers /se_connecter (Location : $location)."
            );
        }
    }

    /**
     * Vérifie qu'aucune route protégée ne renvoie 200 à un anonyme.
     * Test global qui liste toutes les routes en échec d'un coup.
     */
    public function test_no_protected_route_returns_200_for_anonymous(): void
    {
        $failures = [];

        foreach (RouteProvider::protectedRoutesProvider() as $name => [$concretePath, $originalPath]) {
            $response = $this->requestWithoutAuth($concretePath);
            if ($response['status'] === 200) {
                $failures[] = sprintf(
                    "  - %s (%s) → 200 OK",
                    $name,
                    $originalPath
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Routes protégées accessibles en anonyme :\n" . implode("\n", $failures)
        );
    }

    /**
     * Vérifie qu'aucune route protégée ne renvoie 405 Method Not Allowed
     * pour un GET anonyme. Un 405 signifie que la route existe mais que
     * la méthode HTTP est refusée — cela peut masquer un problème de contrôle
     * d'accès si le contrôleur ne vérifie pas le rôle avant la méthode.
     *
     * ⚠️ Ce test peut légitimement échouer pour les routes POST-only :
     * dans ce cas, un GET anonyme renvoie 405 AVANT d'atteindre le contrôleur.
     * On l'inclut pour information, mais on accepte 405 comme réponse valide
     * (la route est bien inaccessible).
     */
    public function test_protected_routes_do_not_leak_data_on_405(): void
    {
        $suspicious = [];

        foreach (RouteProvider::protectedRoutesProvider() as $name => [$concretePath, $originalPath]) {
            $response = $this->requestWithoutAuth($concretePath);
            if ($response['status'] === 405 && strlen($response['body']) > 500) {
                // 405 avec un corps volumineux = fuite potentielle
                $suspicious[] = "  - $name ($originalPath) → 405 avec " . strlen($response['body']) . " octets";
            }
        }

        $this->assertEmpty(
            $suspicious,
            "Routes renvoyant 405 avec un corps volumineux (fuite potentielle) :\n" . implode("\n", $suspicious)
        );
    }

    /**
     * Vérifie que les routes publiques ne sont pas refusées (401/403).
     * Accepte 200 (page servie) ou 3xx (redirection légitime).
     */
    public function test_public_routes_are_not_forbidden(): void
    {
        $publicRoutes = require __DIR__ . '/../public_routes.php';
        $failures = [];

        foreach ($publicRoutes as $path) {
            $concrete = preg_replace_callback('/\{([^}]+)\}/', fn($m) => '1', $path);
            $response = $this->requestWithoutAuth($concrete);

            if (in_array($response['status'], [401, 403], true)) {
                $failures[] = "  - $path → {$response['status']}";
            }
        }

        $this->assertEmpty(
            $failures,
            "Routes publiques refusées :\n" . implode("\n", $failures)
        );
    }

    /**
     * Vérifie spécifiquement que /guide est accessible en anonyme.
     */
    public function test_guide_is_publicly_accessible(): void
    {
        $response = $this->requestWithoutAuth('/guide');

        $this->assertSame(
            200,
            $response['status'],
            "Le guide doit être accessible sans connexion (statut reçu : {$response['status']})."
        );

        $this->assertStringContainsString(
            'guide',
            strtolower($response['body']),
            "La page /guide ne semble pas contenir le guide attendu."
        );
    }

    /**
     * Vérifie que les routes destructives refusent un GET (405 Method Not Allowed).
     * Ces routes sont déclarées en POST uniquement dans routes.php.
     *
     * @dataProvider \Tests\Security\RouteProvider::destructiveRoutesProvider
     */
    public function test_destructive_routes_reject_get(string $concretePath, string $originalPath): void
    {
        $response = $this->requestWithoutAuth($concretePath);

        $this->assertSame(
            405,
            $response['status'],
            sprintf(
                "La route destructive '%s' (testée sur '%s') devrait renvoyer 405 en GET, statut %d reçu.",
                $originalPath,
                $concretePath,
                $response['status']
            )
        );
    }

    /**
     * Envoie une requête HTTP anonyme (sans cookie de session).
     * FOLLOWLOCATION désactivé pour observer le 302.
     */
    private function requestWithoutAuth(string $path): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'EPIClub-AccessControlTest/1.0',
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->fail("cURL a échoué pour $url : " . curl_error($ch));
        }

        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body       = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}