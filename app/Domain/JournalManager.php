<?php
// app/Domain/JournalManager.php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class JournalManager extends AbstractManager
{
    // ------------------------------------------------------------
    //  Gestion de la connexion PDO
    // ------------------------------------------------------------
    
    private function getPdo(): \PDO
    {
        if (property_exists($this, 'pdo')) {
            return $this->pdo;
        }
        if (property_exists($this, 'db')) {
            return $this->db;
        }
        if (method_exists($this, 'getConnection')) {
            return $this->getConnection();
        }
        throw new \RuntimeException('Impossible de récupérer la connexion PDO');
    }

    // ------------------------------------------------------------
    //  Requêtes principales
    // ------------------------------------------------------------
    
    /**
     * Liste des contrôles clôturés avec filtres et pagination
     */
    public function getControlesClotures(array $filtres = []): array
    {
        $pdo = $this->getPdo();
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
        
        $orderBy = 'c.date_debut DESC';
        if (!empty($filtres['order_by'])) {
            switch ($filtres['order_by']) {
                case 'controleur':
                    $orderBy = 'u.nom, u.prenom';
                    break;
                case 'date_debut':
                    $orderBy = 'c.date_debut';
                    break;
                case 'date_fin':
                    $orderBy = 'c.date_fin';
                    break;
                default:
                    $orderBy = 'c.date_debut DESC';
            }
            if (!empty($filtres['order_dir'])) {
                $orderBy .= ' ' . ($filtres['order_dir'] === 'asc' ? 'ASC' : 'DESC');
            }
        }
        
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
     * Détails d'un contrôle clôturé (avec déchiffrement des remarques générales)
     */
    public function getControleCloture(int $controleId): ?array
    {
        $pdo = $this->getPdo();
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
        
        if ($result && !empty($result['hash_remarques'])) {
            $result['hash_remarques'] = $this->decrypt($result['hash_remarques']);
        }
        
        return $result ?: null;
    }
    
    /**
     * Lignes d'un contrôle clôturé (avec déchiffrement des remarques)
     */
    public function getLignesControle(int $controleId): array
    {
        $pdo = $this->getPdo();
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
        
        // Déchiffrer la remarque de chaque ligne
        foreach ($lignes as &$ligne) {
            if (!empty($ligne['remarque'])) {
                $ligne['remarque'] = $this->decrypt($ligne['remarque']);
            }
        }
        
        return $lignes;
    }
    
    /**
     * Liste des contrôleurs pour le filtre
     */
    public function getControleurs(): array
    {
        $pdo = $this->getPdo();
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
        $pdo = $this->getPdo();
        $sql = "SELECT DISTINCT YEAR(date_debut) as annee 
                FROM controle 
                WHERE statut = 'cloture'
                ORDER BY annee DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ... (début du fichier)
    
    // ------------------------------------------------------------
    //  Gestion du déchiffrement (identique à ControleController)
    // ------------------------------------------------------------
    
    private function decrypt(string $encrypted): string
    {
        $config = include __DIR__ . '/../../.env.local.php';
        $secretKey = isset($config['SECRET_KEY']) ? hex2bin($config['SECRET_KEY']) : null;
        $cipherMethod = $config['CIPHER_METHOD'] ?? 'AES-256-CBC';
        
        if (!$secretKey || !$cipherMethod) {
            return $encrypted;
        }
        
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return $encrypted;
        }
        
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        if ($ivLength === false || strlen($data) < $ivLength) {
            return $encrypted;
        }
        
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, $cipherMethod, $secretKey, 0, $iv);
        if ($decrypted === false) {
            return $encrypted;
        }
        
        return $decrypted;
    }
}