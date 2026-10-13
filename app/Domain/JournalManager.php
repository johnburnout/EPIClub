<?php
// app/Domain/JournalManager.php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class JournalManager extends AbstractManager
{
    // ------------------------------------------------------------
    //  Whitelist ORDER BY
    // ------------------------------------------------------------

    /**
     * Colonnes de tri autorisées.
     *
     * La valeur est la clause SQL exacte (peut contenir plusieurs
     * colonnes, un préfixe de table, etc.).
     */
    private const ORDER_BY_WHITELIST = [
        'controleur' => 'u.nom, u.prenom',
        'date_debut' => 'c.date_debut',
        'date_fin'   => 'c.date_fin',
    ];

    private const ORDER_DIR_WHITELIST = ['asc', 'desc'];

    private const ORDER_BY_DEFAULT_COLUMN = 'c.date_debut';
    private const ORDER_BY_DEFAULT_DIR    = 'DESC';

    // ------------------------------------------------------------
    //  Construction de la clause ORDER BY (pure, testable)
    // ------------------------------------------------------------

    /**
     * Construit une clause ORDER BY sécurisée via whitelist.
     *
     * Comportement :
     *  - $orderBy null ou inconnu → colonne par défaut 'c.date_debut'.
     *  - $orderDir null ou invalide → direction par défaut 'DESC'.
     *  - La casse de $orderDir est normalisée (ASC → asc).
     *
     * @param string|null $orderBy Clé de tri ('controleur', 'date_debut',
     *                             'date_fin'). Toute autre valeur tombe
     *                             sur le défaut.
     * @param string|null $orderDir Direction ('asc', 'desc', 'ASC', …).
     * @return string              Clause SQL, sans espace initial
     *                             (ex: 'c.date_debut DESC').
     */
    protected function buildOrderByClause(?string $orderBy, ?string $orderDir): string
    {
        $column = self::ORDER_BY_WHITELIST[$orderBy ?? '']
                ?? self::ORDER_BY_DEFAULT_COLUMN;

        $direction = self::ORDER_BY_DEFAULT_DIR;
        if ($orderDir !== null
            && in_array(strtolower($orderDir), self::ORDER_DIR_WHITELIST, true)) {
            $direction = strtoupper($orderDir);
        }

        return "{$column} {$direction}";
    }

    // ------------------------------------------------------------
    //  Requêtes principales
    // ------------------------------------------------------------

    /**
     * Liste des contrôles clôturés avec filtres et pagination
     */
    public function getControlesClotures(array $filtres = []): array
    {
        $pdo = $this->db;
        $params = [];
        $conditions = ['c.statut = "cloture"'];

        if (!empty($filtres['controleur_id'])) {
            $conditions[] = 'c.controleur_id = :controleur_id';
            $params[':controleur_id'] = $filtres['controleur_id'];
        }
        if (!empty($filtres['annee'])) {
            $conditions[] = 'YEAR(c.date_debut) = :annee';
            $params[':annee'] = $filtres['annee'];
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $orderBy = $this->buildOrderByClause(
            $filtres['order_by'] ?? null,
            $filtres['order_dir'] ?? null,
        );

        $limit = (int)($filtres['limit'] ?? 10);
        $page = (int)($filtres['page'] ?? 1);
        $offset = ($page - 1) * $limit;

        // Comptage total
        $countSql = "SELECT COUNT(*) as total
                     FROM controle c
                     INNER JOIN utilisateur u ON c.controleur_id = u.id
                     $where";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch(\PDO::FETCH_ASSOC)['total'];

        // Données
        $sql = "SELECT c.*,
                       u.nom as controleur_nom,
                       u.prenom as controleur_prenom,
                       (SELECT COUNT(*) FROM controle_ligne cl WHERE cl.controle_id = c.id) as nb_equipements
                FROM controle c
                INNER JOIN utilisateur u ON c.controleur_id = u.id
                $where
                ORDER BY $orderBy
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $controles = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data' => $controles,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit)
        ];
    }

    /**
     * Détails d'un contrôle clôturé (retourne les données brutes)
     */
    public function getControleCloture(int $controleId): ?array
    {
        $pdo = $this->db;
        $sql = "SELECT c.*,
                       u.nom as controleur_nom,
                       u.prenom as controleur_prenom,
                       u2.nom as cree_par_nom,
                       u2.prenom as cree_par_prenom
                FROM controle c
                INNER JOIN utilisateur u ON c.controleur_id = u.id
                INNER JOIN utilisateur u2 ON c.cree_par = u2.id
                WHERE c.id = :id AND c.statut = 'cloture'";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $controleId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Le déchiffrement des remarques est désormais effectué dans le contrôleur
        return $result ?: null;
    }

    /**
     * Lignes d'un contrôle clôturé (retourne les données brutes)
     */
    public function getLignesControle(int $controleId): array
    {
        $pdo = $this->db;
        $sql = "SELECT cl.*,
                       ce.reference,
                       ce.libelle as equipement_libelle,
                       ce.code as equipement_code,
                       ca.libelle as categorie_libelle,
                       ca.est_epi,
                       e.libelle as emplacement_libelle
                FROM controle_ligne cl
                INNER JOIN club_equipement ce ON cl.equipement_id = ce.id
                LEFT JOIN categorie ca ON ce.categorie_id = ca.id
                LEFT JOIN emplacement e ON ce.emplacement_id = e.id
                WHERE cl.controle_id = :controle_id
                ORDER BY cl.date_controle DESC, ce.reference";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':controle_id' => $controleId]);
        $lignes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Le déchiffrement des remarques est désormais effectué dans le contrôleur
        return $lignes;
    }

    /**
     * Liste des contrôleurs pour le filtre
     */
    public function getControleurs(): array
    {
        $pdo = $this->db;
        $sql = "SELECT DISTINCT u.id, u.nom, u.prenom
                FROM controle c
                INNER JOIN utilisateur u ON c.controleur_id = u.id
                WHERE c.statut = 'cloture'
                ORDER BY u.nom, u.prenom";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Années disponibles pour le filtre
     */
    public function getAnneesDisponibles(): array
    {
        $pdo = $this->db;
        $sql = "SELECT DISTINCT YEAR(date_debut) as annee
                FROM controle
                WHERE statut = 'cloture'
                ORDER BY annee DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}