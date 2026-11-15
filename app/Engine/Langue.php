<?php

namespace Epiclub\Engine;

/**
 * [Localisation] Langues d'affichage proposées et choix de la langue
 * d'une requête.
 *
 * Ordre de priorité :
 *   1. préférence enregistrée de l'utilisateur connecté (utilisateur.langue) ;
 *   2. choix fait dans la session (visiteur non connecté, ou préférence
 *      pas encore enregistrable : migration 022 non appliquée) ;
 *   3. langue du navigateur (Accept-Language) ;
 *   4. français.
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

    /** Clé de session du choix d'un visiteur. */
    public const CLE_SESSION = 'langue';

    public static function estDisponible(?string $langue): bool
    {
        return $langue !== null && isset(self::DISPONIBLES[$langue]);
    }

    public static function resoudre(?string $preference, ?string $choixSession, ?string $navigateur): string
    {
        foreach ([$preference, $choixSession, $navigateur] as $candidate) {
            if (self::estDisponible($candidate)) {
                return $candidate;
            }
        }
        return self::DEFAUT;
    }
}
