<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Enum\EquipementStatut;

/**
 * [2026-10-24] Dissociation d'un lot (super-administrateur) : les
 * éléments restants du lot sont répartis en parties (« 4, 3, 3 ») ; la
 * première reste à l'équipement d'origine (référence, historique des
 * contrôles et des prêts), chacune des autres devient un nouvel
 * équipement — lot si la partie compte plusieurs éléments, équipement
 * individuel sinon. Chaque partie a pour nombre initial sa taille.
 *
 * Les nouveaux équipements reprennent les caractéristiques de l'origine
 * (acquisition, catégorie, libellé, statut, dates, emplacement, photo…),
 * avec la référence choisie (proposée selon le modèle) et un code dérivé
 * du code d'origine.
 */
final class DissociationLot
{
    public const MOT_CONFIRMATION = 'DISSOCIER';

    /** Nombre maximal de parties d'une dissociation. */
    public const PARTIES_MAX = 500;

    /** Colonnes recopiées de l'équipement d'origine vers les nouveaux. */
    private const CHAMPS_COPIES = [
        'acquisition_id', 'categorie_id', 'libelle', 'statut', 'remarques',
        'date_dernier_controle', 'emplacement_id', 'date_mise_en_service',
        'date_fin_utilisation', 'photo', 'est_epi', 'duree_max_utilisation',
        'textile_remplacable',
    ];

    public function __construct(private readonly EquipementManager $equipementManager)
    {
    }

    /**
     * Raison pour laquelle l'équipement ne peut pas être dissocié, ou null.
     */
    public static function erreurEquipement(array $equipement): ?string
    {
        if ((int) ($equipement['nombre_initial'] ?? 1) < 2 || (int) ($equipement['nombre'] ?? 1) < 2) {
            return 'Seul un lot d\'au moins 2 éléments restants peut être dissocié.';
        }
        if (!empty($equipement['controle_en_cours'])) {
            return 'Cet équipement est dans un contrôle en cours : dissociation impossible avant sa clôture.';
        }
        $statut = EquipementStatut::tryFrom((int) ($equipement['statut'] ?? 0));
        if ($statut !== null && $statut->estReserve()) {
            return sprintf('Un équipement au statut « %s » ne peut pas être dissocié.', $statut->label());
        }
        return null;
    }

    /**
     * Tailles des parties saisies (« 4, 3, 3 » ; virgules, points-virgules,
     * signes + ou espaces), dont la somme doit valoir le nombre actuel.
     *
     * @return int[]|string Parties, ou message d'erreur
     */
    public static function parties(string $saisie, int $nombre): array|string
    {
        $morceaux = preg_split('/[\s,;+]+/', trim($saisie), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($morceaux) < 2) {
            return 'Indiquez au moins deux parties (ex. « 4, 3, 3 »).';
        }
        if (count($morceaux) > self::PARTIES_MAX) {
            return sprintf('Au plus %d parties.', self::PARTIES_MAX);
        }
        $parties = [];
        foreach ($morceaux as $morceau) {
            if (!ctype_digit($morceau) || (int) $morceau < 1) {
                return sprintf('« %s » n\'est pas une taille de partie valide (entier ≥ 1).', $morceau);
            }
            $parties[] = (int) $morceau;
        }
        if (array_sum($parties) !== $nombre) {
            return sprintf(
                'La somme des parties (%d) doit être égale au nombre actuel d\'éléments du lot (%d).',
                array_sum($parties),
                $nombre
            );
        }
        return $parties;
    }

    /**
     * Dissocie le lot en une transaction.
     *
     * @param int[]    $parties    Tailles (la première reste à l'origine)
     * @param string[] $references Références des nouveaux équipements (une par partie suivante)
     * @return int[] Ids des équipements créés
     */
    public function dissocier(array $equipement, array $parties, array $references): array
    {
        if (count($references) !== count($parties) - 1) {
            throw new \InvalidArgumentException('Une référence par nouvel équipement est attendue.');
        }

        $manager = $this->equipementManager;
        $modele = array_intersect_key($equipement, array_flip(self::CHAMPS_COPIES));
        $code = (string) ($equipement['code'] ?? '');
        $ids = [];

        $manager->beginTransaction();
        try {
            $manager->save([
                'id' => (int) $equipement['id'],
                'nombre' => $parties[0],
                'nombre_initial' => $parties[0],
            ]);
            $rang = 2;
            foreach (array_slice($parties, 1) as $i => $taille) {
                do {
                    $codeNouveau = $code . '-' . $rang++;
                } while ($manager->codeExists($codeNouveau));
                $ids[] = (int) $manager->save($modele + [
                    'reference' => $references[$i],
                    'code' => $codeNouveau,
                    'nombre' => $taille,
                    'nombre_initial' => $taille,
                    'controle_en_cours' => 0,
                ]);
            }
            $manager->commit();
        } catch (\Throwable $e) {
            $manager->rollBack();
            throw $e;
        }

        return $ids;
    }
}
