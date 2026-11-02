<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\PasskeyService;
use Epiclub\Tests\Unit\Support\AuthentificateurLogiciel;
use PHPUnit\Framework\TestCase;

/**
 * [migration 020] Passkeys : cérémonies complètes avec un authentificateur
 * logiciel (ES256, attestation « none »).
 */
final class PasskeyServiceTest extends TestCase
{
    private const ORIGINE = 'https://epi.club.example';

    public function testEnregistrementPuisConnexion(): void
    {
        $service = new PasskeyService(self::ORIGINE . '/');
        $appareil = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);

        $options = $service->optionsEnregistrement(7, 'jean', 'Jean Dupont', []);
        self::assertSame('epi.club.example', $options['options']['publicKey']['rp']['id']);
        self::assertSame('none', $options['options']['publicKey']['attestation']);
        self::assertSame(
            AuthentificateurLogiciel::b64(PasskeyService::identifiantUtilisateur(7)),
            $options['options']['publicKey']['user']['id']
        );
        self::assertSame(AuthentificateurLogiciel::b64($options['challenge']), $options['options']['publicKey']['challenge']);

        $reponse = $appareil->creer($options['challenge']);
        $passkey = $service->verifierEnregistrement($reponse['clientDataJSON'], $reponse['attestationObject'], $options['challenge']);
        self::assertSame(AuthentificateurLogiciel::b64($appareil->credentialId), $passkey['credential_id']);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $passkey['cle_publique']);

        $connexion = $service->optionsConnexion([$passkey['credential_id']], true);
        self::assertSame($passkey['credential_id'], $connexion['options']['publicKey']['allowCredentials'][0]['id']);
        self::assertSame('required', $connexion['options']['publicKey']['userVerification']);

        $assertion = $appareil->signer($connexion['challenge']);
        $compteur = $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], $connexion['challenge'], $passkey['compteur'], true
        );
        self::assertSame(1, $compteur);
    }

    public function testConnexionRefuseUnAutreChallenge(): void
    {
        [$service, $appareil, $passkey] = $this->enregistrer();
        $assertion = $appareil->signer(random_bytes(32));

        $this->expectException(\RuntimeException::class);
        $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], random_bytes(32), 0, false
        );
    }

    public function testConnexionRefuseUneSignatureFalsifiee(): void
    {
        [$service, $appareil, $passkey] = $this->enregistrer();
        $challenge = random_bytes(32);
        $assertion = $appareil->signer($challenge);
        $autre = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);
        $faux = $autre->signer($challenge);

        $this->expectException(\RuntimeException::class);
        $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $faux['signature'],
            $passkey['cle_publique'], $challenge, 0, false
        );
    }

    public function testConnexionRefuseUneOrigineVoisine(): void
    {
        [$service, $appareil, $passkey] = $this->enregistrer();
        $challenge = random_bytes(32);
        // Suffixe de domaine accepté par la bibliothèque seule : refusé ici.
        $assertion = $appareil->signer($challenge, true, 'https://pirate-epi.club.example');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('origine');
        $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], $challenge, 0, false
        );
    }

    public function testVerificationUtilisateurExigeeSansMotDePasse(): void
    {
        [$service, $appareil, $passkey] = $this->enregistrer();
        $challenge = random_bytes(32);
        $assertion = $appareil->signer($challenge, false);

        // Second facteur : la présence suffit.
        self::assertSame(1, $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], $challenge, 0, false
        ));

        $challenge = random_bytes(32);
        $assertion = $appareil->signer($challenge, false);
        $this->expectException(\RuntimeException::class);
        $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], $challenge, 1, true
        );
    }

    public function testCompteurQuiReculeRefuse(): void
    {
        [$service, $appareil, $passkey] = $this->enregistrer();
        $challenge = random_bytes(32);
        $assertion = $appareil->signer($challenge); // compteur 1

        $this->expectException(\RuntimeException::class);
        $service->verifierConnexion(
            $assertion['clientDataJSON'], $assertion['authenticatorData'], $assertion['signature'],
            $passkey['cle_publique'], $challenge, 5, false
        );
    }

    public function testEnregistrementRefuseUneAutreOrigine(): void
    {
        $service = new PasskeyService(self::ORIGINE);
        $appareil = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);
        $options = $service->optionsEnregistrement(7, 'jean', 'Jean', []);
        $reponse = $appareil->creer($options['challenge'], 'https://epi.club.example:8443');

        $this->expectException(\RuntimeException::class);
        $service->verifierEnregistrement($reponse['clientDataJSON'], $reponse['attestationObject'], $options['challenge']);
    }

    public function testHttpRefuseHorsLocalhost(): void
    {
        self::assertSame('localhost', (new PasskeyService('http://localhost:8000'))->rpId());
        $this->expectException(\RuntimeException::class);
        new PasskeyService('http://epi.club.example');
    }

    public function testIdentifiantUtilisateur(): void
    {
        self::assertSame(42, PasskeyService::utilisateurDepuisIdentifiant(PasskeyService::identifiantUtilisateur(42)));
        self::assertNull(PasskeyService::utilisateurDepuisIdentifiant('42'));
        self::assertNull(PasskeyService::utilisateurDepuisIdentifiant('epiclub-utilisateur-0'));
    }

    public function testBase64UrlMalForme(): void
    {
        $this->expectException(\RuntimeException::class);
        PasskeyService::decoderBase64Url('a+b/');
    }

    /** @return array{PasskeyService, AuthentificateurLogiciel, array{credential_id: string, cle_publique: string, compteur: int}} */
    private function enregistrer(): array
    {
        $service = new PasskeyService(self::ORIGINE);
        $appareil = new AuthentificateurLogiciel('epi.club.example', self::ORIGINE);
        $options = $service->optionsEnregistrement(7, 'jean', 'Jean', []);
        $reponse = $appareil->creer($options['challenge']);
        return [$service, $appareil, $service->verifierEnregistrement($reponse['clientDataJSON'], $reponse['attestationObject'], $options['challenge'])];
    }
}
