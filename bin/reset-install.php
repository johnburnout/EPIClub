<?php
/**
 * Réinitialise une installation EPIClub (dev uniquement).
 *
 * Usage : php bin/reset-install.php
 *
 * ATTENTION : ce script supprime la base de données et .env.local.php.
 * Il refuse de s'exécuter depuis le navigateur.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne peut être exécuté qu'en ligne de commande.\n");
}

$rootDir = dirname(__DIR__);
$envFile = $rootDir . '/.env.local.php';

echo "\n⚠️  Réinitialisation d'EPIClub\n";
echo "   Ce script va :\n";
echo "     - supprimer la base de données configurée\n";
echo "     - supprimer .env.local.php\n";
echo "     - vider le dossier setup/ (optionnel)\n\n";
echo "Continuer ? (tapez 'oui' pour confirmer) : ";

$answer = trim(fgets(STDIN));
if ($answer !== 'oui') {
    echo "Annulé.\n";
    exit(1);
}

// 1. Lire la config existante
if (!file_exists($envFile)) {
    echo "ℹ️  Aucun .env.local.php trouvé. Rien à faire côté config.\n";
} else {
    $config = include $envFile;
    if (!is_array($config)) {
        echo "❌ .env.local.php est invalide.\n";
        exit(1);
    }

    // 2. Supprimer la base
    if (!empty($config['DB_HOST']) && !empty($config['DB_NAME'])) {
        try {
            $dsn = 'mysql:host=' . $config['DB_HOST'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $config['DB_USER'] ?? '', $config['DB_PASS'] ?? '');
            $pdo->exec("DROP DATABASE IF EXISTS `{$config['DB_NAME']}`");
            echo "✅ Base « {$config['DB_NAME']} » supprimée.\n";
        } catch (PDOException $e) {
            echo "❌ Impossible de supprimer la base : " . $e->getMessage() . "\n";
            echo "   (le fichier .env.local.php ne sera pas supprimé)\n";
            exit(1);
        }
    }

    // 3. Supprimer .env.local.php
    if (unlink($envFile)) {
        echo "✅ .env.local.php supprimé.\n";
    } else {
        echo "❌ Impossible de supprimer .env.local.php.\n";
        exit(1);
    }
}

// 4. Nettoyer les sessions éventuelles
$sessionDir = sys_get_temp_dir();
$sessions = glob($sessionDir . '/sess_*');
if (!empty($sessions)) {
    echo "ℹ️  " . count($sessions) . " fichier(s) de session trouvé(s) dans " . $sessionDir . ".\n";
    echo "   (non supprimés automatiquement, à nettoyer manuellement si besoin)\n";
}

echo "\n✅ Reset terminé. Tu peux relancer /setup/.\n\n";