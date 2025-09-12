<?php

namespace Epiclub\Enum;

/**
 * USER: toute personne pouvant se connecter
 * MONITEUR: niveau USER + lecture et controle epi
 * ADMINSTRATEUR: niveau MONITEUR + edition epi + changement user.role
 */
class UserRole
{
    const ROLE_USER = 'Utilisateur';
    const ROLE_MONITEUR = 'Moniteur';
    const ROLE_ADMIN = 'Administrateur';

    static public function list()
    {
        return [
            'ROLE_USER' => self::ROLE_USER,
            'ROLE_MONITEUR' => self::ROLE_MONITEUR,
            'ROLE_ADMIN' => self::ROLE_ADMIN
        ];
    }

    static public function fromRole(string $role)
    {
        $matches = [
            'ROLE_USER' => self::ROLE_USER,
            'ROLE_MONITEUR' => self::ROLE_MONITEUR,
            'ROLE_ADMIN' => self::ROLE_ADMIN
        ];

        return $matches[$role];
    }
}
