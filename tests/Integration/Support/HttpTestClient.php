<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Support;

/**
 * Client HTTP de test avec gestion automatique :
 * - Des cookies de session (PHPSESSID, etc.)
 * - Du CSRF token (extrait d'une page, réinjecté dans les POST)
 * - De l'authentification (login admin)
 *
 * ⚠️ Nécessite un serveur HTTP démarré sur BASE_URL
 *    (par défaut : http://localhost:8000).
 */
final class HttpTestClient
{
    private string $baseUrl;

    /** @var array<string,string> */
    private array $cookies = [];

    private ?string $csrfToken = null;

    /** @var array<int,array{method:string,path:string,status:int}> */
    private array $history = [];

    public function __construct(string $baseUrl = 'http://localhost:8000')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Requête GET.
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * Requête POST avec CSRF token automatique.
     *
     * @param array<string,mixed> $data
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function post(string $path, array $data = []): array
    {
        if (!isset($data['csrf_token'])) {
            $data['csrf_token'] = $this->getCsrfToken();
        }

        return $this->request('POST', $path, $data);
    }

    /**
     * POST sans CSRF (pour tester le rejet).
     *
     * @param array<string,mixed> $data
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function postWithoutCsrf(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data);
    }

    /**
     * Authentifie un utilisateur.
     */
    public function login(string $username, string $password): void
    {
        // 1. Charger /se_connecter ET rafraîchir le CSRF token
        $this->refreshCsrfToken('/se_connecter');
        
        // 2. POST credentials
        $response = $this->post('/se_connecter', [
            'username' => $username,
            'password' => $password,
        ]);
        
        if (!in_array($response['status'], [302, 303], true)) {
            throw new \RuntimeException(sprintf(
                "Login échoué pour '%s' (status: %d). Body: %s",
                $username,
                $response['status'],
                substr(strip_tags($response['body']), 0, 200)
            ));
        }
        
        // 3. ⚠️ CRUCIAL : rafraîchir le token APRÈS login
        //    La session a changé (nouveau cookie), donc le token en session
        //    a été régénéré. Sans ce refresh, le POST suivant échouera au CSRF.
        $this->refreshCsrfToken('/tableau_de_bord');
    }

    /**
     * Login admin par défaut.
     */
    public function loginAsAdmin(
        string $email = 'admin',
        string $password = 'adminadmin',
    ): void {
        $this->login($email, $password);
    }

    /**
     * Récupère le CSRF token courant ou en charge un nouveau.
     */
    public function getCsrfToken(): string
    {
        if ($this->csrfToken === null) {
            // Fallback : /se_connecter est une page qui expose toujours un CSRF token.
            $this->refreshCsrfToken('/se_connecter');
        }
        
        if ($this->csrfToken === null) {
            throw new \RuntimeException(
                'Aucun CSRF token trouvé. Vérifiez que /se_connecter contient '
                . 'un <input name="csrf_token">.'
            );
        }
        
        return $this->csrfToken;
    }

    /**
     * Force le rechargement du CSRF token depuis une page.
     */
    public function refreshCsrfToken(string $path = '/'): void
    {
        $page = $this->get($path);
        
        // [DEBUG] À retirer après diagnostic
        //error_log("[HttpTestClient] GET $path → status {$page['status']}");
        
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m)) {
            $this->csrfToken = $m[1];
            //error_log("[HttpTestClient] CSRF token OK");
        } else {
            //error_log("[HttpTestClient] CSRF token NOT FOUND (path=$path)");
        }
    }

    /**
     * Efface les cookies (logout).
     */
    public function clearSession(): void
    {
        $this->cookies = [];
        $this->csrfToken = null;
    }

    /**
     * Historique des requêtes (debug).
     *
     * @return array<int,array{method:string,path:string,status:int}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Envoie une requête HTTP et retourne la réponse parsée.
     *
     * @param array<string,mixed> $data
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $headers = [];
        if (!empty($this->cookies)) {
            $cookieHeader = [];
            foreach ($this->cookies as $name => $value) {
                $cookieHeader[] = "$name=$value";
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookieHeader);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'EPIClub-IntegrationTest/1.0',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            // curl_close($ch);   // deprecated PHP 8.5
            throw new \RuntimeException("cURL a échoué pour $url : $error");
        }

        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // curl_close($ch);   // deprecated PHP 8.5

        $rawHeaders = substr($raw, 0, $headerSize);
        $body       = substr($raw, $headerSize);

        $responseHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                if (preg_match('/Set-Cookie:\s*([^=]+)=([^;]+)/i', $line, $m)) {
                    $this->cookies[trim($m[1])] = trim($m[2]);
                }
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        $this->history[] = ['method' => $method, 'path' => $path, 'status' => $status];

        return [
            'status'  => $status,
            'headers' => $responseHeaders,
            'body'    => $body,
        ];
    }
}