<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\ConfigFile;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\EncryptionService;
use Epiclub\Engine\PasskeyService;
use Epiclub\Engine\Session;
use Epiclub\Engine\Totp;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableDoubleAuthentificationController;
use Epiclub\Tests\Unit\Domain\Support\InMemoryDoubleAuthentificationManager;
use Epiclub\Tests\Unit\Support\AuthentificateurLogiciel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [migration 020] Double authentification : second facteur à la
 * connexion (application, code de secours, passkey), connexion sans mot
 * de passe et réglages de la page « Sécurité du compte ».
 */
final class DoubleAuthentificationControllerTest extends TestCase
{
    private const CSRF = 'csrf';
    private const ORIGINE = 'https://epi.club.example';
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const MOT_DE_PASSE = 'motdepasse-tres-long';

    private Session $session;
    private InMemoryDoubleAuthentificationManager $manager;
    /** @var array<int, array<string, mixed>> */
    private array $comptes;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('csrf_token', self::CSRF);
        $this->manager = new InMemoryDoubleAuthentificationManager();
        $this->comptes = [
            7 => ['id' => 7, 'username' => 'jean', 'prenom' => 'Jean', 'nom' => 'Dupont', 'role' => 'ROLE_USER',
                  'password' => password_hash(self::MOT_DE_PASSE, PASSWORD_BCRYPT, ['cost' => 4]),
                  'totp_secret' => 'ne doit pas aller en session'],
            8 => ['id' => 8, 'username' => 'paul', 'prenom' => 'Paul', 'nom' => 'Martin', 'role' => 'ROLE_ADMIN', 'password' => 'x'],
        ];
    }

    // ------------------------------------------------------------------
    // Second facteur à la connexion
    // ------------------------------------------------------------------

    public function testVerificationSansConnexionEnAttenteRenvoieAuFormulaire(): void
    {
        $reponse = $this->controleur()->verification(Request::create('/se_connecter/verification'));

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('/se_connecter', $reponse->getTargetUrl());
    }

    public function testConnexionEnAttenteExpiree(): void
    {
        $c = $this->controleur();
        $this->enAttente(7, $c->horloge - 1);

        $reponse = $c->verification(Request::create('/se_connecter/verification'));

        self::assertSame('/se_connecter', $reponse->getTargetUrl());
        self::assertFalse($this->session->has(AppUserAuthenticator::SESSION_EN_ATTENTE));
    }

    public function testFormulaireDuSecondFacteur(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $this->enAttente(7, $c->horloge + 300);

        $reponse = $c->verification(Request::create('/se_connecter/verification'));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('application d\'authentification', (string) $reponse->getContent());
        self::assertFalse($this->session->has('user'));
    }

    public function testCodeDeLApplicationOuvreLaSession(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $this->enAttente(7, $c->horloge + 300, '/qr/12');

        $reponse = $c->verification($this->post('/se_connecter/verification', ['code' => $this->codeCourant($c)]));

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('/qr/12', $reponse->getTargetUrl());
        $user = $this->session->get('user');
        self::assertSame(7, $user['id']);
        self::assertArrayNotHasKey('password', $user);
        self::assertArrayNotHasKey('totp_secret', $user);
        self::assertGreaterThan(time() - 5, $user[AppUserAuthenticator::CLE_AUTHENTIFIE_LE]);
        self::assertFalse($this->session->has(AppUserAuthenticator::SESSION_EN_ATTENTE));
        self::assertNotSame(self::CSRF, $this->session->get('csrf_token'));
    }

    public function testUnCodeDejaUtiliseEstRefuse(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $code = $this->codeCourant($c);
        $this->enAttente(7, $c->horloge + 300);
        $c->verification($this->post('/se_connecter/verification', ['code' => $code]));

        // Seconde connexion avec le même code (rejeu).
        $this->session->remove('user');
        $this->session->set('csrf_token', self::CSRF);
        $this->enAttente(7, $c->horloge + 300);
        $reponse = $c->verification($this->post('/se_connecter/verification', ['code' => $code]));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('Code incorrect', (string) $reponse->getContent());
        self::assertFalse($this->session->has('user'));
    }

    public function testCodesIncorrectsBloquentLeCompte(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $this->enAttente(7, $c->horloge + 300);

        for ($i = 0; $i < 5; $i++) {
            $c->verification($this->post('/se_connecter/verification', ['code' => '000000']));
        }
        // Même le bon code est refusé pendant le blocage.
        $reponse = $c->verification($this->post('/se_connecter/verification', ['code' => $this->codeCourant($c)]));

        self::assertStringContainsString('Trop de codes incorrects', (string) $reponse->getContent());
        self::assertFalse($this->session->has('user'));
    }

    public function testCodeDeSecoursAUsageUnique(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $codes = $this->manager->genererCodesSecours(7);
        $this->enAttente(7, $c->horloge + 300);

        $reponse = $c->verification($this->post('/se_connecter/verification', ['code' => strtoupper($codes[3])]));
        self::assertSame('/', $reponse->getTargetUrl());
        self::assertCount(9, $this->manager->etats[7]['codes_secours']);

        $this->session->remove('user');
        $this->session->set('csrf_token', self::CSRF);
        $this->enAttente(7, $c->horloge + 300);
        $reponse = $c->verification($this->post('/se_connecter/verification', ['code' => $codes[3]]));
        self::assertFalse($this->session->has('user'));
        self::assertSame(200, $reponse->getStatusCode());
    }

    public function testVerificationSansJetonCsrf(): void
    {
        $c = $this->controleur();
        $this->enAttente(7, $c->horloge + 300);

        $this->expectException(AccessDeniedException::class);
        $c->verification(Request::create('/se_connecter/verification', 'POST', ['code' => '123456']));
    }

    // ------------------------------------------------------------------
    // Passkeys à la connexion
    // ------------------------------------------------------------------

    public function testPasskeyCommeSecondFacteur(): void
    {
        $c = $this->controleur();
        $appareil = $this->enregistrerPasskey(7);
        $this->enAttente(7, $c->horloge + 300, '/equipements');

        $options = $this->json($c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', [])));
        self::assertCount(1, $options['publicKey']['allowCredentials']);
        self::assertSame('preferred', $options['publicKey']['userVerification']);

        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $appareil->signer($this->challengeConnexion())));

        self::assertSame(['redirect' => '/equipements'], $this->json($reponse));
        self::assertSame(7, $this->session->get('user')['id']);
        self::assertSame(1, array_values($this->manager->passkeys)[0]['compteur']);
    }

    public function testPasskeyDUnAutreCompteRefuseeEnSecondFacteur(): void
    {
        $c = $this->controleur();
        $this->enregistrerPasskey(7);
        $autre = $this->enregistrerPasskey(8);
        $this->enAttente(7, $c->horloge + 300);

        $c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', []));
        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $autre->signer($this->challengeConnexion())));

        self::assertSame(401, $reponse->getStatusCode());
        self::assertFalse($this->session->has('user'));
    }

    public function testConnexionSansMotDePasse(): void
    {
        $c = $this->controleur();
        $appareil = $this->enregistrerPasskey(7);

        $options = $this->json($c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', [])));
        self::assertSame([], $options['publicKey']['allowCredentials'] ?? []);
        self::assertSame('required', $options['publicKey']['userVerification']);

        $donnees = $appareil->signer($this->challengeConnexion()) + [
            'userHandle' => AuthentificateurLogiciel::b64(PasskeyService::identifiantUtilisateur(7)),
            'redirect'   => 'https://pirate.example/',
        ];
        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $donnees));

        self::assertSame(['redirect' => '/'], $this->json($reponse));
        self::assertSame(7, $this->session->get('user')['id']);
    }

    public function testConnexionSansMotDePasseExigeLaVerificationDeLUtilisateur(): void
    {
        $c = $this->controleur();
        $appareil = $this->enregistrerPasskey(7);
        $c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', []));

        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $appareil->signer($this->challengeConnexion(), false)));

        self::assertSame(401, $reponse->getStatusCode());
        self::assertFalse($this->session->has('user'));
    }

    public function testUserHandleIncoherentRefuse(): void
    {
        $c = $this->controleur();
        $appareil = $this->enregistrerPasskey(7);
        $c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', []));

        $donnees = $appareil->signer($this->challengeConnexion())
            + ['userHandle' => AuthentificateurLogiciel::b64(PasskeyService::identifiantUtilisateur(8))];
        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $donnees));

        self::assertSame(401, $reponse->getStatusCode());
    }

    public function testChallengeAUsageUnique(): void
    {
        $c = $this->controleur();
        $appareil = $this->enregistrerPasskey(7);
        $c->passkeyOptionsConnexion($this->postJson('/se_connecter/passkey/options', []));
        $donnees = $appareil->signer($this->challengeConnexion());
        $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $donnees));

        $this->session->remove('user');
        $this->session->set('csrf_token', self::CSRF); // régénéré à la connexion
        $reponse = $c->passkeyConnexion($this->postJson('/se_connecter/passkey', $donnees));

        self::assertSame(400, $reponse->getStatusCode());
        self::assertFalse($this->session->has('user'));
    }

    /**
     * Navigateur sur une autre adresse que ROOT_URL : refus explicite
     * avant la cérémonie (le navigateur la rejetterait de toute façon).
     */
    public function testAdresseDuNavigateurDifferenteDeRootUrl(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge);
        $request = Request::create('http://localhost:8000/mon-compte/securite/passkey/options', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF,
        ], '{}');

        $reponse = $c->passkeyOptionsEnregistrement($request);

        self::assertSame(409, $reponse->getStatusCode());
        self::assertStringContainsString('epi.club.example', (string) $reponse->getContent());
        self::assertStringContainsString('localhost', (string) $reponse->getContent());

        $page = $c->securite(Request::create('http://localhost:8000/mon-compte/securite'));
        self::assertStringContainsString('vous naviguez sur http://localhost:8000', (string) $page->getContent());
        self::assertStringNotContainsString('passkey-ajouter', (string) $page->getContent());
    }

    /** Même hôte mais http au lieu de https : refus explicite aussi. */
    public function testSchemaDifferentDeRootUrl(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge);

        $page = $c->securite(Request::create('http://epi.club.example/mon-compte/securite'));

        self::assertStringContainsString('vous naviguez sur http://epi.club.example', (string) $page->getContent());
    }

    public function testOptionsPasskeySansCsrf(): void
    {
        $reponse = $this->controleur()->passkeyOptionsConnexion(Request::create('/se_connecter/passkey/options', 'POST'));
        self::assertSame(403, $reponse->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Page « Sécurité du compte »
    // ------------------------------------------------------------------

    public function testSecuriteDemandeLaConfirmationDuMotDePasse(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge - 3600);

        $reponse = $c->securite(Request::create('/mon-compte/securite'));
        self::assertStringContainsString('Confirmez votre mot de passe', (string) $reponse->getContent());

        $reponse = $c->securite($this->post('/mon-compte/securite', ['mot_de_passe' => 'faux']));
        self::assertStringContainsString('Mot de passe incorrect', (string) $reponse->getContent());

        $reponse = $c->securite($this->post('/mon-compte/securite', ['mot_de_passe' => self::MOT_DE_PASSE]));
        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame($c->horloge, $this->session->get('user')[AppUserAuthenticator::CLE_AUTHENTIFIE_LE]);
    }

    public function testActivationDeLApplicationExigeUneConfirmationRecente(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge - 3600);

        $reponse = $c->application(Request::create('/mon-compte/securite/application'));

        self::assertSame('/mon-compte/securite', $reponse->getTargetUrl());
        self::assertFalse($this->session->has('totp_en_preparation'));
    }

    public function testActivationDeLApplication(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge);

        $page = $c->application(Request::create('/mon-compte/securite/application'));
        self::assertSame(200, $page->getStatusCode());
        $secret = $this->session->get('totp_en_preparation');
        self::assertStringContainsString(Totp::formaterPourSaisie($secret), (string) $page->getContent());

        $faux = $c->application($this->post('/mon-compte/securite/application', ['code' => '000000']));
        self::assertStringContainsString('Code incorrect', (string) $faux->getContent());

        $code = Totp::code($secret, Totp::pas($c->horloge));
        $reponse = $c->application($this->post('/mon-compte/securite/application', ['code' => $code]));

        self::assertSame('/mon-compte/securite', $reponse->getTargetUrl());
        $etat = $this->manager->etats[7];
        self::assertNotNull($etat['totp_active_le']);
        // Secret chiffré en base, jamais en clair.
        self::assertStringNotContainsString($secret, (string) $etat['totp_secret']);
        self::assertSame($secret, $this->chiffrement()->decrypt($etat['totp_secret']));
        // Premier facteur : 10 codes de secours, affichés une fois.
        self::assertCount(10, $etat['codes_secours']);
        $page = $c->securite(Request::create('/mon-compte/securite'));
        self::assertStringContainsString('Vos codes de secours', (string) $page->getContent());
        $page = $c->securite(Request::create('/mon-compte/securite'));
        self::assertStringNotContainsString('Vos codes de secours', (string) $page->getContent());
    }

    public function testDesactivationRetireLesCodesSansAutreFacteur(): void
    {
        $c = $this->controleur();
        $this->activerApplication(7);
        $this->manager->genererCodesSecours(7);
        $this->connecter(7, $c->horloge);

        $c->desactiverApplication($this->post('/mon-compte/securite/application/desactiver'));

        self::assertNull($this->manager->etats[7]['totp_active_le']);
        self::assertSame([], $this->manager->etats[7]['codes_secours']);
    }

    public function testEnregistrementDUnePasskey(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge);
        $appareil = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);

        $options = $this->json($c->passkeyOptionsEnregistrement($this->postJson('/mon-compte/securite/passkey/options', [])));
        $challenge = PasskeyService::decoderBase64Url($options['publicKey']['challenge']);
        $reponse = $c->passkeyEnregistrer($this->postJson('/mon-compte/securite/passkey', $appareil->creer($challenge) + ['nom' => "Mon\ntéléphone"]));

        self::assertSame(['redirect' => '/mon-compte/securite'], $this->json($reponse));
        $passkey = array_values($this->manager->passkeys)[0];
        self::assertSame(7, $passkey['utilisateur_id']);
        self::assertSame('Mon téléphone', $passkey['nom']);
        self::assertCount(10, $this->manager->etats[7]['codes_secours']);
    }

    public function testEnregistrementDUnePasskeyExigeUneConfirmationRecente(): void
    {
        $c = $this->controleur();
        $this->connecter(7, $c->horloge - 3600);

        $reponse = $c->passkeyOptionsEnregistrement($this->postJson('/mon-compte/securite/passkey/options', []));

        self::assertSame(403, $reponse->getStatusCode());
    }

    public function testSuppressionDUnePasskeyDUnAutreCompteImpossible(): void
    {
        $c = $this->controleur();
        $this->enregistrerPasskey(8);
        $this->connecter(7, $c->horloge);
        $id = array_key_first($this->manager->passkeys);

        $c->passkeySupprimer($this->post('/mon-compte/securite/passkey/supprimer-' . $id, [], ['id' => $id]));

        self::assertCount(1, $this->manager->passkeys);
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private function controleur(): TestableDoubleAuthentificationController
    {
        $utilisateurs = $this->createMock(UtilisateurManager::class);
        $utilisateurs->method('findId')->willReturnCallback(fn (int $id) => $this->comptes[$id] ?? null);
        $utilisateurs->method('save')->willReturn(true);
        return new TestableDoubleAuthentificationController($this->session, $this->manager, $utilisateurs);
    }

    private function enAttente(int $id, int $expire, ?string $redirect = null): void
    {
        $this->session->set(AppUserAuthenticator::SESSION_EN_ATTENTE, ['id' => $id, 'expire' => $expire] + ($redirect ? ['redirect' => $redirect] : []));
    }

    private function connecter(int $id, int $authentifieLe): void
    {
        $user = $this->comptes[$id];
        unset($user['password'], $user['totp_secret']);
        $this->session->set('user', $user + [AppUserAuthenticator::CLE_AUTHENTIFIE_LE => $authentifieLe]);
    }

    private function activerApplication(int $id): void
    {
        $this->manager->activerTotp($id, $this->chiffrement()->encrypt(self::SECRET), 0);
    }

    private function codeCourant(TestableDoubleAuthentificationController $c): string
    {
        return Totp::code(self::SECRET, Totp::pas($c->horloge));
    }

    private function enregistrerPasskey(int $id): AuthentificateurLogiciel
    {
        $service = new PasskeyService(self::ORIGINE);
        $appareil = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);
        $options = $service->optionsEnregistrement($id, 'u', 'U', []);
        $r = $appareil->creer($options['challenge']);
        $p = $service->verifierEnregistrement($r['clientDataJSON'], $r['attestationObject'], $options['challenge']);
        $this->manager->ajouterPasskey($id, $p['credential_id'], $p['cle_publique'], $p['compteur'], 'Clé');
        return $appareil;
    }

    private function challengeConnexion(): string
    {
        return (string) hex2bin($this->session->get('webauthn_connexion')['challenge']);
    }

    private function chiffrement(): EncryptionService
    {
        return new EncryptionService(new ConfigProvider(ConfigFile::defaultPath()));
    }

    /** @param array<string, mixed> $donnees */
    private function post(string $uri, array $donnees = [], array $attributs = []): Request
    {
        $request = Request::create(self::ORIGINE . $uri, 'POST', $donnees + ['csrf_token' => self::CSRF]);
        foreach ($attributs as $cle => $valeur) {
            $request->attributes->set($cle, $valeur);
        }
        return $request;
    }

    /** @param array<string, mixed> $donnees */
    private function postJson(string $uri, array $donnees): Request
    {
        return Request::create(self::ORIGINE . $uri, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => self::CSRF,
        ], (string) json_encode($donnees));
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $reponse): array
    {
        self::assertInstanceOf(JsonResponse::class, $reponse, (string) $reponse->getContent());
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        return json_decode((string) $reponse->getContent(), true);
    }
}
