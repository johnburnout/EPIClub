<?php

namespace Epiclub\Engine;

/**
 * Gestionnaire de migrations SQL.
 *
 * Détecte les migrations disponibles dans un dossier et applique
 * celles qui n'ont pas encore été jouées sur la base.
 *
 * Table de suivi : schema_migrations(version, applied_at)
 */
class MigrationManager
{
    private \PDO $pdo;
    private string $migrationsDir;

    public function __construct(\PDO $pdo, string $migrationsDir)
    {
        $this->pdo = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, '/');
        $this->ensureTable();
    }

    // ----------------------------------------------------------------
    // INITIALISATION
    // ----------------------------------------------------------------

    /**
     * Crée la table de suivi si nécessaire.
     * Si la base contient déjà des tables (installation antérieure au
     * système de migrations), on marque 001_initial comme déjà appliquée.
     */
    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if ($this->getAppliedVersions() === [] && $this->databaseHasTables()) {
            $this->markAsApplied(['001_initial']);
        }
    }

    /**
     * Y a-t-il d'autres tables que schema_migrations ?
     */
    private function databaseHasTables(): bool
    {
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        $tables = array_filter($tables, fn($t) => $t !== 'schema_migrations');
        return count($tables) > 0;
    }

    // ----------------------------------------------------------------
    // LECTURE
    // ----------------------------------------------------------------

    /**
     * @return string[] Versions déjà appliquées, triées.
     */
    public function getAppliedVersions(): array
    {
        return $this->pdo
            ->query("SELECT version FROM schema_migrations ORDER BY version")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return string[] Versions disponibles sur le disque, triées.
     */
    public function getAvailableMigrations(): array
    {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $versions = array_map(fn($f) => basename($f, '.sql'), $files);
        sort($versions, SORT_STRING);
        return $versions;
    }

    /**
     * @return string[] Versions à appliquer.
     */
    public function getPendingMigrations(): array
    {
        return array_values(array_diff(
            $this->getAvailableMigrations(),
            $this->getAppliedVersions()
        ));
    }

    public function isUpToDate(): bool
    {
        return empty($this->getPendingMigrations());
    }

    // ----------------------------------------------------------------
    // MIGRATION
    // ----------------------------------------------------------------

    /**
     * Applique toutes les migrations en attente, dans l'ordre.
     *
     * @param callable|null $logger Callback appelé après chaque migration réussie
     * @return string[] Versions appliquées
     */
    public function migrate(?callable $logger = null): array
    {
        $pending = $this->getPendingMigrations();
        $applied = [];

        foreach ($pending as $version) {
            $file = $this->migrationsDir . '/' . $version . '.sql';

            if (!is_readable($file)) {
                throw new \RuntimeException("Migration illisible : $file");
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                throw new \RuntimeException("Migration vide : $file");
            }

            try {
                $this->executeSql($sql);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Échec de la migration $version : " . $e->getMessage(),
                    0,
                    $e
                );
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)"
            );
            $stmt->execute([
                ':v' => $version,
                ':t' => date('Y-m-d H:i:s'),
            ]);

            $applied[] = $version;
            if ($logger) {
                $logger($version);
            }
        }

        return $applied;
    }

    /**
     * Marque des versions comme déjà appliquées, sans les exécuter.
     * Utile pour amorcer une installation existante.
     */
    public function markAsApplied(array $versions): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES (:v, :t)"
        );
        foreach ($versions as $v) {
            $stmt->execute([
                ':v' => $v,
                ':t' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ----------------------------------------------------------------
    // EXÉCUTION SQL
    // ----------------------------------------------------------------

    /**
     * Découpe un script SQL en instructions et les exécute une par une.
     *
     * Gère :
     *  - les commentaires `--`, `#`, `/* ... *\/`
     *  - les chaînes entre quotes (simple et double)
     *  - les `;` en fin de ligne (pas ceux dans les chaînes)
     *
     * ⚠️ Pas de transaction : MySQL ne supporte pas les DDL
     * (CREATE/DROP/ALTER) dans une transaction.
     */
    private function executeSql(string $sql): void
    {
        // Retirer les commentaires
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('/^\s*#.*$/m', '', $sql);
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql);

        $statements = [];
        $buffer = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($inString) {
                if ($char === $stringChar && $prev !== '\\') {
                    $inString = false;
                }
            } else {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === ';') {
                    $stmt = trim($buffer);
                    if ($stmt !== '') {
                        $statements[] = $stmt;
                    }
                    $buffer = '';
                    continue;
                }
            }
            $buffer .= $char;
        }

        $last = trim($buffer);
        if ($last !== '') {
            $statements[] = $last;
        }

        if (empty($statements)) {
            throw new \RuntimeException("Aucune instruction SQL valide trouvée.");
        }

        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                error_log(sprintf(
                    '[MigrationManager] SQL error at statement #%d: %s | Statement: %s',
                    $index + 1,
                    $e->getMessage(),
                    substr($statement, 0, 200)
                ));
                throw $e;
            }
        }
    }
}