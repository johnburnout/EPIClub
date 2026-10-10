<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * [ISSUE #62] Test d'intégration du rendu Twig de edit() GET.
 *
 * Périmètre : non-régression du hotfix #52.
 *
 * Le hotfix #52 (commit 0690b4f) a rendu le déchiffrement de
 * `hash_remarques` INCONDITIONNEL dans edit() GET. Avant ce fix,
 * le déchiffrement n'avait lieu que si `!($readonly && cloture)`,
 * ce qui exposait le ciphertext base64 dans la vue « clôturé »
 * (bloc `<p>` en lecture seule du template).
 *
 * ⚠️ Prérequis :
 *   - un serveur HTTP lancé sur BASE_URL (défaut : localhost:8000)
 *   - un compte `admin` / `adminadmin` en BDD (id = 2 attendu)
 *   - le serveur doit utiliser le .env.local.php du projet
 */
final class ControleControllerEditViewTest extends AbstractControllerTestCase
{
    private const ADMIN_ID = 2;

    /**
     * Cas 1 — Contrôle OUVERT, hash chiffré.
     *
     * La vue est éditable → le template rend le `<textarea>` avec
     * la valeur déchiffrée.
     */
    public function testEditViewDecryptsHashForOpenControle(): void
    {
        $plaintext = 'Remarque secrète 123';
        $ciphertext = $this->encrypt($plaintext);

        $id = $this->fixture->createControle(
            controleurId: self::ADMIN_ID,
            libelle: 'TEST-OUVERT-CHIFFRE',
            statut: 'ouvert',
            hashRemarques: $ciphertext,
        );

        $response = $this->client->get("/admin/controles/edit/$id");

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringContainsString(
            $plaintext,
            $response['body'],
            'La remarque déchiffrée doit apparaître dans le <textarea>.'
        );
        self::assertStringNotContainsString(
            $ciphertext,
            $response['body'],
            'Le ciphertext base64 ne doit JAMAIS fuiter dans le HTML.'
        );
    }

    /**
     * Cas 2 — Contrôle CLÔTURÉ, hash chiffré. ⭐ CŒUR DU FIX #52.
     *
     * La vue est readonly → le template rend le bloc
     * `<p class="mb-0">{{ controle.hash_remarques }}</p>`.
     * Avant le fix #52, c'est le ciphertext base64 qui s'y affichait.
     */
    public function testEditViewDecryptsHashForClosedControle(): void
    {
        $plaintext = 'Remarque secrète 456';
        $ciphertext = $this->encrypt($plaintext);

        $id = $this->fixture->createControle(
            controleurId: self::ADMIN_ID,
            libelle: 'TEST-CLOTURE-CHIFFRE',
            statut: 'cloture',
            hashRemarques: $ciphertext,
        );

        $response = $this->client->get("/admin/controles/edit/$id");

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringContainsString(
            $plaintext,
            $response['body'],
            'La remarque déchiffrée doit apparaître dans le bloc readonly.'
        );
        self::assertStringNotContainsString(
            $ciphertext,
            $response['body'],
            'NON-RÉGRESSION #52 : le ciphertext base64 ne doit PAS fuiter.'
        );
    }

    /**
     * Cas 3 — Contrôle OUVERT, hash NULL.
     *
     * La vue doit rendre un `<textarea>` vide, sans erreur.
     * Aucun bloc `<p>Remarques générales</p>` (car `controle.hash_remarques` falsy).
     */
    public function testEditViewHandlesEmptyHash(): void
    {
        $id = $this->fixture->createControle(
            controleurId: self::ADMIN_ID,
            libelle: 'TEST-VIDE',
            statut: 'ouvert',
            hashRemarques: null,
        );

        $response = $this->client->get("/admin/controles/edit/$id");

        self::assertSame(200, $response['status']);
        // Le textarea doit être présent (vue éditable) et vide.
        self::assertMatchesRegularExpression(
            '/<textarea[^>]*name="remarques_generales"[^>]*>\s*<\/textarea>/s',
            $response['body'],
            'Le textarea des remarques doit être vide.'
        );
    }

    // ==================================================================
    // Helper : reproduit ControleController::encryptRemarque()
    // ==================================================================

    /**
     * Génère un ciphertext au format base64( IV || ciphertext ),
     * compatible avec ControleController::decryptRemarque().
     */
    private function encrypt(string $plain): string
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $env = include $envFile;
        if (!is_array($env) || empty($env['SECRET_KEY'])) {
            self::markTestSkipped('SECRET_KEY absente de .env.local.php');
        }

        $key = hex2bin($env['SECRET_KEY']);
        $cipher = $env['CIPHER_METHOD'] ?? 'AES-256-CBC';

        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $chiffre = openssl_encrypt($plain, $cipher, $key, 0, $iv);

        return base64_encode($iv . $chiffre);
    }
}