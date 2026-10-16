<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class EmplacementManager extends AbstractManager
{
    /** [REFACTOR #91] Champs autorisés en écriture (filteredInsert / filteredUpdate). */
    private const ALLOWED_FIELDS = ['libelle', 'description', 'image'];

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT * FROM emplacement $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM emplacement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        if ($emplacement = $stmt->fetch()) {
            return $emplacement;
        }

        return null;
    }

    /**
    * [REFACTOR #87] Table pour findOneByCriteria() générique
    * (AbstractManager).
    */
    protected function tableName(): string
    {
        return 'emplacement';
    }

    public function save(array $emplacement)
    {
        if (isset($emplacement['id'])) {
            return $this->_patch($emplacement);
        }

        $this->_create($emplacement);
        return $this->db->lastInsertId('emplacement');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM emplacement WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function hasEquipements(int $id): bool
    {
        $sql = "SELECT COUNT(*) FROM club_equipement WHERE emplacement_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() > 0;
    }

    private function _create(array $emplacement)
    {
        return $this->filteredInsert($emplacement, self::ALLOWED_FIELDS);
    }

    private function _patch(array $emplacement)
    {
        return $this->filteredUpdate($emplacement, self::ALLOWED_FIELDS);
    }
}