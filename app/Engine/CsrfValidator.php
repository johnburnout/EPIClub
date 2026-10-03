<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Valide le jeton CSRF pour les requêtes state-changing (POST).
 *
 * Extrait de AbstractController::validateCsrf() pour être testable
 * unitairement en isolation, sans dépendre du constructeur du contrôleur
 * (TwigRenderer, Session, uploads, etc.).
 *
 * Tolérant aux méthodes autres que POST : l'appel peut donc être placé
 * en tête d'une action mixte (affichage du formulaire + POST) sans
 * casser l'affichage initial.
 *
 * Le jeton peut être fourni :
 *   - dans le corps du formulaire : champ `csrf_token`
 *   - ou via l'en-tête HTTP       : `X-CSRF-Token` (utile pour fetch/ajax)
 *
 * La comparaison est faite en temps constant via hash_equals().
 */
final class CsrfValidator
{
    public function __construct(private Session $session)
    {
    }

    /**
     * @throws AccessDeniedException si le jeton est absent ou invalide
     */
    public function validate(Request $request): void
    {
        if (!$request->isMethod('POST')) {
            return;
        }

        $submittedToken = $request->request->get('csrf_token')
            ?? $request->headers->get('X-CSRF-Token');

        $sessionToken = $this->session->get('csrf_token');

        if (!is_string($sessionToken) || $sessionToken === ''
            || !is_string($submittedToken) || $submittedToken === ''
            || !hash_equals($sessionToken, $submittedToken)
        ) {
            error_log('[CsrfValidator] Jeton CSRF absent ou invalide pour '
                . $request->getPathInfo());

            // Flash optionnel : si la session n'a pas de FlashBag utilisable
            // (cas des tests unitaires par ex.), on tolère l'absence.
            // En prod, la FlashBag est toujours disponible.
            try {
                $this->session->getFlashBag()->add(
                    'note',
                    'Session expirée ou requête invalide. Veuillez réessayer.'
                );
            } catch (\Throwable $e) {
                // Pas de flash possible — on continue, le throw qui suit
                // reste la garantie de sécurité.
            }

            throw new AccessDeniedException('Jeton CSRF invalide.');
        }
    }
}