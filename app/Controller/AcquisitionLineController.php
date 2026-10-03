<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidator;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AcquisitionLineController extends AbstractController
{
    /**
     * Instancie le validator (issue #34).
     *
     * Pas de cache : le constructeur ne fait aucune I/O, l'instanciation
     * est triviale et évite tout état persistant entre appels.
     */
    private function validator(
        AcquisitionLigneManager $ligneManager,
        AcquisitionManager $acquisitionManager,
    ): AcquisitionValidator {
        return new AcquisitionValidator(
            $ligneManager,
            $acquisitionManager,
            new AcquisitionProcess(),
        );
    }

    public function modifyLine(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $acquisitionLigneManager = new AcquisitionLigneManager();
        $categorieManager = new CategorieManager();

        $id = $this->getValidId($request);
        if ($id === null) {
            return new RedirectResponse('/admin/acquisitions');
        }

        $ligne = $acquisitionLigneManager->findId($id);
        if (!$ligne) {
            $this->session->getFlashBag()->add('error', 'Ligne non trouvée.');
            return new RedirectResponse('/admin/acquisitions');
        }

        // Vérifier si l'acquisition est validée
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
        if ($acquisition && $acquisition['est_validee'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette acquisition est validée, les lignes ne peuvent plus être modifiées.');
            return new RedirectResponse("/admin/acquisitions/acquisition-{$acquisition['id']}");
        }

        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);

            $ligneData = [
                'reference'         => trim((string) $request->request->get('reference')),
                'designation'       => trim((string) $request->request->get('designation')),
                'categorie_libelle' => trim((string) $request->request->get('categorie_libelle')),
                'nombre'            => (int) $request->request->get('nombre'),
            ];
            $regrouper_en_lot = $request->request->has('regrouper_en_lot') ? 1 : 0;

            // [REFACTOR #34] Validation centralisée (excludeId = $id :
            // on s'exclut soi-même du test d'unicité).
            // Pas de remap : acquisition_ligne_form.twig attend déjà
            // les clés courtes (reference, designation, categorie_libelle, nombre).
            $form_errors = $this->validator($acquisitionLigneManager, $acquisitionManager)
                ->validateLigne($ligneData, $id);

            if (empty($form_errors)) {
                // Traiter la catégorie
                $acquisitionProcess = new AcquisitionProcess();
                $categorie_id = $acquisitionProcess->categorie_process([
                    'categorie_libelle' => $ligneData['categorie_libelle'],
                ]);

                // Mettre à jour la ligne
                $ligne['reference']        = $ligneData['reference'];
                $ligne['designation']      = $ligneData['designation'];
                $ligne['categorie_id']     = $categorie_id;
                $ligne['nombre']           = $ligneData['nombre'];
                $ligne['regrouper_en_lot'] = $regrouper_en_lot;
                // equipements_generes est conservé (0 si non généré)

                try {
                    $acquisitionLigneManager->save($ligne);
                } catch (DuplicateReferenceException $e) {
                    // [ROBUSTESSE] Race condition : la pré-vérification
                    // findByReference() a passé mais la contrainte UNIQUE
                    // a rejeté l'UPDATE. On ré-affiche le formulaire.
                    $form_errors['reference'] = 'Cette référence existe déjà.';
                } catch (\Throwable $e) {
                    // [SÉCURITÉ] Log serveur, message générique au client
                    error_log(sprintf(
                        '[AcquisitionLineController] modifyLine failed for ligne id=%s: %s in %s:%d',
                        $id,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ));
                    $form_errors['general'] = 'Une erreur est survenue lors de la modification. Merci de réessayer.';
                }
            }

            // Redirection uniquement si aucune erreur n'a été levée
            if (empty($form_errors)) {
                $this->session->getFlashBag()->add('success', 'Ligne modifiée avec succès.');
                return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
            }
        }

        // Charger les catégories pour le formulaire
        $categories = $categorieManager->findAll();

        return $this->render('acquisition_ligne_form.twig', [
            'ligne' => $ligne,
            'categories' => $categories,
            'form_errors' => $form_errors,
        ]);
    }

    public function deleteLine(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        // [SÉCURITÉ] Vérification CSRF
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        if ($id === null) {
            return new RedirectResponse('/admin/acquisitions');
        }

        $acquisitionLigneManager = new AcquisitionLigneManager();
        $ligne = $acquisitionLigneManager->findId($id);
        if (!$ligne) {
            $this->session->getFlashBag()->add('error', 'Ligne non trouvée.');
            return new RedirectResponse('/admin/acquisitions');
        }

        // Vérifier si l'acquisition est validée
        $acquisitionManager = new AcquisitionManager();
        $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
        if ($acquisition && $acquisition['est_validee'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette acquisition est validée, les lignes ne peuvent plus être supprimées.');
            return new RedirectResponse("/admin/acquisitions/acquisition-{$acquisition['id']}");
        }

        // Si la ligne a déjà des équipements générés, empêcher la suppression
        if ($ligne['equipements_generes'] == 1) {
            $this->session->getFlashBag()->add('error', 'Cette ligne a déjà généré des équipements, elle ne peut pas être supprimée.');
            return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
        }

        try {
            $acquisitionLigneManager->delete($id);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[AcquisitionLineController] deleteLine failed for ligne id=%s: %s in %s:%d',
                $id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $this->session->getFlashBag()->add('error', 'Une erreur est survenue lors de la suppression. Merci de réessayer.');
            return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
        }

        $this->session->getFlashBag()->add('success', 'Ligne supprimée avec succès.');
        return new RedirectResponse("/admin/acquisitions/acquisition_modification-{$ligne['acquisition_id']}");
    }
}