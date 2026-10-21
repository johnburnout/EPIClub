<?php

namespace Epiclub\Engine;

use Twig\TwigFunction;
use Epiclub\Engine\Session;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Domain\ClubManager;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Engine\CsrfValidator;
use Epiclub\Engine\ConfigProvider;

abstract class AbstractController
{
    private TwigRenderer $renderer;

    /** [Prêt de matériel] Cache par requête de club.pret_actif. */
    private ?bool $pretActifCache = null;

    public function __construct(protected Session $session)
    {
        $this->renderer = new TwigRenderer($this->session);

        $this->renderer->addGlobal('csrf_token', $session->get('csrf_token'));

        $this->renderer->addFunction(new TwigFunction('isAuthenticated', function () {
            return $this->isAuthenticated();
        }));

        $this->renderer->addFunction(new TwigFunction('isGranted', function ($role) {
            return $this->isGranted($role);
        }));

        $this->renderer->addFunction(new TwigFunction('app_user', function () {
            return $this->session->get('user');
        }));

        // [Prêt de matériel — migration 016] Module activé dans les
        // réglages du club (menu, boutons « Ajouter au prêt »).
        $this->renderer->addFunction(new TwigFunction('pret_actif', function () {
            return $this->pretActif();
        }));

        // Libellé / couleur / badge d'un statut d'équipement (0 à 4).
        $this->renderer->addFunction(new TwigFunction('statut_equipement', function ($statut) {
            $enum = is_numeric($statut) ? \Epiclub\Enum\EquipementStatut::tryFrom((int) $statut) : null;
            return $enum === null
                ? ['libelle' => 'Inconnu', 'couleur' => '#6c757d', 'badge' => 'secondary', 'reserve' => false]
                : ['libelle' => $enum->label(), 'couleur' => $enum->couleur(), 'badge' => $enum->badge(), 'reserve' => $enum->estReserve()];
        }));

        $this->updateLastActivity();
    }

    public function render(string $template, array $data = []): Response
    {
        $data['_user'] = $this->session->get('user');
        return new Response($this->renderer->render($template, $data));
    }

    public function createEmail(string $from, string $to, string $subject, string $template, array $data = [])
    {
        $response = $this->render($template, $data);
        $html = $response->getContent();

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->html($html);
        return $email;
    }

    /**
     * [Prêt de matériel — migration 016] Le gestionnaire de prêt est-il
     * activé (club.pret_actif) ? Lu une fois par requête ; false si la
     * base est inaccessible ou la colonne absente (migration non
     * appliquée).
     */
    public function pretActif(): bool
    {
        if ($this->pretActifCache === null) {
            try {
                $club = $this->clubManagerModules()->findParameters();
                $this->pretActifCache = !empty($club['pret_actif']);
            } catch (\Throwable) {
                $this->pretActifCache = false;
            }
        }
        return $this->pretActifCache;
    }

    /** Factory — surchargeable en test Unit. */
    protected function clubManagerModules(): ClubManager
    {
        return new ClubManager();
    }

    public function isAuthenticated(): bool
    {
        return $this->session->isAuthenticated();
    }

    public function isGranted(string $role): bool
    {
        return $this->session->isGranted($role);
    }

    /**
     * Vérifie que l'utilisateur possède le rôle requis.
     * Lance une AccessDeniedException si ce n'est pas le cas.
     *
     * @throws AccessDeniedException
     */
    public function deniAccessUnlessGranted(string $role): void
    {
        if (!$this->isGranted($role)) {
            error_log('[deniAccessUnlessGranted] refus pour rôle ' . $role);
            $this->session->getFlashBag()->add('note', 'Vous n\'avez pas les autorisations nécessaires.');
            throw new AccessDeniedException('Accès refusé.');
        }
    }

    /**
    * Valide le jeton CSRF pour les requêtes state-changing (POST).
    *
    * Délègue à CsrfValidator (extrait en Vague 5 pour testabilité unitaire).
    * La méthode reste protected sur le contrôleur pour ne rien changer aux
    * appels existants (create, update, delete, valider, …).
    *
    * @throws AccessDeniedException si le jeton est absent ou invalide
    */
    protected function validateCsrf(Request $request): void
    {
        (new CsrfValidator($this->session))->validate($request);
    }

    public function redirectTo(string $route, int $status = 302, array $headers = []): Response
    {
        return new RedirectResponse($route, $status, $headers);
    }

    /**
     * Récupère un identifiant valide (entier strictement positif) depuis la requête.
     *
     * Retourne null si l'ID est absent, vide, non numérique ou <= 0.
     * L'appelant est invité à rediriger silencieusement dans ce cas,
     * car cela signifie que l'URL a été modifiée manuellement.
     *
     * @param Request $request
     * @param string  $key     Nom du paramètre (par défaut 'id')
     * @return int|null
     */
    protected function getValidId(Request $request, string $key = 'id'): ?int
    {
        $value = $request->get($key);

        // filter_var retourne false si non numérique ; int sinon.
        // Attention : '0' passe la validation, d'où le min_range=1.
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $id === false ? null : $id;
    }

    /**
     * Synchronise l'utilisateur de la session avec la base, à chaque requête,
     * puis met à jour last_activity (sans écraser les autres champs).
     *
     * [SÉCURITÉ — audit 2026-10-19 S5] Avant ce correctif, le rôle était
     * copié en session à la connexion puis jamais relu : un utilisateur
     * rétrogradé gardait ses droits, et un compte SUPPRIMÉ restait
     * utilisable, tant que sa session vivait. Désormais :
     *   - compte supprimé  → session utilisateur fermée immédiatement ;
     *   - rôle / identité modifiés → session mise à jour immédiatement.
     *
     * Une seule connexion, une lecture (PUBLIC_FIELDS) et une écriture par
     * requête : même coût qu'avant (l'écriture last_activity existait déjà).
     */
    protected function updateLastActivity()
    {
        $user = $this->session->get('user');
        if (!is_array($user) || !isset($user['id'])) {
            return;
        }

        $manager = $this->sessionUtilisateurManager();
        $enBase = $manager->findPublicId((int) $user['id']);

        if ($enBase === null) {
            error_log(sprintf('[AbstractController] Session fermée : utilisateur id=%s supprimé.', $user['id']));
            $this->session->remove('user');
            return;
        }

        $aJour = array_merge($user, $enBase);
        if ($aJour !== $user) {
            $this->session->set('user', $aJour);
        }

        $manager->updateLastActivity((int) $user['id']);
    }

    /**
     * Factory du manager utilisé pour la synchronisation de session —
     * surchargée en test Unit.
     */
    protected function sessionUtilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }

    /**
    * Détecte si PHP a tronqué le POST parce que post_max_size a été dépassé.
    * Dans ce cas, $_POST et $_FILES sont vides et le CSRF est indétectable.
    */
    protected function isPostTruncated(Request $request): bool
    {
        if ($request->getMethod() !== 'POST') {
            return false;
        }
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return false;
        }
        $postMaxSize = $this->parseIniSize(ini_get('post_max_size'));
        return $contentLength > $postMaxSize;
    }
    
    protected function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
    
    /**
    * [ROBUSTESSE] Retourne le chemin absolu vers le répertoire public/.
    *
    * Le chemin est configurable via .env.local.php (clé PUBLIC_PATH) pour
    * permettre un déploiement où public/ n'est pas dans le même dossier
    * que app/ (layouts exotiques, symlinks, etc.). Par défaut, on suppose
    * la structure standard : {projet}/public/.
    *
    * @param string $subpath Sous-chemin relatif à concaténer (ex. 'images/equipements/')
    * @return string Chemin absolu, sans slash final
    */
    protected function getPublicPath(string $subpath = ''): string
    {
        // [#110] PUBLIC_PATH se lit désormais via ConfigProvider (qui itère
        // le même fichier que le bootstrap). $_SERVER reste le repli
        // serveur (SetEnv), le défaut reste {projet}/public.
        $base = $this->configProvider()->get('PUBLIC_PATH')
        ?? $_SERVER['PUBLIC_PATH']
        ?? dirname(__DIR__, 2) . '/public';
        
        $base = rtrim($base, '/');
        $subpath = ltrim($subpath, '/');
        
        return $subpath === '' ? $base : $base . '/' . $subpath;
    }
    
    // ==================================================================
    // [REFACTOR #50a] Config + URL de base
    // ==================================================================
    
    /**
    * [VAGUE 11] Factory ConfigProvider — mockable en test Unit.
    *
    * Les sous-classes de test peuvent surcharger cette factory pour
    * injecter un ConfigProvider pré-rempli (sans lire .env.local.php).
    */
    protected function configProvider(): ConfigProvider
    {
        return new ConfigProvider(ConfigFile::defaultPath());
    }
    
    /**
    * [REFACTOR #78 - Dette 5] Factory EncryptionService — mockable en test Unit.
    *
    * Centralise l'instanciation auparavant dupliquée dans
    * ControleController (#50b) et JournalController (#70). Les sous-classes
    * de test peuvent surcharger cette factory pour injecter un service
    * basé sur une fixture (env.test.php) plutôt que .env.local.php.
    */
    protected function encryptionService(): EncryptionService
    {
        return new EncryptionService($this->configProvider());
    }
    
    /**
    * [SÉCURITÉ #50a-bis] Retourne l'URL de base.
    *
    * Lit ROOT_URL depuis ConfigProvider. Si absent, vide ou non-string,
    * lance une RuntimeException avec un message d'aide explicite.
    *
    * ⚠️ Plus de fallback sur SERVER_NAME : ce dernier provenait de la
    * config serveur (Apache/Nginx) et n'était pas spoofable via le
    * header Host:, mais il restait imprécis derrière un reverse-proxy
    * ou un load-balancer, et masquait une configuration incomplète.
    * Le strict force l'admin à déclarer explicitement son URL publique.
    *
    * ⚠️ On n'utilise PAS $_SERVER['HTTP_HOST'] (Host header injection) :
    * ce header est contrôlable par le client et permet de rediriger les
    * liens générés (QR, reset password) vers un domaine attaquant.
    *
    * @throws \RuntimeException Si ROOT_URL est absent, vide ou non-string.
    * @see https://github.com/johnburnout/EPIClub/issues/50a-bis
    */
    protected function getBaseUrl(): string
    {
        $url = $this->configProvider()->get('ROOT_URL');
        
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                'ROOT_URL is not configured in .env.local.php. '
                . 'Add: ROOT_URL="https://votre-domaine.fr"'
            );
        }
        
        return rtrim($url, '/');
    }
}