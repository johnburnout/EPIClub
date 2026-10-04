<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Response;

/**
 * [REFACTOR VAGUE 7] DTO retourné par les handlers d'AcquisitionController.
 *
 * Remplace la convention ?Response + mutation par référence
 * (&$acquisition, &$form_errors, &$ligneData) par un objet immuable
 * explicite, testable unitairement.
 *
 * Contrat :
 *  - response     : RedirectResponse si redirection immédiate, null sinon
 *                   (dans ce cas, le contrôleur doit rendre le formulaire).
 *  - acquisition  : l'array $acquisition mis à jour (whitelist, fournisseur_id,
 *                   facture_document, ...) pour réaffichage en cas d'erreur.
 *  - formErrors   : les erreurs de formulaire indexées par champ.
 *  - ligneData    : les données de ligne saisies (uniquement add_ligne).
 *
 * Cas particulier handleDeleteAction : response est TOUJOURS non-null
 * (pas de rendu de formulaire en cas d'erreur), acquisition/formErrors/
 * ligneData restent vides.
 */
final class HandlerResult
{
    public function __construct(
        public readonly ?Response $response,
        public readonly array $acquisition = [],
        public readonly array $formErrors = [],
        public readonly array $ligneData = [],
    ) {}

    public function isRedirect(): bool
    {
        return $this->response !== null;
    }
}