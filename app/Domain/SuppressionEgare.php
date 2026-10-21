<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Enum\EquipementStatut;

/**
 * Suppression d'un équipement égaré (migration 017) : autorisée quand
 * il est égaré depuis au moins N mois (club.delai_suppression_egare_mois,
 * réglable sur la page Club), sans contrôle en cours.
 */
final class SuppressionEgare
{
    public const DELAI_DEFAUT = 12;
    public const DELAI_MIN = 1;
    public const DELAI_MAX = 120;

    /** Délai en mois, borné ; défaut si absent ou invalide. */
    public static function normaliserDelai(mixed $mois): int
    {
        if (is_int($mois) || (is_string($mois) && ctype_digit($mois))) {
            $mois = (int) $mois;
            if ($mois >= self::DELAI_MIN && $mois <= self::DELAI_MAX) {
                return $mois;
            }
        }
        return self::DELAI_DEFAUT;
    }

    /**
     * Date limite : un équipement égaré au plus tard ce jour-là peut être
     * supprimé (Y-m-d).
     */
    public static function dateLimite(string $aujourdhui, int $mois): string
    {
        return (new \DateTimeImmutable($aujourdhui))->modify('-' . $mois . ' months')->format('Y-m-d');
    }

    /**
     * Date à partir de laquelle l'équipement pourra être supprimé, ou
     * null s'il n'est pas égaré (ou sans date).
     *
     * @param array<string, mixed> $equipement
     */
    public static function dateSuppressionPossible(array $equipement, int $mois): ?string
    {
        if ((int) ($equipement['statut'] ?? -1) !== EquipementStatut::EGARE->value || empty($equipement['date_egare'])) {
            return null;
        }
        return (new \DateTimeImmutable(substr((string) $equipement['date_egare'], 0, 10)))
            ->modify('+' . $mois . ' months')->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $equipement
     */
    public static function autorisee(array $equipement, string $dateLimite): bool
    {
        return (int) ($equipement['statut'] ?? -1) === EquipementStatut::EGARE->value
            && empty($equipement['controle_en_cours'])
            && !empty($equipement['date_egare'])
            && substr((string) $equipement['date_egare'], 0, 10) <= $dateLimite;
    }
}
