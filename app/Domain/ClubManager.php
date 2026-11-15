<?php 
namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ClubManager extends AbstractManager
{
    /** [REFACTOR #92] Champs autorisés en écriture (filteredInsert / filteredUpdate). */
    private const ALLOWED_FIELDS = [
        'nom', 'activite', 'description', 'email', 'phone', 'ref_modele',
        // [Tableau de bord — migration 012]
        'logo', 'image_fond', 'alerte_renouvellement_mois',
        // [Prêt de matériel — migration 016]
        'pret_actif',
        // [migration 017]
        'delai_suppression_egare_mois',
        // [migration 019]
        'alerte_controle_actif', 'alerte_controle_mois',
        // [Localisation — migration 022] null = automatique (navigateur)
        'langue',
    ];

    public function findParameters()
    {
        $sql = "SELECT * FROM club WHERE id=1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        if ($parameters = $stmt->fetch()) {
            return $parameters;
        }
        return null;
    }

    /**
     * [Localisation — migration 022] Langue de l'interface réglée pour le
     * club, ou null (automatique : langue du navigateur). Null aussi si la
     * base ou la colonne est indisponible (installation, mise à jour en
     * cours) : l'interface ne doit jamais tomber pour une langue.
     */
    public function langue(): ?string
    {
        try {
            $langue = $this->db->query('SELECT langue FROM club WHERE id = 1')->fetchColumn();
            return is_string($langue) && $langue !== '' ? $langue : null;
        } catch (\Throwable $e) {
            error_log('[ClubManager] Langue du club indisponible : ' . $e->getMessage());
            return null;
        }
    }

    public function save(array $club)
    {
        // On s'assure que l'ID est présent pour la mise à jour
        if (isset($club['id'])) {
            return $this->_patch($club);
        }
        $this->_create($club);
        return $this->db->lastInsertId('club');
    }

    /**
     * [REFACTOR #92] Table pour filteredInsert()/filteredUpdate()
     * (AbstractManager).
     */
    protected function tableName(): string
    {
        return 'club';
    }

    private function _create(array $club)
    {
        return $this->filteredInsert($club, self::ALLOWED_FIELDS);
    }

    private function _patch(array $club)
    {
        // Singleton : la fiche club est toujours la ligne id=1, quelle
        // que soit la valeur de 'id' reçue (iso-comportement : l'ancien
        // SQL était WHERE id=1 littéral, sans paramètre bindé).
        $club['id'] = 1;
        return $this->filteredUpdate($club, self::ALLOWED_FIELDS);
    }
}