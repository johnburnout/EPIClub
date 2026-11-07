<?php

declare(strict_types=1);

// [SÉCURITÉ — audit 2026-11-07] Ligne de commande uniquement : bin/ est
// déjà refusé par son .htaccess, mais un serveur qui l'ignore (nginx, docroot
// mal réglé) ne doit pas pouvoir jouer les migrations depuis le navigateur.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ligne de commande uniquement.');
}

require __DIR__ . '/../vendor/autoload.php';

use Epiclub\Engine\MigrationManager;

// --- Charger la configuration comme AbstractManager ---
$configFile = __DIR__ . '/../.env.local.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "❌ Fichier .env.local.php introuvable.\n");
    exit(1);
}
$config = include $configFile;

$host = $config['DB_HOST'];
$port = null;
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host);
}

$dsn = "mysql:host={$host};dbname={$config['DB_NAME']};charset=utf8mb4";
if ($port) {
    $dsn .= ";port={$port}";
}

// --- Connexion PDO ---
try {
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "❌ Connexion BDD impossible : " . $e->getMessage() . "\n");
    exit(1);
}

// --- MigrationManager ---
$migrationsDir = __DIR__ . '/../migrations';
$manager = new MigrationManager($pdo, $migrationsDir);

echo "📋 Migrations disponibles : " . implode(', ', $manager->getAvailableMigrations()) . "\n";
echo "✅ Migrations déjà appliquées : " . (implode(', ', $manager->getAppliedVersions()) ?: '(aucune)') . "\n";
echo "⏳ Migrations en attente : " . (implode(', ', $manager->getPendingMigrations()) ?: '(aucune)') . "\n\n";

if ($manager->isUpToDate()) {
    echo "🎉 Base de données déjà à jour.\n";
    exit(0);
}

// --- [v1.4.0] Sauvegarde SQL avant migrations (option --sans-sauvegarde) ---
if (!in_array('--sans-sauvegarde', $argv ?? [], true)) {
    try {
        $backup = new \Epiclub\Engine\DatabaseBackup(
            new \Epiclub\Engine\MySqlDatabaseDumper($pdo),
            __DIR__ . '/../var/backups'
        );
        $fichier = $backup->dump();
        $backup->cleanup(\Epiclub\Engine\DatabaseBackup::retention($config['BACKUP_RETENTION'] ?? null));
        echo "💾 Sauvegarde de la base : " . $fichier . "\n\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ Sauvegarde impossible, migrations non lancées : " . $e->getMessage() . "\n");
        fwrite(STDERR, "   (relancer avec --sans-sauvegarde pour passer outre)\n");
        exit(1);
    }
}

try {
    $applied = $manager->migrate(function (string $version) {
        echo "  ➜ $version appliquée\n";
    });
    echo "\n✅ Terminé : " . count($applied) . " migration(s) appliquée(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ Échec : " . $e->getMessage() . "\n");
    exit(1);
}