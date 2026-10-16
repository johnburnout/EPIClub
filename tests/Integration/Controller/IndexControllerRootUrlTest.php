<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * [ISSUE #59] Test d'intégration HTTP de ROOT_URL dans la configuration système.
 *
 * Périmètre :
 *   - GET  /admin/system-settings  → rend le champ `root_url` pré-rempli
 *   - POST /update-smtp            → persiste ROOT_URL si valide
 *   - POST /update-smtp            → refuse ROOT_URL invalide (pas d'écriture + flash)
 *   - POST /update-smtp            → ROOT_URL vide = no-op (clé préservée)
 *
 * ⚠️ Prérequis :
 *   - un serveur HTTP lancé sur BASE_URL (défaut : localhost:8000)
 *   - un compte `admin` / `adminadmin` en BDD (ROLE_SUPER_ADMIN)
 *   - le serveur doit utiliser le .env.local.php du projet
 *
 * ⚠️ Attention : ces tests écrivent dans le .env.local.php du projet.
 *   Ils le sauvegardent en setUp() et le restaurent en tearDown().
 */
final class IndexControllerRootUrlTest extends AbstractControllerTestCase
{
    private const SETTINGS_PATH = '/admin/system-settings';

    private string $envFile;
    private string $envBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFile = dirname(__DIR__, 3) . '/.env.local.php';
        if (!is_file($this->envFile)) {
            self::markTestSkipped('.env.local.php introuvable.');
        }
        $this->envBackup = (string) file_get_contents($this->envFile);
    }

    protected function tearDown(): void
    {
        // Restaure le .env.local.php d'origine dans tous les cas
        if (isset($this->envFile, $this->envBackup)) {
            file_put_contents($this->envFile, $this->envBackup);
        }
        parent::tearDown();
    }

    // ==================================================================
    // GET /admin/system-settings
    // ==================================================================

    /**
     * Le formulaire doit exposer le champ `root_url`, pré-rempli avec
     * la valeur actuelle de .env.local.php.
     */
    public function testSystemSettingsRendersRootUrlFieldPrefilled(): void
    {
        $currentRootUrl = $this->readRootUrlFromEnv();
        if ($currentRootUrl === '') {
            self::markTestSkipped('ROOT_URL absente du .env.local.php ; impossible de tester le pré-remplissage.');
        }

        $response = $this->client->get(self::SETTINGS_PATH);

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringContainsString(
            'name="root_url"',
            $response['body'],
            'Le champ root_url doit être présent dans le formulaire.'
        );
        self::assertStringContainsString(
            'value="' . htmlspecialchars($currentRootUrl, ENT_QUOTES) . '"',
            $response['body'],
            'Le champ root_url doit être pré-rempli avec la valeur actuelle.'
        );
    }

    // ==================================================================
    // POST /update-smtp — cas valides
    // ==================================================================

    /**
     * ROOT_URL valide avec slash final → persistée sans slash.
     */
    public function testUpdateSmtpPersistsValidRootUrlWithoutTrailingSlash(): void
    {
        $response = $this->postUpdateSmtp([
            'root_url' => 'https://epiclub.example.com/',
        ]);

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame(
            'https://epiclub.example.com',
            $this->readRootUrlFromEnv(),
            'ROOT_URL doit être persistée sans slash final.'
        );
    }

    /**
     * ROOT_URL valide sans slash → persistée telle quelle.
     */
    public function testUpdateSmtpPersistsValidRootUrlWithoutSlash(): void
    {
        $response = $this->postUpdateSmtp([
            'root_url' => 'http://localhost:8000',
        ]);

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame(
            'http://localhost:8000',
            $this->readRootUrlFromEnv()
        );
    }

    // ==================================================================
    // POST /update-smtp — cas invalides
    // ==================================================================

    /**
     * ROOT_URL invalide → flash error, ROOT_URL inchangée.
     */
    public function testUpdateSmtpRejectsInvalidRootUrlAndDoesNotPersist(): void
    {
        $before = $this->readRootUrlFromEnv();

        $response = $this->postUpdateSmtp([
            'root_url' => 'not-a-url',
        ]);

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame(
            $before,
            $this->readRootUrlFromEnv(),
            'ROOT_URL ne doit PAS être modifiée quand la validation échoue.'
        );
        $this->assertFlashContains("n'est pas une URL valide", $response);
    }

    /**
     * ROOT_URL avec schéma non-http(s) → refusée.
     */
    public function testUpdateSmtpRejectsFtpScheme(): void
    {
        $before = $this->readRootUrlFromEnv();

        $response = $this->postUpdateSmtp([
            'root_url' => 'ftp://example.com',
        ]);

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame($before, $this->readRootUrlFromEnv());
        $this->assertFlashContains('http ou https', $response);
    }

    /**
     * ROOT_URL avec query string → refusée.
     */
    public function testUpdateSmtpRejectsQueryString(): void
    {
        $before = $this->readRootUrlFromEnv();

        $response = $this->postUpdateSmtp([
            'root_url' => 'https://example.com?foo=bar',
        ]);

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame($before, $this->readRootUrlFromEnv());
        $this->assertFlashContains('ni query string ni fragment', $response);
    }

    // ==================================================================
    // POST /update-smtp — cas vide (no-op)
    // ==================================================================

    /**
    * ROOT_URL vide → no-op, la clé existante est préservée.
    *
    * On lit la valeur AVANT le POST (elle vient du .env.local.php
    * d'origine, que le serveur a réellement lu), puis on vérifie
    * qu'après un POST avec root_url='', la valeur est identique.
    *
    * Pas de writeRootUrlToEnv() : on ne modifie pas le fichier depuis
    * le test, on vérifie seulement que le contrôleur ne le modifie pas.
    */
    public function testUpdateSmtpEmptyRootUrlPreservesExistingKey(): void
    {
        $before = $this->readRootUrlFromEnv();
        if ($before === '') {
        self::markTestSkipped('ROOT_URL absente du .env.local.php ; le cas no-op ne peut pas être distingué d\'une absence.');
        }
        
        $response = $this->postUpdateSmtp([
            'root_url' => '',
        ]);
        
        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
    self::assertSame(
            $before,
            $this->readRootUrlFromEnv(),
            'ROOT_URL vide = no-op : la valeur existante doit être préservée.'
        );
    }

    // ==================================================================
    // POST /update-system-settings — route canonique (#97, Dette 1)
    // (les cas ci-dessus couvrent l'alias historique /update-smtp)
    // ==================================================================

    /**
     * [#97] La route canonique /update-system-settings remplace
     * /update-smtp (conservée en alias POST, même action) : ROOT_URL
     * est persistée pareil.
     */
    public function testUpdateSystemSettingsCanonicalRoutePersistsRootUrl(): void
    {
        $response = $this->postUpdateSmtp([
            'root_url' => 'https://epiclub.example.com/',
        ], '/update-system-settings');

        $this->assertRedirectsTo(self::SETTINGS_PATH, $response);
        self::assertSame(
            'https://epiclub.example.com',
            $this->readRootUrlFromEnv(),
            'La route canonique doit persister ROOT_URL sans slash final.'
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * POST /update-smtp (alias historique) ou /update-system-settings
     * (route canonique #97) avec les champs SMTP obligatoires renseignés
     * (sinon la validation SMTP rejette avant même de regarder ROOT_URL)
     * et les surcharges passées en argument.
     *
     * @param array<string,string> $overrides
     */
    private function postUpdateSmtp(array $overrides = [], string $path = '/update-smtp'): array
    {
        $defaults = [
            'smtp_host'       => 'smtp.example.com',
            'smtp_port'       => '587',
            'smtp_username'   => 'test@example.com',
            'smtp_password'   => '',
            'smtp_encryption' => 'tls',
            'mail_from'       => 'test@example.com',
            'mail_from_name'  => 'EPIClub Test',
        ];

        $payload = array_merge($defaults, $overrides);

        // HttpTestClient::post() injecte automatiquement le CSRF token
        // (getCsrfToken() le charge depuis /se_connecter si nécessaire).
        return $this->client->post($path, $payload);
    }

    /**
     * Lit ROOT_URL depuis .env.local.php.
     */
    private function readRootUrlFromEnv(): string
    {
        $config = include $this->envFile;
        if (!is_array($config)) {
            return '';
        }
        return (string) ($config['ROOT_URL'] ?? '');
    }

    /**
     * Écrit ROOT_URL dans .env.local.php en préservant le reste,
     * au format attendu par EnvironmentFileParser.
     */
    private function writeRootUrlToEnv(string $value): void
    {
        $config = include $this->envFile;
        if (!is_array($config)) {
            self::fail('.env.local.php ne retourne pas un tableau.');
        }
        $config['ROOT_URL'] = $value;

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * This file is autogenerated by application.\n";
        $content .= " * Do not edit manually.\n";
        $content .= " */\n";
        $content .= 'return ' . var_export($config, true) . ";\n";

        file_put_contents($this->envFile, $content);
    }
}