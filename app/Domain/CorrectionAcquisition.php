<?php

declare(strict_types=1);

namespace Epiclub\Domain;

/**
 * [2026-10-24] Correction de la référence et de la date de facture d'une
 * acquisition (super-administrateur, depuis sa fiche).
 *
 * Quand la date change, les dates des équipements de l'acquisition sont
 * réalignées :
 *  - date de mise en service égale à l'ancienne date de facture (ou vide)
 *    → nouvelle date ;
 *  - date de péremption calculée (ancienne date + 10 ans, fin de mois,
 *    cf. DureeDeVie) → recalculée sur la nouvelle date ;
 *  - une date saisie à la main n'est jamais modifiée en silence : elle est
 *    réinterrogée (valeur confirmée ou corrigée par le super-administrateur),
 *    une mise en service ne pouvant pas précéder la nouvelle date.
 * Les références des équipements ne changent pas.
 */
final class CorrectionAcquisition
{
    public const MOT_CONFIRMATION_REFERENCE = 'REFERENCE';
    public const MOT_CONFIRMATION_DATE = 'DATE';

    /** Longueur de acquisition.facture_reference (VARCHAR(32)). */
    public const REFERENCE_MAX = 32;

    public function __construct(
        private readonly AcquisitionManager $acquisitionManager,
        private readonly EquipementManager $equipementManager,
    ) {
    }

    /** Message d'erreur de la référence de facture, ou null si elle est recevable. */
    public static function erreurReference(string $reference): ?string
    {
        if ($reference === '') {
            return 'La référence de facture est obligatoire.';
        }
        if (!mb_check_encoding($reference, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            return 'La référence de facture contient des caractères non autorisés.';
        }
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            return sprintf('La référence de facture ne doit pas dépasser %d caractères.', self::REFERENCE_MAX);
        }
        return null;
    }

    /** Date AAAA-MM-JJ valide ? */
    public static function dateValide(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * Équipements de l'acquisition, avec la nature de leurs dates par
     * rapport à la date de facture actuelle.
     *
     * @return list<array{id:int, reference:string, libelle:string,
     *     date_mise_en_service:?string, date_fin_utilisation:?string,
     *     mes_manuelle:bool, fin_manuelle:bool}>
     */
    public static function classer(array $equipements, string $dateFacture): array
    {
        $dateFacture = substr($dateFacture, 0, 10);
        $finCalculee = DureeDeVie::finDeVie($dateFacture);
        $lignes = [];
        foreach ($equipements as $e) {
            $mes = self::jour($e['date_mise_en_service'] ?? null);
            $fin = self::jour($e['date_fin_utilisation'] ?? null);
            $lignes[] = [
                'id' => (int) $e['id'],
                'reference' => (string) ($e['reference'] ?? ''),
                'libelle' => (string) ($e['libelle'] ?? ''),
                'date_mise_en_service' => $mes,
                'date_fin_utilisation' => $fin,
                'mes_manuelle' => $mes !== null && $mes !== $dateFacture,
                'fin_manuelle' => $fin !== null && $fin !== $finCalculee,
            ];
        }
        return $lignes;
    }

    /**
     * Nouvelles dates des équipements pour la nouvelle date de facture.
     *
     * @param list<array> $lignes   Résultat de classer()
     * @param array<int|string, mixed> $mesSaisies Dates de mise en service réinterrogées, par id
     * @param array<int|string, mixed> $finSaisies Dates de péremption réinterrogées, par id
     * @return array<int, array{date_mise_en_service:?string, date_fin_utilisation:?string}>|string
     *         Modifications par id (équipements inchangés absents), ou message d'erreur
     */
    public static function nouvellesDates(array $lignes, string $nouvelleDate, array $mesSaisies, array $finSaisies): array|string
    {
        $modifs = [];
        foreach ($lignes as $l) {
            $id = $l['id'];
            if ($l['mes_manuelle']) {
                $mes = trim((string) ($mesSaisies[$id] ?? ''));
                if (!self::dateValide($mes)) {
                    return sprintf('Équipement %s : date de mise en service à confirmer.', $l['reference']);
                }
                if ($mes < $nouvelleDate) {
                    return sprintf(
                        'Équipement %s : la mise en service ne peut pas précéder la date de facture (%s).',
                        $l['reference'],
                        date('d/m/Y', (int) strtotime($nouvelleDate))
                    );
                }
            } else {
                $mes = $nouvelleDate;
            }

            if ($l['date_fin_utilisation'] === null) {
                $fin = null;
            } elseif ($l['fin_manuelle']) {
                $fin = trim((string) ($finSaisies[$id] ?? ''));
                if (!self::dateValide($fin)) {
                    return sprintf('Équipement %s : date de fin d\'utilisation à confirmer.', $l['reference']);
                }
            } else {
                $fin = DureeDeVie::finDeVie($nouvelleDate);
            }

            if ($mes !== $l['date_mise_en_service'] || $fin !== $l['date_fin_utilisation']) {
                $modifs[$id] = ['date_mise_en_service' => $mes, 'date_fin_utilisation' => $fin];
            }
        }
        return $modifs;
    }

    /**
     * Enregistre la correction : dates des équipements (transaction) puis
     * acquisition ; un échec sur l'acquisition annule les équipements.
     *
     * @param array<int, array{date_mise_en_service:?string, date_fin_utilisation:?string}> $modifs
     */
    public function appliquer(int $acquisitionId, string $reference, string $date, array $modifs): void
    {
        $equipements = $this->equipementManager;
        $equipements->beginTransaction();
        try {
            foreach ($modifs as $id => $dates) {
                $equipements->save(['id' => $id] + $dates);
            }
            $this->acquisitionManager->save([
                'id' => $acquisitionId,
                'facture_reference' => $reference,
                'facture_date' => $date,
            ]);
            $equipements->commit();
        } catch (\Throwable $e) {
            $equipements->rollBack();
            throw $e;
        }
    }

    private static function jour(mixed $date): ?string
    {
        return ($date === null || $date === '') ? null : substr((string) $date, 0, 10);
    }
}
