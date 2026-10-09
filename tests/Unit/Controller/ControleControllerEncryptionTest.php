<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleController;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests Unit pour l'issue #52 :
 * chiffrement de controle.hash_remarques dès l'écriture.
 *
 * ⚠️ On utilise le builder (session mockée) + surcharge de
 *    updateLastActivity() → aucun accès PDO, pas de MySQL requis.
 */
final class ControleControllerEncryptionTest extends TestCase
{
    private const FIXTURE_SECRET_KEY_HEX = 'abababababababababababababababababababababababababababababababab';
    private const CIPHER_METHOD          = 'AES-256-CBC';

    // ==================================================================
    // edit() — écriture
    // ==================================================================

    public function testEditPostEncryptsHashRemarques(): void
    {
        $controleManager = $this->makeControleManagerMock();
        $controleManager->method('findId')->willReturn([
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => date('Y-m-d H:i:s'),
            'hash_remarques' => null,
        ]);

        // ⚠️ save() DOIT être appelé avec hash_remarques ≠ clair, déchiffrable
        $controleManager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $c): bool {
                $stored = $c['hash_remarques'] ?? null;
                if (!is_string($stored) || $stored === '') {
                    return false;
                }
                // Assertion forte : le ciphertext contient un IV + ciphertext
                $data = base64_decode($stored, true);
                if ($data === false) {
                    return false;
                }
                $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
                if (strlen($data) <= $ivLength) {
                    return false;
                }
                $iv       = substr($data, 0, $ivLength);
                $chiffre  = substr($data, $ivLength);
                $key      = hex2bin(self::FIXTURE_SECRET_KEY_HEX);
                $decrypted = openssl_decrypt($chiffre, self::CIPHER_METHOD, $key, 0, $iv);
                return $decrypted === 'Ma remarque générale';
            }))
            ->willReturn(true);

        $controller = $this->makeController($controleManager);

        $request = $this->makePostRequest([
            'id'                  => '5',
            'remarques_generales' => 'Ma remarque générale',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testEditPostStoresNullWhenRemarquesEmpty(): void
    {
        $controleManager = $this->makeControleManagerMock();
        $controleManager->method('findId')->willReturn([
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => date('Y-m-d H:i:s'),
            'hash_remarques' => null,
        ]);

        // ⚠️ remarque vide → hash_remarques === null (pas de ciphertext)
        $controleManager->expects(self::once())
            ->method('save')
            ->with(self::callback(fn(array $c): bool => ($c['hash_remarques'] ?? null) === null))
            ->willReturn(true);

        $controller = $this->makeController($controleManager);

        $request = $this->makePostRequest([
            'id'                  => '5',
            'remarques_generales' => '',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testEditPostThrowsWhenSecretKeyMissing(): void
    {
        // ConfigProvider sans SECRET_KEY
        $emptyConfigFile = sys_get_temp_dir() . '/epiclub-test-empty-' . uniqid() . '.php';
        file_put_contents($emptyConfigFile, "<?php\nreturn ['CIPHER_METHOD' => 'AES-256-CBC'];\n");

        try {
            $provider = new ConfigProvider($emptyConfigFile);

            $controleManager = $this->makeControleManagerMock();
            $controleManager->method('findId')->willReturn([
                'id'             => 5,
                'statut'         => 'ouvert',
                'controleur_id'  => 42,
                'date_debut'     => date('Y-m-d H:i:s'),
                'hash_remarques' => null,
            ]);
            // ⚠️ save() NE DOIT PAS être appelé si la clé manque
            $controleManager->expects(self::never())->method('save');

            $controller = $this->makeController(
            controleManager: $controleManager,
            configProvider: $provider,
            );

            $request = $this->makePostRequest([
                'id'                  => '5',
                'remarques_generales' => 'Ma remarque',
            ]);

            $this->expectException(\RuntimeException::class);

            $controller->edit($request);
        } finally {
            @unlink($emptyConfigFile);
        }
    }

    // ==================================================================
    // cloturer() — idempotence
    // ==================================================================

    public function testCloturerDoesNotDoubleEncryptAlreadyEncrypted(): void
    {
        // On pré-chiffre une remarque comme le ferait edit() après le fix
        $key      = hex2bin(self::FIXTURE_SECRET_KEY_HEX);
        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv       = openssl_random_pseudo_bytes($ivLength);
        $chiffre  = openssl_encrypt('Remarque déjà chiffrée', self::CIPHER_METHOD, $key, 0, $iv);
        $alreadyEncrypted = base64_encode($iv . $chiffre);

        $controleManager = $this->makeControleManagerMock();
        $controleManager->method('findId')->willReturn([
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => date('Y-m-d H:i:s'),
            'hash_remarques' => $alreadyEncrypted,
        ]);

        // ⚠️ save() DOIT être appelé avec hash_remarques INCHANGÉ
        $controleManager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $c) use ($alreadyEncrypted): bool {
                return ($c['hash_remarques'] ?? null) === $alreadyEncrypted;
            }))
            ->willReturn(true);

        $controller = $this->makeController($controleManager);

        $request = $this->makePostRequest(['id' => '5']);

        $result = $controller->cloturer($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/admin/controles', $result->headers->get('Location'));
    }

    public function testCloturerEncryptsLegacyPlaintextValue(): void
    {
        $controleManager = $this->makeControleManagerMock();
        $controleManager->method('findId')->willReturn([
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => date('Y-m-d H:i:s'),
            'hash_remarques' => 'Remarque legacy en clair',  // ← legacy
        ]);

        // ⚠️ save() DOIT recevoir un ciphertext déchiffrable vers le legacy
        $controleManager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $c): bool {
                $stored = $c['hash_remarques'] ?? null;
                if (!is_string($stored) || $stored === '') {
                    return false;
                }
                $data = base64_decode($stored, true);
                if ($data === false) {
                    return false;
                }
                $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
                if (strlen($data) <= $ivLength) {
                    return false;
                }
                $iv        = substr($data, 0, $ivLength);
                $chiffre   = substr($data, $ivLength);
                $key       = hex2bin(self::FIXTURE_SECRET_KEY_HEX);
                $decrypted = openssl_decrypt($chiffre, self::CIPHER_METHOD, $key, 0, $iv);
                return $decrypted === 'Remarque legacy en clair';
            }))
            ->willReturn(true);

        $controller = $this->makeController($controleManager);

        $request = $this->makePostRequest(['id' => '5']);

        $result = $controller->cloturer($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeController(
        ControleManager $controleManager,
        ?ControleLigneManager $controleLigneManager = null,
        ?ConfigProvider $configProvider = null,
    ): TestableControleController {
        $ligneManager = $controleLigneManager ?? $this->makeEmptyLigneManager();
        
        $builder = (new TestableControleControllerBuilder($this))
        ->withSession($this->makeSession())
        ->withControleManager($controleManager)
        ->withControleLigneManager($ligneManager);
        
        if ($configProvider !== null) {
            $builder->withConfigProvider($configProvider);
        }
        
        return $builder->build();
    }
    
    private function makeEmptyLigneManager(): ControleLigneManager
    {
        $m = (new MockBuilder($this, ControleLigneManager::class))
        ->disableOriginalConstructor()
        ->getMock();
        $m->method('findByControle')->willReturn([]);
        $m->method('findId')->willReturn(null);
        return $m;
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'                   => 42,
            'username'             => 'controleur',
            'role'                 => 'ROLE_CONTROLLEUR',
            'controle_en_cours_id' => null,
        ]);
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    /**
     * @param array<string,mixed> $post
     */
    private function makePostRequest(array $post): Request
    {
        $post['csrf_token'] = $post['csrf_token'] ?? 'test-csrf-token';

        return Request::create(
            '/admin/controles/edit',
            'POST',
            $post,
        );
    }

    private function makeControleManagerMock(): ControleManager
    {
        return (new MockBuilder($this, ControleManager::class))
            ->disableOriginalConstructor()
            ->getMock();
    }
}