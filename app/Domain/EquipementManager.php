<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;
use Epiclub\Engine\EncryptionService;

class EquipementManager extends AbstractManager
{
    /**
    * [VAGUE 13 - #50b / REFACTOR #82 - Dette 6] Factory EncryptionService.
    *
    * Instancie le service avec le ConfigProvider par défaut. Depuis #82,
    * utilise la factory configProvider() héritée d'AbstractManager —
    * alignement sur AbstractController (#50a / #78).
    *
    * Surchargeable en test pour injecter une fixture ou un mock.
    */
    protected function encryptionService(): EncryptionService
    {
        return new EncryptionService($this->configProvider());
    }
    
    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM club_equipement WHERE deleted_at IS NULL $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM club_equipement WHERE id=:id AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($equipement = $stmt->fetch()) {
            return $equipement;
        }

        return null;
    }

    /**
    * [REFACTOR #87] Table + filtre permanent (soft delete) pour
    * findOneByCriteria() générique (AbstractManager).
    * Iso-comportement : 'WHERE deleted_at IS NULL' toujours appliqué,
    * seul ou combiné aux critères.
    */
    protected function tableName(): string
    {
        return 'club_equipement';
    }

    protected function baseWhereClause(): string
    {
        return ' WHERE deleted_at IS NULL';
    }

    public function delete(int $id)
    {
        $sql = "UPDATE club_equipement SET deleted_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function save(array $equipement)
    {
        if (isset($equipement['id'])) {
            return $this->_patch($equipement);
        }

        $this->_create($equipement);
        return $this->db->lastInsertId('club_equipement');
    }

    public function codeExists(string $code): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE code = :code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['code' => $code]);
        return $stmt->fetchColumn() > 0;
    }

    private function _create(array $equipement)
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

    private function _patch(array $equipement)
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

        // [VAGUE 13 - #50b] Déchiffrement via EncryptionService.
        // Si SECRET_KEY est absente/invalide, le service throw — on
        // laisse remonter : mieux vaut une 500 visible qu'un déchiffrement
        // silencieusement raté (aligné #52).
        if (!empty($result)) {
            $service = $this->encryptionService();
            foreach ($result as &$ligne) {
                if (!empty($ligne['remarque'])) {
                    $decrypted = $service->decrypt($ligne['remarque']);
                    if ($decrypted !== null) {
                        $ligne['remarque'] = $decrypted;
                    }
                    // Si decrypt() retourne null (legacy en clair, base64
                    // corrompu), on garde la valeur d'origine — iso-comportement.
                }
            }
            unset($ligne);
        }

        return $result;
    }
}