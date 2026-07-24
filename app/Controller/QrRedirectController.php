<?php

namespace Epiclub\Controller;

use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\QrCodeGenerator;
use Epiclub\Engine\QrCodeReader;
use Epiclub\Engine\TwigRenderer;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

class QrRedirectController extends AbstractController
{
    private EquipementManager $equipementManager;
    private ControleManager $controleManager;
    private ControleLigneManager $controleLigneManager;
    private UtilisateurManager $utilisateurManager;
    private QrCodeGenerator $qrCodeGenerator;
    private QrCodeReader $qrCodeReader;
    
    public function __construct(Session $session)
    {
        parent::__construct($session);
        
        $this->equipementManager = new EquipementManager();
        $this->controleManager = new ControleManager();
        $this->controleLigneManager = new ControleLigneManager();
        $this->utilisateurManager = new UtilisateurManager();
        $this->qrCodeGenerator = new QrCodeGenerator();
        $this->qrCodeReader = new QrCodeReader();
    }
    
    /**
     * Point d'entrée pour le scan du QR code
     * URL: /qr/{id}
     */
    public function redirect(Request $request)
    {
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        
        if (!$id) {
            $this->addFlash('danger', 'ID d\'équipement manquant');
            header('Location: /equipements');
            exit;
        }
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            $this->addFlash('danger', 'Équipement non trouvé');
            header('Location: /equipements');
            exit;
        }
        
        $user = $this->getCurrentUser();
        
        if (!$user) {
            header('Location: /se_connecter?redirect=/qr/' . $id);
            exit;
        }
        
        $controleEnCours = $this->findControleEnCours((int)$id, $user['id']);
        
        if ($controleEnCours) {
            header('Location: /admin/controles/edit/' . $controleEnCours['id']);
            exit;
        }
        
        header('Location: /equipements/equipement-' . $id);
        exit;
    }
    
    /**
     * Page d'aiguillage avec interface (choix)
     * URL: /qr/choix/{id}
     */
    public function choicePage(Request $request)
    {
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/choix\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        
        if (!$id) {
            $this->addFlash('danger', 'ID d\'équipement manquant');
            header('Location: /equipements');
            exit;
        }
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            $this->addFlash('danger', 'Équipement non trouvé');
            header('Location: /equipements');
            exit;
        }
        
        $user = $this->getCurrentUser();
        
        $controleEnCours = null;
        if ($user) {
            $controleEnCours = $this->findControleEnCours((int)$id, $user['id']);
        }
        
        $historique = $this->equipementManager->getHistoriqueControles((int)$id);
        
        // Utiliser la méthode render du contrôleur
        return $this->render('qr/choice_page.twig', [
            'equipement' => $equipement,
            'controleEnCours' => $controleEnCours,
            'historiqueControles' => $historique,
            'user' => $user,
            'qrCodeFilename' => 'equipement_' . $id
        ]);
    }
    
    /**
     * Génère et affiche le QR code
     * URL: /qr/generate/{id}
     */
    public function generateQr(Request $request)
    {
        error_log("=== QR GENERATE ===");
        
        $id = $request->attributes->get('id');
        error_log("ID depuis attributes: " . ($id ?? 'null'));
        
        if (!$id) {
            $path = $request->getPathInfo();
            error_log("Path: " . $path);
            preg_match('/\/qr\/generate\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
            error_log("ID extrait: " . ($id ?? 'null'));
        }
        
        if (!$id) {
            error_log("❌ Aucun ID trouvé");
            http_response_code(404);
            echo 'ID d\'équipement manquant';
            exit;
        }
        
        error_log("✅ ID trouvé: " . $id);
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            error_log("❌ Équipement non trouvé - ID: " . $id);
            http_response_code(404);
            echo 'Équipement non trouvé pour l\'ID: ' . $id;
            exit;
        }
        
        error_log("✅ Équipement trouvé: " . $equipement['reference']);
        
        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;
        
        $qrData = json_encode([
            'id' => (int)$equipement['id'],
            'reference' => $equipement['reference'],
            'libelle' => $equipement['libelle'],
            'url' => $qrUrl
        ]);
        
        error_log("📊 Données: " . $qrData);
        
        if (!class_exists('Endroid\QrCode\Builder\Builder')) {
            error_log("❌ Endroid QR Code non trouvé");
            http_response_code(500);
            echo 'Erreur: Bibliothèque QR Code non installée';
            exit;
        }
        
        try {
            error_log("🔄 Génération du QR...");
            $qrCodeContent = $this->qrCodeGenerator->generateFromData($qrData, 400, true);
            
            if (empty($qrCodeContent)) {
                throw new \Exception('QR code généré est vide');
            }
            
            error_log("✅ QR généré - Taille: " . strlen($qrCodeContent) . " octets");
            
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($qrCodeContent));
            header('Cache-Control: public, max-age=86400');
            echo $qrCodeContent;
            exit;
            
        } catch (\Exception $e) {
            error_log("❌ Erreur: " . $e->getMessage());
            http_response_code(500);
            echo 'Erreur de génération: ' . $e->getMessage();
            exit;
        }
    }
    
    /**
     * Télécharge le QR code
     * URL: /qr/download/{id}
     */
    public function downloadQr(Request $request)
    {
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/download\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        
        if (!$id) {
            http_response_code(404);
            echo 'ID d\'équipement manquant';
            exit;
        }
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            http_response_code(404);
            echo 'Équipement non trouvé';
            exit;
        }
        
        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;
        
        $qrData = json_encode([
            'id' => (int)$equipement['id'],
            'reference' => $equipement['reference'],
            'libelle' => $equipement['libelle'],
            'url' => $qrUrl
        ]);
        
        try {
            $filename = 'equipement_' . $id;
            $filePath = $this->qrCodeGenerator->generateAndSave($filename, $qrData, 400);
            
            if (file_exists($filePath)) {
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="QR_' . $equipement['reference'] . '.png"');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit;
            }
            
            throw new \Exception('Fichier non trouvé');
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo 'Erreur: ' . $e->getMessage();
            exit;
        }
    }
    
    /**
     * Affiche un QR code pré-généré
     * URL: /qr/view/{filename}
     */
    public function viewQr(Request $request)
    {
        $filename = $request->attributes->get('filename');
        
        if (!$filename) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/view\/([^\/]+)/', $path, $matches);
            $filename = $matches[1] ?? null;
        }
        
        if (!$filename) {
            http_response_code(404);
            echo 'Nom de fichier manquant';
            exit;
        }
        
        $filePath = __DIR__ . '/../../public/images/qrcodes/' . $filename . '.png';
        
        if (file_exists($filePath)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($filePath);
            exit;
        }
        
        http_response_code(404);
        echo 'QR code non trouvé';
        exit;
    }
    
    /**
     * Génère le QR code d'un équipement et le sauvegarde (API JSON)
     * URL: /qr/save/{id}
     */
    public function saveQr(Request $request)
    {
        header('Content-Type: application/json');
        
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/save\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID d\'équipement manquant']);
            exit;
        }
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            http_response_code(404);
            echo json_encode(['error' => 'Équipement non trouvé']);
            exit;
        }
        
        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;
        
        $qrData = json_encode([
            'id' => (int)$equipement['id'],
            'reference' => $equipement['reference'],
            'libelle' => $equipement['libelle'],
            'url' => $qrUrl
        ]);
        
        try {
            $filename = 'equipement_' . $id;
            $filePath = $this->qrCodeGenerator->generateAndSave($filename, $qrData, 300);
            
            echo json_encode([
                'success' => true,
                'filename' => $filename . '.png',
                'path' => $filePath,
                'url' => '/qr/view/' . $filename
            ]);
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    /**
     * API pour générer un QR code (retourne l'URL du QR)
     * URL: /api/qr/generate/{id}
     */
    public function apiGenerateQr(Request $request)
    {
        header('Content-Type: application/json');
        
        $id = $request->attributes->get('id');
        
        if (!$id) {
            $path = $request->getPathInfo();
            preg_match('/\/api\/qr\/generate\/(\d+)/', $path, $matches);
            $id = $matches[1] ?? null;
        }
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID d\'équipement manquant']);
            exit;
        }
        
        $equipement = $this->equipementManager->findId((int)$id);
        
        if (!$equipement) {
            http_response_code(404);
            echo json_encode(['error' => 'Équipement non trouvé']);
            exit;
        }
        
        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;
        
        echo json_encode([
            'success' => true,
            'equipement' => [
                'id' => $equipement['id'],
                'reference' => $equipement['reference'],
                'libelle' => $equipement['libelle']
            ],
            'qr_url' => $qrUrl,
            'qr_image_url' => $baseUrl . '/qr/generate/' . $id,
            'qr_download_url' => $baseUrl . '/qr/download/' . $id
        ]);
        exit;
    }
    
    /**
     * Trouve un contrôle en cours pour un équipement et un utilisateur
     */
    private function findControleEnCours(int $equipementId, int $userId): ?array
    {
        $controleLignes = [];
        if (method_exists($this->controleLigneManager, 'findByEquipement')) {
            $controleLignes = $this->controleLigneManager->findByEquipement($equipementId);
        } elseif (method_exists($this->controleLigneManager, 'getByEquipement')) {
            $controleLignes = $this->controleLigneManager->getByEquipement($equipementId);
        } else {
            $sql = "SELECT * FROM controle_ligne WHERE equipement_id = :equipement_id";
            $stmt = $this->controleLigneManager->db->prepare($sql);
            $stmt->execute(['equipement_id' => $equipementId]);
            $controleLignes = $stmt->fetchAll();
        }
        
        if (empty($controleLignes)) {
            return null;
        }
        
        foreach ($controleLignes as $ligne) {
            $controle = $this->getControleById($ligne['controle_id']);
            if ($controle && 
                $controle['statut'] === 'en_cours' && 
                $controle['utilisateur_id'] == $userId) {
                return $controle;
            }
        }
        
        return null;
    }
    
    /**
     * Récupère un contrôle par son ID
     */
    private function getControleById(int $id): ?array
    {
        if (method_exists($this->controleManager, 'find')) {
            return $this->controleManager->find($id);
        } elseif (method_exists($this->controleManager, 'findId')) {
            return $this->controleManager->findId($id);
        } elseif (method_exists($this->controleManager, 'getById')) {
            return $this->controleManager->getById($id);
        }
        return null;
    }
    
    /**
     * Récupère un utilisateur par son ID
     */
    private function getUtilisateurById(int $id): ?array
    {
        if (method_exists($this->utilisateurManager, 'find')) {
            return $this->utilisateurManager->find($id);
        } elseif (method_exists($this->utilisateurManager, 'findId')) {
            return $this->utilisateurManager->findId($id);
        } elseif (method_exists($this->utilisateurManager, 'getById')) {
            return $this->utilisateurManager->getById($id);
        }
        return null;
    }
    
    /**
     * Récupère l'utilisateur actuellement connecté
     */
    private function getCurrentUser(): ?array
    {
        if ($this->session->has('user_id')) {
            return $this->getUtilisateurById($this->session->get('user_id'));
        }
        return null;
    }
    
    /**
     * Ajoute un message flash
     */
    private function addFlash(string $type, string $message): void
    {
        if (method_exists($this->session, 'addFlashBag')) {
            $this->session->addFlashBag($type, $message);
        } else {
            if (!isset($_SESSION['flash_bag'])) {
                $_SESSION['flash_bag'] = [];
            }
            if (!isset($_SESSION['flash_bag'][$type])) {
                $_SESSION['flash_bag'][$type] = [];
            }
            $_SESSION['flash_bag'][$type][] = $message;
        }
    }
    
    /**
     * Récupère l'URL de base
     */
    private function getBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $protocol . $host . $basePath;
    }
}