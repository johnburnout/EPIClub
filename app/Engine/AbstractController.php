<?php

namespace Epiclub\Engine;

use Twig\TwigFunction;
use Epiclub\Engine\Session;
use Epiclub\Domain\UtilisateurManager;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

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

    public function deniAccessUnlessGranted(string $role): Response|null
    {
        if (!$this->isGranted($role)) {
            $this->session->getFlashBag()->add('note', 'Vous n\'avez pas les autorisations necessaire.');
            return new RedirectResponse('/');
        }

        return null;
    }

    public function redirectTo(string $route, int $status = 302, array $headers = []): Response
    {
        return new RedirectResponse($route, $status, $headers);
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
}