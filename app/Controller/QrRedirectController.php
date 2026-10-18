<?php

namespace Epiclub\Controller;

use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\QrCodeGenerator;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class QrRedirectController extends AbstractController
{
    public function __construct(Session $session)
    {
        parent::__construct($session);
        // [REFACTOR #73] Plus d'instanciations ici : les factories
        // protected sont appelées à la demande (pattern Vague 11).
    }

    // ==================================================================
    // [REFACTOR #73] Factories Vague 11 — mockables en test Unit.
    //
    // Les sous-classes de test peuvent surcharger ces factories pour
    // injecter des managers mockés (TestableQrRedirectController).
    //
    // QrCodeGenerator n'est pas mockable (pas d'interface), donc
    // laissé en réel — comme FactureUploader dans
    // TestableAcquisitionController.
    //
    // [#94] La factory utilisateurManager() a été retirée : elle ne
    // servait qu'à getUtilisateurById(), code mort supprimé.
    // [#96] QrCodeReader supprimé : classe entièrement morte
    // (zéro appelant de read/readAsBase64/exists/getFilePath), elle
    // contenait le dernier header()+exit du projet.
    // ==================================================================

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    protected function controleManager(): ControleManager
    {
        return new ControleManager();
    }

    protected function controleLigneManager(): ControleLigneManager
    {
        return new ControleLigneManager();
    }

    protected function qrCodeGenerator(): QrCodeGenerator
    {
        return new QrCodeGenerator();
    }

    /**
    * Point d'entrée pour le scan du QR code
    * URL: /qr/{id}
    *
    * [REFACTOR #95 - Dette 13] Retourne des RedirectResponse au lieu de
    * header()+exit : l'action est désormais testable en Unit et le
    * dispatcher (public/index.php) fait le ->send().
    */
    public function redirect(Request $request): Response
    {
        // 1. Récupérer l'ID de l'équipement
        $id = $this->extractId($request, '/\/qr\/(\d+)/');
        if (!$id) {
            return $this->redirectTo('/equipements');
        }

        // 2. Non connecté → login AVEC retour sur le QR.
        //    ⚠️ Doit précéder deniAccessUnlessGranted() : pour un anonyme,
        //    la garde lève AccessDeniedException, interceptée par le
        //    dispatcher qui redirige vers '/' — le paramètre redirect
        //    serait perdu (l'utilisateur arrivait sur le tableau de bord
        //    après connexion au lieu de revenir sur le QR scanné).
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectTo('/se_connecter?redirect=/qr/' . $id);
        }

        $this->deniAccessUnlessGranted('ROLE_USER');

        // 3. Vérifier que l'équipement existe
        $equipement = $this->equipementManager()->findId($id);
        if (!$equipement) {
            $this->addFlash('danger', 'Équipement non trouvé');
            return $this->redirectTo('/equipements');
        }

        // 4. Un contrôleur (ou admin) avec un contrôle ouvert bascule
        //    sur le parcours « contrôle ». Un simple utilisateur — même
        //    s'il est encore controleur_id d'un contrôle ouvert (ex.
        //    rétrogradé) — va sur la fiche équipement : la route
        //    update-ligne exige ROLE_CONTROLLEUR et lui serait refusée.
        $controleEnCours = $this->isGranted('ROLE_CONTROLLEUR')
            ? $this->findControleEnCours($id, $user['id'])
            : null;
        if ($controleEnCours) {
            $controleLigne = $this->findControleLigneByEquipement($controleEnCours['id'], $id);
            if ($controleLigne) {
                return $this->redirectTo('/admin/controles/update-ligne/' . $controleLigne['id']);
            }
            $this->addEquipementToControle($id, $controleEnCours['id']);
            $newLigne = $this->findControleLigneByEquipement($controleEnCours['id'], $id);
            if ($newLigne) {
                return $this->redirectTo('/admin/controles/update-ligne/' . $newLigne['id']);
            }
            return $this->redirectTo('/admin/controles/edit/' . $controleEnCours['id']);
        }

        // 5. Aucun contrôle en cours → fiche de l'équipement
        return $this->redirectTo('/equipements/equipement-' . $id);
    }

    /**
    * Page d'aiguillage avec interface (choix)
    * URL: /qr/choix/{id}
    */
    public function choicePage(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->extractId($request, '/\/qr\/choix\/(\d+)/');
        if (!$id) {
            return $this->redirectTo('/equipements');
        }

        $equipement = $this->equipementManager()->findId($id);

        if (!$equipement) {
            $this->addFlash('danger', 'Équipement non trouvé');
            return $this->redirectTo('/equipements');
        }

        $user = $this->getCurrentUser();
        $peutCreerControle = $user ? ($this->isGranted('ROLE_CONTROLLEUR') || $this->isGranted('ROLE_ADMIN')) : false;

        $controleEnCours = null;
        if ($user && $peutCreerControle) {
            $controleEnCours = $this->findControleEnCours($id, $user['id']);
        }

        $historique = $this->equipementManager()->getHistoriqueControles($id);

        return $this->render('qr/choice_page.twig', [
            'equipement' => $equipement,
            'controleEnCours' => $controleEnCours,
            'historiqueControles' => $historique,
            'user' => $user,
            'peutCreerControle' => $peutCreerControle,
            'qrCodeFilename' => 'equipement_' . $id
        ]);
    }

    /**
    * Génère et affiche le QR code
    * URL: /qr/generate/{id}
    *
    * [REFACTOR #95] Les error_log() de debug ont été retirés (bruit) ;
    * seul le log d'erreur réel est conservé.
    */
    public function generateQr(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->extractId($request, '/\/qr\/generate\/(\d+)/');
        if (!$id) {
            return new Response('QR introuvable', Response::HTTP_NOT_FOUND);
        }

        $equipement = $this->equipementManager()->findId($id);

        if (!$equipement) {
            return new Response('Équipement non trouvé', Response::HTTP_NOT_FOUND);
        }

        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;

        if (!class_exists('Endroid\QrCode\Builder\Builder')) {
            error_log('[QrRedirectController] Endroid QR Code non trouvé');
            return new Response('Génération impossible', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $qrCodeContent = $this->qrCodeGenerator()->generateFromData($qrUrl, 400, false);

            if (empty($qrCodeContent)) {
                throw new \Exception('QR code généré est vide');
            }

            return new Response($qrCodeContent, Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($qrCodeContent),
                'Cache-Control' => 'public, max-age=86400',
            ]);

        } catch (\Exception $e) {
            error_log('[QrRedirectController] Generate QR failed: ' . $e->getMessage());
            return new Response('Erreur serveur', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
    * Télécharge le QR code
    * URL: /qr/download/{id}
    */
    public function downloadQr(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->extractId($request, '/\/qr\/download\/(\d+)/');
        if (!$id) {
            return new Response('QR introuvable', Response::HTTP_NOT_FOUND);
        }

        $equipement = $this->equipementManager()->findId($id);

        if (!$equipement) {
            return new Response('Équipement non trouvé', Response::HTTP_NOT_FOUND);
        }

        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;

        try {
            $filename = 'equipement_' . $id;
            $filePath = $this->qrCodeGenerator()->generateAndSave($filename, $qrUrl, 400);

            if (!file_exists($filePath)) {
                throw new \Exception('Fichier non trouvé');
            }

            return new Response((string) file_get_contents($filePath), Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="QR_' . $equipement['reference'] . '.png"',
                'Content-Length' => (string) filesize($filePath),
            ]);

        } catch (\Exception $e) {
            error_log('[QrRedirectController] Download QR failed: ' . $e->getMessage());
            return new Response('Erreur serveur', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Affiche un QR code pré-généré
     * URL: /qr/view/{filename}
     */
    public function viewQr(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $filename = $request->attributes->get('filename');
        if (!$filename) {
            $path = $request->getPathInfo();
            preg_match('/\/qr\/view\/([^\/]+)/', $path, $matches);
            $filename = $matches[1] ?? null;
        }

        if (!$filename) {
            return new Response('QR introuvable', Response::HTTP_NOT_FOUND);
        }

        $filePath = __DIR__ . '/../../public/images/qrcodes/' . $filename . '.png';

        if (!file_exists($filePath)) {
            return new Response('QR introuvable', Response::HTTP_NOT_FOUND);
        }

        return new Response((string) file_get_contents($filePath), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
    * Génère le QR code d'un équipement et le sauvegarde (API JSON)
    * URL: /qr/save/{id}
    *
    * NOTE SÉCURITÉ :
    * Cette action est déclenchable en GET (route non restreinte à POST),
    * mais elle est :
    *   - réservée aux utilisateurs authentifiés (ROLE_USER)
    *   - idempotente (le fichier `equipement_{id}.png` est écrasé à chaque appel)
    *   - sans effet de bord sur les données métier (aucune écriture BDD)
    * Le risque résiduel est un DoS disque/CPU très limité (N IDs × ~5 Ko).
    * Choix volontaire de ne pas exiger POST+CSRF pour préserver la simplicité
    * du fetch JS côté fiche équipement.
    */
    public function saveQr(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->extractId($request, '/\/qr\/save\/(\d+)/');
        if (!$id) {
            return new JsonResponse(['error' => 'Requête invalide'], Response::HTTP_BAD_REQUEST);
        }

        $equipement = $this->equipementManager()->findId($id);

        if (!$equipement) {
            return new JsonResponse(['error' => 'Ressource non trouvée'], Response::HTTP_NOT_FOUND);
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
            $filePath = $this->qrCodeGenerator()->generateAndSave($filename, $qrData, 300);

            return new JsonResponse([
                'success' => true,
                'filename' => $filename . '.png',
                'path' => $filePath,
                'url' => '/qr/view/' . $filename
            ]);
        } catch (\Exception $e) {
            error_log('[QrRedirectController] Save QR failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Erreur serveur'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * API pour générer un QR code (retourne l'URL du QR)
     * URL: /api/qr/generate/{id}
     */
    public function apiGenerateQr(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $id = $this->extractId($request, '/\/api\/qr\/generate\/(\d+)/');
        if (!$id) {
            return new JsonResponse(['error' => 'Requête invalide'], Response::HTTP_BAD_REQUEST);
        }

        $equipement = $this->equipementManager()->findId($id);

        if (!$equipement) {
            return new JsonResponse(['error' => 'Ressource non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $baseUrl = $this->getBaseUrl();
        $qrUrl = $baseUrl . '/qr/' . $id;

        return new JsonResponse([
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
    }

    /**
     * Trouve un contrôle en cours pour un équipement et un utilisateur
     *
     * [REFACTOR #95] error_log() de debug retirés (bruit).
     */
    private function findControleEnCours(?int $equipementId, int $userId): ?array
    {
        return $this->controleManager()->findActiveByUser($userId) ?: null;
    }

    private function addEquipementToControle(int $equipementId, int $controleId): bool
    {
        return $this->controleLigneManager()->addEquipement($controleId, $equipementId);
    }

    /**
     * Récupère l'utilisateur actuellement connecté
     */
    private function getCurrentUser(): ?array
    {
        $user = $this->session->get('user');

        if ($user && isset($user['id'])) {
            return $user;
        }

        return null;
    }

    /**
     * Ajoute un message flash.
     *
     * [FIX #94 - Dette 12] L'ancienne implémentation testait
     * method_exists($this->session, 'addFlashBag') — méthode absente de
     * Epiclub\Engine\Session, donc TOUJOURS fausse — puis écrivait dans
     * $_SESSION['flash_bag'], une clé jamais lue par les templates
     * (qui consomment session.getFlashBag.all). Les messages flash des
     * écrans QR (« Équipement non trouvé ») étaient silencieusement
     * perdus. Alignement sur le reste du projet : getFlashBag()->add().
     */
    private function addFlash(string $type, string $message): void
    {
        $this->session->getFlashBag()->add($type, $message);
    }

    /**
     * Récupère la ligne de contrôle pour un équipement dans un contrôle donné
     */
    private function findControleLigneByEquipement(int $controleId, int $equipementId): ?array
    {
        return $this->controleLigneManager()->findByControleAndEquipement($controleId, $equipementId);
    }

    /**
     * [REFACTOR #95 - Dette 13] Extraction de l'id de route, factorisée
     * (7 copies quasi identiques) : attribut de route prioritaire,
     * sinon regex sur le path. Retourne null si introuvable.
     */
    private function extractId(Request $request, string $pattern): ?int
    {
        $id = $request->attributes->get('id');

        if (!$id) {
            preg_match($pattern, $request->getPathInfo(), $matches);
            $id = $matches[1] ?? null;
        }

        return $id ? (int) $id : null;
    }
}
