<?php

use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

require __DIR__ . '/../app/bootstrap.php';

$session = new Session();
$session->start();

$flashes = $session->getFlashBag();

if (!$session->has('csrf_token')) {
    $session->set('csrf_token', bin2hex(random_bytes(32)));
}

$isLoggedIn = $session->has('user');
$isAdmin = $isLoggedIn && $session->get('user')['role'] === 'admin';

if (time() - $session->getMetadataBag()->getLastUsed() > Session::SESSION_LIFETIME) {
    $session->invalidate();
    $response = new RedirectResponse('/');
    $response->send();
    exit;
}

$request = Request::createFromGlobals();
$request->setDefaultLocale('fr');
$request->setLocale('fr');

$context = new RequestContext();
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
} catch (AccessDeniedHttpException $exception) {
    // Accès refusé : le flash est déjà posé par deniAccessUnlessGranted().
    // On redirige vers l'accueil, comme le faisait l'ancienne implémentation.
    $response = new RedirectResponse('/');
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