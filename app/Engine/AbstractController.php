<?php

namespace Epiclub\Engine;

use Twig\TwigFunction;
use Epiclub\Engine\Session;
use Epiclub\Domain\UtilisateurManager;
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
     * ✅ Met à jour uniquement last_activity, sans écraser les autres champs
     */
    protected function updateLastActivity()
    {
        $user = $this->session->get('user');
        if ($user && isset($user['id'])) {
            $manager = new UtilisateurManager();
            $manager->updateLastActivity($user['id']);
        }
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
        $base = $_ENV['PUBLIC_PATH']
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
        return new ConfigProvider(__DIR__ . '/../../.env.local.php');
    }
    
    /**
    * [SÉCURITÉ #50a] Retourne l'URL de base.
    *
    * Lit ROOT_URL depuis .env.local.php. Si absent, fallback sur
    * $_SERVER['SERVER_NAME'] (⚠️ transition : ce fallback sera
    * supprimé en #50a-bis, ROOT_URL deviendra obligatoire).
    *
    * ⚠️ On n'utilise PLUS $_SERVER['HTTP_HOST'] : ce header est
    * contrôlable par le client (Host header injection) et permet
    * de rediriger les liens générés (QR, reset password) vers un
    * domaine attaquant.
    *
    * @see https://github.com/johnburnout/EPIClub/issues/50a
    */
    protected function getBaseUrl(): string
    {
        $url = $this->configProvider()->get('ROOT_URL');
        
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }
        
        // ⚠️ FALLBACK TRANSITOIRE — sera supprimé en #50a-bis.
        // SERVER_NAME provient de la config serveur (Apache/Nginx),
        // pas d'un header client. Moins flexible que ROOT_URL mais
        // non spoofable via le header Host:.
        error_log(
            '[AbstractController] ROOT_URL manquant dans .env.local.php, '
            . 'fallback sur SERVER_NAME (déprécié, cf. #50a-bis).'
        );
        
        $protocol = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https://' : 'http://';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        return $protocol . $host;
    }
}