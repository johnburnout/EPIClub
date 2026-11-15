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

// ==================================================================
// [Localisation] Langue de l'installeur : choix fait dans le sélecteur
// (?lang=xx, mémorisé en session), sinon langue du navigateur, sinon
// français. Textes : setup_t() (texte échappé) ou setup_html() (texte
// de confiance du catalogue, paramètres échappés).
// ==================================================================

function setup_langue(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $choix = $_GET['lang'] ?? null;
    if (is_string($choix) && \Epiclub\Engine\Langue::estDisponible($choix)) {
        $_SESSION['setup_langue'] = $choix;
    }
    $navigateur = \Symfony\Component\HttpFoundation\Request::createFromGlobals()
        ->getPreferredLanguage(array_keys(\Epiclub\Engine\Langue::DISPONIBLES));
    return \Epiclub\Engine\Langue::resoudre($_SESSION['setup_langue'] ?? null, $navigateur);
}

/**
 * @param array<string, scalar|null> $parametres
 */
function setup_t(string $texte, array $parametres = []): string
{
    return htmlspecialchars(\Epiclub\Engine\Traducteur::t($texte, $parametres), ENT_QUOTES);
}

/**
 * Texte contenant des balises (<strong>, <code>…) : le texte vient du
 * catalogue (de confiance), seuls les paramètres sont échappés.
 *
 * @param array<string, scalar|null> $parametres
 */
function setup_html(string $texte, array $parametres = []): string
{
    return \Epiclub\Engine\Traducteur::t(
        $texte,
        array_map(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES), $parametres)
    );
}
