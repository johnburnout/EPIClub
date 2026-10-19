<?php

namespace Epiclub\Process;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
 *  - la ligne 1 est la ligne d'en-tête ;
 *  - groupement : par facture_reference si renseignée, sinon par date
 *    d'achat normalisée (référence synthétique IMP-AAAA-MM-JJ) — une
 *    acquisition par groupe, automatiquement validée, document
 *    « facture à importer » (un PDF groupé pourra être téléversé
 *    ultérieurement) ;
 *  - date d'achat : année seule → 1er janvier, vide → date courante ;
 *  - date de péremption : année seule → 31 décembre, vide → sans
 *    objet ; reportée sur club_equipement.date_fin_utilisation ;
 *  - catégorie : résolue par CODE (ex. COR), sinon par libellé, sinon
 *    créée ;
 *  - nombre > 1 → la ligne est un LOT (un seul équipement, quantité =
 *    nombre) : une ligne = un équipement = une référence (forcée si la
 *    colonne reference est remplie, automatique sinon) ;
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
        'nombre' => 'Nombre (> 1 → lot)',
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
        'designation' => ['designation', 'libelle', 'nom'],
        'categorie' => ['categorie', 'code', 'codecategorie'],
        'nombre' => ['nombre', 'quantite', 'qte'],
        'facture_reference' => ['facturereference', 'nfacture', 'numerofacture', 'facture'],
        'date_achat' => ['dateachat', 'facturedate', 'datefacture', 'date', 'achat'],
        'date_peremption' => ['dateperemption', 'peremption', 'datefinutilisation', 'finutilisation'],
        'fournisseur' => ['fournisseur'],
    ];

    /** Fournisseur de repli quand la colonne est absente/vide. */
    private const FOURNISSEUR_DEFAUT = 'Divers';

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
     *   nb_lignes: int,
     *   lignes_non_conformes: array<int, array{ligne:int, raison:string}>
     * }
     */
    public function analyser(string $cheminFichier): array
    {
        $analyse = [
            'erreur_globale' => null,
            'colonnes' => [],
            'apercu' => [],
            'nb_lignes' => 0,
            'lignes_non_conformes' => [],
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

        // Ligne 1 = en-têtes
        $entetes = array_shift($lignes);

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
        $numero = 1; // la ligne 1 = en-têtes
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
                $erreur = $this->validerLigne($donnees);
                if ($erreur !== null) {
                    $analyse['lignes_non_conformes'][] = ['ligne' => $numero, 'raison' => $erreur];
                }
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
     * @return array{
     *   erreur_globale: ?string,
     *   lignes_lues: int,
     *   lignes_en_erreur: array<int, array{ligne:int, message:string}>,
     *   acquisitions: array<int, array{id:int, facture_reference:string, nb_lignes:int, equipements_generes:int}>,
     *   groupes_ignores: array<int, array{facture_reference:string, raison:string}>,
     *   notes: string[]
     * }
     */
    public function import(string $cheminFichier, int $userId, array $mapping): array
    {
        $rapport = [
            'erreur_globale' => null,
            'lignes_lues' => 0,
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

        $lignes = $this->lireFichier($cheminFichier);
        if ($lignes === null) {
            $rapport['erreur_globale'] = "Le fichier n'a pas pu être lu (format Excel ou CSV attendu).";
            return $rapport;
        }
        if ($lignes === []) {
            $rapport['erreur_globale'] = 'Le fichier est vide.';
            return $rapport;
        }
        array_shift($lignes); // ligne d'en-tête

        // --- Validation ligne à ligne + groupement ---
        $groupes = [];
        $numero = 1;
        foreach ($lignes as $ligneBrute) {
            $numero++;
            $donnees = $this->extraireLigne($ligneBrute, $mapping);
            if ($donnees === null) {
                continue; // ligne vide
            }
            $rapport['lignes_lues']++;

            $erreur = $this->validerLigne($donnees);
            if ($erreur !== null) {
                $rapport['lignes_en_erreur'][] = ['ligne' => $numero, 'message' => $erreur];
                continue;
            }

            $donnees = $this->finaliserLigne($donnees);

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
            $this->importerGroupe($groupe, $userId, $rapport);
        }

        return $rapport;
    }

    // ==================================================================
    // Groupe (une facture ou une date d'achat = une acquisition validée)
    // ==================================================================

    private function importerGroupe(array $groupe, int $userId, array &$rapport): void
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
                    'categorie_id' => $this->resoudreCategorieId($ligne['categorie']),
                    'nombre' => $ligne['nombre'],
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
     * Résout la catégorie d'une ligne : par CODE (ex. COR), sinon par
     * libellé, sinon création (décision produit 2026-10-19).
     */
    private function resoudreCategorieId(string $valeur): int
    {
        $manager = $this->categorieManager();
        $valeur = trim($valeur);

        // 1. Par code (les codes sont en majuscules alnum)
        $code = mb_strtoupper($valeur);
        if (preg_match('/^[A-Z0-9]{2,8}$/', $code) === 1) {
            $categorie = $manager->findOneByCriteria(['code' => $code]);
            if ($categorie) {
                return (int) $categorie['id'];
            }
        }

        // 2. Par libellé
        $categorie = $manager->findOneByCriteria(['libelle' => $valeur]);
        if ($categorie) {
            return (int) $categorie['id'];
        }

        // 3. Création (libellé tronqué à 32, EPI par défaut — le code
        //    sera attribué à la volée par ReferenceGenerator)
        return (int) $manager->save([
            'libelle' => mb_substr($valeur, 0, 32),
            'est_epi' => 1,
        ]);
    }

    // ==================================================================
    // Parsing
    // ==================================================================

    /**
     * Lit la feuille active du fichier (Excel ou CSV).
     *
     * @return array<int, array<int, mixed>>|null null si illisible
     */
    private function lireFichier(string $cheminFichier): ?array
    {
        try {
            return IOFactory::load($cheminFichier)
                ->getActiveSheet()
                ->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            error_log('[AcquisitionImportService] Lecture impossible: ' . $e->getMessage());
            return null;
        }
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
            'facture_reference' => (string) ($get('facture_reference') ?? ''),
            'fournisseur' => (string) ($get('fournisseur') ?? ''),
            // Année seule → 1er janvier ; vide → date courante
            'date_achat' => $this->normaliserDateImport($getBrut('date_achat'), '01-01', date('Y-m-d')),
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
        if (mb_strlen($d['reference']) > 32) {
            return 'La référence dépasse 32 caractères.';
        }
        return null;
    }

    /**
     * Valeurs finales normalisées d'une ligne validée : nombre vide → 1 ;
     * nombre > 1 → lot (décision produit 2026-10-19).
     */
    private function finaliserLigne(array $d): array
    {
        $d['nombre'] = ($d['nombre'] === null || $d['nombre'] === '') ? 1 : (int) $d['nombre'];
        $d['lot'] = $d['nombre'] > 1 ? 1 : 0;
        return $d;
    }
}
