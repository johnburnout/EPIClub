<?php

use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Exception\NotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;   // ← AJOUT

require __DIR__ . '/../app/bootstrap.php';

// [SÉCURITÉ — audit 2026-10-19 S5] Cookie de session durci explicitement
// (sans dépendre de php.ini) : inaccessible au JavaScript (HttpOnly),
// non envoyé par les requêtes intersites (SameSite=Lax), réservé à HTTPS
// quand le site est servi en HTTPS ; identifiants de session non
// initialisés refusés (strict mode, anti-fixation).
$enHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$session = new Session(new NativeSessionStorage([
    'cookie_httponly'  => true,
    'cookie_samesite'  => 'lax',
    'cookie_secure'    => $enHttps,
    'use_strict_mode'  => true,
    'use_only_cookies' => true,
]));
$session->start();

$flashes = $session->getFlashBag();

if (!$session->has('csrf_token')) {
    $session->set('csrf_token', bin2hex(random_bytes(32)));
}

$isLoggedIn = $session->has('user');

if (time() - $session->getMetadataBag()->getLastUsed() > Session::SESSION_LIFETIME) {
    $session->invalidate();
    $response = new RedirectResponse('/');
    $response->send();
    exit();
}

$request = Request::createFromGlobals();
$request->setDefaultLocale('fr');
$request->setLocale('fr');

$context = new RequestContext();
$context->fromRequest($request);

$matcher = new UrlMatcher($routes, $context);

try {
    $parameters = $matcher->match($request->getPathInfo());

    foreach ($parameters as $key => $value) {
        $request->attributes->set($key, $value);
    }

    $controller = new $parameters['_controller']($session);
    $response = call_user_func_array([$controller, $parameters['action']], [$request]);

} catch (ResourceNotFoundException $exception) {
    $response = new Response('L\'url que vous demandez n\'existe pas.', Response::HTTP_NOT_FOUND);

} catch (MethodNotAllowedException $exception) {                    // ← AJOUT
    $response = new Response(
        'Méthode non autorisée pour ' . $request->getPathInfo(),
        Response::HTTP_METHOD_NOT_ALLOWED
    );
    if ($allowed = $exception->getAllowedMethods()) {
        $response->headers->set('Allow', implode(', ', $allowed));
    }

} catch (AccessDeniedException $exception) {
    // Accès refusé : le flash est déjà posé par deniAccessUnlessGranted().
    // On redirige vers l'accueil, comme le faisait l'ancienne implémentation.
    $response = new RedirectResponse('/');

} catch (NotFoundException $exception) {
    // Fichier non trouvé (UploadController::serve(), AcquisitionController::serveFile())
    // ou toute autre HttpException 404 levée explicitement.
    $response = new Response(
        $exception->getMessage() ?: 'Ressource non trouvée',
        Response::HTTP_NOT_FOUND
    );

} catch (\Throwable $exception) {
    // Log serveur uniquement — JAMAIS exposé au client.
    error_log(sprintf(
        '[index] Unhandled %s: %s in %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    $response = new Response(
        '<h1>Une erreur est survenue</h1><p>Merci de réessayer plus tard.</p>',
        Response::HTTP_INTERNAL_SERVER_ERROR
    );
}

$response->send();