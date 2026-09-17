<?php
// tests/Security/RouteProvider.php

declare(strict_types=1);

namespace Tests\Security;

use Symfony\Component\Routing\RouteCollection;

class RouteProvider
{
    private static function loadRoutes(): RouteCollection
    {
        // ⚠️ À AJUSTER selon l'emplacement réel de routes.php
        return require __DIR__ . '/../../config/routes.php';
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
            $provider[$name] = [self::concretize($path), $path];
        }

        return $provider;
    }

    /**
     * Routes destructives (POST-only) : doivent renvoyer 405 en GET.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function destructiveRoutesProvider(): array
    {
        $destructiveRouteNames = [
            'categorie_delete',
            'fournisseur_delete',
            'emplacement_delete',
            'utilisateur_delete',
            'controle_delete',
            'equipement_delete',
            'acquisition_delete',
            'acquisition_ligne_delete',
            'acquisition_valider',
            'controle_cloturer',
            'controle_add_equipement',
            'update_smtp',
            'test_mail',
            'admin_update_perform',
            'admin_update_cleanup',
        ];

        $routes = self::loadRoutes();
        $provider = [];

        foreach ($destructiveRouteNames as $name) {
            if (!$routes->has($name)) {
                continue;
            }
            $route = $routes->get($name);
            $path = $route->getPath();
            $provider[$name] = [self::concretize($path), $path];
        }

        return $provider;
    }
}