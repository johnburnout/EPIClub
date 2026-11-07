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
 * - [audit 2026-11-07] CSP complétée : object-src 'none' (aucun plugin),
 *   base-uri 'self' (pas de <base> injecté détournant les liens relatifs),
 *   form-action 'self' (aucun formulaire envoyé vers un site tiers).
 *   Pages HTML seulement : un PDF ou une image servis avec object-src
 *   'none' peuvent être bloqués par la visionneuse intégrée du navigateur ;
 *   ces réponses gardent la CSP frame-ancestors seule.
 *   script-src : scripts du site, de cdn.jsdelivr.net (Bootstrap) et
 *   scripts inline portant le nonce de la requête (Csp), sans
 *   'unsafe-inline' ni 'unsafe-eval'.
 * - [audit 2026-11-07] HSTS, en HTTPS seulement : le navigateur refuse
 *   ensuite le HTTP pendant un an (pas de includeSubDomains : d'autres
 *   sites du club peuvent vivre sur des sous-domaines).
 *
 * Un en-tête déjà posé par un contrôleur n'est pas écrasé.
 */
final class SecurityHeaders
{
    public const HEADERS = [
        'Content-Security-Policy' => "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'",
        'X-Frame-Options'         => 'SAMEORIGIN',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        'Permissions-Policy'      => 'camera=(self), microphone=(), geolocation=()',
    ];

    public const HSTS = 'max-age=31536000';

    /** CSP des réponses non HTML (PDF, images, exports). */
    public const CSP_FICHIER = "frame-ancestors 'self'";

    public static function apply(Response $response, bool $https = false): Response
    {
        $entetes = self::HEADERS;
        $type = (string) $response->headers->get('Content-Type', '');
        if ($type !== '' && !str_starts_with(strtolower($type), 'text/html')) {
            $entetes['Content-Security-Policy'] = self::CSP_FICHIER;
        } else {
            $entetes['Content-Security-Policy'] .= '; ' . Csp::scriptSrc();
        }
        if ($https) {
            $entetes['Strict-Transport-Security'] = self::HSTS;
        }
        foreach ($entetes as $nom => $valeur) {
            if (!$response->headers->has($nom)) {
                $response->headers->set($nom, $valeur);
            }
        }
        return $response;
    }
}
