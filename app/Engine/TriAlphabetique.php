<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [2026-10-24] Tri alphabétique des listes déroulantes (filtre Twig
 * « tri_alpha ») : ordre français, sans tenir compte des majuscules ni
 * des accents (« Échelle » avec les E, pas après Z), nombres dans
 * l'ordre naturel (« Armoire 2 » avant « Armoire 10 »).
 *
 * Collator (extension intl) quand elle est disponible ; sinon,
 * comparaison des textes en minuscules sans accents.
 */
final class TriAlphabetique
{
    /**
     * @param mixed                $elements Liste (tableau ou itérable) ;
     *                                       null ou vide → [] (comme un for Twig)
     * @param string|string[]|null $champs   Champ(s) du libellé (concaténés),
     *                                       null pour une liste de textes
     * @return list<mixed>
     */
    public static function trier(mixed $elements, string|array|null $champs = null): array
    {
        if (is_array($elements)) {
            $liste = array_values($elements);
        } elseif ($elements instanceof \Traversable) {
            $liste = iterator_to_array($elements, false);
        } else {
            return [];
        }
        $champs = $champs === null ? null : (array) $champs;
        $cle = static function (mixed $e) use ($champs): string {
            if ($champs === null) {
                return (string) $e;
            }
            $morceaux = [];
            foreach ($champs as $champ) {
                $morceaux[] = (string) (is_array($e) ? ($e[$champ] ?? '') : ($e->$champ ?? ''));
            }
            return trim(implode(' ', $morceaux));
        };

        $collator = class_exists(\Collator::class) ? new \Collator('fr_FR') : null;
        if ($collator !== null) {
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
            $collator->setStrength(\Collator::SECONDARY);
        }
        usort($liste, static function (mixed $a, mixed $b) use ($cle, $collator): int {
            return $collator !== null
                ? (int) $collator->compare($cle($a), $cle($b))
                : strnatcmp(self::normaliser($cle($a)), self::normaliser($cle($b)));
        });
        return $liste;
    }

    /** Minuscules sans accents (repli sans l'extension intl). */
    public static function normaliser(string $texte): string
    {
        $texte = mb_strtolower($texte);
        return strtr($texte, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);
    }
}
