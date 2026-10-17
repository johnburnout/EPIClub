<?php
// Fichier d'aide pour l'installation
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}

function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die();
}

/**
 * Convertit une valeur php.ini ("12M", "1G", "512K") en octets.
 * Retourne 0 si la valeur est vide ou invalide.
 *
 * Utilisé par l'installateur pour comparer les limites d'upload
 * effectives (post_max_size, upload_max_filesize) aux valeurs
 * recommandées dans setup/requierements.php.
 */
function parseIniSize(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $num  = (int) $value;

    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => (int) $value,
    };
}

// ==================================================================
// [#107 - Dette 24] CSRF de l'installeur
//
// Pré-install, bootstrap sert setup/install.php sur TOUTES les URLs :
// sans jeton, un site tiers peut forger les POST des étapes — dont la
// création du compte SUPER_ADMIN avec les identifiants de l'attaquant
// (détournement d'installation). Jeton unique de session, hash_equals.
// Testé dans tests/Unit/Setup/SetupCsrfHelpersTest.php.
// ==================================================================

/**
 * Retourne (et crée si besoin) le jeton CSRF de session de l'installeur.
 */
function setup_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['setup_csrf_token'])) {
        $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['setup_csrf_token'];
}

/**
 * Champ caché à insérer dans chaque formulaire d'étape.
 */
function setup_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(setup_csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Vérifie le jeton POSTé contre celui de session.
 */
function setup_csrf_is_valid(?string $postedToken): bool
{
    $expected = setup_csrf_token();
    return is_string($postedToken) && $postedToken !== ''
        && hash_equals($expected, $postedToken);
}