<?php 
namespace Epiclub\Enum;


enum EquipementEtats: string
{
    case NEW = 'Excellent';
    case GOOD = 'Bon';
    case USED = 'Usé';
    case END_OF_LIFE = 'Fin de vie';

    public static function forSelect(): array
    {
        return array_column(self::cases(), 'name', 'value');
    }
}