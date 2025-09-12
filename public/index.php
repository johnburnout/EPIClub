<?php

use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

require __DIR__ . '/../app/bootstrap.php';

$session = new Session();
$session->start();

$flashes = $session->getFlashBag();

if (!$session->has('csrf_token')) {
	$session->set('csrf_token', bin2hex(random_bytes(32)));
};

$isLoggedIn = $session->has('user');  // Vérifie si l'utilisateur est connecté
$isAdmin = $isLoggedIn && $session->get('user')['role'] === 'admin';

if (time() - $session->getMetadataBag()->getLastUsed() > Session::SESSION_LIFETIME) {
    $session->invalidate();
    $response = new RedirectResponse('/');
    $response->send();
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
    $response = new Response('L\url que vous demandez n\'existe pas.', 404);
} catch (\Exception $exception) {
    $response = new Response('An error occurred' . $exception, 500);
}

$response->send();
