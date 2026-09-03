<?php
    
namespace Epiclub\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\AcquisitionManager;  // AJOUT
use Epiclub\Enum\EquipementEtats;
use Epiclub\Enum\EquipementStatuts;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    
    // --------------------------------------------------------------
    // LISTE (avec pagination et filtres)
    // --------------------------------------------------------------
    public function list(Request $request)
    {
        // --- Gestion du menu déroulant d'export ---
        if ($request->query->get('action') === 'export') {
            $type = $request->query->get('export_type', 'pdf');
            switch ($type) {
                case 'pdf':
                    return $this->listPdf($request);
                case 'excel':
                    return $this->listExcel($request);
                case 'etiquettes':
                    return $this->etiquettesPdf($request);
                default:
                    return $this->listPdf($request);
            }
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

        // Construction des paramètres pour les URLs de pagination
        $baseParams = [
            'categorie' => $request->query->get('categorie'),
            'epi' => $request->query->get('epi'),
            'en_service' => $request->query->get('en_service'),
            'emplacement' => $request->query->get('emplacement'),
            'dernier_controle' => $request->query->get('dernier_controle'),
            'order_by' => $request->query->get('order_by', 'categorie'),
            'order_dir' => $request->query->get('order_dir', 'asc'),
            'limit' => $limit,
            'acquisition' => $request->query->get('acquisition'), // AJOUT
        ];
        // Nettoyer les valeurs vides pour l'URL
        $baseParams = array_filter($baseParams, function($v) {
            return $v !== null && $v !== '';
        });

        $paginationUrls = [
            'first' => '?' . http_build_query(array_merge($baseParams, ['page' => 1])),
            'previous' => '?' . http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)])),
            'next' => '?' . http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])),
            'last' => '?' . http_build_query(array_merge($baseParams, ['page' => $totalPages])),
        ];

        // Chargement des catégories et emplacements pour le formulaire de filtre
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        $categories = $categorieManager->findAll();
        $emplacements = $emplacementManager->findAll();

        // Récupération des filtres pour l'affichage
        $filtres = [
            'categorie_id' => $request->query->get('categorie'),
            'epi' => $request->query->get('epi'),
            'en_service' => $request->query->get('en_service'),
            'emplacement_id' => $request->query->get('emplacement'),
            'dernier_controle' => $request->query->get('dernier_controle'),
            'order_by' => $request->query->get('order_by', 'categorie'),
            'order_dir' => $request->query->get('order_dir', 'asc'),
            'page' => $page,
            'limit' => $limit,
            'acquisition' => $request->query->get('acquisition'), // AJOUT
        ];

        return $this->render('equipement_list.twig', [
            'equipements' => $equipements,
            'categories' => $categories,
            'emplacements' => $emplacements,
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
            'acquisition_id' => $request->query->get('acquisition'), // AJOUT pour affichage
        ]);
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
        $equipementManager = new EquipementManager();
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        
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
    public function show(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $this->session->getFlashBag()->add('danger', 'Identifiant de l\'équipement manquant.');
            return $this->redirectTo('/equipements');
        }
        
        $equipementManager = new EquipementManager();
        $equipement = $equipementManager->findId((int)$id);
        
        if (!$equipement) {
            $this->session->getFlashBag()->add('danger', 'Équipement non trouvé.');
            return $this->redirectTo('/equipements');
        }
        
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        
        if (!empty($equipement['categorie_id'])) {
            $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
        }
        if (!empty($equipement['emplacement_id'])) {
            $equipement['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
        }
        
        $historiqueControles = $equipementManager->getHistoriqueControles((int)$id);
        
        // Charger l'acquisition pour la facture
        $acquisition = null;
        if (!empty($equipement['acquisition_id'])) {
            $acquisitionManager = new AcquisitionManager();
            $acquisition = $acquisitionManager->findId($equipement['acquisition_id']);
        }
        
        return $this->render('equipement_detail.twig', [
            'equipement' => $equipement,
            'historiqueControles' => $historiqueControles,
            'acquisition' => $acquisition,
        ]);
    }
    
    // --------------------------------------------------------------
    // ÉDITION
    // --------------------------------------------------------------
    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        $equipementManager = new EquipementManager();
        $equipement = [];
        $form_errors = [];
        
        if ($id = $request->get('id')) {
            $equipement = $equipementManager->findId($id);
            if ($equipement && isset($equipement['categorie_id'])) {
                $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
            }
        }
        
        if ($request->getMethod() === 'POST') {
            $emplacement_id = $request->request->get('emplacement_id');
            if ($emplacement_id === '') $emplacement_id = null;
            
            $date_mise_en_service = $request->request->get('date_mise_en_service');
            if ($date_mise_en_service === '') $date_mise_en_service = null;
            
            $date_fin_utilisation = $request->request->get('date_fin_utilisation');
            if ($date_fin_utilisation === '') $date_fin_utilisation = null;
            
            $photoPath = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                try {
                    $photoPath = $this->resizeAndSaveImage($_FILES['photo']);
                } catch (\Exception $e) {
                    $form_errors[] = 'Erreur lors du traitement de l\'image : ' . $e->getMessage();
                }
            }
            
            if (empty($form_errors)) {
                $equipementData = [
                    'statut_id' => $request->request->get('statut_id'),
                    'etat_usure_id' => $request->request->get('etat_usure_id'),
                    'emplacement_id' => $emplacement_id,
                    'remarques' => $request->request->get('remarques'),
                    'date_mise_en_service' => $date_mise_en_service,
                    'date_fin_utilisation' => $date_fin_utilisation,
                ];
                
                if ($photoPath !== null) {
                    if (!empty($equipement['photo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $equipement['photo'])) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $equipement['photo']);
                    }
                    $equipementData['photo'] = $photoPath;
                }
                
                $equipement = array_merge($equipement, $equipementData);
                $equipementManager->save($equipement);
                return $this->redirectTo("/equipements");
            }
        }
        
        return $this->render('equipement_form.twig', [
            'categories' => $categorieManager->findAll(),
            'emplacements' => $emplacementManager->findAll(),
            'equipement_statuts' => EquipementStatuts::forSelect(),
            'equipement_etats' => EquipementEtats::forSelect(),
            'equipement' => $equipement,
            'form_errors' => $form_errors
        ]);
    }
    
    // --------------------------------------------------------------
    // SUPPRESSION (non implémentée)
    // --------------------------------------------------------------
    public function delete(Request $request)
    {
        throw new \Exception("Error Processing Request", 1);
    }
    
    // --------------------------------------------------------------
    // PDF INDIVIDUEL
    // --------------------------------------------------------------
    public function pdf(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        $id = $request->attributes->get('id');
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/equipements\/equipement-pdf-(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        if (!$id) {
            throw new \Exception('ID manquant');
        }
        
        $equipementManager = new EquipementManager();
        $equipement = $equipementManager->findId((int)$id);
        if (!$equipement) {
            throw new \Exception('Équipement non trouvé pour l\'ID : ' . $id);
        }
        
        $categorieManager = new CategorieManager();
        $emplacementManager = new EmplacementManager();
        if (!empty($equipement['categorie_id'])) {
            $equipement['categorie'] = $categorieManager->findId($equipement['categorie_id']);
        }
        if (!empty($equipement['emplacement_id'])) {
            $equipement['emplacement'] = $emplacementManager->findId($equipement['emplacement_id']);
        }
        
        $qrGenerator = new QrCodeGenerator();
        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;
        $qrPngBinary = $qrGenerator->generateFromData($qrUrl, 300, false);
        $qrBase64 = 'data:image/png;base64,' . base64_encode($qrPngBinary);
        
        $photoBase64 = null;
        if (!empty($equipement['photo'])) {
            $photoPath = $_SERVER['DOCUMENT_ROOT'] . $equipement['photo'];
            if (file_exists($photoPath)) {
                $imageData = file_get_contents($photoPath);
                $mime = mime_content_type($photoPath);
                $photoBase64 = 'data:' . $mime . ';base64,' . base64_encode($imageData);
            }
        }
        
        $html = $this->renderPdfHtml($equipement, $qrBase64, $photoBase64);
        
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $output = $dompdf->output();
        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="equipement_' . $equipement['reference'] . '.pdf"'
        ]);
    }

    /**
    * Génère le contenu HTML du PDF individuel
    */
    private function renderPdfHtml(array $equipement, string $qrBase64, ?string $photoBase64): string
    {
        // --- Extraction sécurisée des valeurs ---
        $reference = $equipement['reference'] ?? '';
        $code = $equipement['code'] ?? '';
        $categorieLibelle = $equipement['categorie']['libelle'] ?? 'Non définie';
        $estEpi = (!empty($equipement['categorie']['est_epi'])) ? 'Oui' : 'Non';
        $emplacementLibelle = $equipement['emplacement']['libelle'] ?? 'Non défini';
        
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
        
        $etatUsure = $equipement['etat_usure_id'] ?? 'Non défini';
        $remarques = $equipement['remarques'] ?? 'Aucune';
        
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
                    <div class="row"><span class="label">Code :</span> {$code}</div>
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
        switch ($statut) {
            case 0: return '<span class="badge badge-success">Disponible</span>';
            case 1: return '<span class="badge badge-warning">En maintenance</span>';
            case 2: return '<span class="badge badge-danger">Hors service</span>';
            default: return '<span class="badge badge-secondary">Inconnu</span>';
        }
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

        // Configuration Dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="liste_equipements.pdf"'
        ]);
    }

    /**
    * Génère le HTML pour le PDF de la liste des équipements
    */
    private function renderPdfListHtml(array $equipements, array $filtres): string
    {
        $clubName = 'EPIClub'; // À adapter selon votre configuration
        
        // --- Construction des libellés des filtres ---
        $filtresLabels = [];
        if (!empty($filtres['categorie'])) {
            $categorieManager = new CategorieManager();
            $cat = $categorieManager->findId($filtres['categorie']);
            $filtresLabels[] = 'Catégorie : ' . ($cat['libelle'] ?? $filtres['categorie']);
        }
        if ($filtres['epi'] !== null && $filtres['epi'] !== '') {
            $filtresLabels[] = 'EPI : ' . ($filtres['epi'] ? 'Oui' : 'Non');
        }
        if ($filtres['en_service'] !== null && $filtres['en_service'] !== '') {
            $filtresLabels[] = 'Statut : ' . ($filtres['en_service'] === 'oui' ? 'En service' : 'Hors service');
        }
        if (!empty($filtres['emplacement'])) {
            $emplacementManager = new EmplacementManager();
            $emp = $emplacementManager->findId($filtres['emplacement']);
            $filtresLabels[] = 'Emplacement : ' . ($emp['libelle'] ?? $filtres['emplacement']);
        }
        if ($filtres['dernier_controle'] !== null && $filtres['dernier_controle'] !== '') {
            $filtresLabels[] = 'Dernier contrôle : ' . ($filtres['dernier_controle'] === 'plus_1_an' ? 'Plus d\'un an' : 'Moins d\'un an');
        }
        if (!empty($filtres['acquisition'])) { // AJOUT
            $filtresLabels[] = 'Acquisition #' . $filtres['acquisition'];
        }
        $filtresStr = empty($filtresLabels) ? 'Aucun filtre' : implode(' | ', $filtresLabels);
        
        // --- Générateur de QR code ---
        $qrGenerator = new QrCodeGenerator();
        $baseUrl = $this->getBaseUrl();
        
        // --- Construction des lignes du tableau ---
        $rows = '';
        foreach ($equipements as $e) {
            // 1. Photo en base64 – 3cm x 3cm
            $photoHtml = '';
            if (!empty($e['photo'])) {
                $photoPath = $_SERVER['DOCUMENT_ROOT'] . $e['photo'];
                if (file_exists($photoPath)) {
                    $imageData = file_get_contents($photoPath);
                    $mime = mime_content_type($photoPath);
                    $photoBase64 = 'data:' . $mime . ';base64,' . base64_encode($imageData);
                    $photoHtml = '<img src="' . $photoBase64 . '" style="width:3cm; height:3cm; object-fit:cover; border:1px solid #ddd;">';
                }
            }
            if (empty($photoHtml)) {
                $photoHtml = '<span style="color:#ccc; font-size:10px;">—</span>';
            }
            
            // 2. QR code en base64 – 3cm x 3cm
            $qrUrl = $baseUrl . '/qr/' . $e['id'];
            try {
                $qrPngBinary = $qrGenerator->generateFromData($qrUrl, 300, false);
                $qrBase64 = 'data:image/png;base64,' . base64_encode($qrPngBinary);
                $qrHtml = '<img src="' . $qrBase64 . '" style="width:3cm; height:3cm;">';
            } catch (\Exception $ex) {
                $qrHtml = '<span style="color:#999; font-size:9px;">QR indisponible</span>';
            }
            
            $rows .= <<<ROW
        <tr>
            <td>{$e['reference']}</td>
            <td>{$e['code']}</td>
            <td>{$e['libelle']}</td>
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
                    th:nth-child(4), td:nth-child(4) { width: 3.2cm; text-align:center; }
                    th:nth-child(5), td:nth-child(5) { width: 3.2cm; text-align:center; }
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
                            <th>Code</th>
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
        $headers = ['Référence', 'Code', 'Nom', 'Photo', 'QR Code'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setWidth(25);
            $col = chr(ord($col) + 1); // Incrémentation sans warning
        }
        
        $headerStyle = $sheet->getStyle('A1:E1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        $row = 2;
        $qrGenerator = new QrCodeGenerator();
        $baseUrl = $this->getBaseUrl();
        $tempFiles = [];
        
        foreach ($equipements as $e) {
            $sheet->setCellValue('A' . $row, $e['reference'] ?? '');
            $sheet->setCellValue('B' . $row, $e['code'] ?? '');
            $sheet->setCellValue('C' . $row, $e['libelle'] ?? '');
            
            // --- Photo (colonne D) ---
            $photoPath = null;
            if (!empty($e['photo'])) {
                $fullPath = $_SERVER['DOCUMENT_ROOT'] . $e['photo'];
                if (file_exists($fullPath)) {
                    $photoPath = $fullPath;
                }
            }
            if ($photoPath) {
                $drawing = new Drawing();
                $drawing->setPath($photoPath);
                $drawing->setCoordinates('D' . $row);
                $drawing->setWidth(80);
                $drawing->setHeight(80);
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(5);
                $drawing->setWorksheet($sheet);
            }
            
            // --- QR Code (colonne E) ---
            try {
                $qrUrl = $baseUrl . '/qr/' . $e['id'];
                $qrPng = $qrGenerator->generateFromData($qrUrl, 150, false);
                if ($qrPng) {
                    $tempQr = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
                    file_put_contents($tempQr, $qrPng);
                    $tempFiles[] = $tempQr;
                    $drawingQr = new Drawing();
                    $drawingQr->setPath($tempQr);
                    $drawingQr->setCoordinates('E' . $row);
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
        
        // Génération du fichier Excel
        $writer = new Xlsx($spreadsheet);
        $tempExcel = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($tempExcel);
        
        // Nettoyer les QR temporaires
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        $response = new Response(file_get_contents($tempExcel));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'inline; filename="liste_equipements.xlsx"');
        $response->headers->set('Content-Length', filesize($tempExcel));
        unlink($tempExcel);
        
        return $response;
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
        
        // Configuration Dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $output = $dompdf->output();
        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquettes_avery5160.pdf"'
        ]);
    }

    /**
    * Génère le HTML pour la planche d'étiquettes Avery 5160
    */
    private function renderEtiquettesHtml(array $equipements): string
    {
        $qrGenerator = new QrCodeGenerator();
        $baseUrl = $this->getBaseUrl();

        $etiquettesHtml = '';
        foreach ($equipements as $e) {
            $qrUrl = $baseUrl . '/qr/' . $e['id'];
            $qrPng = $qrGenerator->generateFromData($qrUrl, 240, false);
            $qrBase64 = 'data:image/png;base64,' . base64_encode($qrPng);

            $etiquettesHtml .= <<<LABEL
        <div class="etiquette">
            <div class="etiquette-content">
                <div class="qr-code">
                    <img src="{$qrBase64}" alt="QR Code">
                </div>
                <div class="infos">
                    <div class="reference">{$e['reference']}</div>
                    <div class="libelle">{$e['libelle']}</div>
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
    // UTILITAIRES
    // --------------------------------------------------------------
    /**
     * Récupère l'URL de base (copiée depuis QrRedirectController)
     */
    private function getBaseUrl(): string
    {
        $configFile = __DIR__ . '/../../.env.local.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['ROOT_URL']) && !empty($config['ROOT_URL'])) {
                return rtrim($config['ROOT_URL'], '/');
            }
        }
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $protocol . $host . $basePath;
    }

    /**
     * Redimensionne et sauvegarde une image
     */
    private function resizeAndSaveImage(array $file): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mimeType, $allowedTypes, true)) {
            throw new \Exception("Type de fichier non autorisé. Seuls JPEG, PNG, WebP et GIF sont acceptés.");
        }

        $image = imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($image === false) {
            throw new \Exception("Impossible de décoder l'image. Fichier corrompu ou format non supporté.");
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        $maxDimension = 1000;
        $ratio = min($maxDimension / $originalWidth, $maxDimension / $originalHeight, 1);
        $newWidth = (int) ($originalWidth * $ratio);
        $newHeight = (int) ($originalHeight * $ratio);

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . self::UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = uniqid() . '.webp';
        $filepath = $uploadDir . $filename;

        $saved = imagewebp($resizedImage, $filepath, 80);
        if (!$saved) {
            $filename = uniqid() . '.jpg';
            $filepath = $uploadDir . $filename;
            imagejpeg($resizedImage, $filepath, 85);
        }

        imagedestroy($image);
        imagedestroy($resizedImage);

        return self::UPLOAD_DIR . $filename;
    }
}