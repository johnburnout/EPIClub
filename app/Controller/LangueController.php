<?php

namespace Epiclub\Controller;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Langue;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [Localisation] POST /langue : choix de la langue d'affichage (sélecteur
 * du bandeau). Ouvert aux visiteurs non connectés (page de connexion).
 *
 * Le choix est mémorisé en session ; pour un utilisateur connecté, il
 * devient sa préférence (utilisateur.langue, migration 022), retrouvée à
 * chaque connexion. Si la colonne n'existe pas encore (mise à jour en
 * cours), le choix vaut pour la session.
 */
class LangueController extends AbstractController
{
    public function choisir(Request $request): Response
    {
        $this->validateCsrf($request);

        $langue = (string) $request->request->get('langue', '');
        if (Langue::estDisponible($langue)) {
            $this->session->set(Langue::CLE_SESSION, $langue);

            $utilisateur = $this->session->get('user');
            if (is_array($utilisateur) && isset($utilisateur['id'])) {
                try {
                    $this->enregistrerPreference((int) $utilisateur['id'], $langue);
                } catch (\Throwable $e) {
                    error_log('[LangueController] Préférence non enregistrée : ' . $e->getMessage());
                }
                $utilisateur['langue'] = $langue;
                $this->session->set('user', $utilisateur);
            }
        }

        return new RedirectResponse($this->pageDeRetour($request));
    }

    /**
     * Page d'où vient la demande (Referer), si elle est sur ce site ;
     * sinon l'accueil. Seuls le chemin et la requête sont repris : jamais
     * de redirection vers un autre site.
     */
    private function pageDeRetour(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $parties = parse_url($referer);
        if (
            !is_array($parties)
            || !isset($parties['host'], $parties['path'])
            || !in_array($parties['scheme'] ?? '', ['http', 'https'], true)
            || strcasecmp($parties['host'], $request->getHost()) !== 0
            || !str_starts_with($parties['path'], '/')
            || str_starts_with($parties['path'], '//')
            || str_contains($parties['path'], '\\')
        ) {
            return '/';
        }
        return $parties['path'] . (isset($parties['query']) ? '?' . $parties['query'] : '');
    }

    /** Surchargé en test Unit. */
    protected function enregistrerPreference(int $utilisateurId, string $langue): void
    {
        (new UtilisateurManager())->enregistrerLangue($utilisateurId, $langue);
    }
}
