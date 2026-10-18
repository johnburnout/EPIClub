<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Administration du référencement automatique des équipements.
 *
 * Le modèle de référence est GLOBAL au club (club.ref_modele) :
 *   - YY / YYYY : année sur 2 ou 4 chiffres
 *   - CAT       : code de la catégorie (écran Catégories)
 *   - N…        : n° d'ordre, autant de N que de chiffres (NNN → 001)
 *
 * Le compteur est remis à zéro chaque année, par catégorie (décision
 * produit 2026-10-18).
 */
class ReferenceController extends AbstractController
{
    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
    // ==================================================================

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }

    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $clubManager = $this->clubManager();
        $generator = $this->referenceGenerator();
        $form_errors = [];

        $modele = $generator->getModele();

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);

            $modeleRaw = $request->request->get('ref_modele');
            $modele = is_string($modeleRaw) ? trim($modeleRaw) : '';

            $form_errors = $generator->validateModele($modele);

            if (empty($form_errors)) {
                // [MÉTIER] Mise à jour partielle : seul ref_modele est
                // écrit (filteredUpdate sans defaults), la fiche club
                // n'est pas touchée. Singleton id=1 forcé dans
                // ClubManager::_patch().
                $clubManager->save(['id' => 1, 'ref_modele' => $modele]);
                $this->session->getFlashBag()->add('success', "Le modèle de référence a été enregistré.");
                return $this->redirectTo('/admin/references');
            }
        }

        return $this->render('reference_form.twig', [
            'modele' => $modele,
            'form_errors' => $form_errors,
        ]);
    }
}
