<?php

declare(strict_types=1);

namespace Epiclub\Domain;

/**
 * [MÉTIER 2026-10-21] Date limite d'utilisation (péremption) par défaut
 * d'un EPI à durée de vie limitée : date d'achat + 10 ans, au dernier
 * jour du mois. Ex. achat le 15/03/2026 → limite le 31/03/2036.
 *
 * Règle unique pour la validation d'une acquisition et pour l'import
 * Excel/CSV (côté serveur ; le formulaire d'import la reproduit en JS).
 */
final class DureeDeVie
{
    public const ANS = 10;

    /**
     * @param string $dateAchat AAAA-MM-JJ (un horaire éventuel est ignoré)
     * @return string|null AAAA-MM-JJ, ou null si la date est invalide
     */
    public static function finDeVie(string $dateAchat): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($dateAchat), 0, 10));
        if ($date === false || $date->format('Y-m-d') !== substr(trim($dateAchat), 0, 10)) {
            return null;
        }
        // Premier du mois avant d'ajouter les années : pas de
        // débordement (29/02 + 10 ans → mars).
        return $date->modify('first day of this month')
            ->modify('+' . self::ANS . ' years')
            ->format('Y-m-t');
    }
}
