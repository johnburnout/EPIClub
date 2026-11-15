<?php

namespace Epiclub\Enum;

use Epiclub\Engine\Traducteur;

/**
 * Hiérarchie des rôles (du plus bas au plus élevé) :
 *   USER < CONTROLLEUR < ADMIN < SUPER_ADMIN
 *
 * USER          : toute personne pouvant se connecter
 * CONTROLLEUR   : niveau USER + lecture et contrôle des EPI
 * ADMIN         : niveau CONTROLLEUR + édition EPI, catégories, emplacements,
 *                 fournisseurs, acquisitions, utilisateurs (hors ADMIN/SUPER_ADMIN)
 * SUPER_ADMIN   : niveau ADMIN + réglages club, système (SMTP), mises à jour,
 *                 et gestion des comptes ADMIN et SUPER_ADMIN
 */
class UserRole
{
    const ROLE_USER = 'Utilisateur';
    const ROLE_CONTROLLEUR = 'Contrôleur';
    const ROLE_ADMIN = 'Administrateur';
    const ROLE_SUPER_ADMIN = 'Super Administrateur';

    /**
     * Liste complète des rôles (clé technique => libellé affiché).
     */
    static public function list(): array
    {
        // [Localisation] Libellés dans la langue de l'utilisateur (les
        // constantes gardent le libellé français, clé de traduction).
        return [
            'ROLE_USER' => Traducteur::t('Utilisateur'),
            'ROLE_CONTROLLEUR' => Traducteur::t('Contrôleur'),
            'ROLE_ADMIN' => Traducteur::t('Administrateur'),
            'ROLE_SUPER_ADMIN' => Traducteur::t('Super Administrateur'),
        ];
    }

    /**
     * Liste restreinte pour les formulaires selon le rôle de l'acteur.
     *
     * Un ADMIN ne peut pas attribuer un rôle >= au sien.
     * Seul un SUPER_ADMIN peut attribuer ADMIN ou SUPER_ADMIN.
     *
     * @param string $actorRole Rôle technique de l'utilisateur qui agit (ex: 'ROLE_ADMIN')
     */
    static public function listAssignableBy(string $actorRole): array
    {
        $all = self::list();

        if ($actorRole === 'ROLE_SUPER_ADMIN') {
            return $all;
        }

        if ($actorRole === 'ROLE_ADMIN') {
            // Un ADMIN peut créer/modifier des USER et CONTROLLEUR, mais pas
            // promouvoir quelqu'un ADMIN ou SUPER_ADMIN.
            return array_intersect_key($all, array_flip(['ROLE_USER', 'ROLE_CONTROLLEUR']));
        }

        // Aucun autre rôle n'a le droit de distribuer des rôles.
        return [];
    }

    /**
     * Convertit une clé technique en libellé affiché.
     * Retourne la valeur d'entrée si la clé est inconnue.
     */
    static public function fromRole(string $role): string
    {
        return self::list()[$role] ?? $role;
    }
}