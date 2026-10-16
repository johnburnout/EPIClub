<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [VAGUE 13 - #59] Validateur dédié à ROOT_URL.
 *
 * Extrait dans une classe testable unitairement (même approche que
 * CsrfValidator en Vague 5), pour éviter d'alourdir les tests du
 * contrôleur avec les 9 cas de bord de la validation d'URL.
 *
 * Règles métier (cf. ticket #59) :
 * - URL absolue valide (FILTER_VALIDATE_URL)
 * - schéma http ou https uniquement
 * - ni query string ni fragment (une racine n'en a pas)
 * - slash final normalisé (rtrim)
 *
 * Note : la règle « champ vide = no-op » n'est PAS dans ce validateur.
 * C'est une décision du contrôleur (updateSystemSettings), pas du validateur.
 *
 * @see \Epiclub\Engine\CsrfValidator Pour le pattern d'extraction.
 */
final class RootUrlValidator
{
    /**
     * Valide et normalise une ROOT_URL.
     *
     * @param string $raw Valeur brute issue de la requête.
     * @return string URL normalisée (sans slash final).
     * @throws \InvalidArgumentException Si l'URL est invalide.
     */
    public static function validate(string $raw): string
    {
        $url = trim($raw);

        if ($url === '') {
            throw new \InvalidArgumentException('ROOT_URL ne peut pas être vide.');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('ROOT_URL n\'est pas une URL valide.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('ROOT_URL n\'est pas une URL valide.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('ROOT_URL doit utiliser le schéma http ou https.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('ROOT_URL ne doit contenir ni query string ni fragment.');
        }

        return rtrim($url, '/');
    }
}