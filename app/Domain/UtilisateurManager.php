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
    
    /**
    * [SÉCURITÉ #53] Longueur minimale d'un mot de passe.
    *
    * Recommandation ANSSI (2024) : 12 caractères minimum pour un
    * mot de passe à facteur unique (hors gestionnaire de mots de passe).
    */
    public const MIN_PASSWORD_LENGTH = 12;

    /**
    * [SÉCURITÉ Dette 8] Colonnes exposables à l'affichage (vues Twig,
    * hydratation 'redacteur', etc.). Exclut strictement les colonnes
    * sensibles : password, reset_token, reset_token_expires,
    * reset_email_sent_at (et les colonnes internes last_activity,
    * controle_en_cours_id).
    *
    * Utiliser findPublicId()/findPublicIds() dès que les données ont
    * vocation à être rendues — jamais findId()/findIds() (SELECT *).
    */
    public const PUBLIC_FIELDS = [
        'id', 'nom', 'prenom', 'username', 'email', 'role', 'date_creation',
    ];

    public function findAll($order = '', $limit = -1, $offset = 0)
    {
        $params = $this->buildOrderClause($order)
                . $this->buildLimitClause($limit, $offset);

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
    
    /**
    * [REFACTOR #71] Récupère plusieurs utilisateurs par leurs IDs en une requête.
    *
    * Iso-comportement avec findId() appliqué en boucle.
    * ⚠️ SELECT * : inclut password, reset_token, etc. — à réserver
    * à la logique interne (authentification). Pour tout affichage,
    * utiliser findPublicIds() (Dette 8 soldée).
    *
    * @param int[] $ids
    * @return array<int, array> Tableau indexé par id.
    */
    public function findIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        
        $ids = array_values(array_unique(array_map('intval', $ids)));
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM utilisateur WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        
        return array_column($stmt->fetchAll(), null, 'id');
    }

    /**
    * [SÉCURITÉ Dette 8] Récupère un utilisateur par son ID, SANS les
    * colonnes sensibles (projection PUBLIC_FIELDS).
    *
    * ⚠️ findId() (SELECT *) inclut password et reset_token — ne
    * l'utiliser que pour de la logique interne (authentification, etc.).
    * Pour tout affichage, utiliser findPublicId().
    *
    * Iso-forme avec findId() : même clé 'id', fetch unique, null si absent.
    */
    public function findPublicId(int $id): ?array
    {
        $columns = implode(', ', self::PUBLIC_FIELDS);
        $sql = "SELECT $columns FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
    * [SÉCURITÉ Dette 8] Batch de findPublicId() : plusieurs utilisateurs
    * par leurs IDs, SANS les colonnes sensibles.
    *
    * ⚠️ findIds() (SELECT *) inclut password et reset_token — cf. Dette 8
    * pour l'exposition historique dans 'redacteur'. Pour tout affichage,
    * utiliser findPublicIds().
    *
    * @param int[] $ids
    * @return array<int, array> Tableau indexé par id.
    */
    public function findPublicIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $columns = implode(', ', self::PUBLIC_FIELDS);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT $columns FROM utilisateur WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return array_column($stmt->fetchAll(), null, 'id');
    }

    public function findOneByCriteria(array $criteria = [])
    {
        [$where, $params] = $this->buildWhereClause($criteria);
        
        $sql = "SELECT * FROM utilisateur$where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
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
    * [SÉCURITÉ #53] Valide un mot de passe selon les règles communes.
    *
    * Règle actuelle : longueur minimale MIN_PASSWORD_LENGTH.
    * (Extensible plus tard : complexité, dictionnaire, etc.)
    *
    * ⚠️ strlen() et non mb_strlen() : on mesure en octets, ce qui est
    * plus strict pour les caractères multi-octets (é = 2 octets). Un
    * mot de passe de 12 caractères accentués totalise 24 octets → OK.
    *
    * @param string $password Le mot de passe en clair à valider
    * @return string|null     Message d'erreur, ou null si valide
    */
    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return sprintf(
                'Le mot de passe doit contenir au moins %d caractères.',
            self::MIN_PASSWORD_LENGTH
            );
        }
        
        return null;
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
    
    /**
    * [REFACTOR #51] Enregistre un token de reset password.
    *
    * Remplace le SQL brut qui était dans
    * AppUserRegisterController::forgotPassword().
    *
    * @param int    $userId  ID de l'utilisateur
    * @param string $token   Token hex (32 bytes → 64 chars)
    * @param string $expires Date d'expiration (format Y-m-d H:i:s)
    * @param string $sentAt  Date d'envoi (format Y-m-d H:i:s)
    */
    public function setResetToken(
        int $userId,
        string $token,
        string $expires,
        string $sentAt,
    ): bool {
        $sql = "UPDATE utilisateur
        SET reset_token = :token,
        reset_token_expires = :expires,
        reset_email_sent_at = :sent_at
        WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            'token'   => $token,
            'expires' => $expires,
            'sent_at' => $sentAt,
            'id'      => $userId,
        ]);
    }
    
    /**
    * [REFACTOR #51] Efface le token de reset password.
    *
    * Utilisé dans deux cas :
    *  - resetPassword() détecte un token expiré → on nettoie la BDD
    *  - resetPassword() réussit → on nettoie après changement du mdp
    *
    * @param int $userId ID de l'utilisateur
    */
    public function clearResetToken(int $userId): bool
    {
        $sql = "UPDATE utilisateur
        SET reset_token = NULL,
        reset_token_expires = NULL,
        reset_email_sent_at = NULL
        WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute(['id' => $userId]);
    }

    public function save(array $utilisateur)
    {
        if (isset($utilisateur['id'])) {
            return $this->_patch($utilisateur);
        }
        $this->_create($utilisateur);
        return $this->db->lastInsertId('utilisateur');
    }

    public function delete(int $id)
    {
        $sql = "DELETE FROM utilisateur WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    private function _patch(array $utilisateur): bool
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

    private function _create(array $utilisateur): bool
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