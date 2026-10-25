<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Request;

/**
 * [2026-10-25] Pagination des listes (gabarit _pagination.twig).
 *
 * Règle commune à toutes les listes : la pagination (navigation, résumé,
 * choix du nombre de lignes) n'est affichée que s'il y a plus de SEUIL
 * éléments ; à SEUIL ou moins, tout tient sur une page et elle est cachée.
 *
 * Paramètres d'URL : page et limit, préfixés quand une page affiche
 * plusieurs listes (ex. lignes_page, lignes_limit) ; les autres
 * paramètres (filtres, tri) sont conservés dans les liens.
 */
final class Pagination
{
    /** Au-delà de ce nombre d'éléments, la pagination est affichée. */
    public const SEUIL = 10;

    /** Nombres de lignes par page proposés (le premier est le défaut). */
    public const LIMITES = [10, 25, 50, 100];

    /**
     * Nombre de lignes par page valide : une valeur hors LIMITES (dont 5,
     * qui laisserait plusieurs pages sans navigation sous le SEUIL) revient
     * au défaut.
     */
    public static function limite(mixed $valeur): int
    {
        $limite = is_numeric($valeur) ? (int) $valeur : 0;
        return in_array($limite, self::LIMITES, true) ? $limite : self::LIMITES[0];
    }

    /**
     * @param array<int|string, mixed> $lignes Tous les éléments (filtrés, triés)
     * @param string                   $ancre  Ancre ajoutée aux liens (ex. '#retour')
     * @return array{0: list<mixed>, 1: array<string, mixed>} [éléments de la page, pagination]
     */
    public static function paginer(array $lignes, Request $request, string $prefixe = '', string $ancre = ''): array
    {
        $paramPage = $prefixe . 'page';
        $paramLimit = $prefixe . 'limit';

        $limit = self::limite($request->query->get($paramLimit));
        $total = count($lignes);
        $totalPages = max(1, (int) ceil($total / $limit));
        $page = min($totalPages, max(1, (int) $request->query->get($paramPage, 1)));

        // Autres paramètres conservés (scalaires seulement), sans page / limit.
        $query = array_filter(
            $request->query->all(),
            static fn ($v, $k) => is_scalar($v) && $v !== '' && $k !== $paramPage && $k !== $paramLimit,
            ARRAY_FILTER_USE_BOTH
        );
        $url = static fn (int $p) => '?' . http_build_query($query + [$paramLimit => $limit, $paramPage => $p]) . $ancre;

        return [
            array_slice(array_values($lignes), ($page - 1) * $limit, $limit),
            [
                'visible' => $total > self::SEUIL,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'limites' => self::LIMITES,
                'totalPages' => $totalPages,
                'hasPrevious' => $page > 1,
                'hasNext' => $page < $totalPages,
                'param_page' => $paramPage,
                'param_limit' => $paramLimit,
                'ancre' => $ancre,
                'query' => $query,
                'urls' => [
                    'first' => $url(1),
                    'previous' => $url(max(1, $page - 1)),
                    'next' => $url(min($totalPages, $page + 1)),
                    'last' => $url($totalPages),
                ],
            ],
        ];
    }
}
