<?php

namespace Epiclub\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;

class AcquisitionProcess
{
    
    // ==================================================================
    // [REFACTOR VAGUE 9] Factories managers — mockables en test Unit.
    //
    // Chaque méthode retourne une nouvelle instance concrète.
    // Les sous-classes de test (TestableAcquisitionProcess) surchargent
    // ces factories pour injecter des mocks et éviter l'ouverture PDO
    // déclenchée par AbstractManager::__construct().
    // ==================================================================
    
    protected function acquisitionManager(): AcquisitionManager
    {
        return new AcquisitionManager();
    }
    
    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return new AcquisitionLigneManager();
    }
    
    protected function fournisseurManager(): FournisseurManager
    {
        return new FournisseurManager();
    }
    
    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
    
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    /**
     * Factory ReferenceGenerator — mockable en test Unit (même pattern
     * que les managers ci-dessus).
     */
    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }
    
    public function acquisition_process($acquisition)
    {
        $acquisitionManager = $this->acquisitionManager();
        $fournisseurManager = $this->fournisseurManager();

        // ✅ S'assurer que toutes les clés existent avec des valeurs par défaut
        $defaults = [
            'fournisseur_nom' => null,
            'facture_reference' => null,
            'facture_date' => date('Y-m-d'),
            'facture_document' => null,
            'saisie_par' => null,
            'est_validee' => 0,
        ];
        
        $acquisition = array_merge($defaults, $acquisition);

        if ($fournisseur = $fournisseurManager->findOneByCriteria(['nom' => $acquisition['fournisseur_nom']])) {
            $fournisseur_id = $fournisseur['id'];
        } else {
            $fournisseur_id = $fournisseurManager->save(['nom' => $acquisition['fournisseur_nom']]);
        }

        $acquisition['fournisseur_id'] = $fournisseur_id;
        unset($acquisition['fournisseur_nom']);

        return (int) $acquisitionManager->save($acquisition);
    }

    public function categorie_process(array $ligne)
    {
        $categorieManager = $this->categorieManager();

        if ($categorie = $categorieManager->findOneByCriteria(['libelle' => $ligne['categorie_libelle']])) {
            $categorie_id = $categorie['id'];
        } else {
            $new_categorie = [
                'libelle' => ucfirst($ligne['categorie_libelle']),
                'description' => '',
                'image' => '',
                'est_epi' => 1
            ];
            $categorie_id = $categorieManager->save($new_categorie);
        }

        return $categorie_id;
    }

    public function create_equipement_process(int $ligne_id)
    {
        $acquisitionLigneManager = $this->acquisitionLigneManager();
        $acquisitionManager = $this->acquisitionManager();
        $categorieManager = $this->categorieManager();
        
        if ($ligne = $acquisitionLigneManager->findId($ligne_id)) {
            if ($ligne['equipements_generes'] === 1) {
                throw new \RuntimeException("Les équipements ont déjà été générés.", 1);
            }
            
            $acquisition = $acquisitionManager->findId($ligne['acquisition_id']);
            $annee = date('Y', strtotime($acquisition['facture_date']));
            $equipementManager = $this->equipementManager();
            
            $categorie = $categorieManager->findId($ligne['categorie_id']);
            $est_epi = $categorie ? $categorie['est_epi'] : 1;
            // [MÉTIER] La case « durée maximum d'utilisation » de
            // l'équipement hérite du défaut de la catégorie
            // (décision produit 2026-10-19), modifiable ensuite sur
            // la fiche équipement.
            $dureeMax = $categorie ? (int) ($categorie['duree_max_utilisation'] ?? 0) : 0;
            // [MÉTIER] Date de péremption issue de l'import Excel/CSV
            // → date_fin_utilisation de l'équipement.
            $datePeremption = !empty($ligne['date_peremption']) ? $ligne['date_peremption'] : null;
            
            // [MÉTIER] Date de mise en service = date de la facture
            // (décision produit 2026-10-20) ; modifiable une seule fois
            // ensuite dans la fiche équipement, jamais avant la facture.
            $dateMiseEnService = !empty($acquisition['facture_date'])
                ? date('Y-m-d', strtotime($acquisition['facture_date']))
                : null;

            $regrouper_en_lot = isset($ligne['regrouper_en_lot']) && $ligne['regrouper_en_lot'] == 1;

            // [MÉTIER] Référence de la fiche équipement (décision
            // produit 2026-10-18) :
            //  - reference_forcee renseignée (import Excel/CSV) → elle
            //    prime, suffixée -1..-n si plusieurs unités ;
            //  - sinon → référence AUTO selon le modèle global
            //    (club.ref_modele), compteur remis à zéro chaque année,
            //    recalculé après chaque enregistrement.
            $referenceForcee = trim((string) ($ligne['reference_forcee'] ?? ''));
            $referenceAuto = function () use ($ligne, $annee): string {
                return $this->referenceGenerator()->generate(
                    (int) $ligne['categorie_id'],
                    (int) $annee
                );
            };

            //  - [2026-10-20] référence de ligne conforme au modèle
            //    (proposée à la saisie) → elle devient la référence de
            //    la 1re unité, les suivantes prennent les n° suivants ;
            //    un n° déjà pris entre-temps → référence auto.
            $compteurDepart = $referenceForcee === ''
                ? $this->referenceGenerator()->compteurSiConforme(
                    (string) ($ligne['reference'] ?? ''),
                    (int) $ligne['categorie_id'],
                    (int) $annee
                )
                : null;
            $referenceUnite = function (int $rang) use ($ligne, $annee, $compteurDepart, $equipementManager, $referenceAuto): string {
                if ($compteurDepart !== null) {
                    $candidate = $this->referenceGenerator()->referenceAuRang(
                        (int) $ligne['categorie_id'],
                        (int) $annee,
                        $compteurDepart + $rang
                    );
                    if (!$equipementManager->referenceExists($candidate)) {
                        return $candidate;
                    }
                }
                return $referenceAuto();
            };

            // [MÉTIER] Stock actuel d'un lot (import Excel/CSV —
            // décision 2026-10-20) : entre 1 et le nombre initial,
            // sinon le lot est complet.
            $nombreActuel = (int) ($ligne['nombre_actuel'] ?? 0);
            if ($nombreActuel < 1 || $nombreActuel > (int) $ligne['nombre']) {
                $nombreActuel = (int) $ligne['nombre'];
            }

            if ($regrouper_en_lot) {
                // [MÉTIER] Pas de suffixe « LOT » dans le code
                // (décision produit 2026-10-19) : le lot porte le n° 001
                // de sa ligne, comme une unité.
                $code = $annee . '-' . str_pad($ligne['acquisition_id'], 3, '0', STR_PAD_LEFT) . '-' .
                        str_pad($ligne['id'], 3, '0', STR_PAD_LEFT) . '-001';
                while ($equipementManager->codeExists($code)) {
                    $code = $code . '-' . rand(10, 99);
                }
                $new_equipement = [
                    'acquisition_id' => $ligne['acquisition_id'],
                    'categorie_id' => $ligne['categorie_id'],
                    'reference' => $referenceForcee !== '' ? $referenceForcee : $referenceUnite(0),
                    'libelle' => $ligne['designation'],
                    'code' => $code,
                    'statut' => 0,
                    'remarques' => null,
                    'date_dernier_controle' => null,
                    'controle_en_cours' => 0,
                    'emplacement_id' => null,
                    'date_mise_en_service' => $dateMiseEnService,
                    'date_fin_utilisation' => $datePeremption,
                    'nombre' => $nombreActuel,
                    // [MÉTIER] Nombre initial du lot : plafond non
                    // dépassable du nombre actuel (décision 2026-10-19).
                    'nombre_initial' => $ligne['nombre'],
                    'est_epi' => $est_epi,
                    'duree_max_utilisation' => $dureeMax
                ];
                $this->saveEquipementAvecGardeDoublon($equipementManager, $new_equipement);
            } else {
                for ($i = 1; $i <= $ligne['nombre']; $i++) {
                    $code = $annee . '-' . str_pad($ligne['acquisition_id'], 3, '0', STR_PAD_LEFT) . '-' .
                            str_pad($ligne['id'], 3, '0', STR_PAD_LEFT) . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                    while ($equipementManager->codeExists($code)) {
                        $code = $code . '-' . rand(10, 99);
                    }
                    
                    if ($referenceForcee !== '') {
                        $reference = $ligne['nombre'] > 1
                            ? $referenceForcee . '-' . $i
                            : $referenceForcee;
                    } else {
                        // Référence auto : le compteur se recalcule à
                        // chaque passage (l'unité précédente est déjà
                        // enregistrée).
                        $reference = $referenceUnite($i - 1);
                    }
                    
                    $new_equipement = [
                        'acquisition_id' => $ligne['acquisition_id'],
                        'categorie_id' => $ligne['categorie_id'],
                        'reference' => $reference,
                        'libelle' => $ligne['designation'],
                        'code' => $code,
                        'statut' => 0,
                        'remarques' => null,
                        'date_dernier_controle' => null,
                        'controle_en_cours' => 0,
                        'emplacement_id' => null,
                        'date_mise_en_service' => $dateMiseEnService,
                        'date_fin_utilisation' => $datePeremption,
                        'nombre' => 1,
                        'nombre_initial' => 1,
                        'est_epi' => $est_epi,
                        'duree_max_utilisation' => $dureeMax
                    ];
                    $this->saveEquipementAvecGardeDoublon($equipementManager, $new_equipement);
                }
            }
            
            $ligne['equipements_generes'] = 1;
            $acquisitionLigneManager->save($ligne);
            return true;
        }
        throw new \RuntimeException("L'acquisition ligne n'existe pas", 1);
    }
    
    /**
     * [SÉCURITÉ — audit C5] Garde anti-doublon sur club_equipement.reference.
     *
     * La migration 009 pose UNIQUE(reference) ; si un import tente une
     * référence déjà existante (reference_forcee dupliquée, race
     * condition), MySQL lève PDOException 1062. On la convertit en
     * erreur métier claire au lieu d'un 500 générique — même pattern
     * que AcquisitionLigneManager::_create().
     */
    private function saveEquipementAvecGardeDoublon(EquipementManager $equipementManager, array $equipement): void
    {
        try {
            $equipementManager->save($equipement);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
                throw new \RuntimeException(
                    sprintf('La référence « %s » existe déjà : import refusé pour éviter un doublon.', $equipement['reference']),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    public function validerAcquisition(int $acquisitionId): bool
    {
        $acquisitionLigneManager = $this->acquisitionLigneManager();
        $lignes = $acquisitionLigneManager->findByAcquisition($acquisitionId);
        
        $lignesNonGenerees = array_filter($lignes, function($ligne) {
            return $ligne['equipements_generes'] == 0;
        });
        
        if (empty($lignesNonGenerees)) {
            return false;
        }
        
        foreach ($lignesNonGenerees as $ligne) {
            $this->create_equipement_process($ligne['id']);
        }
        
        foreach ($lignesNonGenerees as $ligne) {
            $ligne['equipements_generes'] = 1;
            $acquisitionLigneManager->save($ligne);
        }
        
        $acquisitionManager = $this->acquisitionManager();
        $acquisition = $acquisitionManager->findId($acquisitionId);
        if ($acquisition) {
            $acquisition['est_validee'] = 1;
            $acquisitionManager->save($acquisition);
        }
        
        return true;
    }
}