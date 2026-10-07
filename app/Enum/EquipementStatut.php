<?php

namespace Epiclub\Enum;

/**
 * Statut métier d'un équipement (colonne `club_equipement.statut` TINYINT).
 *
 * ⚠️ Ne pas confondre avec EquipementStatuts (pluriel, string, legacy)
 *    qui alimente un ancien <select> et dont les valeurs ne correspondent
 *    PAS à la colonne BDD.
 */
enum EquipementStatut: int
{
    case DISPONIBLE     = 0;
    case EN_MAINTENANCE = 1;
    case HORS_SERVICE   = 2;

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE     => 'Disponible',
            self::EN_MAINTENANCE => 'En maintenance',
            self::HORS_SERVICE   => 'Hors service',
        };
    }

    /**
     * Retourne ['Disponible' => 0, 'En maintenance' => 1, 'Hors service' => 2]
     * pour alimenter un <select> Twig via `for name, value in ...`.
     *
     * @return array<string, int>
     */
    public static function forSelect(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->label()] = $case->value;
        }
        return $out;
    }
}