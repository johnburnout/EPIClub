<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EquipementManager extends AbstractManager
{
    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = '';

        if ($order) {
            $params .= " ORDER BY $order";
        }

        if ($limit > 1) {
            $params .= " LIMIT $limit, $offset";
        }

        $sql = "SELECT * FROM club_equipement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM club_equipement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($equipement = $stmt->fetch()) {
            return $equipement;
        }

        return null;
    }

    public function findOneByCriteria(array $criteria = [])
    {
        $params = '';
        $i = 0;
        foreach ($criteria as $key => $value) {
            if ($i === 0) {
                $params .= "WHERE $key=:$key";
            } else {
                $params .= " AND $key=:$key";
            }
            $i++;
        }

        $sql = "SELECT * FROM club_equipement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);

        if ($equipement = $stmt->fetch()) {
            return $equipement;
        }

        return null;
    }

    public function save(array $equipement)
    {
        if (isset($equipement['id'])) {
            return $this->_update($equipement);
        }

        $this->_insert($equipement);
        return $this->db->lastInsertId('club_equipement');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM club_equipement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function codeExists(string $code): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE code = :code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['code' => $code]);
        return $stmt->fetchColumn() > 0;
    }

    private function _insert(array $equipement)
    {
        $equipement['remarques'] = $equipement['remarques'] ?? null;
        $equipement['date_dernier_controle'] = $equipement['date_dernier_controle'] ?? null;
        $equipement['controle_en_cours'] = $equipement['controle_en_cours'] ?? 0;
        $equipement['emplacement_id'] = $equipement['emplacement_id'] ?? null;
        $equipement['date_mise_en_service'] = $equipement['date_mise_en_service'] ?? null;
        $equipement['date_fin_utilisation'] = $equipement['date_fin_utilisation'] ?? null;
        $equipement['nombre'] = $equipement['nombre'] ?? 1;
        $equipement['photo'] = $equipement['photo'] ?? null;
        $equipement['est_epi'] = $equipement['est_epi'] ?? 1;
        
        $allowedFields = [
            'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code', 
            'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours', 
            'emplacement_id', 'date_mise_en_service', 'date_fin_utilisation',
            'nombre', 'photo', 'est_epi'
        ];
        
        $filtered = array_intersect_key($equipement, array_flip($allowedFields));
        
        $sql = "INSERT INTO club_equipement 
        (acquisition_id, categorie_id, reference, libelle, code, statut, remarques, 
            date_dernier_controle, controle_en_cours, emplacement_id, 
            date_mise_en_service, date_fin_utilisation, nombre, photo, est_epi)
        VALUES 
        (:acquisition_id, :categorie_id, :reference, :libelle, :code, :statut, :remarques, 
            :date_dernier_controle, :controle_en_cours, :emplacement_id, 
            :date_mise_en_service, :date_fin_utilisation, :nombre, :photo, :est_epi)";
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $filtered)) {
                $filtered[$field] = null;
            }
        }
        
        return $stmt->execute($filtered);
    }
    
    private function _update(array $equipement)
    {
        $allowedFields = [
            'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code', 
            'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours', 
            'emplacement_id', 'id', 'date_mise_en_service', 'date_fin_utilisation',
            'nombre', 'photo', 'est_epi'
        ];
        $filtered = array_intersect_key($equipement, array_flip($allowedFields));
        
        $sql = "UPDATE club_equipement 
                SET acquisition_id=:acquisition_id, categorie_id=:categorie_id, 
                    reference=:reference, libelle=:libelle, code=:code, 
                    statut=:statut, remarques=:remarques, 
                    date_dernier_controle=:date_dernier_controle, 
                    controle_en_cours=:controle_en_cours,
                    emplacement_id=:emplacement_id,
                    date_mise_en_service=:date_mise_en_service,
                    date_fin_utilisation=:date_fin_utilisation,
                    nombre=:nombre,
                    photo=:photo,
                    est_epi=:est_epi
                WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }
    
    // app/Domain/EquipementManager.php
    
    /**
    * Récupère l'historique des contrôles clôturés pour un équipement
    */
    public function getHistoriqueControles(int $equipementId): array
    {
        $sql = "SELECT cl.*, 
        c.libelle as controle_libelle,
        c.date_debut as controle_date_debut,
        c.date_fin as controle_date_fin,
        c.hash_remarques as controle_remarques,
        u.nom as controleur_nom,
        u.prenom as controleur_prenom
        FROM controle_ligne cl
        INNER JOIN controle c ON cl.controle_id = c.id
        LEFT JOIN utilisateur u ON c.controleur_id = u.id
        WHERE cl.equipement_id = :equipement_id 
        AND c.statut = 'cloture'
        ORDER BY c.date_fin DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['equipement_id' => $equipementId]);
        $result = $stmt->fetchAll();
        
        // Déchiffrer les remarques si nécessaire
        if (!empty($result)) {
            $config = include __DIR__ . '/../../.env.local.php';
            $secretKey = isset($config['SECRET_KEY']) ? hex2bin($config['SECRET_KEY']) : null;
            $cipherMethod = $config['CIPHER_METHOD'] ?? 'AES-256-CBC';
            
            foreach ($result as &$ligne) {
                // Déchiffrer la remarque de la ligne si elle existe
                if (!empty($ligne['remarque'])) {
                    $data = base64_decode($ligne['remarque'], true);
                    if ($data !== false) {
                        $ivLength = openssl_cipher_iv_length($cipherMethod);
                        if (strlen($data) >= $ivLength) {
                            $iv = substr($data, 0, $ivLength);
                            $chiffre = substr($data, $ivLength);
                            $decrypted = openssl_decrypt($chiffre, $cipherMethod, $secretKey, 0, $iv);
                            if ($decrypted !== false) {
                                $ligne['remarque'] = $decrypted;
                            }
                        }
                    }
                }
            }
        }
        
        return $result;
    }
}