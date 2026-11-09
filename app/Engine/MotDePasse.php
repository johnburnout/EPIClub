<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [SÉCURITÉ — audit 2026-11-08 R5] Point unique de hachage des mots de
 * passe : bcrypt coût 12 (le coût 10 de PASSWORD_DEFAULT double le
 * travail d'une attaque hors ligne en moins).
 *
 * Les hachages existants (coût 10) restent valides et sont recalculés
 * au coût 12 à la prochaine connexion réussie (doitEtreRehache()).
 */
final class MotDePasse
{
    public const ALGORITHME = PASSWORD_BCRYPT;
    public const OPTIONS = ['cost' => 12];

    public static function hacher(string $motDePasse): string
    {
        return password_hash($motDePasse, self::ALGORITHME, self::OPTIONS);
    }

    public static function doitEtreRehache(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHME, self::OPTIONS);
    }
}
