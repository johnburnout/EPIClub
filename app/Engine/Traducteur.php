<?php

namespace Epiclub\Engine;

use Twig\TwigFilter;

/**
 * [Localisation] Traduction des textes de l'interface.
 *
 * Les clés sont le texte français lui-même : le français n'a pas de
 * catalogue, et un texte absent d'un catalogue reste affiché en
 * français (jamais de clé technique ni de texte vide à l'écran).
 *
 * Catalogues : ressources/traductions/{en,es,it}.php, tableaux
 * « texte français => traduction ». Paramètres entre accolades :
 *   Traducteur::t('Bonjour {nom}', ['nom' => $prenom])
 *   {{ 'Bonjour {nom}'|trans({nom: user.prenom}) }}
 *
 * La langue est fixée une fois par requête (public/index.php, à partir
 * de Langue::resoudre()) ; elle vaut le français par défaut, notamment
 * en ligne de commande et dans les tests.
 */
final class Traducteur
{
    private const DOSSIER = __DIR__ . '/../../ressources/traductions';

    private static string $langue = Langue::DEFAUT;

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    public static function definirLangue(?string $langue): void
    {
        self::$langue = Langue::estDisponible($langue) ? $langue : Langue::DEFAUT;
    }

    public static function langue(): string
    {
        return self::$langue;
    }

    /**
     * @param array<string, scalar|\Stringable|null> $parametres
     */
    public static function t(string $texte, array $parametres = []): string
    {
        $traduction = self::$langue === Langue::DEFAUT
            ? $texte
            : (self::catalogue(self::$langue)[$texte] ?? $texte);

        if ($parametres === []) {
            return $traduction;
        }

        $remplacements = [];
        foreach ($parametres as $nom => $valeur) {
            $remplacements['{' . $nom . '}'] = (string) $valeur;
        }
        return strtr($traduction, $remplacements);
    }

    /**
     * Exécute $calcul en français, puis rétablit la langue de la requête.
     * Pour les textes enregistrés (journal d'activité) qui réutilisent des
     * libellés traduits, comme EquipementStatut::label().
     *
     * @template T
     * @param callable(): T $calcul
     * @return T
     */
    public static function enFrancais(callable $calcul): mixed
    {
        return self::dans(Langue::DEFAUT, $calcul);
    }

    /**
     * Exécute $calcul dans la langue donnée (langue de la requête si elle
     * est vide ou inconnue), puis rétablit la langue de la requête. Pour
     * les e-mails, écrits dans la langue préférée de leur destinataire.
     *
     * @template T
     * @param callable(): T $calcul
     * @return T
     */
    public static function dans(?string $langue, callable $calcul): mixed
    {
        $precedente = self::$langue;
        if (Langue::estDisponible($langue)) {
            self::$langue = $langue;
        }
        try {
            return $calcul();
        } finally {
            self::$langue = $precedente;
        }
    }

    /**
     * Catalogue d'une langue (vide pour le français ou une langue inconnue).
     *
     * @return array<string, string>
     */
    public static function catalogue(string $langue): array
    {
        if (!Langue::estDisponible($langue) || $langue === Langue::DEFAUT) {
            return [];
        }
        if (!isset(self::$catalogues[$langue])) {
            $fichier = self::DOSSIER . '/' . $langue . '.php';
            $contenu = is_file($fichier) ? require $fichier : [];
            self::$catalogues[$langue] = is_array($contenu) ? $contenu : [];
        }
        return self::$catalogues[$langue];
    }

    /**
     * Filtre Twig « trans ». Le résultat n'est pas marqué sûr : il est
     * échappé comme tout texte (paramètres compris).
     */
    public static function filtreTwig(): TwigFilter
    {
        return new TwigFilter('trans', [self::class, 't']);
    }
}
