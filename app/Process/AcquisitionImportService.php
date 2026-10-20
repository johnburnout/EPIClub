<?php

namespace Epiclub\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import d'acquisitions depuis un fichier Excel (.xlsx/.xls) ou CSV,
 * en deux étapes (décision produit 2026-10-19) :
 *
 *  1. analyser() : détection des colonnes, proposition de
 *     correspondance champ ↔ colonne, repérage des lignes non
 *     conformes (cases fusionnées, données incompatibles…) qui seront
 *     ignorées ;
 *  2. import() : exécution avec la correspondance validée par
 *     l'utilisateur.
 *
 * Règles métier :
 *  - la ligne d'en-tête est détectée automatiquement dans les 10
 *    premières lignes (≥ 2 en-têtes reconnus ; sinon première ligne
 *    non vide) — les lignes au-dessus sont ignorées (signalées à
 *    l'analyse) ;
 *  - groupement : par facture_reference si renseignée, sinon par date
 *    d'achat normalisée (référence synthétique IMP-AAAA-MM-JJ) — une
 *    acquisition par groupe, automatiquement validée, document
 *    « facture à importer » (un PDF groupé pourra être téléversé
 *    ultérieurement) ;
 *  - date d'achat : année seule → 1er janvier, vide → date courante ;
 *  - date de péremption : année seule → 31 décembre, vide → sans
 *    objet ; reportée sur club_equipement.date_fin_utilisation ;
 *  - EPI à durée de vie limitée (catégorie « durée maximum
 *    d'utilisation ») — décision produit 2026-10-20 : importé
 *    seulement si la date d'achat est renseignée (pas de date du
 *    jour par défaut) ; fin de vie vide → 31 décembre de l'année
 *    d'achat + 10 ans. Les deux dates sont modifiables ligne à ligne
 *    avant l'import ;
 *  - catégorie : résolue par CODE (ex. COR), sinon par libellé ; si
 *    inconnue, l'étape d'analyse propose de la rattacher à une
 *    catégorie existante, de la regrouper avec une autre catégorie à
 *    créer, ou de la créer avec un nom et un code choisis ; chaque
 *    ligne peut en outre être affectée à une autre catégorie (du
 *    fichier ou existante) — décision produit 2026-10-20 ;
 *  - sélection : chaque ligne peut être cochée/décochée à l'analyse ;
 *    seules les lignes cochées sont importées (lignes non conformes
 *    décochées par défaut) — décision produit 2026-10-20 ;
 *  - nombre > 1 → la ligne est un LOT (un seul équipement, quantité =
 *    nombre) : une ligne = un équipement = une référence (forcée si la
 *    colonne reference est remplie, automatique sinon) ;
 *  - stock d'un lot (décision produit 2026-10-20) : « nombre » = stock
 *    max (nombre initial), « nombre_actuel » = stock actuel, compris
 *    entre 1 et le stock max ; si un seul des deux est fourni, l'autre
 *    prend la même valeur ; aucun → 1. Les deux valeurs sont
 *    modifiables ligne à ligne avant l'import ;
 *  - date_mise_en_service des équipements importés = date de facture ;
 *  - fournisseur vide → « Divers » ; plusieurs fournisseurs dans un
 *    groupe par date → le premier fait foi (noté au rapport) ;
 *  - une référence de facture déjà connue → groupe ignoré (jamais de
 *    fusion).
 *
 * Aucune transaction globale : chaque groupe est importé
 * indépendamment ; une erreur sur un groupe n'annule pas les autres.
 */
class AcquisitionImportService
{
    /**
     * Champs cibles de la correspondance colonnes → champs.
     * Ordre = ordre d'affichage dans l'écran d'analyse.
     */
    public const CHAMPS = [
        'ignorer' => '(ignorer cette colonne)',
        'designation' => 'Désignation *',
        'categorie' => 'Catégorie (code ou libellé) *',
        'nombre' => 'Nombre / stock max (> 1 → lot)',
        'nombre_actuel' => 'Stock actuel (≤ stock max)',
        'reference' => 'Référence équipement (vide → auto)',
        'date_achat' => "Date d'achat",
        'date_peremption' => 'Date de péremption',
        'facture_reference' => 'Référence facture',
        'fournisseur' => 'Fournisseur',
    ];

    /** Champs obligatoires dans la correspondance. */
    public const CHAMPS_REQUIS = ['designation', 'categorie'];

    /** Aliases d'en-têtes (normalisés : minuscules, sans accents ni ponctuation). */
    private const HEADER_ALIASES = [
        'reference' => ['reference', 'ref'],
        'designation' => ['designation', 'libelle', 'nom', 'epi'],
        'categorie' => ['categorie', 'code', 'codecategorie'],
        'nombre' => ['nombre', 'quantite', 'qte', 'stocktheorique', 'stock', 'stockmax', 'nombreinitial'],
        'nombre_actuel' => ['nombreactuel', 'stockreel', 'stockactuel', 'quantiteactuelle', 'quantitereelle'],
        'facture_reference' => ['facturereference', 'nfacture', 'numerofacture', 'facture'],
        'date_achat' => ['dateachat', 'facturedate', 'datefacture', 'date', 'achat'],
        'date_peremption' => ['dateperemption', 'peremption', 'perime', 'dateperime', 'haperime', 'datefinutilisation', 'finutilisation'],
        'fournisseur' => ['fournisseur'],
    ];

    /** Message : EPI à durée de vie limitée sans date d'achat. */
    public const MESSAGE_DATE_ACHAT_LIMITEE =
        "Date d'achat obligatoire pour un EPI à durée de vie limitée.";

    /** Durée de vie par défaut (années) d'un EPI à durée limitée. */
    public const DUREE_VIE_LIMITEE_ANS = 10;

    /** Fournisseur de repli quand la colonne est absente/vide. */
    private const FOURNISSEUR_DEFAUT = 'Divers';

    /**
     * Catégories créées pendant l'import en cours (valeur du fichier
     * => id) : une catégorie à créer n'est créée qu'une fois, et
     * seulement si au moins une ligne sélectionnée l'utilise.
     *
     * @var array<string, int>
     */
    private array $categoriesCreees = [];

    /** @var string[] Notes de rapport liées aux catégories créées */
    private array $notesCategories = [];

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

    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }

    // ==================================================================
    // Étape 1 — Analyse
    // ==================================================================

    /**
     * Analyse le fichier : colonnes détectées, correspondance proposée,
     * aperçu, lignes non conformes (seront ignorées à l'import).
     *
     * @return array{
     *   erreur_globale: ?string,
     *   colonnes: array<int, array{index:int, entete:string, champ:string, exemples:string[]}>,
     *   apercu: array<int, array<int, string>>,
     *   ligne_entete: int,
     *   nb_lignes: int,
     *   categories_fichier: string[],
     *   lignes_non_conformes: array<int, array{ligne:int, raison:string, donnees:string[]}>,
     *   lignes: array<int, array{ligne:int, donnees:string[], categorie:string, conforme:bool, raison:?string}>
     * }
     */
    public function analyser(string $cheminFichier): array
    {
        $analyse = [
            'erreur_globale' => null,
            'colonnes' => [],
            'apercu' => [],
            'ligne_entete' => 1,
            'nb_lignes' => 0,
            'categories_fichier' => [],
            'lignes_non_conformes' => [],
            // Toutes les lignes de données, pour la sélection ligne à
            // ligne avant import (décision produit 2026-10-20)
            'lignes' => [],
        ];

        $lignes = $this->lireFichier($cheminFichier);
        if ($lignes === null) {
            $analyse['erreur_globale'] = "Le fichier n'a pas pu être lu (format Excel ou CSV attendu).";
            return $analyse;
        }
        if ($lignes === []) {
            $analyse['erreur_globale'] = 'Le fichier est vide.';
            return $analyse;
        }

        // La ligne d'en-tête n'est pas forcément la ligne 1 (fichiers
        // réels : titre fusionné, lignes vides…) — détection auto.
        $idxEntete = $this->detecterLigneEntete($lignes);
        $analyse['ligne_entete'] = $idxEntete + 1; // 1-based pour l'affichage
        $entetes = $lignes[$idxEntete];
        $lignes = array_slice($lignes, $idxEntete + 1);

        // Correspondance proposée (index colonne → champ)
        $mapping = [];
        foreach ($entetes as $index => $entete) {
            if (!is_scalar($entete)) {
                continue;
            }
            $champ = $this->devinerChamp((string) $entete);
            $mapping[$index] = $champ;
            $analyse['colonnes'][] = [
                'index' => $index,
                'entete' => trim((string) $entete) !== '' ? (string) $entete : '(colonne ' . ($index + 1) . ')',
                'champ' => $champ,
                'exemples' => [],
            ];
        }

        if ($analyse['colonnes'] === []) {
            $analyse['erreur_globale'] = "Le fichier est vide ou sa ligne d'en-tête est inexploitable.";
            return $analyse;
        }

        // Exemples de valeurs (jusqu'à 3 par colonne) + aperçu (5 lignes)
        $nbExemples = array_fill_keys(array_keys($mapping), 0);
        $nbLignes = 0;
        $numero = $idxEntete + 1; // numéros 1-based, en-tête incluse
        foreach ($lignes as $ligneBrute) {
            $numero++;
            if ($this->ligneVide($ligneBrute)) {
                continue;
            }
            $nbLignes++;

            if (count($analyse['apercu']) < 5) {
                $analyse['apercu'][] = array_map(
                    static fn($v) => is_scalar($v) ? (string) $v : '',
                    $ligneBrute
                );
            }
            foreach ($analyse['colonnes'] as &$col) {
                if ($nbExemples[$col['index']] < 3) {
                    $v = $ligneBrute[$col['index']] ?? null;
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        $col['exemples'][] = (string) $v;
                        $nbExemples[$col['index']]++;
                    }
                }
            }
            unset($col);

            // Contrôle de conformité selon la correspondance proposée
            $donnees = $this->extraireLigne($ligneBrute, $mapping);
            if ($donnees !== null) {
                // Catégories distinctes du fichier (pour le choix de
                // rattachement à l'étape d'analyse — décision 2026-10-19)
                if ($donnees['categorie'] !== ''
                    && !in_array($donnees['categorie'], $analyse['categories_fichier'], true)) {
                    $analyse['categories_fichier'][] = $donnees['categorie'];
                }
                $erreur = $this->validerLigne($donnees);
                $cellules = array_map(
                    static fn($v) => is_scalar($v) ? trim((string) $v) : '',
                    $ligneBrute
                );
                if ($erreur !== null) {
                    // La ligne est présentée COMPLÈTE pour vérification
                    // avant import (décision produit 2026-10-19).
                    $analyse['lignes_non_conformes'][] = [
                        'ligne' => $numero,
                        'raison' => $erreur,
                        'donnees' => $cellules,
                    ];
                }
                // Ligne sélectionnable pour l'import (cochée par défaut
                // si conforme — décision produit 2026-10-20)
                [$nombre, $nombreActuel] = $this->nombresProposes($donnees);
                $analyse['lignes'][] = [
                    'ligne' => $numero,
                    'donnees' => $cellules,
                    'categorie' => $donnees['categorie'],
                    'conforme' => $erreur === null,
                    'raison' => $erreur,
                    // Stock max / actuel proposés, modifiables (2026-10-20)
                    'nombre' => $nombre,
                    'nombre_actuel' => $nombreActuel,
                    // Dates proposées, modifiables (2026-10-20) ; fin de
                    // vie par défaut calculée selon la catégorie
                    // (marquerDureeDeVieLimitee)
                    'date_achat' => is_string($donnees['date_achat']) ? $donnees['date_achat'] : '',
                    'date_peremption' => is_string($donnees['date_peremption']) ? $donnees['date_peremption'] : '',
                    'limitee' => false,
                    'date_peremption_auto' => false,
                ];
            }
        }
        $analyse['nb_lignes'] = $nbLignes;

        return $analyse;
    }

    // ==================================================================
    // Étape 2 — Import
    // ==================================================================

    /**
     * Importe le fichier avec la correspondance validée.
     *
     * @param array<int, string> $mapping index de colonne => champ (CHAMPS)
     * @param array<string, string|array{cible?:string, libelle?:string, code?:string}> $choixCategories
     *        valeur du fichier => choix de rattachement (décisions
     *        2026-10-19/20) : cible 'nouvelle' (création, avec libellé
     *        et code éventuels), id de catégorie existante, ou
     *        'v:<autre valeur>' (même catégorie que cette autre valeur).
     *        Une simple chaîne vaut cible.
     * @param int[]|null $lignesSelectionnees numéros (1-based) des
     *        lignes à importer ; null = toutes (décision 2026-10-20)
     * @param array<int, string> $categoriesParLigne numéro de ligne =>
     *        catégorie imposée : id existant ou 'v:<valeur du fichier>'
     * @param array<int, array{nombre?:string, nombre_actuel?:string}> $nombresParLigne
     *        numéro de ligne => stock max / stock actuel saisis à
     *        l'analyse (remplacent les valeurs du fichier — 2026-10-20)
     * @param array<int, array{date_achat?:string, date_peremption?:string}> $datesParLigne
     *        numéro de ligne => dates saisies à l'analyse (2026-10-20)
     * @return array{
     *   erreur_globale: ?string,
     *   lignes_lues: int,
     *   lignes_exclues: int,
     *   lignes_en_erreur: array<int, array{ligne:int, message:string}>,
     *   acquisitions: array<int, array{id:int, facture_reference:string, nb_lignes:int, equipements_generes:int}>,
     *   groupes_ignores: array<int, array{facture_reference:string, raison:string}>,
     *   notes: string[]
     * }
     */
    public function import(
        string $cheminFichier,
        int $userId,
        array $mapping,
        array $choixCategories = [],
        ?array $lignesSelectionnees = null,
        array $categoriesParLigne = [],
        array $nombresParLigne = [],
        array $datesParLigne = []
    ): array {
        $this->categoriesCreees = [];
        $this->notesCategories = [];
        $choixCategories = $this->normaliserChoixCategories($choixCategories);

        $rapport = [
            'erreur_globale' => null,
            'lignes_lues' => 0,
            'lignes_exclues' => 0,
            'lignes_en_erreur' => [],
            'acquisitions' => [],
            'groupes_ignores' => [],
            'notes' => [],
        ];

        // [SÉCURITÉ] La correspondance doit couvrir les champs requis,
        // sans doublon (hors 'ignorer').
        $champs = array_filter($mapping, static fn($c) => $c !== 'ignorer');
        foreach (self::CHAMPS_REQUIS as $requis) {
            if (!in_array($requis, $champs, true)) {
                $rapport['erreur_globale'] = sprintf(
                    'Correspondance incomplète : le champ « %s » doit être associé à une colonne.',
                    self::CHAMPS[$requis]
                );
                return $rapport;
            }
        }
        if (count($champs) !== count(array_unique($champs))) {
            $rapport['erreur_globale'] = 'Correspondance invalide : un même champ est associé à plusieurs colonnes.';
            return $rapport;
        }
        if ($lignesSelectionnees !== null) {
            if ($lignesSelectionnees === []) {
                $rapport['erreur_globale'] = "Aucune ligne n'a été sélectionnée pour l'import.";
                return $rapport;
            }
            $lignesSelectionnees = array_fill_keys(array_map('intval', $lignesSelectionnees), true);
        }

        $lignes = $this->lireFichier($cheminFichier);
        if ($lignes === null) {
            $rapport['erreur_globale'] = "Le fichier n'a pas pu être lu (format Excel ou CSV attendu).";
            return $rapport;
        }
        if ($lignes === []) {
            $rapport['erreur_globale'] = 'Le fichier est vide.';
            return $rapport;
        }
        $idxEntete = $this->detecterLigneEntete($lignes);
        $lignes = array_slice($lignes, $idxEntete + 1); // après l'en-tête

        // --- Validation ligne à ligne + groupement ---
        $groupes = [];
        $numero = $idxEntete + 1;
        foreach ($lignes as $ligneBrute) {
            $numero++;
            $donnees = $this->extraireLigne($ligneBrute, $mapping);
            if ($donnees === null) {
                continue; // ligne vide
            }
            // Ligne décochée à l'analyse → non importée (2026-10-20)
            if ($lignesSelectionnees !== null && !isset($lignesSelectionnees[$numero])) {
                $rapport['lignes_exclues']++;
                continue;
            }
            $rapport['lignes_lues']++;

            // Stock max / actuel saisis à l'analyse (2026-10-20)
            foreach (['nombre', 'nombre_actuel'] as $champ) {
                if (isset($nombresParLigne[$numero][$champ])) {
                    $donnees[$champ] = trim((string) $nombresParLigne[$numero][$champ]);
                }
            }

            // Dates saisies à l'analyse (2026-10-20)
            if (isset($datesParLigne[$numero]['date_achat'])) {
                $donnees['date_achat'] = $this->normaliserDateImport($datesParLigne[$numero]['date_achat'], '01-01', null);
            }
            if (isset($datesParLigne[$numero]['date_peremption'])) {
                $donnees['date_peremption'] = $this->normaliserDateImport($datesParLigne[$numero]['date_peremption'], '12-31', null);
            }

            $erreur = $this->validerLigne($donnees);
            if ($erreur !== null) {
                $rapport['lignes_en_erreur'][] = ['ligne' => $numero, 'message' => $erreur];
                continue;
            }

            // Catégorie imposée pour cette ligne (2026-10-20)
            $categorieImposee = isset($categoriesParLigne[$numero])
                ? (string) $categoriesParLigne[$numero]
                : null;

            // [MÉTIER] Durée de vie limitée : date d'achat obligatoire
            // (décision produit 2026-10-20)
            $limitee = $this->categorieLimitee($donnees['categorie'], $choixCategories, $categorieImposee);
            if ($limitee && $donnees['date_achat'] === null) {
                $rapport['lignes_en_erreur'][] = ['ligne' => $numero, 'message' => self::MESSAGE_DATE_ACHAT_LIMITEE];
                continue;
            }

            $donnees = $this->finaliserLigne($donnees, $limitee);
            $donnees['categorie_imposee'] = $categorieImposee;

            // Groupement : par facture si mentionnée, sinon par date d'achat
            if ($donnees['facture_reference'] !== '') {
                $cle = 'F:' . $donnees['facture_reference'];
            } else {
                $cle = 'D:' . $donnees['date_achat'];
            }

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = [
                    'facture_reference' => $donnees['facture_reference'] !== ''
                        ? $donnees['facture_reference']
                        : 'IMP-' . $donnees['date_achat'],
                    'facture_date' => $donnees['date_achat'],
                    'fournisseur' => $donnees['fournisseur'] !== '' ? $donnees['fournisseur'] : self::FOURNISSEUR_DEFAUT,
                    'fournisseurs_vus' => [],
                    'par_date' => $donnees['facture_reference'] === '',
                    'lignes' => [],
                ];
            }

            // Cohérence intra-groupe
            if (!$groupes[$cle]['par_date']) {
                if ($groupes[$cle]['facture_date'] !== $donnees['date_achat']
                    || ($donnees['fournisseur'] !== '' && $groupes[$cle]['fournisseur'] !== $donnees['fournisseur'])) {
                    $rapport['lignes_en_erreur'][] = [
                        'ligne' => $numero,
                        'message' => "Date d'achat ou fournisseur incohérent avec les autres lignes de la facture « {$donnees['facture_reference']} ».",
                    ];
                    continue;
                }
            } elseif ($donnees['fournisseur'] !== '') {
                $groupes[$cle]['fournisseurs_vus'][$donnees['fournisseur']] = true;
            }

            $groupes[$cle]['lignes'][] = $donnees;
        }

        // Note de rapport : groupe par date à fournisseurs multiples
        foreach ($groupes as $groupe) {
            if ($groupe['par_date'] && count($groupe['fournisseurs_vus']) > 1) {
                $rapport['notes'][] = sprintf(
                    'Groupe « %s » : plusieurs fournisseurs détectés, « %s » retenu.',
                    $groupe['facture_reference'],
                    $groupe['fournisseur']
                );
            }
        }

        // --- Création des acquisitions (une par groupe) ---
        foreach ($groupes as $groupe) {
            $this->importerGroupe($groupe, $userId, $rapport, $choixCategories);
        }

        foreach ($this->notesCategories as $note) {
            $rapport['notes'][] = $note;
        }

        return $rapport;
    }

    // ==================================================================
    // Groupe (une facture ou une date d'achat = une acquisition validée)
    // ==================================================================

    private function importerGroupe(array $groupe, int $userId, array &$rapport, array $choixCategories = []): void
    {
        $factureReference = $groupe['facture_reference'];

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

            $n = 0;
            foreach ($groupe['lignes'] as $ligne) {
                $n++;

                // Référence produit synthétique (colonne UNIQUE de
                // acquisition_ligne) : compacte pour rester < 32 chars.
                $referenceLigne = mb_substr('IMP-' . $factureReference, 0, 27) . '-' . $n;

                $this->acquisitionLigneManager()->save([
                    'acquisition_id' => $acquisitionId,
                    'reference' => $referenceLigne,
                    'designation' => $ligne['designation'],
                    'categorie_id' => $this->resoudreCategorieId(
                        $ligne['categorie'],
                        $choixCategories,
                        $ligne['categorie_imposee'] ?? null
                    ),
                    'nombre' => $ligne['nombre'],
                    // Stock actuel du lot (≤ nombre) — 2026-10-20
                    'nombre_actuel' => $ligne['lot'] ? $ligne['nombre_actuel'] : null,
                    'regrouper_en_lot' => $ligne['lot'],
                    'reference_forcee' => $ligne['reference'] !== '' ? $ligne['reference'] : null,
                    'date_peremption' => $ligne['date_peremption'],
                ]);
            }

            // Validation directe (court-circuite AcquisitionValidator
            // qui exigerait un vrai document de facture) : équipements
            // générés, références automatiques ou forcées.
            $process->validerAcquisition($acquisitionId);

            // [MÉTIER] Date de mise en service = date de facture
            // (décision produit 2026-10-19).
            $this->equipementManager()->marquerEnServiceParAcquisition(
                $acquisitionId,
                $groupe['facture_date']
            );

            $rapport['acquisitions'][] = [
                'id' => $acquisitionId,
                'facture_reference' => $factureReference,
                'nb_lignes' => count($groupe['lignes']),
                // Une ligne = un équipement (lot si nombre > 1)
                'equipements_generes' => count($groupe['lignes']),
            ];
        } catch (\Throwable $e) {
            error_log('[AcquisitionImportService] Groupe « ' . $factureReference . ' »: ' . $e->getMessage());
            $rapport['groupes_ignores'][] = [
                'facture_reference' => $factureReference,
                'raison' => "Erreur lors de l'import du groupe (détail en journal serveur).",
            ];
        }
    }

    /**
     * Résout la catégorie d'une ligne (décisions produit 2026-10-19/20) :
     *  0. catégorie imposée pour la ligne (id existant, ou autre valeur
     *     du fichier dont on reprend alors le choix) ;
     *  1. regroupements « même catégorie que <autre valeur> » suivis ;
     *  2. choix explicite : id existant, ou création (libellé et code
     *     choisis) ;
     *  3. sans choix : par CODE (ex. COR), sinon par libellé, sinon
     *     création avec les valeurs par défaut.
     *
     * @param array<string, array{cible:?string, libelle:string, code:string}> $choixCategories
     */
    private function resoudreCategorieId(string $valeur, array $choixCategories = [], ?string $imposee = null): int
    {
        $cible = $this->resoudreCible($valeur, $choixCategories, $imposee);
        if ($cible['categorie'] !== null) {
            return (int) $cible['categorie']['id'];
        }
        if (isset($this->categoriesCreees[$cible['valeur']])) {
            return $this->categoriesCreees[$cible['valeur']];
        }
        $choix = $cible['choix'];
        return $this->creerCategorie($cible['valeur'], $choix['libelle'], $choix['code'], $choix['duree_max']);
    }

    /**
     * La catégorie d'une ligne est-elle à durée de vie limitée ?
     * Même résolution que resoudreCategorieId(), sans rien créer :
     * catégorie existante → son indicateur ; catégorie à créer → case
     * cochée à l'analyse (décision produit 2026-10-20).
     */
    private function categorieLimitee(string $valeur, array $choixCategories = [], ?string $imposee = null): bool
    {
        $cible = $this->resoudreCible($valeur, $choixCategories, $imposee);
        if ($cible['categorie'] !== null) {
            return (int) ($cible['categorie']['duree_max_utilisation'] ?? 0) === 1;
        }
        return $cible['choix']['duree_max'];
    }

    /**
     * Résolution (sans création) de la catégorie d'une ligne :
     *  0. catégorie imposée pour la ligne (id existant, ou autre valeur
     *     du fichier dont on reprend alors le choix) ;
     *  1. regroupements « même catégorie que <autre valeur> » suivis ;
     *  2. choix explicite : id existant, ou création ;
     *  3. sans choix : par CODE (ex. COR), sinon par libellé, sinon
     *     création avec les valeurs par défaut.
     *
     * @return array{categorie:?array, valeur:string, choix:array{cible:?string, libelle:string, code:string, duree_max:bool}}
     *         categorie = catégorie existante, sinon null (à créer
     *         pour « valeur » avec « choix »)
     */
    private function resoudreCible(string $valeur, array $choixCategories, ?string $imposee): array
    {
        $manager = $this->categorieManager();
        $valeur = trim($valeur);
        $defaut = ['cible' => null, 'libelle' => '', 'code' => '', 'duree_max' => false];

        // 0. Catégorie imposée pour cette ligne
        if ($imposee !== null && $imposee !== '') {
            if (ctype_digit($imposee)) {
                $categorie = $manager->findOneByCriteria(['id' => (int) $imposee]);
                if ($categorie) {
                    return ['categorie' => $categorie, 'valeur' => $valeur, 'choix' => $defaut];
                }
                // Id inconnu (formulaire forgé) → résolution normale
            } elseif (str_starts_with($imposee, 'v:') && trim(substr($imposee, 2)) !== '') {
                $valeur = trim(substr($imposee, 2));
            }
        }

        // 1. Regroupements (anti-boucle)
        $vues = [];
        while (true) {
            $cible = $choixCategories[$valeur]['cible'] ?? null;
            if ($cible === null || !str_starts_with($cible, 'v:') || isset($vues[$valeur])) {
                break;
            }
            $vues[$valeur] = true;
            $valeur = trim(substr($cible, 2));
        }
        $choix = ($choixCategories[$valeur] ?? []) + $defaut;
        $cible = $choix['cible'];

        // Déjà créée pendant cet import
        if (isset($this->categoriesCreees[$valeur])) {
            $categorie = $manager->findOneByCriteria(['id' => $this->categoriesCreees[$valeur]]);
            if ($categorie) {
                return ['categorie' => $categorie, 'valeur' => $valeur, 'choix' => $choix];
            }
        }

        // 2a. Rattachement explicite à une catégorie existante
        if ($cible !== null && ctype_digit($cible)) {
            $categorie = $manager->findOneByCriteria(['id' => (int) $cible]);
            if ($categorie) {
                return ['categorie' => $categorie, 'valeur' => $valeur, 'choix' => $choix];
            }
            // Id inconnu (formulaire forgé) → résolution normale
            $cible = null;
        }

        // 3. Sans choix explicite : par code, puis par libellé
        if ($cible !== 'nouvelle') {
            $categorie = $this->trouverCategorie($valeur);
            if ($categorie) {
                return ['categorie' => $categorie, 'valeur' => $valeur, 'choix' => $choix];
            }
        }

        // 2b / 3. À créer
        return ['categorie' => null, 'valeur' => $valeur, 'choix' => $choix];
    }

    /**
     * Crée la catégorie d'une valeur du fichier, avec le libellé et le
     * code choisis à l'analyse (décision produit 2026-10-20).
     *  - libellé vide → valeur du fichier (tronquée à 32) ; libellé
     *    déjà existant → cette catégorie est réutilisée (noté) ;
     *  - code vide → attribué à la volée par ReferenceGenerator ; code
     *    invalide ou déjà pris → idem (noté au rapport).
     */
    private function creerCategorie(string $valeur, string $libelle, string $code, bool $dureeMax = false): int
    {
        $manager = $this->categorieManager();

        $libelle = mb_substr(trim($libelle) !== '' ? trim($libelle) : $valeur, 0, 32);

        $existante = $manager->findOneByCriteria(['libelle' => $libelle]);
        if ($existante) {
            $id = (int) $existante['id'];
            if (!in_array($id, $this->categoriesCreees, true)) {
                $this->notesCategories[] = sprintf(
                    'Catégorie « %s » : une catégorie de ce nom existe déjà, elle a été utilisée.',
                    $libelle
                );
            }
            $this->categoriesCreees[$valeur] = $id;
            return $id;
        }

        $donnees = [
            'libelle' => $libelle,
            'est_epi' => 1,
            // Durée de vie limitée cochée à l'analyse (2026-10-20)
            'duree_max_utilisation' => $dureeMax ? 1 : 0,
        ];

        $code = $this->referenceGenerator()->normalize(trim($code));
        if ($code !== '') {
            if (preg_match('/^[A-Z0-9]{2,8}$/', $code) !== 1) {
                $this->notesCategories[] = sprintf(
                    'Catégorie « %s » : code « %s » invalide (2 à 8 lettres ou chiffres), code attribué automatiquement.',
                    $libelle,
                    $code
                );
            } elseif ($manager->findOneByCriteria(['code' => $code])) {
                $this->notesCategories[] = sprintf(
                    'Catégorie « %s » : le code « %s » est déjà utilisé, code attribué automatiquement.',
                    $libelle,
                    $code
                );
            } else {
                $donnees['code'] = $code;
            }
        }

        $id = (int) $manager->save($donnees);
        $this->categoriesCreees[$valeur] = $id;
        return $id;
    }

    /**
     * Normalise les choix de catégories : forme tableau
     * {cible, libelle, code} ; une simple chaîne vaut cible
     * (compatibilité 2026-10-19).
     *
     * @return array<string, array{cible:?string, libelle:string, code:string, duree_max:bool}>
     */
    private function normaliserChoixCategories(array $choix): array
    {
        $out = [];
        foreach ($choix as $valeur => $c) {
            $valeur = trim((string) $valeur);
            if ($valeur === '') {
                continue;
            }
            if (!is_array($c)) {
                $c = ['cible' => $c];
            }
            $cible = isset($c['cible']) && is_scalar($c['cible']) ? trim((string) $c['cible']) : '';
            $out[$valeur] = [
                'cible' => $cible !== '' ? $cible : null,
                'libelle' => isset($c['libelle']) && is_scalar($c['libelle']) ? trim((string) $c['libelle']) : '',
                'code' => isset($c['code']) && is_scalar($c['code']) ? trim((string) $c['code']) : '',
                'duree_max' => !empty($c['duree_max']),
            ];
        }
        return $out;
    }

    /**
     * Recherche une catégorie existante : par CODE (ex. COR), sinon
     * par libellé. Ne crée rien.
     */
    private function trouverCategorie(string $valeur): ?array
    {
        $manager = $this->categorieManager();
        $valeur = trim($valeur);

        // Par code (les codes sont en majuscules alnum)
        $code = mb_strtoupper($valeur);
        if (preg_match('/^[A-Z0-9]{2,8}$/', $code) === 1) {
            $categorie = $manager->findOneByCriteria(['code' => $code]);
            if ($categorie) {
                return $categorie;
            }
        }

        // Par libellé
        $categorie = $manager->findOneByCriteria(['libelle' => $valeur]);
        return $categorie ?: null;
    }

    /**
     * Enrichit les catégories détectées dans le fichier pour l'écran
     * d'analyse : résolution éventuelle (code/libellé) + liste des
     * catégories existantes pour le choix de rattachement
     * (décision produit 2026-10-19).
     *
     * @param string[] $valeurs  Catégories distinctes du fichier
     * Pour une catégorie à créer, un libellé et un code sont proposés
     * (modifiables — décision produit 2026-10-20) : codes suggérés
     * sans collision avec l'existant ni entre eux.
     *
     * @return array{
     *   categories: array<int, array{valeur:string, existante:?array, libelle_propose:string, code_propose:string}>,
     *   existantes: array<int, array>
     * }
     */
    public function enrichirCategories(array $valeurs): array
    {
        $existantes = $this->categorieManager()->findAll('libelle');
        $codesPris = array_values(array_filter(
            array_column($existantes, 'code'),
            static fn($code) => is_string($code) && $code !== ''
        ));
        $generateur = $this->referenceGenerator();

        $categories = [];
        foreach ($valeurs as $valeur) {
            $existante = $this->trouverCategorie($valeur);
            $libelle = mb_substr(trim((string) $valeur), 0, 32);
            $code = '';
            if (!$existante) {
                try {
                    $code = $generateur->suggestCode($libelle, $codesPris);
                    $codesPris[] = $code;
                } catch (\RuntimeException) {
                    $code = ''; // attribué à la volée à l'import
                }
            }
            $categories[] = [
                'valeur' => $valeur,
                'existante' => $existante,
                'libelle_propose' => $libelle,
                'code_propose' => $code,
            ];
        }

        return [
            'categories' => $categories,
            'existantes' => $existantes,
        ];
    }

    /**
     * Fin de vie par défaut d'un EPI à durée limitée : 31 décembre de
     * l'année d'achat + 10 ans (décision produit 2026-10-20).
     */
    public function finDeVieParDefaut(string $dateAchat): string
    {
        return ((int) substr($dateAchat, 0, 4) + self::DUREE_VIE_LIMITEE_ANS) . '-12-31';
    }

    /**
     * Complète l'analyse pour les EPI à durée de vie limitée
     * (catégorie existante du fichier — décision produit 2026-10-20) :
     * ligne marquée « limitée », fin de vie par défaut proposée, et
     * ligne sans date d'achat signalée non conforme (décochée).
     * À appeler après enrichirCategories().
     */
    public function marquerDureeDeVieLimitee(array $analyse): array
    {
        $limitees = [];
        foreach ($analyse['categories'] ?? [] as $categorie) {
            if ($categorie['existante']
                && (int) ($categorie['existante']['duree_max_utilisation'] ?? 0) === 1) {
                $limitees[$categorie['valeur']] = true;
            }
        }

        foreach ($analyse['lignes'] as &$ligne) {
            if (!isset($limitees[$ligne['categorie']])) {
                continue;
            }
            $ligne['limitee'] = true;
            if ($ligne['date_achat'] !== '' && $ligne['date_peremption'] === '') {
                $ligne['date_peremption'] = $this->finDeVieParDefaut($ligne['date_achat']);
                $ligne['date_peremption_auto'] = true;
            }
            if ($ligne['conforme'] && $ligne['date_achat'] === '') {
                $ligne['conforme'] = false;
                $ligne['raison'] = self::MESSAGE_DATE_ACHAT_LIMITEE;
                $analyse['lignes_non_conformes'][] = [
                    'ligne' => $ligne['ligne'],
                    'raison' => $ligne['raison'],
                    'donnees' => $ligne['donnees'],
                ];
            }
        }
        unset($ligne);

        usort($analyse['lignes_non_conformes'], static fn($a, $b) => $a['ligne'] <=> $b['ligne']);
        return $analyse;
    }

    // ==================================================================
    // Parsing
    // ==================================================================

    /**
     * Détecte la ligne d'en-tête parmi les 10 premières lignes :
     * première ligne dont au moins 2 cellules correspondent à un alias
     * d'en-tête connu ; à défaut, première ligne non vide (fichiers
     * sans en-têtes reconnus — la correspondance sera à faire à la
     * main dans l'écran d'analyse).
     *
     * @param array<int, array<int, mixed>> $lignes
     */
    private function detecterLigneEntete(array $lignes): int
    {
        $premiereNonVide = 0;
        $trouveNonVide = false;

        foreach (array_slice($lignes, 0, 10, true) as $index => $ligne) {
            if ($this->ligneVide($ligne)) {
                continue;
            }
            if (!$trouveNonVide) {
                $premiereNonVide = (int) $index;
                $trouveNonVide = true;
            }
            $score = 0;
            foreach ($ligne as $cellule) {
                if (is_scalar($cellule) && trim((string) $cellule) !== ''
                    && $this->devinerChamp((string) $cellule) !== 'ignorer') {
                    $score++;
                }
            }
            if ($score >= 2) {
                return (int) $index;
            }
        }

        return $premiereNonVide;
    }

    /**
     * Lit la feuille active du fichier (Excel ou CSV).
     *
     * Pour les CSV, le délimiteur est détecté sur l'ensemble des
     * premières lignes non vides : l'inférence native du lecteur se
     * trompe quand les premières lignes (titre, vide…) ne contiennent
     * pas le délimiteur.
     *
     * @return array<int, array<int, mixed>>|null null si illisible
     */
    private function lireFichier(string $cheminFichier): ?array
    {
        try {
            if (strtolower(pathinfo($cheminFichier, PATHINFO_EXTENSION)) === 'csv') {
                $reader = new Csv();
                $reader->setDelimiter($this->detecterDelimiteurCsv($cheminFichier));
                return $reader->load($cheminFichier)
                    ->getActiveSheet()
                    ->toArray(null, true, false, false);
            }
            return IOFactory::load($cheminFichier)
                ->getActiveSheet()
                ->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            error_log('[AcquisitionImportService] Lecture impossible: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Délimiteur CSV le plus fréquent parmi « ; », « , » et tabulation
     * sur les 20 premières lignes non vides (';' par défaut, Excel FR).
     */
    private function detecterDelimiteurCsv(string $cheminFichier): string
    {
        $compte = [';' => 0, ',' => 0, "\t" => 0];
        $handle = @fopen($cheminFichier, 'rb');
        if ($handle === false) {
            return ';';
        }
        $lues = 0;
        while ($lues < 20 && ($ligne = fgets($handle)) !== false) {
            if (trim($ligne) === '') {
                continue;
            }
            $lues++;
            foreach ($compte as $delimiteur => $n) {
                $compte[$delimiteur] += substr_count($ligne, $delimiteur);
            }
        }
        fclose($handle);

        $max = max($compte);
        return $max > 0 ? (string) array_search($max, $compte, true) : ';';
    }

    /**
     * Devine le champ cible d'une colonne à partir de son en-tête.
     */
    private function devinerChamp(string $entete): string
    {
        $normalise = $this->normaliserEntete($entete);
        foreach (self::HEADER_ALIASES as $champ => $alias) {
            if (in_array($normalise, $alias, true)) {
                return $champ;
            }
        }
        return 'ignorer';
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

    private function ligneVide(array $ligne): bool
    {
        foreach ($ligne as $cellule) {
            if (is_scalar($cellule) && trim((string) $cellule) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Extrait et normalise les champs d'une ligne selon la
     * correspondance. Retourne null si la ligne est entièrement vide.
     */
    private function extraireLigne(array $ligneBrute, array $mapping): ?array
    {
        $get = function (string $champ) use ($ligneBrute, $mapping) {
            foreach ($mapping as $index => $champAssocie) {
                if ($champAssocie === $champ) {
                    $v = $ligneBrute[$index] ?? null;
                    return is_scalar($v) ? trim((string) $v) : null;
                }
            }
            return null;
        };
        // Valeur brute (dates Excel = numéros de série)
        $getBrut = function (string $champ) use ($ligneBrute, $mapping) {
            foreach ($mapping as $index => $champAssocie) {
                if ($champAssocie === $champ) {
                    return $ligneBrute[$index] ?? null;
                }
            }
            return null;
        };

        if ($this->ligneVide($ligneBrute)) {
            return null;
        }

        return [
            'reference' => (string) ($get('reference') ?? ''),
            'designation' => (string) ($get('designation') ?? ''),
            'categorie' => (string) ($get('categorie') ?? ''),
            'nombre' => $get('nombre'),
            'nombre_actuel' => $get('nombre_actuel'),
            'facture_reference' => (string) ($get('facture_reference') ?? ''),
            'fournisseur' => (string) ($get('fournisseur') ?? ''),
            // Année seule → 1er janvier ; vide → null (date courante
            // appliquée par finaliserLigne, sauf durée de vie limitée)
            'date_achat' => $this->normaliserDateImport($getBrut('date_achat'), '01-01', null),
            // Année seule → 31 décembre ; vide → sans objet (null)
            'date_peremption' => $this->normaliserDateImport($getBrut('date_peremption'), '12-31', null),
        ];
    }

    /**
     * Normalise une date d'import en Y-m-d.
     *
     * @return string|false|null  Y-m-d ; $defautSiVide si vide ;
     *                            false si la valeur est invalide
     */
    private function normaliserDateImport(mixed $valeur, string $moisJour, ?string $defautSiVide): string|false|null
    {
        if ($valeur === null || $valeur === '') {
            return $defautSiVide;
        }

        // Numéro de série Excel (cellule date d'un .xlsx)
        if (is_numeric($valeur) && !preg_match('/^\d{4}$/', (string) $valeur)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
            } catch (\Throwable) {
                return false;
            }
        }

        $s = trim((string) $valeur);

        // Année seule → 1er janvier ou 31 décembre selon le champ
        if (preg_match('/^(\d{4})$/', $s, $m)) {
            return $m[1] . '-' . $moisJour;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $s);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return false;
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
        if ($d['date_achat'] === false) {
            return "La date d'achat est invalide (formats acceptés : AAAA-MM-JJ, JJ/MM/AAAA, ou année seule).";
        }
        if ($d['date_peremption'] === false) {
            return 'La date de péremption est invalide (formats acceptés : AAAA-MM-JJ, JJ/MM/AAAA, ou année seule).';
        }
        if ($d['nombre'] !== null && $d['nombre'] !== ''
            && (!ctype_digit($d['nombre']) || (int) $d['nombre'] < 1)) {
            return 'Le nombre doit être un entier supérieur ou égal à 1.';
        }
        $actuel = $d['nombre_actuel'] ?? null;
        if ($actuel !== null && $actuel !== '') {
            if (!ctype_digit($actuel) || (int) $actuel < 1) {
                return 'Le stock actuel doit être un entier supérieur ou égal à 1.';
            }
            if ($d['nombre'] !== null && $d['nombre'] !== '' && (int) $actuel > (int) $d['nombre']) {
                return 'Le stock actuel ne peut pas dépasser le nombre (stock max).';
            }
        }
        if (mb_strlen($d['reference']) > 32) {
            return 'La référence dépasse 32 caractères.';
        }
        return null;
    }

    /**
     * Valeurs finales normalisées d'une ligne validée (décisions
     * produit 2026-10-19/20) : un seul des deux nombres fourni →
     * l'autre prend la même valeur ; aucun → 1 ; nombre > 1 → lot ;
     * date d'achat vide → date du jour ; durée de vie limitée sans
     * fin de vie → fin de vie par défaut.
     */
    private function finaliserLigne(array $d, bool $limitee = false): array
    {
        // [MÉTIER] Fin de vie d'un EPI à durée limitée : 31/12 de
        // l'année d'achat + 10 ans (2026-10-20). Date d'achat vide →
        // date courante (impossible si durée limitée : refusé avant).
        if ($limitee && $d['date_peremption'] === null && is_string($d['date_achat'])) {
            $d['date_peremption'] = $this->finDeVieParDefaut($d['date_achat']);
        }
        $d['date_achat'] = $d['date_achat'] ?? date('Y-m-d');

        $nombre = ($d['nombre'] === null || $d['nombre'] === '') ? null : (int) $d['nombre'];
        $actuel = (($d['nombre_actuel'] ?? null) === null || $d['nombre_actuel'] === '')
            ? null : (int) $d['nombre_actuel'];

        $d['nombre'] = $nombre ?? $actuel ?? 1;
        $d['nombre_actuel'] = $actuel ?? $d['nombre'];
        $d['lot'] = $d['nombre'] > 1 ? 1 : 0;
        return $d;
    }

    /**
     * Stock max et stock actuel proposés à l'analyse pour une ligne :
     * mêmes règles que finaliserLigne() ; une valeur invalide du
     * fichier est laissée vide (à saisir).
     *
     * @return array{0:string, 1:string}
     */
    private function nombresProposes(array $d): array
    {
        $valide = static fn($v) => is_string($v) && ctype_digit($v) && (int) $v >= 1;
        $nombre = $valide($d['nombre']) ? (string) (int) $d['nombre'] : null;
        $actuel = $valide($d['nombre_actuel'] ?? null) ? (string) (int) $d['nombre_actuel'] : null;
        $nombreVide = $d['nombre'] === null || $d['nombre'] === '';
        $actuelVide = ($d['nombre_actuel'] ?? null) === null || $d['nombre_actuel'] === '';

        if ($nombre === null && $nombreVide) {
            $nombre = $actuel ?? ($actuelVide ? '1' : null);
        }
        if ($actuel === null && $actuelVide) {
            $actuel = $nombre;
        }
        return [$nombre ?? '', $actuel ?? ''];
    }
}
