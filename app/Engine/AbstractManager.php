<?php

namespace Epiclub\Engine;

use Epiclub\Engine\ConfigProvider;

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
    
    /**
    * Construit une clause WHERE sécurisée à partir d'un tableau de critères.
    *
    * Accepte uniquement des critères d'égalité : ['col' => 'val'].
    * Le nom de colonne est validé par regex (même pattern que
    * buildOrderClause) pour prévenir l'injection SQL.
    *
    * Iso-comportement avec l'ancien code dupliqué dans les Managers :
    *  - clause préfixée d'un espace (concaténation directe)
    *  - params retournés au format execute() (clés sans ':' préfixe)
    *  - ordre d'itération préservé
    *  - tableau vide → clause vide + params vides
    *
    * @param array<string,mixed> $criteria
    * @return array{0:string,1:array<string,mixed>} [clause SQL, params à binder]
    *
    * @throws \InvalidArgumentException si un nom de colonne est invalide
    */
    protected function buildWhereClause(array $criteria): array
    {
        if ($criteria === []) {
            return ['', []];
        }
        
        $conditions = [];
        foreach ($criteria as $column => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new \InvalidArgumentException(
                    sprintf('Nom de colonne invalide pour la clause WHERE : "%s"', $column)
                );
            }
            $conditions[] = "{$column}=:{$column}";
        }
        
        return [' WHERE ' . implode(' AND ', $conditions), $criteria];
    }
    
    /**
    * [REFACTOR #87] Recherche générique d'UNE ligne par critères
    * d'égalité — factorisation des copies identiques dans les Managers
    * (Utilisateur, Fournisseur, Emplacement, Categorie,
    * EquipementControle).
    *
    * Contrat :
    *  - SELECT * FROM <tableName()> [+ baseWhereClause()]
    *    [+ WHERE critères] — LIMIT 1 implicite du fetch()
    *  - critères vides → pas de WHERE (sauf baseWhereClause())
    *  - ligne absente → null (et non false)
    *  - nom de colonne invalide → InvalidArgumentException
    *    (buildWhereClause, #68)
    *
    * Les Managers à hydratation spécifique (AcquisitionManager) ou à
    * filtre permanent (EquipementManager, soft delete) peuvent
    * surcharger tableName() / baseWhereClause() ou la méthode elle-même
    * en appelant parent::findOneByCriteria().
    *
    * @param array<string,mixed> $criteria
    * @return array<string,mixed>|null
    */
    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        $base = $this->baseWhereClause();

        // buildWhereClause préfixe ' WHERE ' (contrat #68) : combiné
        // avec un filtre permanent, le second WHERE devient un AND.
        if ($base !== '' && $where !== '') {
            $where = ' AND ' . substr($where, strlen(' WHERE '));
        }

        $sql = 'SELECT * FROM ' . $this->tableName() . $base . $where;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    /**
    * Nom de la table SQL du Manager, utilisé par findOneByCriteria().
    *
    * Les Managers utilisant la recherche générique DOIVENT surcharger
    * cette factory ; sinon une RuntimeException explicite est levée
    * (plutôt qu'une erreur SQL opaque).
    */
    protected function tableName(): string
    {
        throw new \RuntimeException(
            sprintf('%s doit surcharger tableName() pour utiliser findOneByCriteria().', static::class)
        );
    }

    /**
    * Filtre WHERE permanent appliqué avant les critères
    * (findOneByCriteria). Chaîne vide par défaut.
    *
    * Exemple : EquipementManager retourne ' WHERE deleted_at IS NULL'
    * (soft delete, iso-comportement historique).
    */
    protected function baseWhereClause(): string
    {
        return '';
    }

    /**
    * [REFACTOR #82 - Dette 6] Factory ConfigProvider — mockable en test Unit.
    *
    * Aligne AbstractManager sur AbstractController (#50a).
    * Les sous-classes de test peuvent surcharger cette factory pour
    * injecter un ConfigProvider pré-rempli (sans lire .env.local.php).
    */
    protected function configProvider(): ConfigProvider
    {
        return new ConfigProvider(__DIR__ . '/../../.env.local.php');
    }
}