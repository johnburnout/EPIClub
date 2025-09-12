<?php 
namespace Epiclub\Enum;

enum EquipementStatuts: string
{
    case LIBRE = 'Libre';
    case ASSIGNE = 'Assigné';
    case RESERVE = 'Réservé';
    case CONTROLE = 'En controle';

    public static function forSelect(): array
    {
        return array_column(self::cases(), 'name', 'value');
    }
}