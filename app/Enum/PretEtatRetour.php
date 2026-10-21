<?php

declare(strict_types=1);

namespace Epiclub\Enum;

/**
 * État constaté au retour d'un matériel prêté (colonne
 * `pret_ligne.etat_retour`, migration 016) et statut d'équipement
 * correspondant.
 */
enum PretEtatRetour: string
{
    case DISPONIBLE  = 'disponible';
    case MAINTENANCE = 'maintenance';
    case EGARE       = 'egare';

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE  => 'Rendu — disponible',
            self::MAINTENANCE => 'Rendu — en maintenance',
            self::EGARE       => 'Égaré',
        };
    }

    public function equipementStatut(): EquipementStatut
    {
        return match ($this) {
            self::DISPONIBLE  => EquipementStatut::DISPONIBLE,
            self::MAINTENANCE => EquipementStatut::EN_MAINTENANCE,
            self::EGARE       => EquipementStatut::EGARE,
        };
    }
}
