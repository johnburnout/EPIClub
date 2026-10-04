<?php

declare(strict_types=1);

namespace Epiclub\Domain;

/**
 * [REFACTOR VAGUE 8] Contrat du validator d'acquisitions.
 *
 * Introduit pour rendre AcquisitionValidator (classe `final`) mockable
 * en test unitaire. Les handlers d'AcquisitionController peuvent ainsi
 * être testés sans BDD ni dépendance à AcquisitionLigneManager /
 * AcquisitionManager / AcquisitionProcess.
 *
 * L'implémentation concrète reste `final class AcquisitionValidator`.
 */
interface AcquisitionValidatorInterface
{
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
    public function validateLigne(array $ligne, ?int $excludeId = null): array;

    /**
     * Valide une acquisition : check facture, appel AcquisitionProcess, save.
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
    ): array;
}