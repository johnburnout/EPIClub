<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class ControleManager extends AbstractManager
{
    /**
     * [REFACTOR #91] Champs autorisés en écriture.
     * cree_par : insertion seule ; jamais modifiable via _patch.
     */
    private const CREATE_FIELDS = ['libelle', 'date_debut', 'date_fin', 'statut', 'controleur_id', 'cree_par', 'hash_remarques'];
    private const PATCH_FIELDS = ['libelle', 'date_debut', 'date_fin', 'statut', 'controleur_id', 'hash_remarques'];

    public function findAll($order = 'date_debut DESC', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

        $sql = "SELECT c.*, u.prenom, u.nom
                FROM controle c
                LEFT JOIN utilisateur u ON c.controleur_id = u.id
                $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
    * [REFACTOR #89] Retourne `null` quand le contrôle est absent
    * (uniformisation du contrat findId, divergence #88 corrigée).
    * Tous les appelants testent `!$controle` (falsy) : iso-comportement.
    */
    public function findId(int $id)
    {
        $sql = "SELECT * FROM controle WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * [REFACTOR #91] Table pour filteredInsert()/filteredUpdate()
     * (AbstractManager).
     */
    protected function tableName(): string
    {
        return 'controle';
    }

    public function save(array $controle)
    {
        if (isset($controle['id'])) {
            return $this->_patch($controle);
        }
        $this->_create($controle);
        return $this->db->lastInsertId('controle');
    }

    private function _create(array $controle)
    {
        // hash_remarques : défaut null historique (caractérisé en #90)
        return $this->filteredInsert($controle, self::CREATE_FIELDS, ['hash_remarques' => null]);
    }

    private function _patch(array $controle)
    {
        // cree_par n'est jamais modifiable après création
        return $this->filteredUpdate($controle, self::PATCH_FIELDS);
    }

    /**
     * Trouve un contrôle en cours pour un utilisateur
     */
    public function findActiveByUser(int $userId): ?array
    {
        $sql = "SELECT * FROM controle WHERE statut = 'ouvert' AND controleur_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM controle WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }
}