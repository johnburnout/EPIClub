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
}