<?php

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

class UtilisateurManager extends AbstractManager
{
    private const ALLOWED_FIELDS = [
        'nom', 'prenom', 'username', 'email', 'password', 'role',
        'date_creation', 'derniere_connexion', 'controle_en_cours_id',
        'last_activity', 'reset_token', 'reset_token_expires',
        'reset_email_sent_at',
    ];

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = '';
        if ($order) {
            $params .= " ORDER BY $order";
        }
        if ($limit > 0) {
            $params .= " LIMIT $offset, $limit";
        }
        $sql = "SELECT * FROM utilisateur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findId(int $id)
    {
        $sql = "SELECT * FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
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
        $sql = "SELECT * FROM utilisateur $params";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($criteria);
        return $stmt->fetch() ?: null;
    }

    /**
     * Trouve un utilisateur par son token de réinitialisation
     */
    public function findByResetToken(string $token): ?array
    {
        $sql = "SELECT * FROM utilisateur WHERE reset_token = :token AND reset_token IS NOT NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Met à jour UNIQUEMENT la colonne last_activity
     */
    public function updateLastActivity(int $userId): void
    {
        $sql = "UPDATE utilisateur SET last_activity = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $userId]);
    }

    public function save(array $utilisateur)
    {
        if (isset($utilisateur['id'])) {
            return $this->_update($utilisateur);
        }
        $this->_insert($utilisateur);
        return $this->db->lastInsertId('utilisateur');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _update(array $utilisateur): bool
    {
        $filtered = array_intersect_key($utilisateur, array_flip(self::ALLOWED_FIELDS));

        if (empty($filtered)) {
            throw new \RuntimeException('Aucun champ valide à mettre à jour.');
        }

        if (empty($utilisateur['id'])) {
            throw new \RuntimeException('ID utilisateur manquant pour la mise à jour.');
        }

        // Construire dynamiquement SET col = :col
        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "$col = :$col";
        }

        $sql = sprintf(
            'UPDATE utilisateur SET %s WHERE id = :id',
            implode(', ', $sets)
        );

        $filtered['id'] = $utilisateur['id'];

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }

    private function _insert(array $utilisateur): bool
    {
        $filtered = array_intersect_key($utilisateur, array_flip(self::ALLOWED_FIELDS));

        if (empty($filtered)) {
            throw new \RuntimeException('Aucun champ valide à insérer.');
        }

        $columns = array_keys($filtered);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO utilisateur (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($filtered);
    }

    public function getDb()
    {
        return $this->db;
    }
}