<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * [SÉCURITÉ — audit 2026-11-08 R2] Déploiement derrière un reverse proxy.
 *
 * Quand un proxy termine TLS devant EPIClub, PHP voit une connexion HTTP
 * venant du proxy : sans configuration, le cookie de session n'est pas
 * marqué Secure, HSTS n'est pas envoyé, et le limiteur de tentatives par
 * IP compte tous les utilisateurs comme une seule adresse (celle du proxy).
 *
 * La clé TRUSTED_PROXIES de `.env.local.php` liste les adresses (IP ou
 * plages CIDR) des proxys dont on accepte les en-têtes X-Forwarded-For,
 * X-Forwarded-Proto et X-Forwarded-Port :
 *
 *     'TRUSTED_PROXIES' => '127.0.0.1, 10.0.0.0/8',
 *
 * Mots-clés Symfony acceptés : PRIVATE_SUBNETS (réseaux privés) et
 * REMOTE_ADDR (fait confiance à l'adresse qui se connecte, quelle
 * qu'elle soit : à réserver au cas où PHP n'est joignable QUE par le
 * proxy).
 *
 * Clé absente ou vide : aucun proxy de confiance, les en-têtes
 * X-Forwarded-* sont ignorés (comportement antérieur, aucun risque
 * d'usurpation d'IP par un client direct).
 *
 * X-Forwarded-Host n'est volontairement pas pris en compte : beaucoup de
 * proxys le transmettent tel que le client l'a envoyé. Le proxy doit
 * conserver l'en-tête Host d'origine (Apache : ProxyPreserveHost On ;
 * nginx : proxy_set_header Host $host).
 */
final class ProxyDeConfiance
{
    public const CLE = 'TRUSTED_PROXIES';

    public const EN_TETES = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    private const MOTS_CLES = ['REMOTE_ADDR', 'PRIVATE_SUBNETS'];

    /**
     * Applique la configuration à Request (réglage global de Symfony).
     * À appeler avant Request::createFromGlobals().
     *
     * @param array<string, mixed> $config contenu de .env.local.php
     * @return list<string> les proxys retenus
     */
    public static function configurer(array $config): array
    {
        $proxys = self::depuisConfig($config);
        Request::setTrustedProxies($proxys, $proxys === [] ? 0 : self::EN_TETES);

        return $proxys;
    }

    /**
     * Adresses valides lues dans la configuration ; les entrées invalides
     * sont ignorées et journalisées.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function depuisConfig(array $config): array
    {
        $valeur = $config[self::CLE] ?? '';
        if (is_string($valeur)) {
            $entrees = explode(',', $valeur);
        } elseif (is_array($valeur)) {
            $entrees = $valeur;
        } else {
            error_log('[ProxyDeConfiance] ' . self::CLE . ' ignorée : chaîne ou tableau attendu.');
            return [];
        }

        $proxys = [];
        foreach ($entrees as $entree) {
            $entree = is_string($entree) ? trim($entree) : '';
            if ($entree === '') {
                continue;
            }
            if (self::estValide($entree)) {
                $proxys[] = $entree;
            } else {
                error_log('[ProxyDeConfiance] Entrée ignorée dans ' . self::CLE . ' : ' . $entree);
            }
        }

        return array_values(array_unique($proxys));
    }

    /**
     * Le site est-il servi en HTTPS (vu du navigateur) ? Tient compte de
     * X-Forwarded-Proto pour un proxy de confiance ; repli historique sur
     * le port 443 quand le serveur ne renseigne pas HTTPS.
     */
    public static function enHttps(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }
        // Le port local n'a de sens que pour une connexion directe : derrière
        // un proxy de confiance, X-Forwarded-Proto fait foi.
        return !$request->isFromTrustedProxy()
            && (int) $request->server->get('SERVER_PORT', 0) === 443;
    }

    private static function estValide(string $entree): bool
    {
        if (in_array($entree, self::MOTS_CLES, true)) {
            return true;
        }
        [$adresse, $masque] = array_pad(explode('/', $entree, 2), 2, null);
        if (filter_var($adresse, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($masque === null) {
            return true;
        }
        $max = str_contains($adresse, ':') ? 128 : 32;
        return ctype_digit($masque) && (int) $masque <= $max
            // Contrôle final par Symfony (même analyse qu'au filtrage).
            && IpUtils::checkIp($adresse, $entree);
    }
}
