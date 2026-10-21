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
    // [Prêt de matériel — migration 016]
    case PRETE          = 3;
    case EGARE          = 4;

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE     => 'Disponible',
            self::EN_MAINTENANCE => 'En maintenance',
            self::HORS_SERVICE   => 'Hors service',
            self::PRETE          => 'Prêté',
            self::EGARE          => 'Égaré',
        };
    }

    /**
     * Couleur du carré de statut (liste des équipements).
     */
    public function couleur(): string
    {
        return match ($this) {
            self::DISPONIBLE     => '#28a745',
            self::EN_MAINTENANCE => '#ffd60a',
            self::HORS_SERVICE   => '#dc3545',
            self::PRETE          => '#0d6efd',
            self::EGARE          => '#6f42c1',
        };
    }

    /**
     * Classe Bootstrap du badge de statut (bg-…).
     */
    public function badge(): string
    {
        return match ($this) {
            self::DISPONIBLE     => 'success',
            self::EN_MAINTENANCE => 'warning',
            self::HORS_SERVICE   => 'danger',
            self::PRETE          => 'primary',
            self::EGARE          => 'dark',
        };
    }

    /**
     * [MÉTIER] Statuts « réservés » : ils ne s'attribuent ni ne se lèvent
     * à la main (fiche, modification groupée).
     *   - Hors service : posé / levé par la clôture d'un contrôle ;
     *   - Prêté        : posé par la validation d'un prêt, levé par la
     *                    vérification du retour ;
     *   - Égaré        : posé par un contrôle ou le retour d'un prêt,
     *                    levé par un contrôle.
     */
    public function estReserve(): bool
    {
        return match ($this) {
            self::HORS_SERVICE, self::PRETE, self::EGARE => true,
            default => false,
        };
    }

    /**
     * Message d'erreur quand un statut réservé est attribué à la main.
     */
    public function messageAttribution(): string
    {
        return match ($this) {
            self::PRETE => "Le statut « Prêté » est attribué par la validation d'un prêt.",
            self::EGARE => "Le statut « Égaré » ne peut être attribué qu'à l'issue d'un contrôle ou du retour d'un prêt.",
            default     => "Le statut « Hors service » ne peut être attribué qu'à l'issue d'un contrôle.",
        };
    }

    /**
     * Message d'erreur quand on tente de lever un statut réservé à la main.
     */
    public function messageLevee(): string
    {
        return match ($this) {
            self::PRETE => "Cet équipement est prêté : son statut changera à la vérification du retour du prêt.",
            self::EGARE => "Cet équipement est égaré : seul un contrôle peut lever ce statut.",
            default     => "Cet équipement est hors service : seul un contrôle peut lever ce statut.",
        };
    }

    /**
     * Retourne ['Disponible' => 0, 'En maintenance' => 1, 'Hors service' => 2, …]
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

    /**
     * [MÉTIER 2026-10-21] Statuts attribuables à la main (fiche,
     * modification groupée) : les statuts réservés (Hors service, Prêté,
     * Égaré) sont exclus — cf. estReserve().
     *
     * @return array<string, int>
     */
    public static function forSelectManuel(): array
    {
        return array_filter(self::forSelect(), static fn (int $v) => !self::from($v)->estReserve());
    }
}