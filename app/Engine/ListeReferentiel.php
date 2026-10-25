<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Request;

/**
 * [2026-10-25] Recherche, filtres et tri des listes de référentiels
 * (catégories, emplacements, fournisseurs).
 *
 * Paramètres d'URL : q (recherche), order_by, order_dir (asc | desc),
 * equipements (avec | sans), plus les filtres propres à chaque liste
 * (valeurs autorisées passées par le contrôleur).
 */
final class ListeReferentiel
{
    /**
     * Paramètres validés de la requête.
     *
     * @param string[]                             $ordres  Clés de tri autorisées
     * @param array<string, list<string>>          $filtres Filtres propres : nom => valeurs autorisées
     * @return array<string, ?string>
     */
    public static function parametres(Request $request, array $ordres, string $ordreParDefaut, array $filtres = []): array
    {
        $q = $request->query->get('q');
        $q = is_string($q) ? trim(mb_substr($q, 0, 100)) : '';
        $orderBy = (string) $request->query->get('order_by', $ordreParDefaut);

        $params = [
            'q' => $q !== '' ? $q : null,
            'order_by' => in_array($orderBy, $ordres, true) ? $orderBy : $ordreParDefaut,
            'order_dir' => $request->query->get('order_dir') === 'desc' ? 'desc' : 'asc',
        ];
        foreach (['equipements' => ['avec', 'sans']] + $filtres as $nom => $valeurs) {
            $valeur = (string) $request->query->get($nom, '');
            $params[$nom] = in_array($valeur, $valeurs, true) ? $valeur : null;
        }
        return $params;
    }

    /**
     * Lignes dont l'un des champs contient le texte (sans tenir compte des
     * majuscules ni des accents).
     *
     * @param array<int|string, array<string, mixed>> $lignes (éventuellement non indexées : array_filter)
     * @param string[]                                $champs
     * @return list<array<string, mixed>>
     */
    public static function rechercher(array $lignes, ?string $q, array $champs): array
    {
        if ($q === null || $q === '') {
            return array_values($lignes);
        }
        $aiguille = TriAlphabetique::normaliser($q);
        return array_values(array_filter($lignes, static function (array $l) use ($champs, $aiguille): bool {
            foreach ($champs as $champ) {
                if (str_contains(TriAlphabetique::normaliser((string) ($l[$champ] ?? '')), $aiguille)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Garde les lignes avec (ou sans) équipements affectés.
     *
     * @param array<int|string, array<string, mixed>> $lignes (éventuellement non indexées : array_filter)
     * @param array<int, int>                         $nbEquipements id => nombre
     * @return list<array<string, mixed>>
     */
    public static function filtrerEquipements(array $lignes, ?string $equipements, array $nbEquipements): array
    {
        if ($equipements === null) {
            return array_values($lignes);
        }
        $avec = $equipements === 'avec';
        return array_values(array_filter(
            $lignes,
            static fn (array $l) => (($nbEquipements[(int) $l['id']] ?? 0) > 0) === $avec
        ));
    }

    /**
     * Trie : texte par ordre alphabétique (TriAlphabetique), nombre par
     * valeur puis par libellé.
     *
     * @param list<array<string, mixed>>                $lignes
     * @param array<string, string|callable(array):int> $cles    clé de tri => champ texte ou valeur numérique
     * @return list<array<string, mixed>>
     */
    public static function trier(array $lignes, string $orderBy, string $orderDir, array $cles, string $champLibelle): array
    {
        $cle = $cles[$orderBy] ?? $champLibelle;
        if (is_string($cle)) {
            $tries = TriAlphabetique::trier($lignes, [$cle, $champLibelle]);
        } else {
            $parLibelle = TriAlphabetique::trier($lignes, $champLibelle);
            $rang = array_flip(array_map(static fn (array $l) => (int) $l['id'], $parLibelle));
            $tries = $parLibelle;
            usort($tries, static fn (array $a, array $b) =>
                ($cle($a) <=> $cle($b)) ?: ($rang[(int) $a['id']] <=> $rang[(int) $b['id']]));
        }
        return $orderDir === 'desc' ? array_reverse($tries) : $tries;
    }
}
