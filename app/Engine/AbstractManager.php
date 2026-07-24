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
}