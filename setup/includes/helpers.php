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