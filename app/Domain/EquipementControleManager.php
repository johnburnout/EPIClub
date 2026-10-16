<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EquipementControleManager extends AbstractManager
{
    /** [REFACTOR #91] Champs autorisés en écriture (filteredInsert / filteredUpdate). */
    private const ALLOWED_FIELDS = ['controleur_id', 'club_equipement_id', 'etat', 'remarques', 'date_controle'];

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM club_equipement_controle $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM club_equipement_controle WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($controle = $stmt->fetch()) {
            return $controle;
        }

        return null;
    }

    /**
    * [REFACTOR #87] Table pour findOneByCriteria() générique
    * (AbstractManager).
    */
    protected function tableName(): string
    {
        return 'club_equipement_controle';
    }

    public function save(array $controle)
    {
        if (isset($controle['id'])) {
            return $this->_patch($controle);
        }

        $this->_create($controle);

        return $this->db->lastInsertId('club_equipement_controle');
    }

    /** @deprecated Ne jamais supprimer un controle, en faire un nouveau qui remplace/corrige l'actuel */
    public function delete(int $id)
    {
        throw new \Exception("La suppression d'un controle est impossible.", 1);
    }

    private function _create(array $controle)
    {
        return $this->filteredInsert($controle, self::ALLOWED_FIELDS);
    }

    private function _patch(array $controle)
    {
        return $this->filteredUpdate($controle, self::ALLOWED_FIELDS);
    }
}