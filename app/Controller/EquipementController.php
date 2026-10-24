<?php
    
namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\DissociationLot;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Enum\EquipementStatut;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ImageUploader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Dompdf\Dompdf;
use Dompdf\Options;
use Epiclub\Engine\QrCodeGenerator;

// ─── Imports pour PhpSpreadsheet ───
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class EquipementController extends AbstractController
{
    private const UPLOAD_DIR = '/images/equipements/';
    
    // ==================================================================
    // [REFACTOR VAGUE 11] Factories managers — mockables en test Unit.
    //
    // Chaque méthode retourne une nouvelle instance concrète.
    // Les sous-classes de test (TestableEquipementController) surchargent
    // ces factories pour injecter des mocks et éviter l'ouverture PDO
    // déclenchée par AbstractManager::__construct().
    // ==================================================================
    
    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }
    
    /** [Prêt de matériel] Factories — surchargeables en test Unit. */
    protected function pretManager(): \Epiclub\Domain\PretManager
    {
        return new \Epiclub\Domain\PretManager();
    }

    protected function pretLigneManager(): \Epiclub\Domain\PretLigneManager
    {
        return new \Epiclub\Domain\PretLigneManager();
    }

    /**
     * [Prêt de matériel] Prêts en saisie proposés pour « Ajouter au
     * prêt » (contrôleur, module activé) ; [] sinon ou en cas d'erreur.
     *
     * @return list<array<string, mixed>>
     */
    private function pretsEnSaisiePourVue(): array
    {
        if (!$this->isGranted('ROLE_CONTROLLEUR') || !$this->pretActif()) {
            return [];
        }
        try {
            return $this->pretManager()->findEnSaisie();
        } catch (\Throwable $e) {
            error_log('[EquipementController] Prêts en saisie indisponibles : ' . $e->getMessage());
            return [];
        }
    }

    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }
    
    protected function emplacementManager(): EmplacementManager
    {
        return new EmplacementManager();
    }
    
    protected function acquisitionManager(): AcquisitionManager
    {
        return new AcquisitionManager();
    }

    protected function referenceGenerator(): ReferenceGenerator
    {
        return new ReferenceGenerator();
    }

    protected function dissociationLot(): DissociationLot
    {
        return new DissociationLot($this->equipementManager());
    }
    
    // --------------------------------------------------------------
    // LISTE (avec pagination et filtres)
    // --------------------------------------------------------------
    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        // --- Gestion du menu déroulant d'export ---
        if ($request->query->get('action') === 'export') {
            return match ($request->query->get('export_type', 'pdf')) {
                'excel' => $this->listExcel($request),
                'etiquettes' => $this->etiquettesPdf($request),
                'donnees_excel' => $this->listDataExcel($request),
                'donnees_csv' => $this->listDataCsv($request),
                default => $this->listPdf($request),
            };
        }

        // Rétrocompatibilité avec les anciens boutons directs (optionnel)
        if ($request->query->get('action') === 'excel') {
            return $this->listExcel($request);
        }
        if ($request->query->get('action') === 'pdf') {
            return $this->listPdf($request);
        }

        // Récupération des paramètres de pagination
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 10);
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;

        // Récupération de la liste filtrée et triée
        $equipements = $this->getFilteredEquipments($request);
        $total = count($equipements);

        // Pagination
        $offset = ($page - 1) * $limit;
        $equipements = array_slice($equipements, $offset, $limit);
        $totalPages = $limit > 0 ? ceil($total / $limit) : 1;

        // Paramètres de filtre / tri, communs aux URLs de pagination et au formulaire
        $params = [
            'categorie' => $request->query->get('categorie'),
            'epi' => $request->query->get('epi'),
            'en_service' => $request->query->get('en_service'),
            'emplacement' => $request->query->get('emplacement'),
            'dernier_controle' => $request->query->get('dernier_controle'),
            'order_by' => $request->query->get('order_by', 'categorie'),
            'order_dir' => $request->query->get('order_dir', 'asc'),
            'limit' => $limit,
            'acquisition' => $request->query->get('acquisition'),
            'fin_avant' => self::dateFinAvant($request),
            'q' => self::recherche($request),
        ];
        // Nettoyer les valeurs vides pour l'URL
        $baseParams = array_filter($params, function($v) {
            return $v !== null && $v !== '';
        });

        $paginationUrls = [
            'first' => '?' . http_build_query(array_merge($baseParams, ['page' => 1])),
            'previous' => '?' . http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)])),
            'next' => '?' . http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])),
            'last' => '?' . http_build_query(array_merge($baseParams, ['page' => $totalPages])),
        ];

        // Filtres pour l'affichage (le formulaire attend categorie_id / emplacement_id)
        $filtres = [
            'categorie_id' => $params['categorie'],
            'emplacement_id' => $params['emplacement'],
            'page' => $page,
        ] + $params;
        unset($filtres['categorie'], $filtres['emplacement']);

        return $this->render('equipement_list.twig', [
            // [Photo groupée] retour sur la même vue après l'affectation
            'retour_query' => (string) $request->getQueryString(),
            // [Prêt de matériel] « Ajouter au prêt » depuis la liste
            'prets_en_saisie' => $this->pretsEnSaisiePourVue(),
            // [migration 017] bouton « Supprimer » des équipements égarés
            'date_limite_egare' => $this->isGranted('ROLE_ADMIN') ? $this->dateLimiteEgare() : null,
            'equipements' => $equipements,
            'categories' => $this->categorieManager()->findAll(),
            'emplacements' => $this->emplacementManager()->findAll(),
            'filtres' => $filtres,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'hasPrevious' => $page > 1,
                'hasNext' => $page < $totalPages,
            ],
            'paginationUrls' => $paginationUrls,
            'acquisition_id' => $params['acquisition'],
        ]);
    }
    
    /**
     * [MÉTIER] La date de mise en service est verrouillée dès qu'elle a
     * été modifiée une fois, c'est-à-dire lorsqu'elle est renseignée et
     * différente de la date de facture (valeur posée à la validation de
     * l'acquisition). Sans date de facture connue, une date renseignée
     * est verrouillée (comportement historique).
     */
    public static function dateMiseEnServiceVerrouillee(array $equipement, ?string $dateFacture): bool
    {
        $dms = $equipement['date_mise_en_service'] ?? null;
        if ($dms === null || $dms === '') {
            return false;
        }
        return $dateFacture === null || substr((string) $dms, 0, 10) !== $dateFacture;
    }

    /** Texte recherché (« q »), nettoyé ; null si vide. */
    private static function recherche(Request $request): ?string
    {
        $valeur = $request->query->get('q');
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim(mb_substr($valeur, 0, 100));
        return $valeur === '' ? null : $valeur;
    }

    /**
     * [Tableau de bord] Paramètre « fin_avant » (Y-m-d) validé ; null si
     * absent ou mal formé (le filtre est alors ignoré).
     */
    private static function dateFinAvant(Request $request): ?string
    {
        $valeur = $request->query->get('fin_avant');
        if (!is_string($valeur) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return ($date !== false && $date->format('Y-m-d') === $valeur) ? $valeur : null;
    }

    // --------------------------------------------------------------
    // MÉTHODE PRIVÉE DE FILTRAGE / TRI
    // --------------------------------------------------------------
    /**
    * Retourne la liste des équipements filtrée et triée selon les paramètres de la requête
    * (sans pagination, sans les catégories/emplacements pour le formulaire)
    */
    private function getFilteredEquipments(Request $request): array
    {
        $equipementManager = $this->equipementManager();
        $categorieManager = $this->categorieManager();
        $emplacementManager = $this->emplacementManager();
        
        // Récupération des filtres
        $categorie_id = $request->query->get('categorie');
        $filter_epi = $request->query->get('epi');
        $en_service = $request->query->get('en_service');
        $emplacement_id = $request->query->get('emplacement');
        $dernier_controle = $request->query->get('dernier_controle');
        $order_by = $request->query->get('order_by', 'categorie');
        $order_dir = $request->query->get('order_dir', 'asc');
        $acquisition_id = $request->query->get('acquisition'); // AJOUT
        
        if ($categorie_id === '') $categorie_id = null;
        if ($filter_epi === '') $filter_epi = null;
        if ($en_service === '') $en_service = null;
        if ($emplacement_id === '') $emplacement_id = null;
        if ($dernier_controle === '') $dernier_controle = null;
        if ($acquisition_id === '') $acquisition_id = null; // AJOUT
        
        // Chargement des équipements avec relations
        $equipements = $equipementManager->findAll();
        foreach ($equipements as $i => $equipement) {
            if (isset($equipement['categorie_id'])) {
                $equipements[$i]['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
            if (isset($equipement['emplacement_id'])) {
                $equipements[$i]['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
            }
        }
        
        // --- Filtres ---
        if ($categorie_id) {
            $equipements = array_filter($equipements, function($e) use ($categorie_id) {
                return isset($e['categorie_id']) && $e['categorie_id'] == $categorie_id;
            });
        }
        
        // AJOUT : Filtre par acquisition
        if ($acquisition_id) {
            $equipements = array_filter($equipements, function($e) use ($acquisition_id) {
                return isset($e['acquisition_id']) && $e['acquisition_id'] == $acquisition_id;
            });
        }
        
        if ($filter_epi !== null) {
            $equipements = array_filter($equipements, function($e) use ($filter_epi) {
                return isset($e['categorie']['est_epi']) && $e['categorie']['est_epi'] == $filter_epi;
            });
        }
        if ($en_service !== null) {
            $today = date('Y-m-d');
            if ($en_service === 'oui') {
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return !isset($e['date_fin_utilisation']) || $e['date_fin_utilisation'] > $today;
                });
            } elseif ($en_service === 'non') {
                $equipements = array_filter($equipements, function($e) use ($today) {
                    return isset($e['date_fin_utilisation']) && $e['date_fin_utilisation'] <= $today;
                });
            }
        }
        if ($emplacement_id !== null) {
            if ($emplacement_id === 'null') {
                $equipements = array_filter($equipements, function($e) {
                    return !isset($e['emplacement_id']) || $e['emplacement_id'] === null;
                });
            } else {
                $equipements = array_filter($equipements, function($e) use ($emplacement_id) {
                    return isset($e['emplacement_id']) && $e['emplacement_id'] == $emplacement_id;
                });
            }
        }
        // Recherche texte (référence ou libellé, insensible à la casse) —
        // utile pour retrouver les équipements identiques.
        $recherche = self::recherche($request);
        if ($recherche !== null) {
            $aiguille = mb_strtolower($recherche);
            $equipements = array_filter($equipements, function($e) use ($aiguille) {
                return str_contains(mb_strtolower((string) ($e['libelle'] ?? '')), $aiguille)
                    || str_contains(mb_strtolower((string) ($e['reference'] ?? '')), $aiguille);
            });
        }

        // [Tableau de bord] Équipements à renouveler : date de fin
        // d'utilisation <= fin_avant (y compris déjà échus), hors
        // équipements supprimés / « Hors service » — même périmètre que
        // EquipementManager::statsARenouveler().
        $fin_avant = self::dateFinAvant($request);
        if ($fin_avant !== null) {
            $equipements = array_filter($equipements, function($e) use ($fin_avant) {
                return !empty($e['date_fin_utilisation'])
                    && $e['date_fin_utilisation'] <= $fin_avant
                    && (int) ($e['statut'] ?? 0) !== EquipementStatut::HORS_SERVICE->value;
            });
        }
        if ($dernier_controle !== null) {
            $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
            if ($dernier_controle === 'plus_1_an') {
                $equipements = array_filter($equipements, function($e) use ($oneYearAgo) {
                    return isset($e['date_dernier_controle']) && $e['date_dernier_controle'] >= $oneYearAgo;
                });
            } elseif ($dernier_controle === 'moins_1_an') {
                $equipements = array_filter($equipements, function($e) use ($oneYearAgo) {
                    return !isset($e['date_dernier_controle']) || $e['date_dernier_controle'] < $oneYearAgo;
                });
            }
        }
        
        // --- Tri ---
        if ($order_by === 'categorie') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_cat = $a['categorie']['libelle'] ?? '';
                $b_cat = $b['categorie']['libelle'] ?? '';
                return $order_dir === 'asc' ? strcmp($a_cat, $b_cat) : strcmp($b_cat, $a_cat);
            });
        } elseif ($order_by === 'date_fin') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_date = $a['date_fin_utilisation'] ?? '9999-12-31';
                $b_date = $b['date_fin_utilisation'] ?? '9999-12-31';
                return $order_dir === 'asc' ? strcmp($a_date, $b_date) : strcmp($b_date, $a_date);
            });
        } elseif ($order_by === 'date_dernier_controle') {
            usort($equipements, function($a, $b) use ($order_dir) {
                $a_date = $a['date_dernier_controle'] ?? '1970-01-01';
                $b_date = $b['date_dernier_controle'] ?? '1970-01-01';
                return $order_dir === 'asc' ? strcmp($a_date, $b_date) : strcmp($b_date, $a_date);
            });
        }
        
        return array_values($equipements); // ré-indexation
    }
    
    // --------------------------------------------------------------
    // AFFICHAGE D'UN ÉQUIPEMENT
    // --------------------------------------------------------------
    public const MESSAGE_HS_ATTRIBUE = "Le statut « Hors service » ne peut être attribué qu'à l'issue d'un contrôle.";
    public const MESSAGE_HS_LEVE = "Cet équipement est hors service : seul un contrôle peut lever ce statut.";

    /**
     * [MÉTIER 2026-10-21] Garde-fou de suppression : l'équipement est
     * « Hors service », aucun contrôle n'est en cours, et c'est son DERNIER
     * contrôle clôturé qui l'a déclaré hors service (un statut posé à la
     * main avant cette règle ne suffit pas).
     *
     * @param array<string, mixed>             $equipement
     * @param list<array<string, mixed>>       $historique Lignes de contrôles clôturés,
     *                                                     les plus récents d'abord
     *                                                     (EquipementManager::getHistoriqueControles).
     * @param string|null                      $dateLimiteEgare [migration 017] Un équipement
     *                                                     « Égaré » au plus tard à cette date
     *                                                     (Y-m-d) est aussi supprimable.
     */
    public static function suppressionAutorisee(array $equipement, array $historique, ?string $dateLimiteEgare = null): bool
    {
        if ($dateLimiteEgare !== null && \Epiclub\Domain\SuppressionEgare::autorisee($equipement, $dateLimiteEgare)) {
            return true;
        }
        return (int) ($equipement['statut'] ?? -1) === EquipementStatut::HORS_SERVICE->value
            && empty($equipement['controle_en_cours'])
            && ($historique[0]['statut'] ?? null) === \Epiclub\Enum\ControleLigneStatut::HORS_SERVICE->value;
    }

    /**
     * [migration 017] Délai (mois) avant suppression d'un équipement égaré
     * (page Club) ; défaut si la base est inaccessible.
     */
    protected function delaiSuppressionEgare(): int
    {
        try {
            $club = $this->clubManagerModules()->findParameters() ?: [];
        } catch (\Throwable) {
            $club = [];
        }
        return \Epiclub\Domain\SuppressionEgare::normaliserDelai($club['delai_suppression_egare_mois'] ?? null);
    }

    /** Date du jour (Y-m-d) — surchargeable en test. */
    protected function aujourdhuiYmd(): string
    {
        return date('Y-m-d');
    }

    private function dateLimiteEgare(): string
    {
        return \Epiclub\Domain\SuppressionEgare::dateLimite($this->aujourdhuiYmd(), $this->delaiSuppressionEgare());
    }

    public function show(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : on redirige silencieusement
            return $this->redirectTo('/equipements');
        }
        
        $equipementManager = $this->equipementManager();
        $equipement = $equipementManager->findId($id);
        
        if (!$equipement) {
            $this->session->getFlashBag()->add('danger', 'Équipement non trouvé.');
            return $this->redirectTo('/equipements');
        }
        
        $equipement = $this->avecCategorieEtEmplacement($equipement);

        $historiqueControles = $equipementManager->getHistoriqueControles($id);
        
        $acquisition = null;
        if (!empty($equipement['acquisition_id'])) {
            $acquisitionManager = $this->acquisitionManager();
            $acquisition = $acquisitionManager->findId($equipement['acquisition_id']);
        }
        
        // [Prêt de matériel] Prêt ouvert sur ce matériel + historique.
        $pretsEnSaisie = $this->pretsEnSaisiePourVue();
        $pretOuvert = null;
        $historiquePrets = [];
        if ($this->isGranted('ROLE_CONTROLLEUR') && $this->pretActif()) {
            try {
                $pretOuvert = $this->pretLigneManager()->findPretOuvertPourEquipement($id);
                $historiquePrets = $this->pretLigneManager()->historiqueEquipement($id);
            } catch (\Throwable $e) {
                error_log('[EquipementController] Historique des prêts indisponible : ' . $e->getMessage());
            }
        }

        return $this->render('equipement_detail.twig', [
            'prets_en_saisie' => $pretOuvert === null ? $pretsEnSaisie : [],
            'pret_ouvert' => $pretOuvert,
            'historique_prets' => $historiquePrets,
            'pret_motif' => \Epiclub\Domain\PretRegles::motifIneligibilite($equipement, null, date('Y-m-d')),
            'equipement' => $equipement,
            'suppression_autorisee' => self::suppressionAutorisee($equipement, $historiqueControles, $this->dateLimiteEgare()),
            // [migration 017] Équipement égaré : date à partir de laquelle il sera supprimable
            'egare_supprimable_le' => \Epiclub\Domain\SuppressionEgare::dateSuppressionPossible($equipement, $this->delaiSuppressionEgare()),
            'historiqueControles' => $historiqueControles,
            'acquisition' => $acquisition,
        ]);
    }

    // --------------------------------------------------------------
    // [2026-10-23] CHANGEMENT DE RÉFÉRENCE (ROLE_SUPER_ADMIN), dans le
    // formulaire de modification. Garde-fous : unicité vérifiée à la volée
    // (AJAX) puis côté serveur, saisie du mot de confirmation REFERENCE.
    // --------------------------------------------------------------
    public const MOT_CONFIRMATION_REFERENCE = 'REFERENCE';

    /** Longueur de club_equipement.reference (VARCHAR(32)). */
    public const REFERENCE_MAX = ReferenceGenerator::REFERENCE_MAX;

    private const MESSAGE_REFERENCE_PRISE = 'La référence « %s » est déjà attribuée à un autre équipement.';

    /** Message d'erreur de format, ou null si la référence est recevable. */
    public static function erreurFormatReference(string $reference): ?string
    {
        if ($reference === '') {
            return 'La référence est obligatoire.';
        }
        if (!mb_check_encoding($reference, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            return 'La référence contient des caractères non autorisés.';
        }
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            return sprintf('La référence ne doit pas dépasser %d caractères.', self::REFERENCE_MAX);
        }
        return null;
    }

    /**
     * GET JSON /equipements/reference-disponible?id=&reference=
     * Vérification d'unicité à la volée pour le formulaire de modification.
     */
    public function referenceDisponible(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $reference = trim((string) $request->query->get('reference', ''));
        $idRaw = (string) $request->query->get('id', '');
        $id = ctype_digit($idRaw) && (int) $idRaw > 0 ? (int) $idRaw : null;

        $erreur = self::erreurFormatReference($reference);
        if ($erreur !== null) {
            return new JsonResponse(['disponible' => false, 'message' => $erreur]);
        }

        if ($id !== null) {
            $equipement = $this->equipementManager()->findId($id);
            if ($equipement && (string) $equipement['reference'] === $reference) {
                return new JsonResponse(['disponible' => false, 'message' => 'C\'est la référence actuelle.']);
            }
        }

        $prise = $this->equipementManager()->referenceExists($reference, $id);
        return new JsonResponse([
            'disponible' => !$prise,
            'message' => $prise
                ? 'Cette référence est déjà attribuée à un autre équipement (éventuellement supprimé).'
                : 'Référence disponible.',
        ]);
    }

    // --------------------------------------------------------------
    // [2026-10-24] CHANGEMENT DE CATÉGORIE (ROLE_SUPER_ADMIN), dans le
    // formulaire de modification. Saisie comme pour une ligne
    // d'acquisition (liste des catégories, une catégorie inconnue est
    // créée) ; une référence libre est proposée pour la nouvelle
    // catégorie sur l'année d'achat ; mot de confirmation CATEGORIE.
    // --------------------------------------------------------------
    public const MOT_CONFIRMATION_CATEGORIE = 'CATEGORIE';

    /** [2026-10-24] Nombre initial maximal d'un lot saisi par un super-administrateur. */
    public const LOT_MAX = 9999;

    /** Longueur maximale d'un libellé de catégorie saisi. */
    public const CATEGORIE_LIBELLE_MAX = 64;

    /** Message d'erreur du libellé de catégorie saisi, ou null s'il est recevable. */
    public static function erreurLibelleCategorie(string $libelle): ?string
    {
        if ($libelle === '') {
            return 'La catégorie est obligatoire.';
        }
        if (!mb_check_encoding($libelle, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $libelle)) {
            return 'La catégorie contient des caractères non autorisés.';
        }
        if (mb_strlen($libelle) > self::CATEGORIE_LIBELLE_MAX) {
            return sprintf('La catégorie ne doit pas dépasser %d caractères.', self::CATEGORIE_LIBELLE_MAX);
        }
        return null;
    }

    /**
     * Année d'achat de l'équipement (date de facture de son acquisition),
     * année courante à défaut : année des références proposées.
     */
    private function anneeAchat(array $equipement): int
    {
        $dateFacture = !empty($equipement['acquisition_id'])
            ? self::dateFacture($this->acquisitionManager()->findId((int) $equipement['acquisition_id']))
            : null;
        return $dateFacture !== null ? (int) substr($dateFacture, 0, 4) : (int) date('Y');
    }

    /**
     * GET JSON /equipements/reference-suggestion?id=&categorie=
     * Référence proposée pour l'équipement dans la catégorie saisie
     * (n° de suivi suivant, année d'achat), et si la catégorie existe.
     */
    public function referenceSuggestion(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $libelle = trim((string) $request->query->get('categorie', ''));
        $idRaw = (string) $request->query->get('id', '');
        $equipement = ctype_digit($idRaw) && (int) $idRaw > 0
            ? $this->equipementManager()->findId((int) $idRaw)
            : null;
        if (!$equipement || self::erreurLibelleCategorie($libelle) !== null) {
            return new JsonResponse(['reference' => null]);
        }

        $categorie = $this->categorieManager()->findOneByCriteria(['libelle' => $libelle]);
        $annee = $this->anneeAchat($equipement);
        try {
            $reference = $this->referenceGenerator()->suggest($libelle, $annee);
        } catch (\Throwable $e) {
            error_log('[EquipementController] referenceSuggestion: ' . $e->getMessage());
            $reference = null;
        }

        return new JsonResponse([
            'reference' => $reference,
            'annee' => $annee,
            'nouvelle' => !$categorie,
            'est_epi' => $categorie ? !empty($categorie['est_epi']) : true,
        ]);
    }

    // --------------------------------------------------------------
    // [2026-10-24] DISSOCIATION D'UN LOT (ROLE_SUPER_ADMIN), depuis le
    // formulaire de modification (roue dentée). Références des nouveaux
    // équipements proposées selon le modèle, mot de confirmation DISSOCIER.
    // --------------------------------------------------------------

    /**
     * GET JSON /equipements/dissociation-references?id=&nombre=
     * Références proposées pour les `nombre` nouveaux équipements : n° de
     * suivi suivants du modèle (catégorie, année d'achat), libres.
     */
    public function referencesDissociation(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $idRaw = (string) $request->query->get('id', '');
        $nombreRaw = (string) $request->query->get('nombre', '');
        $equipement = ctype_digit($idRaw) && (int) $idRaw > 0
            ? $this->equipementManager()->findId((int) $idRaw)
            : null;
        $nombre = ctype_digit($nombreRaw) ? (int) $nombreRaw : 0;
        $categorie = $equipement && !empty($equipement['categorie_id'])
            ? $this->categorieManager()->findId((int) $equipement['categorie_id'])
            : null;
        if (!$categorie || $nombre < 1 || $nombre >= DissociationLot::PARTIES_MAX) {
            return new JsonResponse(['references' => []]);
        }

        $references = [];
        try {
            $annee = $this->anneeAchat($equipement);
            $generateur = $this->referenceGenerator();
            $premiere = $generateur->suggest((string) $categorie['libelle'], $annee);
            $compteur = $generateur->compteurSiConforme($premiere, (int) $categorie['id'], $annee);
            if ($compteur === null) {
                $references = [$premiere];
            } else {
                $equipements = $this->equipementManager();
                for ($essais = 0; count($references) < $nombre && $essais < $nombre + 1000; $essais++) {
                    $reference = $generateur->referenceAuRang((int) $categorie['id'], $annee, $compteur++);
                    if (!$equipements->referenceExists($reference)) {
                        $references[] = $reference;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[EquipementController] referencesDissociation: ' . $e->getMessage());
        }

        return new JsonResponse(['references' => $references]);
    }

    /**
     * POST /equipements/equipement_dissocier-{id}
     * Les contrôles faits à la volée dans le navigateur sont refaits ici ;
     * en cas de refus, retour au formulaire de modification avec le motif.
     */
    public function dissocier(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        $equipement = $id !== null ? $this->equipementManager()->findId($id) : null;
        if (!$equipement) {
            $this->session->getFlashBag()->add('error', 'Équipement non trouvé.');
            return $this->redirectTo('/equipements');
        }
        $flash = $this->session->getFlashBag();
        $retour = '/equipements/equipement_modification-' . $id;

        $erreur = DissociationLot::erreurEquipement($equipement);
        $parties = $erreur === null
            ? DissociationLot::parties((string) $request->request->get('repartition', ''), (int) $equipement['nombre'])
            : [];
        if (is_string($parties)) {
            $erreur = $parties;
        }

        $references = [];
        if ($erreur === null) {
            $postees = $request->request->all('references');
            foreach (array_slice($parties, 1) as $i => $taille) {
                $reference = trim((string) ($postees[$i] ?? ''));
                if (($erreur = self::erreurFormatReference($reference)) !== null) {
                    $erreur = sprintf('Nouvel équipement n° %d : %s', $i + 1, $erreur);
                    break;
                }
                if ($reference === (string) $equipement['reference'] || in_array($reference, $references, true)) {
                    $erreur = sprintf('La référence « %s » est utilisée deux fois.', $reference);
                    break;
                }
                if ($this->equipementManager()->referenceExists($reference)) {
                    $erreur = sprintf(self::MESSAGE_REFERENCE_PRISE, $reference);
                    break;
                }
                $references[] = $reference;
            }
        }
        if ($erreur === null
            && trim((string) $request->request->get('confirmation_dissociation', '')) !== DissociationLot::MOT_CONFIRMATION) {
            $erreur = sprintf('Pour dissocier le lot, saisissez le mot %s (en majuscules).', DissociationLot::MOT_CONFIRMATION);
        }
        if ($erreur !== null) {
            $flash->add('error', $erreur);
            return $this->redirectTo($retour);
        }

        try {
            $ids = $this->dissociationLot()->dissocier($equipement, $parties, $references);
        } catch (\PDOException $e) {
            // Course sur une référence (contrainte UNIQUE) : rien n'a été écrit.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $flash->add('error', 'Une des références vient d\'être attribuée à un autre équipement : rien n\'a été modifié, réessayez.');
            return $this->redirectTo($retour);
        }

        $user = $this->session->get('user');
        error_log(sprintf(
            '[EquipementController] Lot id=%d « %s » dissocié (%s) : nouveaux équipements id=%s, références %s (utilisateur id=%s)',
            $id, $equipement['reference'], implode('+', $parties), implode(',', $ids), implode(',', $references), $user['id'] ?? '?'
        ));
        $flash->add('success', sprintf(
            'Lot « %s » dissocié : %d élément(s) restent sur « %s » ; %d nouvel(s) équipement(s) : %s. Pensez à imprimer leurs étiquettes.',
            $equipement['reference'], $parties[0], $equipement['reference'], count($references), implode(', ', $references)
        ));
        return $this->redirectTo('/equipements/equipement-' . $id);
    }

    // --------------------------------------------------------------
    // ÉDITION
    // --------------------------------------------------------------
    public function edit(Request $request)
    {
        // [2026-10-23] Un contrôleur accède au formulaire mais n'y modifie
        // que l'emplacement (cf. enregistrerEmplacementSeul()).
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        $emplacementSeul = !$this->isGranted('ROLE_ADMIN');
        
        $categorieManager = $this->categorieManager();
        $emplacementManager = $this->emplacementManager();
        $equipementManager = $this->equipementManager();
        $equipement = [];
        $form_errors = [];
        
        $id = $this->getValidId($request);
        if ($id !== null) {
            $equipement = $equipementManager->findId($id);
            if ($equipement && isset($equipement['categorie_id'])) {
                $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
        }
        if ($emplacementSeul && empty($equipement)) {
            return $this->redirectTo('/equipements');
        }

        // [MÉTIER] Date de facture de l'acquisition : date d'achat
        // affichée et borne basse de la date de mise en service.
        $dateFacture = null;
        if (!empty($equipement['acquisition_id'])) {
            $dateFacture = self::dateFacture($this->acquisitionManager()->findId((int) $equipement['acquisition_id']));
        }
        if (is_array($equipement)) {
            $equipement['date_achat'] = $dateFacture;
        }
        // [MÉTIER 2026-10-20] Un super-administrateur peut toujours la
        // modifier (sans verrou), mais jamais avant la date de facture.
        $dateMesVerrouillee = is_array($equipement)
            && !$this->isGranted('ROLE_SUPER_ADMIN')
            && self::dateMiseEnServiceVerrouillee($equipement, $dateFacture);
        
        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF AVANT tout traitement (y compris upload)
            $this->validateCsrf($request);

            if ($emplacementSeul) {
                return $this->enregistrerEmplacementSeul($equipement, $request);
            }

            // [2026-10-23] Changement de référence (super-administrateur)
            $nouvelleReference = null;
            $ancienneReference = (string) ($equipement['reference'] ?? '');
            // [2026-10-24] Changement de catégorie (super-administrateur)
            $nouvelleCategorie = null;
            $ancienneCategorie = (string) ($equipement['categorie']['libelle'] ?? '');
            $ancienneCategorieId = $equipement['categorie_id'] ?? null;
            
            $emplacement_id = $request->request->get('emplacement_id');
            if ($emplacement_id === '') $emplacement_id = null;
            
            // [MÉTIER] Date de mise en service (décision 2026-10-20) :
            // initialisée à la date de facture, modifiable UNE seule fois,
            // jamais antérieure à la facture. Une fois verrouillée, la
            // valeur postée est ignorée (champ non modifiable côté
            // serveur, pas seulement dans le formulaire).
            $date_mise_en_service = $equipement['date_mise_en_service'] ?? null;
            if (!$dateMesVerrouillee) {
                $dmsRaw = trim((string) $request->request->get('date_mise_en_service', ''));
                if ($dmsRaw !== '') {
                    $dms = \DateTimeImmutable::createFromFormat('!Y-m-d', $dmsRaw);
                    if ($dms === false || $dms->format('Y-m-d') !== $dmsRaw) {
                        $form_errors['date_mise_en_service'] = 'Date de mise en service invalide.';
                    } elseif ($dateFacture !== null && $dmsRaw < $dateFacture) {
                        $form_errors['date_mise_en_service'] = sprintf(
                            'La date de mise en service ne peut pas être antérieure à la date de facture (%s).',
                            date('d/m/Y', strtotime($dateFacture))
                        );
                    } else {
                        $date_mise_en_service = $dmsRaw;
                    }
                }
            }
            
            $date_fin_utilisation = $request->request->get('date_fin_utilisation');
            if ($date_fin_utilisation === '') $date_fin_utilisation = null;
            
            $photoPath = null;
            $uploadedPhoto = $request->files->get('photo');
            if ($uploadedPhoto !== null && $uploadedPhoto->isValid()) {
                try {
                    $photoPath = $this->savePhoto($uploadedPhoto);
                } catch (\InvalidArgumentException $e) {
                    // Refus explicite d'ImageUploader (type, AVIF non pris
                    // en charge, taille) : message destiné à l'utilisateur.
                    $form_errors['photo'] = $e->getMessage();
                } catch (\Exception $e) {
                    // [ROBUSTESSE] Clé nommée pour cibler le champ photo dans le template.
                    // On ne remonte pas $e->getMessage() tel quel : il peut contenir
                    // des chemins serveur. On garde le message court.
                    error_log('[EquipementController] Photo processing failed: ' . $e->getMessage());
                    $form_errors['photo'] = self::MESSAGE_PHOTO_ILLISIBLE;
                }
            }
            
            if (empty($form_errors)) {
                // [2026-10-24] Lot et nombre initial : modifiables par un
                // super-administrateur (roue dentée, sans mot de confirmation).
                // Lot décoché ⇒ nombre initial et nombre actuel ramenés à 1.
                $nombreInitial = (int) ($equipement['nombre_initial'] ?? 1);
                $nouveauNombreInitial = null;
                if ($this->isGranted('ROLE_SUPER_ADMIN') && $request->request->has('lot')) {
                    $lotPoste = (string) $request->request->get('lot') === '1';
                    $niRaw = $request->request->get('nombre_initial');
                    $niPoste = $lotPoste ? null : 1;
                    if ($lotPoste) {
                        if (!is_string($niRaw) || !ctype_digit($niRaw)
                            || (int) $niRaw < 2 || (int) $niRaw > self::LOT_MAX) {
                            $form_errors['nombre_initial'] = sprintf(
                                'Le nombre initial d\'un lot doit être compris entre 2 et %d.',
                                self::LOT_MAX
                            );
                        } else {
                            $niPoste = (int) $niRaw;
                        }
                    }
                    if ($niPoste !== null && $niPoste !== $nombreInitial) {
                        $nouveauNombreInitial = $niPoste;
                        $nombreInitial = $niPoste;
                    }
                    if ($niPoste === null || $nouveauNombreInitial !== null) {
                        $lotSaisie = ['lot' => $lotPoste, 'nombre_initial' => is_scalar($niRaw) ? (string) $niRaw : ''];
                    }
                }

                // [MÉTIER] Lot : le nombre d'éléments est modifiable
                // mais plafonné au nombre initial (décision 2026-10-19).
                $nombreLot = null;
                if ($nouveauNombreInitial === 1) {
                    $nombreLot = 1;
                } elseif ($nombreInitial > 1 && !isset($form_errors['nombre_initial'])) {
                    $nombreRaw = $request->request->get('nombre');
                    if ($nouveauNombreInitial !== null && ($nombreRaw === null || $nombreRaw === '')) {
                        // Nouveau lot sans nombre actuel saisi : lot complet.
                        $nombreRaw = (string) $nouveauNombreInitial;
                    }
                    if (!is_string($nombreRaw) || !ctype_digit($nombreRaw)
                        || (int) $nombreRaw < 1 || (int) $nombreRaw > $nombreInitial) {
                        $form_errors['nombre'] = sprintf(
                            'Le nombre d\'éléments du lot doit être compris entre 1 et %d (nombre initial).',
                            $nombreInitial
                        );
                    } else {
                        $nombreLot = (int) $nombreRaw;
                    }
                }
            }

            if (empty($form_errors)) {
                // [SÉCURITÉ] Whitelist stricte du statut (ENUM BDD 0/1/2).
                // Empêche un POST forgé de pousser une valeur arbitraire.
                // ⚠️ On exige une string numérique stricte AVANT le cast :
                // (int)'hacked' === 0 et (int)'' === 0, ce qui laisserait
                // passer des valeurs invalides comme DISPONIBLE.
                $statutRaw = $request->request->get('statut');
                $statutEnum = is_string($statutRaw) && ctype_digit($statutRaw)
                    ? EquipementStatut::tryFrom((int) $statutRaw)
                    : null;
                if ($statutEnum === null) {
                    error_log(sprintf(
                        '[EquipementController] Invalid statut submitted for equipement id=%s: %s',
                        $id ?? 'new',
                        is_scalar($statutRaw) ? substr((string) $statutRaw, 0, 50) : gettype($statutRaw)
                    ));
                    $this->session->getFlashBag()->add('error', 'Statut invalide.');
                    return $this->redirectTo('/equipements');
                }
                
                // [MÉTIER 2026-10-21] Statuts réservés (Hors service, Prêté,
                // Égaré) : ni attribués ni levés par la fiche — seulement par
                // un contrôle ou le cycle d'un prêt.
                $statutActuel = EquipementStatut::tryFrom((int) ($equipement['statut'] ?? 0));
                if ($statutActuel !== $statutEnum) {
                    if ($statutActuel !== null && $statutActuel->estReserve()) {
                        $form_errors['statut'] = $statutActuel->messageLevee();
                    } elseif ($statutEnum->estReserve()) {
                        $form_errors['statut'] = $statutEnum->messageAttribution();
                    }
                }

                // [MÉTIER 2026-10-20] Libellé défini à l'acquisition :
                // modifiable uniquement par un super-administrateur.
                if ($this->isGranted('ROLE_SUPER_ADMIN') && $request->request->has('libelle')) {
                    $libelle = trim((string) $request->request->get('libelle'));
                    if (($erreur = self::erreurLibelle($libelle)) !== null) {
                        $form_errors['libelle'] = $erreur;
                    }
                }

                // [2026-10-23] Référence : modifiable par un super-administrateur,
                // avec le mot de confirmation et un contrôle d'unicité
                // (déjà fait à la volée dans le navigateur, refait ici).
                if ($this->isGranted('ROLE_SUPER_ADMIN') && $request->request->has('reference')) {
                    $referencePostee = trim((string) $request->request->get('reference'));
                    if ($referencePostee !== $ancienneReference) {
                        $referenceSaisie = $referencePostee;
                        $confirmation = trim((string) $request->request->get('confirmation_reference', ''));
                        if (($erreur = self::erreurFormatReference($referencePostee)) !== null) {
                            $form_errors['reference'] = $erreur;
                        } elseif ($confirmation !== self::MOT_CONFIRMATION_REFERENCE) {
                            $form_errors['confirmation_reference'] = sprintf(
                                'Pour changer la référence, saisissez le mot %s (en majuscules).',
                                self::MOT_CONFIRMATION_REFERENCE
                            );
                        } elseif ($equipementManager->referenceExists($referencePostee, $id)) {
                            $form_errors['reference'] = sprintf(self::MESSAGE_REFERENCE_PRISE, $referencePostee);
                        } else {
                            $nouvelleReference = $referencePostee;
                        }
                    }
                }

                // [2026-10-24] Catégorie : modifiable par un super-administrateur,
                // avec le mot de confirmation CATEGORIE. Une catégorie inconnue
                // est créée à l'enregistrement, comme pour une ligne d'acquisition.
                if ($this->isGranted('ROLE_SUPER_ADMIN') && $request->request->has('categorie_libelle')) {
                    $categoriePostee = trim((string) $request->request->get('categorie_libelle'));
                    if ($categoriePostee !== $ancienneCategorie) {
                        $existante = self::erreurLibelleCategorie($categoriePostee) === null
                            ? $categorieManager->findOneByCriteria(['libelle' => $categoriePostee])
                            : null;
                        $confirmation = trim((string) $request->request->get('confirmation_categorie', ''));
                        if ($existante && (int) $existante['id'] === (int) ($equipement['categorie_id'] ?? 0)) {
                            // Même catégorie (casse ou espaces différents) : rien à changer.
                        } elseif (($erreur = self::erreurLibelleCategorie($categoriePostee)) !== null) {
                            $categorieSaisie = $categoriePostee;
                            $form_errors['categorie_libelle'] = $erreur;
                        } elseif ($confirmation !== self::MOT_CONFIRMATION_CATEGORIE) {
                            $categorieSaisie = $categoriePostee;
                            $form_errors['confirmation_categorie'] = sprintf(
                                'Pour changer la catégorie, saisissez le mot %s (en majuscules).',
                                self::MOT_CONFIRMATION_CATEGORIE
                            );
                        } else {
                            $categorieSaisie = $categoriePostee;
                            $nouvelleCategorie = $existante ?: null;
                            $categorieACreer = $existante ? null : $categoriePostee;
                        }
                    }
                }
            }

            if (empty($form_errors) && isset($categorieACreer)) {
                // Mêmes valeurs par défaut que AcquisitionProcess::categorie_process().
                $nouvelleCategorie = [
                    'libelle' => ucfirst($categorieACreer),
                    'description' => '',
                    'image' => '',
                    'est_epi' => 1,
                ];
                $nouvelleCategorie['id'] = (int) $categorieManager->save($nouvelleCategorie);
            }

            if (empty($form_errors)) {
                $equipementData = [
                    'statut' => $statutEnum->value,
                    'emplacement_id' => $emplacement_id,
                    'remarques' => $request->request->get('remarques'),
                    'date_mise_en_service' => $date_mise_en_service,
                    'date_fin_utilisation' => $date_fin_utilisation,
                    // [MÉTIER] Case « durée max » : héritée de la catégorie
                    // à la génération, modifiable ici (checkbox absente
                    // du POST = décochée).
                    'duree_max_utilisation' => $request->request->has('duree_max_utilisation') ? 1 : 0,
                    // [MÉTIER 2026-10-21] Élément textile remplaçable :
                    // hérité de la catégorie, modifiable ici.
                    'textile_remplacable' => $request->request->has('textile_remplacable') ? 1 : 0,
                ];

                if ($nombreLot !== null) {
                    $equipementData['nombre'] = $nombreLot;
                }
                if ($nouveauNombreInitial !== null) {
                    $equipementData['nombre_initial'] = $nouveauNombreInitial;
                }
                if (isset($libelle)) {
                    $equipementData['libelle'] = $libelle;
                }
                if ($nouvelleReference !== null) {
                    $equipementData['reference'] = $nouvelleReference;
                }
                if ($nouvelleCategorie !== null) {
                    $equipementData['categorie_id'] = (int) $nouvelleCategorie['id'];
                }
                
                $anciennePhoto = null;
                if ($photoPath !== null) {
                    $anciennePhoto = $equipement['photo'] ?? null;
                    $equipementData['photo'] = $photoPath;
                }
                
                $equipement = array_merge($equipement, $equipementData);
                try {
                    $equipementManager->save($equipement);
                } catch (\PDOException $e) {
                    // Course sur la référence : prise entre la vérification
                    // et l'écriture (contrainte UNIQUE, migration 009).
                    if ($nouvelleReference === null || (string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    $form_errors['reference'] = sprintf(self::MESSAGE_REFERENCE_PRISE, $nouvelleReference);
                    $equipement['reference'] = $ancienneReference;
                    $equipement['categorie_id'] = $ancienneCategorieId;
                }
            }

            if (empty($form_errors)) {
                // [Photo groupée] La photo remplacée peut être partagée
                // avec d'autres équipements : on n'efface le fichier que
                // s'il n'est plus référencé (vérifié après l'enregistrement).
                if (!empty($anciennePhoto)) {
                    $this->supprimerPhotoSiOrpheline($anciennePhoto);
                }
                if ($nouvelleReference !== null) {
                    $user = $this->session->get('user');
                    error_log(sprintf(
                        '[EquipementController] Référence de l\'équipement id=%d modifiée : « %s » → « %s » (utilisateur id=%s)',
                        $id, $ancienneReference, $nouvelleReference, $user['id'] ?? '?'
                    ));
                    $this->session->getFlashBag()->add('success', sprintf(
                        'Référence modifiée : « %s » → « %s ». Pensez à réimprimer l\'étiquette.',
                        $ancienneReference, $nouvelleReference
                    ));
                }
                if ($nouvelleCategorie !== null) {
                    $user ??= $this->session->get('user');
                    error_log(sprintf(
                        '[EquipementController] Catégorie de l\'équipement id=%d modifiée : « %s » → « %s »%s (utilisateur id=%s)',
                        $id, $ancienneCategorie, $nouvelleCategorie['libelle'],
                        isset($categorieACreer) ? ' (créée)' : '', $user['id'] ?? '?'
                    ));
                    $this->session->getFlashBag()->add('success', sprintf(
                        'Catégorie modifiée : « %s » → « %s »%s.',
                        $ancienneCategorie, $nouvelleCategorie['libelle'],
                        isset($categorieACreer) ? ' (nouvelle catégorie créée)' : ''
                    ));
                }
                return $this->redirectTo("/equipements");
            }
        }
        
        return $this->render('equipement_form.twig', [
            'date_facture' => $dateFacture,
            'date_mes_verrouillee' => $dateMesVerrouillee,
            'categories' => $categorieManager->findAll(),
            'emplacements' => $emplacementManager->findAll(),
            'equipement_statuts' => EquipementStatut::forSelectManuel(),
            'equipement' => $equipement,
            'form_errors' => $form_errors,
            // [2026-10-23] Contrôleur : seul l'emplacement est modifiable
            'emplacement_seul' => $emplacementSeul,
            // [2026-10-23] Changement de référence (super-administrateur)
            'reference_saisie' => $referenceSaisie ?? null,
            'mot_confirmation_reference' => self::MOT_CONFIRMATION_REFERENCE,
            'reference_max' => self::REFERENCE_MAX,
            // [2026-10-24] Changement de catégorie (super-administrateur)
            'categorie_saisie' => $categorieSaisie ?? null,
            'mot_confirmation_categorie' => self::MOT_CONFIRMATION_CATEGORIE,
            'categorie_libelle_max' => self::CATEGORIE_LIBELLE_MAX,
            // [2026-10-24] Lot / nombre initial (super-administrateur)
            'lot_saisie' => $lotSaisie ?? null,
            'lot_max' => self::LOT_MAX,
            // [2026-10-24] Dissociation du lot (super-administrateur)
            'dissociation_erreur' => is_array($equipement) && !empty($equipement['id'])
                ? DissociationLot::erreurEquipement($equipement) : null,
            'mot_confirmation_dissociation' => DissociationLot::MOT_CONFIRMATION,
        ]);
    }

    /**
     * [2026-10-23] Formulaire soumis par un contrôleur : seul
     * l'emplacement est enregistré, les autres champs postés sont ignorés.
     */
    private function enregistrerEmplacementSeul(array $equipement, Request $request): Response
    {
        $id = (int) $equipement['id'];
        $raw = $request->request->get('emplacement_id', '');
        $emplacementId = null;
        $emplacement = null;
        if ($raw !== '' && $raw !== null) {
            // Whitelist : id d'un emplacement existant.
            if (!is_string($raw) || !ctype_digit($raw)
                || !($emplacement = $this->emplacementManager()->findId((int) $raw))) {
                $this->session->getFlashBag()->add('error', 'Emplacement invalide.');
                return $this->redirectTo('/equipements/equipement_modification-' . $id);
            }
            $emplacementId = (int) $raw;
        }

        $actuel = !empty($equipement['emplacement_id']) ? (int) $equipement['emplacement_id'] : null;
        if ($actuel !== $emplacementId) {
            $this->equipementManager()->save(['id' => $id, 'emplacement_id' => $emplacementId]);
            $this->session->getFlashBag()->add('success', $emplacement
                ? sprintf('Équipement « %s » déplacé vers « %s ».', $equipement['reference'] ?? '', $emplacement['libelle'])
                : sprintf('Emplacement retiré pour « %s ».', $equipement['reference'] ?? ''));
        }

        return $this->redirectTo('/equipements/equipement-' . $id);
    }
    
    // --------------------------------------------------------------
    // SUPPRESSION
    // --------------------------------------------------------------
    public function delete(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        // [SÉCURITÉ] Vérification CSRF en premier
        $this->validateCsrf($request);
        
        $id = $this->getValidId($request);
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/equipements');
        }
        
        $equipementManager = $this->equipementManager();
        $equipement = $equipementManager->findId($id);
        
        if (!$equipement) {
            $this->session->getFlashBag()->add('error', "L'équipement demandé n'existe pas.");
            return $this->redirectTo('/equipements');
        }
        
        // [SÉCURITÉ] Refuser si l'équipement est en cours de contrôle
        if (!empty($equipement['controle_en_cours'])) {
            $this->session->getFlashBag()->add(
                'error',
                "Impossible de supprimer cet équipement : un contrôle est en cours."
            );
            return $this->redirectTo("/equipements/equipement-{$id}");
        }
        
        // [MÉTIER 2026-10-21] Seul un équipement déclaré « Hors service »
        // par son dernier contrôle clôturé peut être supprimé — ou, depuis
        // la migration 017, égaré depuis le délai réglé sur la page Club.
        if (!self::suppressionAutorisee($equipement, $equipementManager->getHistoriqueControles($id), $this->dateLimiteEgare())) {
            $this->session->getFlashBag()->add(
                'error',
                sprintf(
                    "Impossible de supprimer cet équipement : il doit avoir été déclaré « Hors service » lors de son dernier contrôle clôturé, ou être égaré depuis au moins %d mois.",
                    $this->delaiSuppressionEgare()
                )
            );
            return $this->redirectTo("/equipements/equipement-{$id}");
        }
        
        $equipementManager->delete($id);
        
        $this->session->getFlashBag()->add('success', "L'équipement '{$equipement['reference']}' a été supprimé.");
        
        return $this->redirectTo('/equipements');
    }
    
    // --------------------------------------------------------------
    // PDF INDIVIDUEL
    // --------------------------------------------------------------
    public function pdf(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        // L'ID peut venir des attributs de route OU du path (regex legacy)
        $id = $this->getValidId($request);
        if ($id === null) {
            $path = $request->getPathInfo();
            if (preg_match('/\/equipements\/equipement-pdf-(\d+)/', $path, $matches)) {
                $id = filter_var($matches[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $id = $id === false ? null : $id;
            }
        }
        
        if ($id === null) {
            // URL modifiée manuellement : redirection silencieuse
            return $this->redirectTo('/equipements');
        }
        
        $equipement = $this->equipementManager()->findId($id);
        if (!$equipement) {
            $this->session->getFlashBag()->add('danger', 'Équipement non trouvé.');
            return $this->redirectTo('/equipements');
        }
        $equipement = $this->avecCategorieEtEmplacement($equipement);

        $html = $this->renderPdfHtml(
            $equipement,
            $this->qrDataUri(new QrCodeGenerator(), $id, 300),
            $this->photoDataUri($equipement['photo'] ?? null)
        );

        return $this->pdfResponse($html, 'equipement_' . $equipement['reference'] . '.pdf');
    }

    /**
    * Génère le contenu HTML du PDF individuel
    */
    private function renderPdfHtml(array $equipement, string $qrBase64, ?string $photoBase64): string
    {
        // --- Extraction sécurisée des valeurs (échappées pour le HTML) ---
        $reference = self::html($equipement['reference'] ?? '');
        $categorieLibelle = self::html($equipement['categorie']['libelle'] ?? 'Non définie');
        $estEpi = (!empty($equipement['categorie']['est_epi'])) ? 'Oui' : 'Non';
        $emplacementLibelle = self::html($equipement['emplacement']['libelle'] ?? 'Non défini');
        
        $dateMise = $equipement['date_mise_en_service'] ?? 'Non renseignée';
        if ($dateMise !== 'Non renseignée') {
            $dateMise = (new \DateTime($dateMise))->format('d/m/Y');
        }
        
        $dateFin = $equipement['date_fin_utilisation'] ?? 'Toujours en service';
        if ($dateFin !== 'Toujours en service') {
            $dateFin = (new \DateTime($dateFin))->format('d/m/Y');
        }
        
        $statut = $equipement['statut'] ?? null;
        $controleEnCours = $equipement['controle_en_cours'] ?? false;
        $statutHtml = $this->renderStatutBadge($statut, $controleEnCours);
        
        $etatUsure = self::html($equipement['etat_usure_id'] ?? 'Non défini');
        $remarques = self::html($equipement['remarques'] ?? 'Aucune');
        
        // --- Photo (4 cm max, proportions conservées) ---
        $photoHtml = $photoBase64
        ? '<img src="' . $photoBase64 . '" style="height:4cm; width:auto; max-width:4cm; border:1px solid #ccc; padding:3px;">'
        : '<span style="color:#999;">Aucune photo</span>';
        
        // --- QR Code (4 cm carré) ---
        $qrHtml = '<img src="' . $qrBase64 . '" style="width:4cm; height:4cm;">';
        
        $dateGeneration = date('d/m/Y H:i');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
            <head>
                <meta charset="UTF-8">
                <title>Fiche équipement</title>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; padding: 20px; }
                    h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
                    .info { margin: 15px 0; }
                    .label { font-weight: bold; width: 180px; display: inline-block; }
                    .row { margin: 8px 0; }
                    /* Nouveau conteneur pour photo + QR */
                    .media-container {
                        margin-top: 20px;
                        border-top: 1px solid #ddd;
                        padding-top: 20px;
                    }
                    .media-container table {
                        width: 100%;
                        border-collapse: collapse;
                    }
                    .media-container td {
                        text-align: center;
                        vertical-align: middle;
                        padding: 10px;
                    }
                    .media-container td:first-child {
                        width: 50%;
                        border-right: 1px solid #eee;
                    }
                    .media-container td:last-child {
                        width: 50%;
                    }
                    .footer { margin-top: 40px; font-size: 12px; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 10px; }
                    .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; color: #fff; font-size: 12px; }
                    .badge-success { background: #28a745; }
                    .badge-primary { background: #0d6efd; }
                    .badge-dark { background: #343a40; }
                    .badge-warning { background: #ffc107; color: #212529; }
                    .badge-danger { background: #dc3545; }
                    .badge-info { background: #17a2b8; }
                    .badge-secondary { background: #6c757d; }
                </style>
            </head>
            <body>
                <h1>Fiche équipement</h1>
                <div class="info">
                    <div class="row"><span class="label">Référence :</span> {$reference}</div>
                    <div class="row"><span class="label">Catégorie :</span> {$categorieLibelle}</div>
                    <div class="row"><span class="label">EPI :</span> {$estEpi}</div>
                    <div class="row"><span class="label">Emplacement :</span> {$emplacementLibelle}</div>
                    <div class="row"><span class="label">Date mise en service :</span> {$dateMise}</div>
                    <div class="row"><span class="label">Date fin d'utilisation :</span> {$dateFin}</div>
                    <div class="row"><span class="label">Statut :</span> {$statutHtml}</div>
                    <div class="row"><span class="label">État d'usure :</span> {$etatUsure}</div>
                    <div class="row"><span class="label">Remarques :</span> {$remarques}</div>
                </div>
                
                <!-- Photo + QR côte à côte -->
                <div class="media-container">
                    <table>
                        <tr>
                            <td>
                                <div style="font-weight:bold; margin-bottom:5px;">Photo</div>
                                {$photoHtml}
                            </td>
                            <td>
                                <div style="font-weight:bold; margin-bottom:5px;">QR Code</div>
                                {$qrHtml}
                                <div style="margin-top:5px; font-size:11px; color:#555;">Scannez pour accéder à la fiche</div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="footer">Généré le {$dateGeneration} – Epiclub</div>
            </body>
        </html>
HTML;
    }

    /**
     * Retourne le badge HTML pour le statut (utilisé dans le PDF)
     */
    private function renderStatutBadge($statut, bool $controleEnCours): string
    {
        if ($controleEnCours) {
            return '<span class="badge badge-info">En contrôle</span>';
        }
        $enum = is_numeric($statut) ? EquipementStatut::tryFrom((int) $statut) : null;
        if ($enum === null) {
            return '<span class="badge badge-secondary">Inconnu</span>';
        }
        // Classes du PDF (badge-success…) ; « primary » / « dark » pour Prêté / Égaré.
        return sprintf('<span class="badge badge-%s">%s</span>', $enum->badge(), self::html($enum->label()));
    }

    // --------------------------------------------------------------
    // PDF DE LISTE
    // --------------------------------------------------------------
    /**
     * Génère un PDF de la liste des équipements avec les filtres actuels
     */
    public function listPdf(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        // Récupération des équipements filtrés (sans pagination)
        $equipements = $this->getFilteredEquipments($request);

        // Récupération des filtres pour l'affichage
        $filtres = [
            'categorie' => $request->query->get('categorie'),
            'epi' => $request->query->get('epi'),
            'en_service' => $request->query->get('en_service'),
            'emplacement' => $request->query->get('emplacement'),
            'dernier_controle' => $request->query->get('dernier_controle'),
            'acquisition' => $request->query->get('acquisition'), // AJOUT
        ];

        // Génération du HTML pour le PDF
        $html = $this->renderPdfListHtml($equipements, $filtres);

        return $this->pdfResponse($html, 'liste_equipements.pdf');
    }

    /**
    * Génère le HTML pour le PDF de la liste des équipements
    */
    private function renderPdfListHtml(array $equipements, array $filtres): string
    {
        $clubName = 'EPIClub'; // À adapter selon votre configuration
        
        // --- Construction des libellés des filtres ---
        $filtresLabels = [];
        // findId() attend un entier : un paramètre d'URL non numérique est
        // affiché tel quel (échappé) au lieu de faire échouer le PDF.
        if (!empty($filtres['categorie'])) {
            $cat = ctype_digit((string) $filtres['categorie'])
                ? $this->categorieManager()->findId((int) $filtres['categorie'])
                : null;
            $filtresLabels[] = 'Catégorie : ' . ($cat['libelle'] ?? $filtres['categorie']);
        }
        if ($filtres['epi'] !== null && $filtres['epi'] !== '') {
            $filtresLabels[] = 'EPI : ' . ($filtres['epi'] ? 'Oui' : 'Non');
        }
        if ($filtres['en_service'] !== null && $filtres['en_service'] !== '') {
            $filtresLabels[] = 'Statut : ' . ($filtres['en_service'] === 'oui' ? 'En service' : 'Hors service');
        }
        if ($filtres['emplacement'] === 'null') {
            // Option « Non défini » du filtre de la liste
            $filtresLabels[] = 'Emplacement : Non défini';
        } elseif (!empty($filtres['emplacement'])) {
            $emp = ctype_digit((string) $filtres['emplacement'])
                ? $this->emplacementManager()->findId((int) $filtres['emplacement'])
                : null;
            $filtresLabels[] = 'Emplacement : ' . ($emp['libelle'] ?? $filtres['emplacement']);
        }
        if ($filtres['dernier_controle'] !== null && $filtres['dernier_controle'] !== '') {
            $filtresLabels[] = 'Dernier contrôle : ' . ($filtres['dernier_controle'] === 'plus_1_an' ? 'Plus d\'un an' : 'Moins d\'un an');
        }
        if (!empty($filtres['acquisition'])) { // AJOUT
            $filtresLabels[] = 'Acquisition #' . $filtres['acquisition'];
        }
        $filtresStr = self::html(empty($filtresLabels) ? 'Aucun filtre' : implode(' | ', $filtresLabels));
        
        // --- Générateur de QR code ---
        $qrGenerator = new QrCodeGenerator();

// --- Construction des lignes du tableau ---
        $rows = '';
        foreach ($equipements as $e) {
            // 1. Photo en base64 – 3cm x 3cm
            $photoBase64 = $this->photoDataUri($e['photo'] ?? null);
            $photoHtml = $photoBase64 !== null
                ? '<img src="' . $photoBase64 . '" style="width:3cm; height:3cm; object-fit:cover; border:1px solid #ddd;">'
                : '<span style="color:#ccc; font-size:10px;">—</span>';

            // 2. QR code en base64 – 3cm x 3cm
            try {
                $qrBase64 = $this->qrDataUri($qrGenerator, (int) $e['id'], 300);
                $qrHtml = '<img src="' . $qrBase64 . '" style="width:3cm; height:3cm;">';
            } catch (\Exception $ex) {
                $qrHtml = '<span style="color:#999; font-size:9px;">QR indisponible</span>';
            }
            
            $reference = self::html($e['reference'] ?? '');
            $libelle = self::html($e['libelle'] ?? '');
            $rows .= <<<ROW
        <tr>
            <td>{$reference}</td>
            <td>{$libelle}</td>
            <td style="text-align:center;">{$photoHtml}</td>
            <td style="text-align:center;">{$qrHtml}</td>
        </tr>
ROW;
        }
        
        $dateGeneration = date('d/m/Y H:i');
        $total = count($equipements);
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
            <head>
                <meta charset="UTF-8">
                <title>Liste des équipements</title>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; padding: 15px; }
                    h1 { font-size: 18px; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 8px; }
                    .header-info { font-size: 12px; margin: 10px 0; }
                    .filtres { background: #f8f9fa; padding: 8px; border-radius: 4px; margin-bottom: 15px; font-size: 11px; }
                    table { width: 100%; border-collapse: collapse; font-size: 10px; }
                    th { background: #3498db; color: white; padding: 5px 6px; text-align: left; }
                    td { padding: 4px 6px; border-bottom: 1px solid #ddd; vertical-align: middle; }
                    /* Ajustement des colonnes photo et QR */
                    th:nth-child(3), td:nth-child(3) { width: 3.2cm; text-align:center; }
                    th:nth-child(4), td:nth-child(4) { width: 3.2cm; text-align:center; }
                    .footer { margin-top: 20px; font-size: 10px; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 8px; }
                </style>
            </head>
            <body>
                <h1>{$clubName} – Liste des équipements</h1>
                <div class="header-info">
                    <div><strong>Filtres :</strong> {$filtresStr}</div>
                    <div><strong>Total :</strong> {$total} équipements</div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Référence</th>
                            <th>Nom</th>
                            <th style="text-align:center;">Photo</th>
                            <th style="text-align:center;">QR Code</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
                
                <div class="footer">Généré le {$dateGeneration} – {$clubName}</div>
            </body>
        </html>
HTML;
    }
    
    // --------------------------------------------------------------
    // EXPORT EXCEL
    // --------------------------------------------------------------
    /**
    * Exporte la liste des équipements en Excel avec photos et QR codes
    */
    public function listExcel(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        $equipements = $this->getFilteredEquipments($request);
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // En-têtes
        $headers = ['Référence', 'Nom', 'Photo', 'QR Code'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setWidth(25);
            $col = chr(ord($col) + 1); // Incrémentation sans warning
        }
        
        $headerStyle = $sheet->getStyle('A1:D1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        $row = 2;
        $qrGenerator = new QrCodeGenerator();
        $baseUrl = $this->getBaseUrl();
        $tempFiles = [];
        
        foreach ($equipements as $e) {
            $sheet->setCellValue('A' . $row, $e['reference'] ?? '');
            $sheet->setCellValue('B' . $row, $e['libelle'] ?? '');
            
            // --- Photo (colonne C) ---
            $photoPath = null;
            if (!empty($e['photo'])) {
                $fullPath = $this->getPublicPath($e['photo']);
                if (file_exists($fullPath)) {
                    $photoPath = $fullPath;
                }
            }
            if ($photoPath) {
                $drawing = new Drawing();
                $drawing->setPath($photoPath);
                $drawing->setCoordinates('C' . $row);
                $drawing->setWidth(80);
                $drawing->setHeight(80);
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(5);
                $drawing->setWorksheet($sheet);
            }
            
            // --- QR Code (colonne D) ---
            try {
                $qrUrl = $baseUrl . '/qr/' . $e['id'];
                $qrPng = $qrGenerator->generateFromData($qrUrl, 150, false);
                if ($qrPng) {
                    $tempQr = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
                    file_put_contents($tempQr, $qrPng);
                    $tempFiles[] = $tempQr;
                    $drawingQr = new Drawing();
                    $drawingQr->setPath($tempQr);
                    $drawingQr->setCoordinates('D' . $row);
                    $drawingQr->setWidth(80);
                    $drawingQr->setHeight(80);
                    $drawingQr->setOffsetX(5);
                    $drawingQr->setOffsetY(5);
                    $drawingQr->setWorksheet($sheet);
                }
            } catch (\Exception $ex) {
                // QR non disponible
            }
            
            $sheet->getRowDimension($row)->setRowHeight(90);
            $row++;
        }
        
        $response = $this->xlsxResponse($spreadsheet, 'inline; filename="liste_equipements.xlsx"');

        // Nettoyer les QR temporaires (lus à l'écriture du classeur)
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return $response;
    }

    // --------------------------------------------------------------
    // EXPORT DONNÉES (Excel / CSV) — consultation, sans images
    // --------------------------------------------------------------

    /**
     * En-têtes communs aux deux exports « données » (ordre = ordre des
     * clés des lignes de buildExportRows()).
     *
     * ⚠️ Format de consultation riche : NON destiné au réimport (le
     * fichier d'import a sa propre structure — voir le guide).
     */
    private const EXPORT_DATA_HEADERS = [
        'Référence', 'Nom', 'Catégorie', 'Emplacement', 'EPI',
        'Statut', 'Nombre', 'Mise en service', 'Dernier contrôle',
        'Fin d\'utilisation', 'N° facture', 'Date facture',
    ];

    /**
     * Construit les lignes plates de l'export données à partir des
     * équipements filtrés (avec jointures catégorie / emplacement /
     * acquisition).
     *
     * @return array<int, array<string, string|int>>
     */
    private function buildExportRows(array $equipements): array
    {
        // Chargement des acquisitions distinctes (une requête par
        // acquisition, pas par équipement).
        $acquisitionManager = $this->acquisitionManager();
        $acquisitions = [];
        foreach ($equipements as $e) {
            $aid = $e['acquisition_id'] ?? null;
            if ($aid !== null && $aid !== '' && !array_key_exists((int) $aid, $acquisitions)) {
                $acquisitions[(int) $aid] = $acquisitionManager->findId((int) $aid) ?: null;
            }
        }

        $rows = [];
        foreach ($equipements as $e) {
            $acquisition = $acquisitions[(int) ($e['acquisition_id'] ?? 0)] ?? null;
            $statut = EquipementStatut::tryFrom((int) ($e['statut'] ?? 0));

            $rows[] = [
                $e['reference'] ?? '',
                $e['libelle'] ?? '',
                $e['categorie']['libelle'] ?? '',
                $e['emplacement']['libelle'] ?? '',
                !empty($e['est_epi']) ? 'Oui' : 'Non',
                $statut?->label() ?? '',
                (int) ($e['nombre'] ?? 1),
                $e['date_mise_en_service'] ?? '',
                $e['date_dernier_controle'] ?? '',
                $e['date_fin_utilisation'] ?? '',
                $acquisition['facture_reference'] ?? '',
                $acquisition['facture_date'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Exporte la liste filtrée des équipements en Excel « données »
     * (format plat, sans photos ni QR codes).
     */
    public function listDataExcel(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $rows = $this->buildExportRows($this->getFilteredEquipments($request));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 'A';
        foreach (self::EXPORT_DATA_HEADERS as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col = chr(ord($col) + 1);
        }
        $sheet->getStyle('A1:' . chr(ord('A') + count(self::EXPORT_DATA_HEADERS) - 1) . '1')
            ->getFont()->setBold(true);

        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 'A';
            foreach ($row as $value) {
                // Préfixe texte explicite pour les références/codes : évite
                // qu'Excel ne réinterprète « 26COR001 » ou une date.
                $sheet->setCellValueExplicit(
                    $col . $rowNum,
                    $value,
                    is_int($value)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                $col = chr(ord($col) + 1);
            }
            $rowNum++;
        }

        return $this->xlsxResponse($spreadsheet, 'attachment; filename="export_equipements_' . date('Ymd_His') . '.xlsx"');
    }

    /**
     * Exporte la liste filtrée des équipements en CSV « données ».
     *
     * BOM UTF-8 + séparateur « ; » : lisible directement dans Excel
     * configuré en français.
     */
    public function listDataCsv(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $rows = $this->buildExportRows($this->getFilteredEquipments($request));

        $fp = fopen('php://temp', 'r+');
        // BOM UTF-8 pour Excel
        fwrite($fp, "\xEF\xBB\xBF");
        // escape: '' : guillemets doublés seulement (RFC 4180), sans
        // caractère d'échappement « \ » propre à PHP — et sans la
        // dépréciation PHP 8.4+ quand $escape est omis.
        fputcsv($fp, self::EXPORT_DATA_HEADERS, ';', escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';', escape: '');
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="export_equipements_' . date('Ymd_His') . '.csv"',
        ]);
    }
    
    // --------------------------------------------------------------
    // ÉTIQUETTES
    // --------------------------------------------------------------
    /**
    * Génère une planche d'étiquettes au format Avery 5160
    */
    public function etiquettesPdf(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        // Récupération des équipements filtrés
        $equipements = $this->getFilteredEquipments($request);
        
        // Génération du HTML
        $html = $this->renderEtiquettesHtml($equipements);
        
        return $this->pdfResponse($html, 'etiquettes_avery5160.pdf');
    }

    /**
    * Génère le HTML pour la planche d'étiquettes Avery 5160
    */
    private function renderEtiquettesHtml(array $equipements): string
    {
        $qrGenerator = new QrCodeGenerator();

        $etiquettesHtml = '';
        foreach ($equipements as $e) {
            $qrBase64 = $this->qrDataUri($qrGenerator, (int) $e['id'], 240);
            $reference = self::html($e['reference'] ?? '');
            $libelle = self::html($e['libelle'] ?? '');

            $etiquettesHtml .= <<<LABEL
        <div class="etiquette">
            <div class="etiquette-content">
                <div class="qr-code">
                    <img src="{$qrBase64}" alt="QR Code">
                </div>
                <div class="infos">
                    <div class="reference">{$reference}</div>
                    <div class="libelle">{$libelle}</div>
                </div>
            </div>
        </div>
LABEL;
        }

        if (empty($etiquettesHtml)) {
            $etiquettesHtml = '<div class="empty-message">Aucun équipement à étiqueter.</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Étiquettes Avery 5160</title>
    <style>
        @page { margin: 1.8cm 0.5cm 1.8cm 0.5cm; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; padding: 0; background: #fff; }
        .etiquette { display: inline-block; width: 63.5mm; height: 29.6mm; margin: 0; padding: 0; border: none; box-sizing: border-box; vertical-align: top; border: 0.5px dashed #e0e0e0; page-break-inside: avoid; }
        .etiquette-content { display: table; width: 100%; height: 100%; padding: 1.5mm 1.5mm; box-sizing: border-box; }
        .qr-code { display: table-cell; vertical-align: middle; width: 18mm; height: 18mm; text-align: center; padding-right: 2mm; }
        .qr-code img { width: 18mm; height: 18mm; object-fit: contain; }
        .infos { display: table-cell; vertical-align: middle; padding-left: 1mm; }
        .reference { font-weight: bold; font-size: 8.5pt; color: #2c3e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 1px; }
        .libelle { font-size: 7.5pt; color: #34495e; font-weight: normal; white-space: normal; word-break: normal; overflow-wrap: break-word; line-height: 1.3; }
        .empty-message { text-align: center; padding: 50px; font-size: 16px; color: #999; }
    </style>
</head>
<body>
    {$etiquettesHtml}
</body>
</html>
HTML;
    }

    // --------------------------------------------------------------
    // MODIFICATION GROUPÉE
    // --------------------------------------------------------------

    /** Nombre maximal d'équipements traités en une fois. */
    private const GROUPE_MAX = 500;

    /** Champs modifiables en groupe (le libellé : super-admin seulement). */
    private const CHAMPS_GROUPE = [
        'statut', 'emplacement_id', 'date_mise_en_service', 'date_fin_utilisation',
        'duree_max_utilisation', 'remarques', 'photo', 'libelle',
    ];

    /**
     * Modification groupée des équipements cochés dans la liste
     * (2026-10-20). Chaque champ n'est appliqué que si sa case
     * « Modifier » est cochée ; les champs proposés sont ceux que le
     * rôle connecté peut modifier dans la fiche équipement.
     *
     *  - GET  : formulaire (ids[] en paramètres) ;
     *  - POST : validation complète puis application (tout ou rien sur
     *    les erreurs de saisie). La date de mise en service suit les
     *    règles de la fiche, équipement par équipement : ceux qui ne
     *    peuvent pas la recevoir sont ignorés et listés.
     */
    public function modificationGroupee(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $estPost = $request->getMethod() === 'POST';
        if ($estPost) {
            $this->validateCsrf($request);
        }
        $source = $estPost ? $request->request : $request->query;
        $retourQuery = (string) $source->get('retour', '');
        $retour = self::urlRetourListe($retourQuery);
        $superAdmin = $this->isGranted('ROLE_SUPER_ADMIN');

        if ($estPost && $this->isPostTruncated($request)) {
            $this->session->getFlashBag()->add('error', sprintf(
                'Le fichier est trop volumineux pour être traité par le serveur (limite PHP : %s).',
                ini_get('post_max_size')
            ));
            return $this->redirectTo($retour);
        }

        $ids = self::idsSelectionnes($source);
        if ($ids === []) {
            $this->session->getFlashBag()->add('error', 'Aucun équipement sélectionné.');
            return $this->redirectTo($retour);
        }
        if (count($ids) > self::GROUPE_MAX) {
            $this->session->getFlashBag()->add('error', sprintf(
                'Sélection trop importante (%d équipements maximum).',
                self::GROUPE_MAX
            ));
            return $this->redirectTo($retour);
        }

        $equipementManager = $this->equipementManager();
        $equipements = $equipementManager->findByIds($ids);
        if ($equipements === []) {
            $this->session->getFlashBag()->add('error', 'Aucun des équipements sélectionnés n\'existe.');
            return $this->redirectTo($retour);
        }

        $emplacements = $this->emplacementManager()->findAll() ?: [];
        $form_errors = [];
        $saisie = [];

        if ($estPost) {
            $appliquer = $request->request->all('appliquer');
            $appliquer = is_array($appliquer) ? $appliquer : [];
            $actifs = array_values(array_filter(
                self::CHAMPS_GROUPE,
                fn(string $c) => !empty($appliquer[$c]) && ($c !== 'libelle' || $superAdmin)
            ));
            $saisie = ['appliquer' => array_fill_keys($actifs, true)] + $request->request->all();

            if ($actifs === []) {
                $form_errors['general'] = 'Cochez « Modifier » pour au moins un champ.';
            }

            $valeurs = [];
            foreach ($actifs as $champ) {
                $brut = $request->request->get($champ);
                switch ($champ) {
                    case 'statut':
                        $statut = is_string($brut) && ctype_digit($brut) ? EquipementStatut::tryFrom((int) $brut) : null;
                        if ($statut === null) {
                            $form_errors['statut'] = 'Statut invalide.';
                        } elseif ($statut->estReserve()) {
                            $form_errors['statut'] = $statut->messageAttribution();
                        } else {
                            $valeurs['statut'] = $statut->value;
                        }
                        break;
                    case 'emplacement_id':
                        $idsEmplacements = array_map('intval', array_column($emplacements, 'id'));
                        if ($brut === '' || $brut === null) {
                            $valeurs['emplacement_id'] = null;
                        } elseif (is_string($brut) && ctype_digit($brut) && in_array((int) $brut, $idsEmplacements, true)) {
                            $valeurs['emplacement_id'] = (int) $brut;
                        } else {
                            $form_errors['emplacement_id'] = 'Emplacement invalide.';
                        }
                        break;
                    case 'date_fin_utilisation':
                    case 'date_mise_en_service':
                        $brut = trim((string) $brut);
                        if ($brut === '' && $champ === 'date_fin_utilisation') {
                            $valeurs[$champ] = null;
                        } elseif (self::dateValide($brut)) {
                            $valeurs[$champ] = $brut;
                        } else {
                            $form_errors[$champ] = 'Date invalide.';
                        }
                        break;
                    case 'duree_max_utilisation':
                        $valeurs['duree_max_utilisation'] = $brut === '1' ? 1 : 0;
                        break;
                    case 'remarques':
                        $valeurs['remarques'] = trim((string) $brut);
                        $valeurs['remarques_mode'] = $request->request->get('remarques_mode') === 'ajouter' ? 'ajouter' : 'remplacer';
                        break;
                    case 'libelle':
                        $libelle = trim((string) $brut);
                        if (($erreur = self::erreurLibelle($libelle)) !== null) {
                            $form_errors['libelle'] = $erreur;
                        } else {
                            $valeurs['libelle'] = $libelle;
                        }
                        break;
                    case 'photo':
                        $fichier = $request->files->get('photo');
                        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
                            $form_errors['photo'] = 'Choisissez une photo à appliquer.';
                        } else {
                            $valeurs['photo'] = $fichier;
                        }
                        break;
                }
            }

            if ($form_errors === [] && isset($valeurs['photo'])) {
                try {
                    $valeurs['photo'] = $this->savePhoto($valeurs['photo']);
                } catch (\InvalidArgumentException $e) {
                    $form_errors['photo'] = $e->getMessage();
                } catch (\Throwable $e) {
                    error_log('[EquipementController] Modification groupée (photo) : ' . $e->getMessage());
                    $form_errors['photo'] = self::MESSAGE_PHOTO_ILLISIBLE;
                }
            }

            if ($form_errors === []) {
                return $this->appliquerModificationGroupee($equipements, $valeurs, $superAdmin, $retour);
            }
        }

        return $this->render('equipement_modification_groupee.twig', [
            'equipements' => $equipements,
            'emplacements' => $emplacements,
            'equipement_statuts' => EquipementStatut::forSelectManuel(),
            'super_admin' => $superAdmin,
            'retour_query' => $retourQuery,
            'retour_url' => $retour,
            'saisie' => $saisie,
            'form_errors' => $form_errors,
        ]);
    }

    /**
     * Applique les valeurs validées à chaque équipement sélectionné.
     *
     * @param array<int, array<string, mixed>> $equipements
     * @param array<string, mixed>             $valeurs
     */
    private function appliquerModificationGroupee(array $equipements, array $valeurs, bool $superAdmin, string $retour): Response
    {
        $equipementManager = $this->equipementManager();
        $datesFacture = [];
        $ignores = [];
        $horsService = [];
        $anciennesPhotos = [];
        $modifies = 0;

        foreach ($equipements as $equipement) {
            $data = ['id' => (int) $equipement['id']];

            foreach (['statut', 'emplacement_id', 'date_fin_utilisation', 'duree_max_utilisation', 'libelle', 'photo'] as $champ) {
                if (array_key_exists($champ, $valeurs)) {
                    $data[$champ] = $valeurs[$champ];
                }
            }

            // [MÉTIER 2026-10-21] Un équipement hors service ne quitte cet
            // état que par un contrôle : son statut n'est pas modifié.
            // [Prêt de matériel] Idem pour « Prêté » et « Égaré » (statuts réservés).
            if (array_key_exists('statut', $data)
                && (EquipementStatut::tryFrom((int) ($equipement['statut'] ?? 0))?->estReserve() ?? false)
            ) {
                unset($data['statut']);
                $horsService[] = $equipement['reference'] ?? '#' . $equipement['id'];
            }

            if (array_key_exists('remarques', $valeurs)) {
                $actuelles = trim((string) ($equipement['remarques'] ?? ''));
                $data['remarques'] = ($valeurs['remarques_mode'] === 'ajouter' && $actuelles !== '')
                    ? ($valeurs['remarques'] === '' ? $actuelles : $actuelles . "\n" . $valeurs['remarques'])
                    : ($valeurs['remarques'] === '' ? null : $valeurs['remarques']);
            }

            // Date de mise en service : mêmes règles que la fiche.
            if (array_key_exists('date_mise_en_service', $valeurs)) {
                $aid = (int) ($equipement['acquisition_id'] ?? 0);
                if (!array_key_exists($aid, $datesFacture)) {
                    $datesFacture[$aid] = self::dateFacture($aid > 0 ? $this->acquisitionManager()->findId($aid) : null);
                }
                $dateFacture = $datesFacture[$aid];
                $dms = $valeurs['date_mise_en_service'];

                if (!$superAdmin && self::dateMiseEnServiceVerrouillee($equipement, $dateFacture)) {
                    $ignores[] = ($equipement['reference'] ?? '#' . $equipement['id']) . ' (date déjà modifiée)';
                } elseif ($dateFacture !== null && $dms < $dateFacture) {
                    $ignores[] = ($equipement['reference'] ?? '#' . $equipement['id'])
                        . ' (facture du ' . date('d/m/Y', strtotime($dateFacture)) . ')';
                } else {
                    $data['date_mise_en_service'] = $dms;
                }
            }

            if (count($data) > 1) {
                if (isset($data['photo']) && !empty($equipement['photo']) && $equipement['photo'] !== $data['photo']) {
                    $anciennesPhotos[$equipement['photo']] = true;
                }
                $equipementManager->save($data);
                $modifies++;
            }
        }

        foreach (array_keys($anciennesPhotos) as $ancienne) {
            $this->supprimerPhotoSiOrpheline($ancienne);
        }

        $this->session->getFlashBag()->add('success', sprintf(
            '%d équipement%s modifié%s.',
            $modifies,
            $modifies > 1 ? 's' : '',
            $modifies > 1 ? 's' : ''
        ));
        if ($ignores !== []) {
            $this->session->getFlashBag()->add('warning',
                'Date de mise en service non modifiée pour : ' . implode(', ', $ignores) . '.');
        }
        if ($horsService !== []) {
            $this->session->getFlashBag()->add('warning',
                'Statut non modifié pour : ' . implode(', ', $horsService)
                . ' (hors service, prêté ou égaré : statut fixé par un contrôle ou un prêt).');
        }

        return $this->redirectTo($retour);
    }

    /** @return int[] ids[] valides et dédoublonnés */
    private static function idsSelectionnes(\Symfony\Component\HttpFoundation\InputBag $source): array
    {
        try {
            $brut = $source->all('ids');
        } catch (\Throwable $e) {
            $brut = [];
        }
        $ids = [];
        foreach ($brut as $valeur) {
            if (is_string($valeur) && ctype_digit($valeur) && (int) $valeur > 0) {
                $ids[(int) $valeur] = (int) $valeur;
            }
        }
        return array_values($ids);
    }

    private static function dateValide(string $valeur): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return $date !== false && $date->format('Y-m-d') === $valeur;
    }

    /**
     * URL de retour vers la liste en conservant filtres / tri / page.
     * Le chemin est fixe : seuls les paramètres de requête sont repris
     * (reconstruits, sans « action » pour ne pas relancer un export).
     */
    private static function urlRetourListe(string $query): string
    {
        parse_str(ltrim($query, '?'), $params);
        unset($params['action'], $params['export_type']);
        $params = array_filter($params, 'is_string');
        return $params === [] ? '/equipements' : '/equipements?' . http_build_query($params);
    }

    // --------------------------------------------------------------
    // UTILITAIRES
    // --------------------------------------------------------------

    private const MESSAGE_PHOTO_ILLISIBLE = 'Erreur lors du traitement de l\'image. '
        . 'Vérifiez que le fichier est bien une image (JPEG, PNG, WebP, GIF, AVIF).';

    /** Échappe une valeur insérée dans le HTML des PDF (Dompdf). */
    private static function html(mixed $valeur): string
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Message d'erreur du libellé, ou null s'il est recevable. */
    private static function erreurLibelle(string $libelle): ?string
    {
        if ($libelle === '') {
            return 'Le libellé est obligatoire.';
        }
        if (mb_strlen($libelle) > 255) {
            return 'Le libellé ne doit pas dépasser 255 caractères.';
        }
        return null;
    }

    /** [MÉTIER] Date de facture (Y-m-d) d'une acquisition ; null si inconnue. */
    private static function dateFacture(array|false|null $acquisition): ?string
    {
        return !empty($acquisition['facture_date'])
            ? date('Y-m-d', strtotime($acquisition['facture_date']))
            : null;
    }

    /** Ajoute les lignes catégorie et emplacement liées à l'équipement. */
    private function avecCategorieEtEmplacement(array $equipement): array
    {
        if (!empty($equipement['categorie_id'])) {
            $equipement['categorie'] = $this->categorieManager()->findId($equipement['categorie_id']);
        }
        if (!empty($equipement['emplacement_id'])) {
            $equipement['emplacement'] = $this->emplacementManager()->findId($equipement['emplacement_id']);
        }
        return $equipement;
    }

    /** QR code (PNG en data URI) pointant vers /qr/{id}. */
    private function qrDataUri(QrCodeGenerator $qrGenerator, int $id, int $taille): string
    {
        $png = $qrGenerator->generateFromData($this->getBaseUrl() . '/qr/' . $id, $taille, false);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** Photo de l'équipement en data URI ; null si absente du disque. */
    private function photoDataUri(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }
        $chemin = $this->getPublicPath($photo);
        if (!file_exists($chemin)) {
            return null;
        }
        return 'data:' . mime_content_type($chemin) . ';base64,' . base64_encode(file_get_contents($chemin));
    }

    /** Rend le HTML en PDF A4 portrait (Dompdf, ressources distantes interdites). */
    private function pdfResponse(string $html, string $filename): Response
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function xlsxResponse(Spreadsheet $spreadsheet, string $disposition): Response
    {
        $tempExcel = tempnam(sys_get_temp_dir(), 'excel_');
        (new Xlsx($spreadsheet))->save($tempExcel);

        $response = new Response(file_get_contents($tempExcel));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Length', filesize($tempExcel));
        unlink($tempExcel);

        return $response;
    }

    /** Efface le fichier photo s'il n'est plus utilisé par aucun équipement. */
    private function supprimerPhotoSiOrpheline(string $photo): void
    {
        try {
            if ($this->equipementManager()->countPhotoReferences($photo) > 0) {
                return;
            }
        } catch (\Throwable $e) {
            // Dans le doute, on conserve le fichier.
            error_log('[EquipementController] countPhotoReferences : ' . $e->getMessage());
            return;
        }
        $chemin = $this->getPublicPath(self::UPLOAD_DIR . basename($photo));
        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }

    /**
     * Photo d'équipement : contrôles, orientation EXIF, redimensionnement
     * (1000 px max), réencodage WebP — délégués à ImageUploader, comme les
     * images de catégorie et du club. Surchargeable en test Unit.
     *
     * @return string Chemin public (ex. /images/equipements/equipement-….webp)
     */
    protected function savePhoto(UploadedFile $file): string
    {
        return self::UPLOAD_DIR . $this->photoUploader()->save($file, 'equipement');
    }

    protected function photoUploader(): ImageUploader
    {
        return new ImageUploader($this->getPublicPath(self::UPLOAD_DIR));
    }
}