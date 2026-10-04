<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Process\AcquisitionProcess;

/**
 * Centralise la validation des acquisitions et de leurs lignes.
 *
 * Extrait de AcquisitionController / AcquisitionLineController (issue #34)
 * pour mettre fin à la duplication et permettre des tests unitaires isolés.
 *
 * Testable en isolation : aucune dépendance à Request/Session.
 * Les contrôleurs fournissent les routes de redirection en paramètre.
 */
final class AcquisitionValidator implements AcquisitionValidatorInterface
{
    public function __construct(
        private AcquisitionLigneManager $ligneManager,
        private AcquisitionManager $acquisitionManager,
        private AcquisitionProcess $acquisitionProcess,
    ) {
    }

    /**
     * Valide une ligne d'acquisition.
     *
     * @param array    $ligne     Données brutes (reference, designation,
     *                            categorie_libelle, nombre, ...).
     * @param int|null $excludeId Id de la ligne à exclure du test d'unicité
     *                            (utile en modification).
     *
     * @return array<string,string> Tableau vide si valide, sinon
     *                              [champ => message d'erreur].
     */
    public function validateLigne(array $ligne, ?int $excludeId = null): array
    {
        $errors = [];

        $reference = trim((string) ($ligne['reference'] ?? ''));
        if ($reference === '') {
            $errors['reference'] = 'La référence est obligatoire.';
        } elseif ($this->ligneManager->findByReference($reference, $excludeId)) {
            $errors['reference'] = 'Cette référence existe déjà.';
        }

        if (trim((string) ($ligne['designation'] ?? '')) === '') {
            $errors['designation'] = 'Le libellé est obligatoire.';
        }

        if (trim((string) ($ligne['categorie_libelle'] ?? '')) === '') {
            $errors['categorie_libelle'] = 'La catégorie est obligatoire.';
        }

        if ((int) ($ligne['nombre'] ?? 0) < 1) {
            $errors['nombre'] = 'Le nombre doit être supérieur à 0.';
        }

        return $errors;
    }

    /**
     * Valide une acquisition : check facture, appel AcquisitionProcess, save.
     *
     * Retourne un struct (pas de Response) pour rester testable en isolation
     * et ne pas dépendre de Symfony HttpFoundation. Les contrôleurs
     * construisent ensuite la RedirectResponse via redirectFromResult().
     *
     * @param array  $acquisition  Acquisition complète (avec 'id' et
     *                             'facture_document').
     * @param string $successRoute URL de redirection en cas de succès.
     * @param string $failureRoute URL de redirection en cas d'échec.
     *
     * @return array{route:string,type:'success'|'error',message:string,critical:bool}
     */
    public function performValidation(
        array $acquisition,
        string $successRoute,
        string $failureRoute,
    ): array {
        // 1. Facture obligatoire — échec critique (retour immédiat)
        if (empty($acquisition['facture_document'])) {
            return [
                'route'    => $failureRoute,
                'type'     => 'error',
                'message'  => '❌ Impossible de valider : veuillez d\'abord télécharger la facture.',
                'critical' => true,
            ];
        }

        // 2. Appel au process métier + save
        try {
            $this->acquisitionProcess->validerAcquisition($acquisition['id']);

            $acquisition['est_validee'] = 1;
            $this->acquisitionManager->save($acquisition);

            return [
                'route'    => $successRoute,
                'type'     => 'success',
                'message'  => '✅ Acquisition validée ! Les équipements ont été générés.',
                'critical' => false,
            ];
        } catch (\Throwable $e) {
            // [SÉCURITÉ] Ne pas exposer $e->getMessage() au client
            error_log(sprintf(
                '[AcquisitionValidator] Validation failed for acquisition id=%s: %s in %s:%d',
                $acquisition['id'] ?? '?',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return [
                'route'    => $failureRoute,
                'type'     => 'error',
                'message'  => 'Une erreur est survenue lors de la validation. '
                            . 'Merci de réessayer ou de contacter un administrateur.',
                'critical' => true,
            ];
        }
    }
}