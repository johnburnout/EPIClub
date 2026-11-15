<?php

namespace Epiclub\Engine;

/**
 * [Localisation] Langues d'affichage proposées et choix de la langue
 * d'une requête.
 *
 * Ordre de priorité :
 *   1. langue réglée pour le club (club.langue, personnalisation du
 *      tableau de bord) ;
 *   2. sinon (réglage « Automatique ») : langue du navigateur ;
 *   3. français.
 */
final class Langue
{
    public const DEFAUT = 'fr';

    /** Code => nom de la langue, écrit dans cette langue. */
    public const DISPONIBLES = [
        'fr' => 'Français',
        'en' => 'English',
        'es' => 'Español',
        'it' => 'Italiano',
    ];

    public static function estDisponible(?string $langue): bool
    {
        return $langue !== null && isset(self::DISPONIBLES[$langue]);
    }

    public static function resoudre(?string $langueClub, ?string $navigateur): string
    {
        foreach ([$langueClub, $navigateur] as $candidate) {
            if (self::estDisponible($candidate)) {
                return $candidate;
            }
        }
        return self::DEFAUT;
    }
}
