<?php

declare(strict_types=1);

namespace Epiclub\Enum;

/**
 * Statut d'une fiche de prêt (colonne `pret.statut`, migration 016).
 *
 *   saisie   → informations de l'emprunteur et matériel modifiables ;
 *   en_cours → matériel prêté (statut « Prêté ») : seul le téléphone de
 *              l'emprunteur reste modifiable ;
 *   termine  → matériel rendu : chaque matériel est vérifié et repasse
 *              Disponible, En maintenance ou Égaré.
 */
enum PretStatut: string
{
    case SAISIE   = 'saisie';
    case EN_COURS = 'en_cours';
    case TERMINE  = 'termine';

    public function label(): string
    {
        return match ($this) {
            self::SAISIE   => 'En saisie',
            self::EN_COURS => 'Prêt en cours',
            self::TERMINE  => 'Prêt terminé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::SAISIE   => 'secondary',
            self::EN_COURS => 'primary',
            self::TERMINE  => 'success',
        };
    }

    /** La fiche est exportable en PDF (prêt en cours ou terminé). */
    public function exportable(): bool
    {
        return $this !== self::SAISIE;
    }

    /** @return array<string, string> libellé => valeur */
    public static function forSelect(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->label()] = $case->value;
        }
        return $out;
    }
}
