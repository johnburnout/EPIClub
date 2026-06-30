<?php

namespace Epiclub\Enum;

/**
 * USER : toute personne pouvant se connecter
 * CONTROLLEUR : niveau USER + lecture et contrôle des EPI
 * ADMINISTRATEUR : niveau CONTROLLEUR + édition EPI + changement des rôles
 */
class UserRole
{
    const ROLE_USER = 'Utilisateur';
    const ROLE_CONTROLLEUR = 'Contrôleur';
    const ROLE_ADMIN = 'Administrateur';

    static public function list()
    {
        return [
            'ROLE_USER' => self::ROLE_USER,
            'ROLE_CONTROLLEUR' => self::ROLE_CONTROLLEUR,
            'ROLE_ADMIN' => self::ROLE_ADMIN
        ];
    }

    static public function fromRole(string $role)
    {
        $matches = [
            'ROLE_USER' => self::ROLE_USER,
            'ROLE_CONTROLLEUR' => self::ROLE_CONTROLLEUR,
            'ROLE_ADMIN' => self::ROLE_ADMIN
        ];

        return $matches[$role] ?? $role;
    }
}