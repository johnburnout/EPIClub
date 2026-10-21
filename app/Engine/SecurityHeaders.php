<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Response;

/**
 * [SÉCURITÉ — audit 2026-10-21] En-têtes de sécurité HTTP posés sur toutes
 * les réponses de l'application (public/index.php).
 *
 * Posés côté PHP plutôt que dans .htaccess : valables quel que soit le
 * serveur (Apache, nginx, php -S) et testables. Les fichiers statiques
 * (CSS, JS, images) n'en ont pas besoin ; public/images/.htaccess garde
 * ses propres en-têtes.
 *
 * - Anti-clickjacking : CSP frame-ancestors 'self' + X-Frame-Options
 *   SAMEORIGIN (navigateurs anciens). Aucune page n'est intégrée par un
 *   site tiers.
 * - nosniff : le navigateur respecte le Content-Type (fichiers servis par
 *   UploadController notamment).
 * - Referrer-Policy : l'URL complète (identifiants dans le chemin) n'est
 *   pas transmise aux sites externes.
 * - Permissions-Policy : caméra limitée à l'application (scan QR) ;
 *   micro et géolocalisation désactivés.
 *
 * Un en-tête déjà posé par un contrôleur n'est pas écrasé.
 */
final class SecurityHeaders
{
    public const HEADERS = [
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Frame-Options'         => 'SAMEORIGIN',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        'Permissions-Policy'      => 'camera=(self), microphone=(), geolocation=()',
    ];

    public static function apply(Response $response): Response
    {
        foreach (self::HEADERS as $nom => $valeur) {
            if (!$response->headers->has($nom)) {
                $response->headers->set($nom, $valeur);
            }
        }
        return $response;
    }
}
