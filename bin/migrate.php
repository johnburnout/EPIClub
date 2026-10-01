<?php

declare(strict_types=1);

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

try {
    $applied = $manager->migrate(function (string $version) {
        echo "  ➜ $version appliquée\n";
    });
    echo "\n✅ Terminé : " . count($applied) . " migration(s) appliquée(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ Échec : " . $e->getMessage() . "\n");
    exit(1);
}