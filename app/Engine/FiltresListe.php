<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * [2026-11-15] Filtres des listes conservés pendant la session : la
 * requête (filtres, recherche, tri, page) de chaque liste affichée est
 * mémorisée, puis reprise au retour vers la liste — lien « Retour à la
 * liste » (fonction Twig `url_liste`) ou redirection après une
 * consultation, une modification ou un contrôle (AbstractController::redirectTo).
 *
 * La liste ouverte sans paramètre (menu, « Retirer ce filtre »,
 * « Réinitialiser ») efface les filtres mémorisés.
 */
final class FiltresListe
{
    public const CLE_SESSION = 'filtres_listes';

    /** Nombre maximal de listes mémorisées (fiches contrôle comprises). */
    public const MAX_LISTES = 20;

    /** Listes dont les filtres sont conservés. */
    private const LISTES = [
        '/equipements',
        '/admin/controles',
        '/admin/acquisitions',
        '/prets',
        '/tickets',
        '/admin/categories',
        '/admin/fournisseurs',
        '/admin/emplacements',
        '/admin/utilisateurs',
        '/admin/journal-activite',
    ];

    /** Fiche d'un contrôle : lignes et équipements à ajouter, filtrables. */
    private const MOTIF_FICHE_CONTROLE = '#^/admin/controles/edit/[1-9][0-9]*$#';

    /** Paramètres jamais repris (ils déclenchent un export). */
    private const PARAMETRES_EXCLUS = ['action', 'export_type'];

    public static function estListe(string $chemin): bool
    {
        return in_array($chemin, self::LISTES, true)
            || preg_match(self::MOTIF_FICHE_CONTROLE, $chemin) === 1;
    }

    /**
     * Après l'affichage d'une liste (GET, 200, sans export) : mémorise sa
     * requête, ou l'efface si la liste est ouverte sans paramètre.
     */
    public static function memoriser(SessionInterface $session, Request $request, Response $response): void
    {
        $chemin = $request->getPathInfo();
        if (!$request->isMethod('GET')
            || $response->getStatusCode() !== Response::HTTP_OK
            || !self::estListe($chemin)
            || $request->query->has('action')
        ) {
            return;
        }

        $parametres = $request->query->all();
        foreach (self::PARAMETRES_EXCLUS as $exclu) {
            unset($parametres[$exclu]);
        }
        $requete = http_build_query($parametres);

        $listes = $session->get(self::CLE_SESSION, []);
        if (!is_array($listes)) {
            $listes = [];
        }
        unset($listes[$chemin]);
        if ($requete !== '') {
            // Ajout en dernier : les listes les plus anciennes sont oubliées d'abord.
            $listes[$chemin] = $requete;
            $listes = array_slice($listes, -self::MAX_LISTES, null, true);
        }
        $session->set(self::CLE_SESSION, $listes);
    }

    /**
     * URL de la liste avec ses filtres mémorisés ; toute autre URL (ou
     * une liste déjà accompagnée de paramètres) est rendue telle quelle.
     */
    public static function url(SessionInterface $session, string $url): string
    {
        if (!self::estListe($url)) {
            return $url;
        }
        $listes = $session->get(self::CLE_SESSION, []);
        $requete = is_array($listes) ? ($listes[$url] ?? '') : '';
        return is_string($requete) && $requete !== '' ? $url . '?' . $requete : $url;
    }
}
