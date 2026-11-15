<?php
// tests/Security/RouteProvider.php

declare(strict_types=1);

namespace Epiclub\Tests\Security;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

class RouteProvider
{
    private static function loadRoutes(): RouteCollection
    {
        return require __DIR__ . '/../../ressources/routes.php';
    }

    private static function publicPatterns(): array
    {
        return require __DIR__ . '/../public_routes.php';
    }

    private static function concretize(string $path): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', function ($m) {
            return $m[1] === 'path' ? 'test.txt' : '1';
        }, $path);
    }

    private static function isPublic(string $path): bool
    {
        foreach (self::publicPatterns() as $pattern) {
            if ($path === $pattern) {
                return true;
            }
            if (str_contains($pattern, '{')) {
                $regex = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
            if (str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Route accessible en GET (méthode par défaut ou GET explicite).
     */
    private static function isGetAccessible(Route $route): bool
    {
        $methods = $route->getMethods();

        // Pas de méthodes déclarées → GET autorisé par défaut
        if (empty($methods)) {
            return true;
        }

        return in_array('GET', $methods, true);
    }

    /**
     * Routes protégées accessibles en GET (utilisées pour le test
     * "un anonyme doit être redirigé vers le login").
     *
     * Les routes POST-only en sont exclues : elles sont testées par
     * destructiveRoutesProvider() qui attend un 405 en GET.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function protectedRoutesProvider(): array
    {
        $routes = self::loadRoutes();
        $provider = [];

        foreach ($routes as $name => $route) {
            $path = $route->getPath();

            if (self::isPublic($path)) {
                continue;
            }

            if (!self::isGetAccessible($route)) {
                continue;
            }

            $provider[$name] = [self::concretize($path), $path];
        }

        return $provider;
    }

    /**
     * Routes destructives (POST-only) : doivent renvoyer 405 en GET.
     *
     * [Audit 2026-11-10, R4] Liste construite depuis routes.php : TOUTE
     * route déclarée sans GET y figure (une liste tenue à la main avait
     * laissé de côté les routes de sauvegarde, de prêt, de double
     * authentification…). Un retrait accidentel de la restriction de
     * méthode est ainsi détecté pour chaque route, présente et future.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function destructiveRoutesProvider(): array
    {
        $provider = [];
        foreach (self::loadRoutes() as $name => $route) {
            $methods = $route->getMethods();
            if ($methods === [] || in_array('GET', $methods, true)) {
                continue;
            }
            $provider[$name] = [self::concretizeRoute($route), $route->getPath()];
        }

        return $provider;
    }

    /**
     * Chemin concret respectant les contraintes de la route (sinon le
     * routeur répondrait 404 au lieu de 405).
     */
    private static function concretizeRoute(Route $route): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($route): string {
            $requirement = $route->getRequirement($m[1]);
            foreach (['1', 'test.txt', 'epiclub-sauvegarde-20260101-000000.zip'] as $value) {
                if ($requirement === null || preg_match('#^(?:' . $requirement . ')$#', $value)) {
                    return $value;
                }
            }
            return '1';
        }, $route->getPath());
    }
}