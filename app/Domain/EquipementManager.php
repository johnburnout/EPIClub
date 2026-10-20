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

    /**
     * [Tableau de bord] Équipements à renouveler : date de fin
     * d'utilisation renseignée et antérieure ou égale à $dateLimite
     * (y compris les équipements déjà échus). Les équipements supprimés
     * et ceux déjà déclarés « Hors service » (statut 2) sont exclus.
     *
     * @param string $aujourdhui Date du jour (Y-m-d) — paramètre pour les tests
     * @param string $dateLimite Date limite incluse (Y-m-d)
     * @return array{total: int, echus: int}
     */
    public function statsARenouveler(string $aujourdhui, string $dateLimite): array
    {
        $sql = "SELECT COUNT(*) AS total,
                       COALESCE(SUM(date_fin_utilisation <= :aujourdhui), 0) AS echus
                  FROM club_equipement
                 WHERE deleted_at IS NULL
                   AND statut <> 2
                   AND date_fin_utilisation IS NOT NULL
                   AND date_fin_utilisation <= :limite";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['aujourdhui' => $aujourdhui, 'limite' => $dateLimite]);
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'echus' => (int) ($row['echus'] ?? 0),
        ];
    }

    /**
     * [Photo groupée] Équipements existants (non supprimés) parmi $ids.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, photo: ?string}>
     */
    public function findPhotosByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, photo FROM club_equipement WHERE deleted_at IS NULL AND id IN ($marqueurs)"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /**
     * [Photo groupée] Affecte la même photo à plusieurs équipements
     * (non supprimés).
     *
     * @param int[] $ids
     * @return int Nombre d'équipements mis à jour
     */
    public function updatePhotoForIds(array $ids, string $photo): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE club_equipement SET photo = ? WHERE deleted_at IS NULL AND id IN ($marqueurs)"
        );
        $stmt->execute(array_merge([$photo], $ids));

        return $stmt->rowCount();
    }

    /**
     * [Photo groupée] Nombre d'équipements (y compris supprimés) qui
     * référencent ce fichier photo. Une photo pouvant être partagée,
     * le fichier ne doit être effacé que lorsqu'il n'est plus utilisé.
     */
    public function countPhotoReferences(string $photo): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM club_equipement WHERE photo = :photo");
        $stmt->execute(['photo' => $photo]);

        return (int) $stmt->fetchColumn();
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
    * [REFACTOR #112] Batch de findId() — suppression du N+1 de
    * ControleController::edit() (enrichLignesWithEquipements).
    *
    * Iso-comportement avec findId() : filtre soft delete
    * (deleted_at IS NULL) conservé — un équipement supprimé est
    * absent de la map, exactement comme findId() retournait null.
    *
    * @return array Map indexée par id (int).
    */
    public function findIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM club_equipement WHERE id IN ($placeholders) AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return array_column($stmt->fetchAll(), null, 'id');
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
     * Références des équipements correspondant à un motif REGEXP MySQL
     * (ex. '^26COR[0-9]{3}$' pour le compteur annuel du référencement
     * automatique). Soft-deleted inclus : une référence consommée ne
     * doit jamais être réattribuée.
     *
     * @return string[]
     */
    /** [Référencement] Une fiche équipement porte-t-elle déjà cette référence ? */
    public function referenceExists(string $reference): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM club_equipement WHERE reference = :reference");
        $stmt->execute(['reference' => $reference]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function findReferencesMatching(string $regexp): array
    {
        $sql = "SELECT reference FROM club_equipement WHERE reference REGEXP :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pattern' => $regexp]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * [IMPORT] Renseigne date_mise_en_service des équipements générés
     * par une acquisition (décision produit 2026-10-19 : à l'import, la
     * date de mise en service est la date de facture). Ne touche que
     * les équipements dont la date est encore NULL (défensif).
     */
    public function marquerEnServiceParAcquisition(int $acquisitionId, string $date): void
    {
        $sql = "UPDATE club_equipement
                SET date_mise_en_service = :date
                WHERE acquisition_id = :id AND date_mise_en_service IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['date' => $date, 'id' => $acquisitionId]);
    }

    /**
     * [REFACTOR #92] Champs autorisés en écriture (filteredInsert /
     * filteredUpdate).
     */
    private const ALLOWED_FIELDS = [
        'acquisition_id', 'categorie_id', 'reference', 'libelle', 'code',
        'statut', 'remarques', 'date_dernier_controle', 'controle_en_cours',
        'emplacement_id', 'date_mise_en_service', 'date_fin_utilisation',
        'nombre', 'nombre_initial', 'photo', 'est_epi', 'duree_max_utilisation'
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
        // [MÉTIER] Nombre initial du lot : par défaut = nombre saisi
        // (décision produit 2026-10-19).
        $equipement['nombre_initial'] = $equipement['nombre_initial'] ?? $equipement['nombre'];
        $equipement['photo'] = $equipement['photo'] ?? null;
        $equipement['est_epi'] = $equipement['est_epi'] ?? 1;
        $equipement['duree_max_utilisation'] = $equipement['duree_max_utilisation'] ?? 0;

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