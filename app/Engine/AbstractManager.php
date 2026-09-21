<?php

namespace Epiclub\Engine;

abstract class AbstractManager
{
    protected \PDO $db;
    const MAX_RESULTS = 25;

    public function __construct()
    {
        // Charger la configuration depuis .env.local.php
        $configFile = __DIR__ . '/../../.env.local.php';
        if (!file_exists($configFile)) {
            throw new \Exception("Fichier de configuration .env.local.php non trouvé");
        }

        $config = include $configFile;

        // Traiter DB_HOST qui peut contenir un port
        $host = $config['DB_HOST'];
        $port = null;
        if (strpos($host, ':') !== false) {
            $parts = explode(':', $host);
            $host = $parts[0];
            $port = $parts[1];
        }

        $dsn = "mysql:host={$host};dbname={$config['DB_NAME']};charset=utf8mb4";
        if ($port) {
            $dsn .= ";port={$port}";
        }

        try {
            $this->db = new \PDO(
                $dsn,
                $config['DB_USER'],
                $config['DB_PASS'],
                [
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (\PDOException $e) {
            throw new \PDOException(
                "Erreur de connexion à la base de données: " . $e->getMessage()
            );
        }
    }

    /**
     * Construit une clause ORDER BY sécurisée.
     *
     * Accepte "$column" ou "$column $direction" (ex: "nom ASC").
     * Le nom de colonne est validé par regex pour prévenir l'injection SQL.
     * La direction est limitée à ASC ou DESC (défaut : ASC).
     *
     * @param string $order Chaîne de tri ("colonne" ou "colonne DIRECTION")
     * @return string      Clause SQL préfixée d'un espace, ou chaîne vide
     */
    protected function buildOrderClause(string $order): string
    {
        $order = trim($order);
        if ($order === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $order);
        $column = $parts[0] ?? '';
        $direction = strtoupper($parts[1] ?? 'ASC');

        // Validation stricte du nom de colonne
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            return '';
        }

        // Validation de la direction
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        return " ORDER BY {$column} {$direction}";
    }

    /**
     * Construit une clause LIMIT sécurisée (syntaxe moderne LIMIT n OFFSET m).
     *
     * @param int $limit  Nombre max de résultats (<= 0 = pas de limite)
     * @param int $offset Décalage (>= 0)
     * @return string     Clause SQL préfixée d'un espace, ou chaîne vide
     */
    protected function buildLimitClause(int $limit, int $offset): string
    {
        if ($limit <= 0) {
            return '';
        }

        $offset = max(0, $offset);

        return " LIMIT {$limit} OFFSET {$offset}";
    }
}