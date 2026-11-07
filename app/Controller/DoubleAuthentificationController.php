<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Epiclub\Domain\DoubleAuthentificationManager;
use Epiclub\Domain\JournalActiviteManager;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\PasskeyService;
use Epiclub\Engine\Totp;
use Epiclub\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [migration 020] Double authentification FACULTATIVE, ouverte à tous les
 * rôles (chacun l'active pour son propre compte) :
 *
 *  - application d'authentification (codes TOTP à 6 chiffres) ;
 *  - passkeys (WebAuthn) : second facteur après le mot de passe, ou
 *    connexion directe sans mot de passe (vérification de l'utilisateur
 *    par l'appareil : empreinte, visage, code PIN) ;
 *  - codes de secours à usage unique, générés avec le premier facteur.
 *
 * Une fois un facteur configuré, il est exigé à chaque connexion par mot
 * de passe (AppUserAuthenticator::deuxiemeFacteurRequis()). Un
 * super-administrateur peut la réinitialiser pour un compte qui a perdu
 * ses facteurs (UtilisateurController::reinitialiserDoubleAuthentification()).
 *
 * Les réglages (page « Sécurité du compte ») exigent une preuve d'identité
 * récente : connexion ou mot de passe confirmé depuis moins de
 * DUREE_CONFIRMATION secondes — un vol de session seul ne suffit pas à
 * ajouter sa propre passkey ni à retirer la double authentification.
 */
class DoubleAuthentificationController extends AbstractController
{
    /** Validité d'une preuve d'identité pour modifier les réglages. */
    public const DUREE_CONFIRMATION = 600;

    /** Échecs de second facteur tolérés par compte avant blocage progressif. */
    public const SEUIL_SECOND_FACTEUR = 5;

    /** Échecs de confirmation du mot de passe tolérés par compte. */
    public const SEUIL_CONFIRMATION = 5;

    private const MESSAGE_LIMITEUR_INDISPONIBLE = 'Vérification momentanément indisponible. Réessayez dans quelques minutes.';

    private const SESSION_TOTP_EN_PREPARATION = 'totp_en_preparation';
    private const SESSION_CODES_A_AFFICHER = 'codes_secours_a_afficher';
    private const SESSION_CEREMONIE_CONNEXION = 'webauthn_connexion';
    private const SESSION_CEREMONIE_ENREGISTREMENT = 'webauthn_enregistrement';

    // ==================================================================
    // Factories — surchargées en test Unit
    // ==================================================================

    protected function doubleAuthentification(): DoubleAuthentificationManager
    {
        return new DoubleAuthentificationManager();
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }

    protected function authenticator(): AppUserAuthenticator
    {
        return new AppUserAuthenticator($this->session);
    }

    protected function loginThrottle(): LoginThrottleManager
    {
        return new LoginThrottleManager();
    }

    protected function passkeyService(): PasskeyService
    {
        return new PasskeyService($this->getBaseUrl());
    }

    protected function maintenant(): int
    {
        return time();
    }

    // ==================================================================
    // Connexion : second facteur
    // ==================================================================

    /**
     * GET : formulaire du second facteur (code de l'application, code de
     * secours, ou passkey). POST : vérification du code.
     */
    public function verification(Request $request): Response
    {
        $enAttente = $this->connexionEnAttente();
        if ($enAttente === null) {
            $this->session->getFlashBag()->add('note', 'Votre connexion a expiré : saisissez de nouveau votre identifiant et votre mot de passe.');
            return new RedirectResponse('/se_connecter');
        }

        $utilisateur = $this->utilisateurManager()->findId($enAttente['id']);
        if (!is_array($utilisateur)) {
            $this->session->remove(AppUserAuthenticator::SESSION_EN_ATTENTE);
            return new RedirectResponse('/se_connecter');
        }

        $manager = $this->doubleAuthentification();
        $etat = $manager->etat((int) $utilisateur['id']) ?? ['totp_active_le' => null, 'codes_secours' => [], 'totp_secret' => null, 'totp_dernier_pas' => null];
        $erreur = null;

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request);
            $cle = 'login-2fa:' . $utilisateur['id'];
            $attente = $this->attenteOuPanne($cle);

            if ($attente === null) {
                $erreur = self::MESSAGE_LIMITEUR_INDISPONIBLE;
            } elseif ($attente > 0) {
                $minutes = (int) ceil($attente / 60);
                $erreur = sprintf('Trop de codes incorrects. Réessayez dans %d minute%s.', $minutes, $minutes > 1 ? 's' : '');
            } else {
                $saisie = trim((string) $request->request->get('code', ''));
                $moyen = $this->verifierCode($manager, (int) $utilisateur['id'], $etat, $saisie);

                if ($moyen !== null) {
                    $this->limiter(fn (LoginThrottleManager $t) => $t->reinitialiser($cle));
                    if ($moyen === 'code de secours') {
                        $restants = count($etat['codes_secours']) - 1;
                        $this->session->getFlashBag()->add(
                            $restants <= 2 ? 'warning' : 'info',
                            sprintf(
                                'Connexion avec un code de secours : il vous en reste %d. Pensez à en générer de nouveaux (Sécurité du compte).',
                                $restants
                            )
                        );
                    }
                    return $this->finaliserConnexion($utilisateur, $enAttente, $moyen, $request);
                }

                $this->limiter(fn (LoginThrottleManager $t) => $t->enregistrerEchec($cle, self::SEUIL_SECOND_FACTEUR));
                $erreur = 'Code incorrect.';
                $this->journaliser(
                    JournalActiviteManager::TYPE_CONNEXION,
                    'Échec de connexion : code de double authentification incorrect',
                    $request,
                    $utilisateur
                );
            }
        }

        return $this->render('user_login_verification.twig', [
            'a_application'     => $etat['totp_active_le'] !== null,
            'a_passkey'         => $manager->compterPasskeys((int) $utilisateur['id']) > 0,
            'a_codes_secours'   => $etat['codes_secours'] !== [],
            'erreur'            => $erreur,
        ]);
    }

    /**
     * Code de l'application (6 chiffres) ou code de secours : retourne le
     * moyen reconnu, ou null.
     *
     * @param array{totp_secret: ?string, totp_active_le: ?string, totp_dernier_pas: ?int, codes_secours: list<string>} $etat
     */
    private function verifierCode(DoubleAuthentificationManager $manager, int $utilisateurId, array $etat, string $saisie): ?string
    {
        if ($saisie === '') {
            return null;
        }

        if (preg_match('/^[\d\s]+$/', $saisie) === 1) {
            if ($etat['totp_active_le'] === null || !is_string($etat['totp_secret'])) {
                return null;
            }
            $secret = $this->encryptionService()->decrypt($etat['totp_secret']);
            if (!is_string($secret) || $secret === '') {
                error_log(sprintf('[DoubleAuthentification] Secret TOTP indéchiffrable (utilisateur %d).', $utilisateurId));
                return null;
            }
            $pas = Totp::verifier($secret, $saisie, $this->maintenant(), $etat['totp_dernier_pas']);
            return $pas !== null && $manager->consommerPasTotp($utilisateurId, $pas) ? 'application' : null;
        }

        return $manager->consommerCodeSecours($utilisateurId, $saisie) ? 'code de secours' : null;
    }

    /**
     * Options WebAuthn de connexion (JSON) : passkeys du compte en attente
     * (second facteur), sinon toute passkey découvrable (connexion sans
     * mot de passe, vérification de l'utilisateur exigée).
     */
    public function passkeyOptionsConnexion(Request $request): Response
    {
        if (($refus = $this->refusJson($request)) !== null) {
            return $refus;
        }
        if (($raison = $this->passkeysIndisponibles($request)) !== null) {
            return $this->erreurJson($raison, 409);
        }

        $enAttente = $this->connexionEnAttente();
        $autorisees = [];
        if ($enAttente !== null) {
            $autorisees = $this->doubleAuthentification()->identifiantsPasskeys($enAttente['id']);
            if ($autorisees === []) {
                return $this->erreurJson('Aucune passkey n\'est enregistrée pour ce compte.', 400);
            }
        }

        try {
            $ceremonie = $this->passkeyService()->optionsConnexion($autorisees, $enAttente === null);
        } catch (\RuntimeException $e) {
            return $this->erreurJson($e->getMessage(), 503);
        }

        $this->session->set(self::SESSION_CEREMONIE_CONNEXION, [
            'challenge'      => bin2hex($ceremonie['challenge']),
            'utilisateur_id' => $enAttente['id'] ?? null,
            'expire'         => $this->maintenant() + PasskeyService::DELAI + 30,
        ]);

        return new JsonResponse($ceremonie['options']);
    }

    /** Vérifie la passkey présentée et ouvre la session (JSON : {redirect}). */
    public function passkeyConnexion(Request $request): Response
    {
        if (($refus = $this->refusJson($request)) !== null) {
            return $refus;
        }

        $ceremonie = $this->session->get(self::SESSION_CEREMONIE_CONNEXION);
        // Usage unique du challenge, réussite ou échec.
        $this->session->remove(self::SESSION_CEREMONIE_CONNEXION);
        if (!is_array($ceremonie) || ($ceremonie['expire'] ?? 0) < $this->maintenant()) {
            return $this->erreurJson('Délai dépassé : recommencez.', 400);
        }

        $donnees = $this->corpsJson($request);
        $credentialId = $donnees['id'] ?? null;
        if (!is_string($credentialId) || $credentialId === '') {
            return $this->erreurJson('Réponse de la passkey incomplète.', 400);
        }

        $manager = $this->doubleAuthentification();
        $passkey = $manager->passkeyParIdentifiant($credentialId);
        $enAttente = $this->connexionEnAttente();
        $attendu = $ceremonie['utilisateur_id'] ?? null;

        // Second facteur : la passkey doit appartenir au compte dont le
        // mot de passe vient d'être vérifié.
        if ($passkey === null
            || ($attendu !== null && ($enAttente === null || $enAttente['id'] !== $attendu || $passkey['utilisateur_id'] !== $attendu))
        ) {
            return $this->erreurJson('Cette passkey n\'est pas enregistrée pour ce compte.', 401);
        }

        $handle = $donnees['userHandle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            try {
                $proprietaire = PasskeyService::utilisateurDepuisIdentifiant(PasskeyService::decoderBase64Url($handle));
            } catch (\RuntimeException) {
                $proprietaire = null;
            }
            if ($proprietaire !== $passkey['utilisateur_id']) {
                return $this->erreurJson('Cette passkey n\'est pas enregistrée pour ce compte.', 401);
            }
        }

        $utilisateur = $this->utilisateurManager()->findId($passkey['utilisateur_id']);
        if (!is_array($utilisateur)) {
            return $this->erreurJson('Compte introuvable.', 401);
        }

        try {
            $compteur = $this->passkeyService()->verifierConnexion(
                (string) ($donnees['clientDataJSON'] ?? ''),
                (string) ($donnees['authenticatorData'] ?? ''),
                (string) ($donnees['signature'] ?? ''),
                $passkey['cle_publique'],
                (string) hex2bin((string) $ceremonie['challenge']),
                $passkey['compteur'],
                $attendu === null
            );
        } catch (\RuntimeException $e) {
            error_log('[DoubleAuthentification] ' . $e->getMessage());
            $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Échec de connexion : passkey refusée', $request, $utilisateur);
            return $this->erreurJson('La passkey n\'a pas pu être vérifiée.', 401);
        }

        $manager->passkeyUtilisee($passkey['id'], $compteur);

        if ($attendu === null) {
            $cible = $donnees['redirect'] ?? null;
            $enAttente = ['id' => (int) $utilisateur['id']];
            if (is_string($cible) && AppUserAuthController::estRedirectionLocale($cible)) {
                $enAttente['redirect'] = $cible;
            }
        }

        $reponse = $this->finaliserConnexion($utilisateur, $enAttente, $attendu === null ? 'passkey, sans mot de passe' : 'passkey', $request);
        return new JsonResponse(['redirect' => $reponse->getTargetUrl()]);
    }

    /**
     * @param array<string, mixed> $utilisateur ligne complète
     * @param array<string, mixed> $enAttente   connexion en attente (cible ?redirect=)
     */
    private function finaliserConnexion(array $utilisateur, array $enAttente, string $moyen, Request $request): RedirectResponse
    {
        $this->authenticator()->ouvrirSession($utilisateur);
        $this->session->set('csrf_token', bin2hex(random_bytes(32)));
        $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, sprintf('Connexion (%s)', $moyen), $request);

        $cible = $enAttente['redirect'] ?? null;
        return new RedirectResponse(is_string($cible) && AppUserAuthController::estRedirectionLocale($cible) ? $cible : '/');
    }

    /**
     * @return array{id: int, expire: int, redirect?: string}|null
     */
    private function connexionEnAttente(): ?array
    {
        $enAttente = $this->session->get(AppUserAuthenticator::SESSION_EN_ATTENTE);
        if (!is_array($enAttente) || !isset($enAttente['id'], $enAttente['expire'])) {
            return null;
        }
        if ((int) $enAttente['expire'] < $this->maintenant()) {
            $this->session->remove(AppUserAuthenticator::SESSION_EN_ATTENTE);
            return null;
        }
        $enAttente['id'] = (int) $enAttente['id'];
        $enAttente['expire'] = (int) $enAttente['expire'];
        return $enAttente;
    }

    // ==================================================================
    // Page « Sécurité du compte »
    // ==================================================================

    /**
     * GET : état de la double authentification. POST : confirmation du
     * mot de passe (déverrouille les réglages pendant 10 minutes).
     */
    public function securite(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        $moi = $this->utilisateurConnecte();
        $erreur = null;

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request);
            $erreur = $this->confirmerMotDePasse($moi, (string) $request->request->get('mot_de_passe', ''));
            if ($erreur === null) {
                return new RedirectResponse('/mon-compte/securite');
            }
        }

        $manager = $this->doubleAuthentification();
        $etat = $manager->etat($moi['id']);
        $codes = $this->session->get(self::SESSION_CODES_A_AFFICHER);
        $this->session->remove(self::SESSION_CODES_A_AFFICHER);

        return $this->render('mon_compte_securite.twig', [
            'confirmee'        => $this->confirmationRecente(),
            'erreur'           => $erreur,
            'application'      => $etat['totp_active_le'] ?? null,
            'codes_restants'   => count($etat['codes_secours'] ?? []),
            'passkeys'         => $manager->passkeys($moi['id']),
            'codes_a_afficher' => is_array($codes) ? $codes : [],
            'passkeys_indisponibles' => $this->passkeysIndisponibles($request),
        ]);
    }

    /**
     * GET : QR code et secret de l'application. POST : vérification du
     * premier code puis activation.
     */
    public function application(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        if (($redirection = $this->exigerConfirmation()) !== null) {
            return $redirection;
        }
        $moi = $this->utilisateurConnecte();
        $manager = $this->doubleAuthentification();
        $etat = $manager->etat($moi['id']);
        if (($etat['totp_active_le'] ?? null) !== null) {
            return new RedirectResponse('/mon-compte/securite');
        }

        $secret = $this->session->get(self::SESSION_TOTP_EN_PREPARATION);
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::genererSecret();
            $this->session->set(self::SESSION_TOTP_EN_PREPARATION, $secret);
        }
        $erreur = null;

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request);
            $pas = Totp::verifier($secret, (string) $request->request->get('code', ''), $this->maintenant());
            if ($pas !== null) {
                $manager->activerTotp($moi['id'], $this->encryptionService()->encrypt($secret), $pas);
                $this->session->remove(self::SESSION_TOTP_EN_PREPARATION);
                $this->premierFacteur($manager, $moi['id'], $etat);
                $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Double authentification : application activée', $request);
                $this->session->getFlashBag()->add('success', 'Application d\'authentification activée : elle vous sera demandée à chaque connexion.');
                return new RedirectResponse('/mon-compte/securite');
            }
            $erreur = 'Code incorrect : vérifiez l\'heure de votre téléphone et saisissez le code affiché.';
        }

        $uri = Totp::uri($secret, (string) ($moi['username'] ?? ''), $this->emetteur());

        return $this->render('mon_compte_application.twig', [
            'qr_code' => $this->qrCodeDataUri($uri),
            'secret'  => Totp::formaterPourSaisie($secret),
            'erreur'  => $erreur,
        ]);
    }

    public function desactiverApplication(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        $this->validateCsrf($request);
        if (($redirection = $this->exigerConfirmation()) !== null) {
            return $redirection;
        }
        $moi = $this->utilisateurConnecte();
        $manager = $this->doubleAuthentification();
        $manager->desactiverTotp($moi['id']);
        $this->retirerCodesSiPlusAucunFacteur($manager, $moi['id']);
        $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Double authentification : application désactivée', $request);
        $this->session->getFlashBag()->add('success', 'Application d\'authentification désactivée.');
        return new RedirectResponse('/mon-compte/securite');
    }

    public function codesSecours(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        $this->validateCsrf($request);
        if (($redirection = $this->exigerConfirmation()) !== null) {
            return $redirection;
        }
        $moi = $this->utilisateurConnecte();
        $manager = $this->doubleAuthentification();
        if (!$manager->estConfiguree($moi['id'])) {
            $this->session->getFlashBag()->add('warning', 'Activez d\'abord une application d\'authentification ou une passkey.');
            return new RedirectResponse('/mon-compte/securite');
        }
        $this->session->set(self::SESSION_CODES_A_AFFICHER, $manager->genererCodesSecours($moi['id']));
        $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Double authentification : nouveaux codes de secours', $request);
        return new RedirectResponse('/mon-compte/securite');
    }

    /** Options WebAuthn d'enregistrement d'une passkey (JSON). */
    public function passkeyOptionsEnregistrement(Request $request): Response
    {
        if (($refus = $this->refusJson($request, true)) !== null) {
            return $refus;
        }
        if (($raison = $this->passkeysIndisponibles($request)) !== null) {
            return $this->erreurJson($raison, 409);
        }
        $moi = $this->utilisateurConnecte();

        try {
            $ceremonie = $this->passkeyService()->optionsEnregistrement(
                $moi['id'],
                (string) ($moi['username'] ?? ''),
                trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: (string) ($moi['username'] ?? ''),
                $this->doubleAuthentification()->identifiantsPasskeys($moi['id'])
            );
        } catch (\RuntimeException $e) {
            return $this->erreurJson($e->getMessage(), 503);
        }

        $this->session->set(self::SESSION_CEREMONIE_ENREGISTREMENT, [
            'challenge' => bin2hex($ceremonie['challenge']),
            'expire'    => $this->maintenant() + PasskeyService::DELAI + 30,
        ]);
        return new JsonResponse($ceremonie['options']);
    }

    /** Enregistre la passkey créée par le navigateur (JSON : {redirect}). */
    public function passkeyEnregistrer(Request $request): Response
    {
        if (($refus = $this->refusJson($request, true)) !== null) {
            return $refus;
        }
        $moi = $this->utilisateurConnecte();

        $ceremonie = $this->session->get(self::SESSION_CEREMONIE_ENREGISTREMENT);
        $this->session->remove(self::SESSION_CEREMONIE_ENREGISTREMENT);
        if (!is_array($ceremonie) || ($ceremonie['expire'] ?? 0) < $this->maintenant()) {
            return $this->erreurJson('Délai dépassé : recommencez.', 400);
        }

        $donnees = $this->corpsJson($request);
        try {
            $passkey = $this->passkeyService()->verifierEnregistrement(
                (string) ($donnees['clientDataJSON'] ?? ''),
                (string) ($donnees['attestationObject'] ?? ''),
                (string) hex2bin((string) $ceremonie['challenge'])
            );
        } catch (\RuntimeException $e) {
            error_log('[DoubleAuthentification] ' . $e->getMessage());
            return $this->erreurJson('La passkey n\'a pas pu être enregistrée.', 400);
        }

        $nom = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($donnees['nom'] ?? '')) ?? ''), 0, 64);
        if ($nom === '') {
            $nom = 'Passkey du ' . date('d/m/Y', $this->maintenant());
        }

        $manager = $this->doubleAuthentification();
        $etat = $manager->etat($moi['id']);
        try {
            $manager->ajouterPasskey($moi['id'], $passkey['credential_id'], $passkey['cle_publique'], $passkey['compteur'], $nom);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->erreurJson('Cette passkey est déjà enregistrée.', 409);
            }
            throw $e;
        }
        $this->premierFacteur($manager, $moi['id'], $etat);
        $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, sprintf('Double authentification : passkey « %s » ajoutée', $nom), $request);
        $this->session->getFlashBag()->add('success', sprintf('Passkey « %s » enregistrée.', $nom));

        return new JsonResponse(['redirect' => '/mon-compte/securite']);
    }

    public function passkeySupprimer(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        $this->validateCsrf($request);
        if (($redirection = $this->exigerConfirmation()) !== null) {
            return $redirection;
        }
        $id = $this->getValidId($request);
        $moi = $this->utilisateurConnecte();
        $manager = $this->doubleAuthentification();
        if ($id !== null && $manager->supprimerPasskey($moi['id'], $id)) {
            $this->retirerCodesSiPlusAucunFacteur($manager, $moi['id']);
            $this->journaliser(JournalActiviteManager::TYPE_CONNEXION, 'Double authentification : passkey supprimée', $request);
            $this->session->getFlashBag()->add('success', 'Passkey supprimée.');
        }
        return new RedirectResponse('/mon-compte/securite');
    }

    // ==================================================================
    // Outils
    // ==================================================================

    /**
     * Premier facteur configuré : codes de secours générés (affichés une
     * seule fois sur la page Sécurité du compte).
     *
     * @param array<string, mixed>|null $etatAvant
     */
    private function premierFacteur(DoubleAuthentificationManager $manager, int $utilisateurId, ?array $etatAvant): void
    {
        if (($etatAvant['codes_secours'] ?? []) === []) {
            $this->session->set(self::SESSION_CODES_A_AFFICHER, $manager->genererCodesSecours($utilisateurId));
        }
    }

    private function retirerCodesSiPlusAucunFacteur(DoubleAuthentificationManager $manager, int $utilisateurId): void
    {
        if (!$manager->estConfiguree($utilisateurId)) {
            $manager->supprimerCodesSecours($utilisateurId);
        }
    }

    /** @return array<string, mixed> utilisateur de la session (id entier) */
    private function utilisateurConnecte(): array
    {
        $moi = $this->session->get('user');
        if (!is_array($moi) || !isset($moi['id'])) {
            throw new AccessDeniedException('Non connecté.');
        }
        $moi['id'] = (int) $moi['id'];
        return $moi;
    }

    private function confirmationRecente(): bool
    {
        $moi = $this->session->get('user');
        $le = is_array($moi) ? (int) ($moi[AppUserAuthenticator::CLE_AUTHENTIFIE_LE] ?? 0) : 0;
        return $le > 0 && $this->maintenant() - $le <= self::DUREE_CONFIRMATION;
    }

    private function exigerConfirmation(): ?RedirectResponse
    {
        if ($this->confirmationRecente()) {
            return null;
        }
        $this->session->getFlashBag()->add('warning', 'Confirmez votre mot de passe pour modifier la double authentification.');
        return new RedirectResponse('/mon-compte/securite');
    }

    /**
     * @param array<string, mixed> $moi
     * @return string|null message d'erreur, null si confirmé
     */
    private function confirmerMotDePasse(array $moi, string $motDePasse): ?string
    {
        $cle = 'confirm-2fa:' . $moi['id'];
        $attente = $this->attenteOuPanne($cle);
        if ($attente === null) {
            return self::MESSAGE_LIMITEUR_INDISPONIBLE;
        }
        if ($attente > 0) {
            return sprintf('Trop de tentatives. Réessayez dans %d minute(s).', (int) ceil($attente / 60));
        }

        $enBase = $this->utilisateurManager()->findId($moi['id']);
        if (is_array($enBase) && is_string($enBase['password'] ?? null) && password_verify($motDePasse, $enBase['password'])) {
            $this->limiter(fn (LoginThrottleManager $t) => $t->reinitialiser($cle));
            $moi[AppUserAuthenticator::CLE_AUTHENTIFIE_LE] = $this->maintenant();
            $this->session->set('user', $moi);
            return null;
        }

        $this->limiter(fn (LoginThrottleManager $t) => $t->enregistrerEchec($cle, self::SEUIL_CONFIRMATION));
        return 'Mot de passe incorrect.';
    }

    /**
     * Garde des routes JSON : CSRF (en-tête X-CSRF-Token) et, pour les
     * réglages, rôle et confirmation récente. null si la requête passe.
     */
    private function refusJson(Request $request, bool $reglages = false): ?JsonResponse
    {
        try {
            $this->validateCsrf($request);
        } catch (AccessDeniedException) {
            return $this->erreurJson('Session expirée : rechargez la page.', 403);
        }
        if ($reglages) {
            if (!$this->isGranted('ROLE_USER')) {
                return $this->erreurJson('Accès refusé.', 403);
            }
            if (!$this->confirmationRecente()) {
                return $this->erreurJson('Confirmez de nouveau votre mot de passe (rechargez la page).', 403);
            }
        }
        return null;
    }

    private function erreurJson(string $message, int $statut): JsonResponse
    {
        return new JsonResponse(['erreur' => $message], $statut);
    }

    /** @return array<string, mixed> */
    private function corpsJson(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * [SÉCURITÉ — audit 2026-11-07] Secondes de blocage restantes, ou null
     * si le limiteur est indisponible. Contrairement à la connexion par mot
     * de passe, le second facteur (6 chiffres) et la confirmation du mot de
     * passe sont REFUSÉS dans ce cas : sans limiteur, un code à 6 chiffres
     * se devine en quelques heures.
     */
    private function attenteOuPanne(string $cle): ?int
    {
        try {
            return $this->loginThrottle()->secondesRestantes($cle);
        } catch (\Throwable $e) {
            error_log('[DoubleAuthentification] Limiteur indisponible, vérification refusée : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Opération du limiteur (enregistrement d'un échec, remise à zéro) —
     * n'échoue jamais : la vérification préalable (attenteOuPanne()) a
     * déjà refusé la tentative si le limiteur est indisponible.
     */
    private function limiter(callable $operation): mixed
    {
        try {
            return $operation($this->loginThrottle());
        } catch (\Throwable $e) {
            error_log('[DoubleAuthentification] Limiteur indisponible : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Raison pour laquelle les passkeys sont inutilisables depuis cette
     * page, ou null si elles le sont : ROOT_URL hors HTTPS, ou adresse
     * du navigateur différente de ROOT_URL (une passkey est liée au
     * domaine de ROOT_URL : le navigateur la refuserait).
     *
     * L'hôte de la requête ne sert qu'à ce diagnostic : la sécurité
     * repose sur la vérification de l'origine (PasskeyService).
     */
    protected function passkeysIndisponibles(Request $request): ?string
    {
        try {
            $service = $this->passkeyService();
        } catch (\RuntimeException $e) {
            return $e->getMessage() . ' (adresse ROOT_URL des réglages système).';
        }
        // Schéma, hôte et port : la passkey est refusée au moindre écart.
        $adresse = strtolower($request->getSchemeAndHttpHost());
        if ($adresse !== $service->origine()) {
            return sprintf(
                'Les passkeys sont liées à l\'adresse du site déclarée dans ROOT_URL (%s), mais vous naviguez sur %s. '
                . 'Ouvrez le site par son adresse officielle, ou corrigez ROOT_URL dans les réglages système.',
                $service->origine(),
                $adresse
            );
        }
        return null;
    }

    /** Nom affiché dans l'application d'authentification. */
    private function emetteur(): string
    {
        try {
            $club = $this->clubManagerModules()->findParameters();
            $nom = is_array($club) ? trim((string) ($club['nom'] ?? '')) : '';
        } catch (\Throwable) {
            $nom = '';
        }
        return $nom === '' ? 'EPIClub' : 'EPIClub ' . $nom;
    }

    protected function qrCodeDataUri(string $donnees): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $donnees,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 240,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );
        return $builder->build()->getDataUri();
    }
}
