<?php 
namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ClubManager extends AbstractManager
{
    /** [REFACTOR #92] Champs autorisés en écriture (filteredInsert / filteredUpdate). */
    private const ALLOWED_FIELDS = ['nom', 'activite', 'description', 'email', 'phone'];

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