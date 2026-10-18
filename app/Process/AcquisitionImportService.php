<?php

namespace Epiclub\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import d'acquisitions depuis un fichier Excel (.xlsx/.xls) ou CSV.
 *
 * Format attendu (ligne 1 = en-têtes, insensible à la casse et aux
 * accents) :
 *
 *   reference | designation | categorie | nombre | lot |
 *   facture_reference | facture_date | fournisseur
 *
 *  - reference        : OPTIONNELLE — remplie → référence forcée sur la
 *                       fiche équipement ; vide → référence automatique
 *                       selon le modèle global (club.ref_modele) à la
 *                       validation.
 *  - facture_reference: OBLIGATOIRE — les lignes partageant la même
 *                       référence sont groupées dans UNE acquisition,
 *                       automatiquement validée (équipements générés)
 *                       avec facture_document = « facture à importer ».
 *                       Une facture_reference déjà connue → groupe
 *                       ignoré (jamais de fusion).
 *  - lot              : « oui »/« 1 » → équipement unique regroupé.
 *
 * Aucune transaction globale : chaque groupe (facture) est importé
 * indépendamment ; une erreur sur un groupe n'annule pas les autres.
 */
class AcquisitionImportService
{
    /** Aliases d'en-têtes (normalisés : minuscules, sans accents ni ponctuation). */
    private const HEADER_ALIASES = [
        'reference'        => ['reference', 'ref'],
        'designation'      => ['designation'],
        'categorie'        => ['categorie'],
        'nombre'           => ['nombre', 'quantite', 'qte'],
        'lot'              => ['lot'],
        'facture_reference' => ['facturereference', 'nfacture', 'numerofacture', 'facture'],
        'facture_date'     => ['facturedate', 'datefacture', 'date'],
        'fournisseur'      => ['fournisseur'],
    ];

    /** Colonnes obligatoires dans le fichier. */
    private const COLONNES_REQUISES = [
        'designation', 'categorie', 'nombre',
        'facture_reference', 'facture_date', 'fournisseur',
    ];

    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
    // ==================================================================

    protected function acquisitionProcess(): AcquisitionProcess
    {
        return new AcquisitionProcess();
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        return new AcquisitionManager();
    }

    protected function acquisitionLigneManager(): AcquisitionLigneManager
    {
        return new AcquisitionLigneManager();
    }

    // ==================================================================
    // Point d'entrée
    // ==================================================================

    /**
     * Importe le fichier et retourne un rapport structuré :
     * [
     *   'erreur_globale'   => ?string,   // fichier illisible / en-têtes manquants
     *   'lignes_lues'      => int,
     *   'lignes_en_erreur' => [ ['ligne' => n, 'message' => ...], ... ],
     *   'acquisitions'     => [ ['id' =>, 'facture_reference' =>,
     *                           'nb_lignes' =>, 'equipements_generes' =>], ... ],
     *   'groupes_ignores'  => [ ['facture_reference' =>, 'raison' =>], ... ],
     * ]
     */
    public function import(string $cheminFichier, int $userId): array
    {
        $rapport = [
            'erreur_globale' => null,
            'lignes_lues' => 0,
            'lignes_en_erreur' => [],
            'acquisitions' => [],
            'groupes_ignores' => [],
        ];

        try {
            $lignes = IOFactory::load($cheminFichier)
                ->getActiveSheet()
                ->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            error_log('[AcquisitionImportService] Lecture impossible: ' . $e->getMessage());
            $rapport['erreur_globale'] = "Le fichier n'a pas pu être lu (format Excel ou CSV attendu).";
            return $rapport;
        }

        if ($lignes === []) {
            $rapport['erreur_globale'] = 'Le fichier est vide.';
            return $rapport;
        }

        // --- Ligne 1 : en-têtes → position des colonnes ---
        $colonnes = $this->mapperEntetes(array_shift($lignes));
        $manquantes = array_diff(self::COLONNES_REQUISES, array_keys($colonnes));
        if ($manquantes !== []) {
            $rapport['erreur_globale'] = sprintf(
                'En-têtes manquants : %s. Attendus : reference (optionnel) | designation | categorie | nombre | lot | facture_reference | facture_date | fournisseur.',
                implode(', ', $manquantes)
            );
            return $rapport;
        }

        // --- Validation ligne à ligne + groupement par facture ---
        $groupes = [];   // facture_reference => ['meta' => ..., 'lignes' => [...]]
        $numero = 1;     // la ligne 1 = en-têtes, les données commencent à 2
        foreach ($lignes as $ligneBrute) {
            $numero++;
            $donnees = $this->extraireLigne($ligneBrute, $colonnes);

            // Ligne entièrement vide → ignorée silencieusement
            if ($donnees === null) {
                continue;
            }
            $rapport['lignes_lues']++;

            $erreur = $this->validerLigne($donnees);
            if ($erreur !== null) {
                $rapport['lignes_en_erreur'][] = ['ligne' => $numero, 'message' => $erreur];
                continue;
            }

            $ref = $donnees['facture_reference'];
            if (!isset($groupes[$ref])) {
                $groupes[$ref] = [
                    'fournisseur' => $donnees['fournisseur'],
                    'facture_date' => $donnees['facture_date'],
                    'lignes' => [],
                ];
            } else {
                // Cohérence du groupe : même fournisseur / même date
                if ($groupes[$ref]['fournisseur'] !== $donnees['fournisseur']
                    || $groupes[$ref]['facture_date'] !== $donnees['facture_date']) {
                    $rapport['lignes_en_erreur'][] = [
                        'ligne' => $numero,
                        'message' => "Fournisseur ou date incohérent avec les autres lignes de la facture « $ref ».",
                    ];
                    continue;
                }
            }
            $groupes[$ref]['lignes'][] = $this->finaliserLigne($donnees);
        }

        // --- Création des acquisitions (une par groupe) ---
        foreach ($groupes as $factureReference => $groupe) {
            $this->importerGroupe($factureReference, $groupe, $userId, $rapport);
        }

        return $rapport;
    }

    // ==================================================================
    // Groupe (une facture = une acquisition validée)
    // ==================================================================

    private function importerGroupe(string $factureReference, array $groupe, int $userId, array &$rapport): void
    {
        // [MÉTIER] Jamais de fusion : une facture_reference connue → ignorée.
        if ($this->acquisitionManager()->findOneByCriteria(['facture_reference' => $factureReference])) {
            $rapport['groupes_ignores'][] = [
                'facture_reference' => $factureReference,
                'raison' => 'Une acquisition avec cette référence de facture existe déjà.',
            ];
            return;
        }

        try {
            $process = $this->acquisitionProcess();

            $acquisitionId = $process->acquisition_process([
                'fournisseur_nom' => $groupe['fournisseur'],
                'facture_reference' => $factureReference,
                'facture_date' => $groupe['facture_date'],
                'facture_document' => AcquisitionManager::FACTURE_A_IMPORTER,
                'saisie_par' => $userId,
            ]);

            $equipementsGeneres = 0;
            $n = 0;
            foreach ($groupe['lignes'] as $ligne) {
                $n++;
                $categorieId = $process->categorie_process(['categorie_libelle' => $ligne['categorie']]);

                // Référence produit synthétique (colonne UNIQUE de
                // acquisition_ligne) : compacte pour rester < 32 chars.
                $referenceLigne = mb_substr('IMP-' . $factureReference, 0, 27) . '-' . $n;

                $this->acquisitionLigneManager()->save([
                    'acquisition_id' => $acquisitionId,
                    'reference' => $referenceLigne,
                    'designation' => $ligne['designation'],
                    'categorie_id' => $categorieId,
                    'nombre' => $ligne['nombre'],
                    'regrouper_en_lot' => $ligne['lot'],
                    'reference_forcee' => $ligne['reference'] !== '' ? $ligne['reference'] : null,
                ]);

                $equipementsGeneres += $ligne['lot'] === 1 ? 1 : $ligne['nombre'];
            }

            // Validation directe (court-circuite AcquisitionValidator
            // qui exigerait un vrai document de facture) : les
            // équipements sont générés, leurs références sont
            // automatiques ou forcées.
            $process->validerAcquisition($acquisitionId);

            $rapport['acquisitions'][] = [
                'id' => $acquisitionId,
                'facture_reference' => $factureReference,
                'nb_lignes' => count($groupe['lignes']),
                'equipements_generes' => $equipementsGeneres,
            ];
        } catch (\Throwable $e) {
            error_log('[AcquisitionImportService] Groupe « ' . $factureReference . ' »: ' . $e->getMessage());
            $rapport['groupes_ignores'][] = [
                'facture_reference' => $factureReference,
                'raison' => "Erreur lors de l'import du groupe (détail en journal serveur).",
            ];
        }
    }

    // ==================================================================
    // Parsing
    // ==================================================================

    /**
     * Mappe les en-têtes normalisés sur les positions de colonnes.
     *
     * @return array<string, int> champ => index de colonne
     */
    private function mapperEntetes(array $entetes): array
    {
        $colonnes = [];
        foreach ($entetes as $index => $entete) {
            if (!is_string($entete) && !is_numeric($entete)) {
                continue;
            }
            $normalise = $this->normaliserEntete((string) $entete);
            foreach (self::HEADER_ALIASES as $champ => $alias) {
                if (in_array($normalise, $alias, true) && !isset($colonnes[$champ])) {
                    $colonnes[$champ] = $index;
                }
            }
        }
        return $colonnes;
    }

    private function normaliserEntete(string $entete): string
    {
        $s = mb_strtolower(trim($entete));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        return (string) preg_replace('/[^a-z0-9]/', '', $s);
    }

    /**
     * Extrait et normalise les champs d'une ligne. Retourne null si la
     * ligne est entièrement vide.
     */
    private function extraireLigne(array $ligneBrute, array $colonnes): ?array
    {
        $get = function (string $champ) use ($ligneBrute, $colonnes) {
            if (!isset($colonnes[$champ])) {
                return null;
            }
            $v = $ligneBrute[$colonnes[$champ]] ?? null;
            return is_scalar($v) ? trim((string) $v) : null;
        };

        $donnees = [
            'reference' => (string) ($get('reference') ?? ''),
            'designation' => (string) ($get('designation') ?? ''),
            'categorie' => (string) ($get('categorie') ?? ''),
            'nombre' => $get('nombre'),
            'lot' => $get('lot'),
            'facture_reference' => (string) ($get('facture_reference') ?? ''),
            'facture_date' => $this->normaliserDate(
                isset($colonnes['facture_date']) ? ($ligneBrute[$colonnes['facture_date']] ?? null) : null
            ),
            'fournisseur' => (string) ($get('fournisseur') ?? ''),
        ];

        $toutVide = $donnees['reference'] === ''
            && $donnees['designation'] === ''
            && $donnees['categorie'] === ''
            && ($donnees['nombre'] === null || $donnees['nombre'] === '')
            && $donnees['facture_reference'] === ''
            && $donnees['fournisseur'] === '';

        return $toutVide ? null : $donnees;
    }

    /**
     * Normalise une date en Y-m-d. Accepte Y-m-d, d/m/Y, d-m-Y et les
     * numéros de série Excel (cellules date d'un .xlsx).
     */
    private function normaliserDate(mixed $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (is_numeric($valeur)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $s = trim((string) $valeur);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $s);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * Valide une ligne extraite. Retourne le message d'erreur ou null.
     */
    private function validerLigne(array $d): ?string
    {
        if ($d['designation'] === '') {
            return 'La désignation est obligatoire.';
        }
        if ($d['categorie'] === '') {
            return 'La catégorie est obligatoire.';
        }
        if ($d['facture_reference'] === '') {
            return 'La référence de facture est obligatoire.';
        }
        if ($d['fournisseur'] === '') {
            return 'Le fournisseur est obligatoire.';
        }
        if ($d['facture_date'] === null) {
            return 'La date de facture est invalide (formats acceptés : AAAA-MM-JJ, JJ/MM/AAAA).';
        }
        if ($d['nombre'] !== null && $d['nombre'] !== ''
            && (!ctype_digit($d['nombre']) || (int) $d['nombre'] < 1)) {
            return 'Le nombre doit être un entier supérieur ou égal à 1.';
        }
        if (mb_strlen($d['reference']) > 32) {
            return 'La référence dépasse 32 caractères.';
        }
        return null;
    }

    /**
     * Valeurs finales normalisées d'une ligne validée : nombre vide → 1 ;
     * lot « oui/1/x… » → 1.
     */
    private function finaliserLigne(array $d): array
    {
        $d['nombre'] = ($d['nombre'] === null || $d['nombre'] === '') ? 1 : (int) $d['nombre'];
        $lot = mb_strtolower((string) ($d['lot'] ?? ''));
        $d['lot'] = in_array($lot, ['oui', 'yes', '1', 'true', 'x'], true) ? 1 : 0;
        return $d;
    }
}
