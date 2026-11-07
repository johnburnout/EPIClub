<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [SÉCURITÉ — audit 2026-11-07] Nonce CSP de la requête.
 *
 * Seuls les <script> portant ce nonce (nonce="{{ csp_nonce }}" dans les
 * gabarits) et les scripts servis par le site ou par cdn.jsdelivr.net
 * s'exécutent : un script injecté par une faille XSS ne connaît pas le
 * nonce et est bloqué par le navigateur. Les attributs on*= (onclick,
 * onsubmit…) ne sont plus autorisés : public/assets/js/app.js remplace
 * les usages courants (data-confirm, data-auto-submit, data-print…).
 *
 * Un nonce par requête : un processus PHP (FPM, CGI) sert une requête à la
 * fois ; la valeur est tirée au premier appel et reste la même pour le
 * rendu Twig et l'en-tête (SecurityHeaders).
 */
final class Csp
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(18));
    }

    /** Politique des scripts des pages HTML. */
    public static function scriptSrc(): string
    {
        return "script-src 'self' 'nonce-" . self::nonce() . "' https://cdn.jsdelivr.net";
    }

    /** Tests (et serveur de développement long) : nouveau nonce. */
    public static function reinitialiser(): void
    {
        self::$nonce = null;
    }
}
