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

    /**
     * [REFACTOR #92] Champs autorisés en écriture (filteredInsert /
     * filteredUpdate).
     */
    private const ALLOWED_FIELDS = [
        'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code',
        'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours',
        'emplacement_id', 'date_mise_en_service', 'date_fin_utilisation',
        'nombre', 'photo', 'est_epi'
    ];

    /** [REFACTOR #92] Défaut null des colonnes sans coercition spécifique. */
    private const CREATE_NULL_DEFAULTS = [
        'acquisition_id' => null, 'categorie_id' => null, 'reference' => null,
        'libelle' => null, 'code' => null, 'statut' => null,
    ];

    private function _create(array $equipement)
    {
        // Coercitions historiques (?? = null explicite compris) conservées
        // telles quelles : le remplissage null des autres colonnes est
        // délégué à CREATE_NULL_DEFAULTS (#92).
        $equipement['remarques'] = $equipement['remarques'] ?? null;
        $equipement['date_dernier_controle'] = $equipement['date_dernier_controle'] ?? null;
        $equipement['controle_en_cours'] = $equipement['controle_en_cours'] ?? 0;
        $equipement['emplacement_id'] = $equipement['emplacement_id'] ?? null;
        $equipement['date_mise_en_service'] = $equipement['date_mise_en_service'] ?? null;
        $equipement['date_fin_utilisation'] = $equipement['date_fin_utilisation'] ?? null;
        $equipement['nombre'] = $equipement['nombre'] ?? 1;
        $equipement['photo'] = $equipement['photo'] ?? null;
        $equipement['est_epi'] = $equipement['est_epi'] ?? 1;

        return $this->filteredInsert($equipement, self::ALLOWED_FIELDS, self::CREATE_NULL_DEFAULTS);
    }

    private function _patch(array $equipement)
    {
        return $this->filteredUpdate($equipement, self::ALLOWED_FIELDS);
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