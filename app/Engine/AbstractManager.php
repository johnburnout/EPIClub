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
    * Nom de la table SQL du Manager, utilisé par findOneByCriteria() (#87)
    * et par filteredInsert()/filteredUpdate() (#91).
    *
    * Les Managers utilisant ces méthodes génériques DOIVENT surcharger
    * cette factory ; sinon une RuntimeException explicite est levée
    * (plutôt qu'une erreur SQL opaque).
    */
    protected function tableName(): string
    {
        throw new \RuntimeException(
            sprintf('%s doit surcharger tableName() pour utiliser les méthodes génériques.', static::class)
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
    * [REFACTOR #91 - Vague 15] INSERT générique avec filtrage des champs.
    *
    * Factorisation des _create() quasi identiques des Managers. Le SQL
    * est construit dynamiquement à partir des champs réellement fournis
    * (même approche que UtilisateurManager::_create) :
    *  - seules les clés de $data listées dans $fields sont insérées
    *    (les clés supplémentaires sont ignorées silencieusement) ;
    *  - $defaults complète les clés absentes de $data (array_merge) ;
    *  - la table vient du hook tableName() (#87) ;
    *  - aucun champ valide → RuntimeException (jamais de SQL vide).
    *
    * ⚠️ Changement de comportement documenté pour les _create() qui
    * exécutaient le tableau brut (Controle, ControleLigne, Fournisseur,
    * Emplacement, EquipementControle) : une clé supplémentaire est
    * désormais ignorée au lieu de lever une PDOException
    * « Invalid parameter number ».
    *
    * @param array<string,mixed> $data     Données d'entrée (brutes)
    * @param string[]            $fields   Colonnes autorisées
    * @param array<string,mixed> $defaults Valeurs par défaut des clés absentes
    * @throws \InvalidArgumentException si un nom de colonne est invalide
    * @throws \RuntimeException si aucun champ valide à insérer
    */
    protected function filteredInsert(array $data, array $fields, array $defaults = []): bool
    {
        $filtered = array_merge($defaults, array_intersect_key($data, array_flip($fields)));

        if ($filtered === []) {
            throw new \RuntimeException(
                sprintf('%s : aucun champ valide à insérer.', static::class)
            );
        }

        $columns = array_keys($filtered);
        $this->assertValidColumnNames($columns);

        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }

    /**
    * [REFACTOR #91 - Vague 15] UPDATE générique avec filtrage des champs.
    *
    * Factorisation des _patch() quasi identiques des Managers. Même
    * contrat que filteredInsert(), avec en plus :
    *  - clause WHERE id=:id — l'id est pris dans $data['id'] et n'a
    *    pas à figurer dans $fields (il n'est jamais écrit dans le SET) ;
    *  - seuls les champs fournis (ou couverts par $defaults) figurent
    *    dans le SET : une mise à jour partielle ne touche pas les
    *    autres colonnes.
    *
    * ⚠️ Changement de comportement documenté : auparavant, le SQL
    * statique exigeait TOUTES les colonnes — un champ manquant levait
    * une PDOException « Invalid parameter number » (HY093). Désormais
    * la mise à jour partielle est possible (alignement sur le contrat
    * de UtilisateurManager::_patch).
    *
    * @param array<string,mixed> $data     Données d'entrée (doit contenir 'id')
    * @param string[]            $fields   Colonnes autorisées (hors 'id')
    * @param array<string,mixed> $defaults Valeurs par défaut des clés absentes
    * @throws \InvalidArgumentException si un nom de colonne est invalide
    * @throws \RuntimeException si id manquant ou aucun champ à mettre à jour
    */
    protected function filteredUpdate(array $data, array $fields, array $defaults = []): bool
    {
        $filtered = array_merge($defaults, array_intersect_key($data, array_flip($fields)));

        if ($filtered === []) {
            throw new \RuntimeException(
                sprintf('%s : aucun champ valide à mettre à jour.', static::class)
            );
        }

        if (empty($data['id'])) {
            throw new \RuntimeException(
                sprintf('%s : ID manquant pour la mise à jour.', static::class)
            );
        }

        $columns = array_keys($filtered);
        $this->assertValidColumnNames($columns);

        $sets = array_map(static fn(string $c): string => "{$c}=:{$c}", $columns);
        $sql = sprintf(
            'UPDATE %s SET %s WHERE id=:id',
            $this->tableName(),
            implode(', ', $sets)
        );

        $filtered['id'] = $data['id'];

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }

    /**
    * [REFACTOR #91] Validation des noms de colonnes pour
    * filteredInsert()/filteredUpdate() — même regex que
    * buildWhereClause() (#68).
    *
    * @param string[] $columns
    * @throws \InvalidArgumentException
    */
    private function assertValidColumnNames(array $columns): void
    {
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $column)) {
                throw new \InvalidArgumentException(
                    sprintf('Nom de colonne invalide pour l\'écriture : "%s"', $column)
                );
            }
        }
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