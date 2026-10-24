<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Enum\EquipementStatut;

/**
 * [2026-10-24] Regroupement en lot (administrateur) : des équipements
 * identiques — même libellé, même catégorie, même acquisition — sont
 * associés à l'équipement de départ, qui devient (ou reste) un lot.
 *
 * L'équipement de départ garde sa référence, ses dates, son emplacement
 * et son historique ; ses nombres (actuel et initial) reçoivent ceux des
 * équipements regroupés, qui sont archivés comme une suppression
 * (deleted_at : leur historique de contrôles et de prêts est conservé).
 *
 * Chacun doit être hors contrôle en cours, hors prêt ouvert, sans statut
 * réservé (hors service, prêté, égaré), et de même statut que le départ.
 */
final class RegroupementLot
{
    public const MOT_CONFIRMATION = 'REGROUPER';

    public function __construct(private readonly EquipementManager $equipementManager)
    {
    }

    /** Même libellé, même catégorie, même acquisition (et pas le même équipement). */
    public static function identiques(array $a, array $b): bool
    {
        return (int) $a['id'] !== (int) $b['id']
            && !empty($a['acquisition_id'])
            && (int) $a['acquisition_id'] === (int) ($b['acquisition_id'] ?? 0)
            && (int) ($a['categorie_id'] ?? 0) === (int) ($b['categorie_id'] ?? 0)
            && trim((string) ($a['libelle'] ?? '')) === trim((string) ($b['libelle'] ?? ''));
    }

    /**
     * Raison pour laquelle l'équipement ne peut pas être regroupé, ou null.
     *
     * @param array|null $depart Équipement de départ (null : c'est lui qu'on examine)
     */
    public static function erreurEquipement(array $equipement, bool $dansPretOuvert, ?array $depart = null): ?string
    {
        if (!empty($equipement['controle_en_cours'])) {
            return 'contrôle en cours';
        }
        if ($dansPretOuvert) {
            return 'inscrit sur un prêt';
        }
        $statut = EquipementStatut::tryFrom((int) ($equipement['statut'] ?? 0));
        if ($statut !== null && $statut->estReserve()) {
            return sprintf('statut « %s »', $statut->label());
        }
        if ($depart !== null && (int) ($equipement['statut'] ?? 0) !== (int) ($depart['statut'] ?? 0)) {
            return 'statut différent';
        }
        return null;
    }

    /**
     * Regroupe en une transaction.
     *
     * @param list<array> $autres Équipements à associer (déjà vérifiés)
     */
    public function regrouper(array $depart, array $autres): void
    {
        $nombre = max(1, (int) ($depart['nombre'] ?? 1));
        $nombreInitial = max(1, (int) ($depart['nombre_initial'] ?? 1));
        foreach ($autres as $autre) {
            $nombre += max(1, (int) ($autre['nombre'] ?? 1));
            $nombreInitial += max(1, (int) ($autre['nombre_initial'] ?? 1));
        }

        $manager = $this->equipementManager;
        $manager->beginTransaction();
        try {
            $manager->save([
                'id' => (int) $depart['id'],
                'nombre' => $nombre,
                'nombre_initial' => $nombreInitial,
            ]);
            foreach ($autres as $autre) {
                $manager->delete((int) $autre['id']);
            }
            $manager->commit();
        } catch (\Throwable $e) {
            $manager->rollBack();
            throw $e;
        }
    }
}
