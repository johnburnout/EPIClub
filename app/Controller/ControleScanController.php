<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scan de QR codes dans la page (caméra du téléphone), sans passer par
 * l'appareil photo natif qui ouvrirait une nouvelle page à chaque scan
 * (décision produit 2026-10-19).
 *
 *  - page()    : écran de scan en continu (html5-qrcode, embarqué en
 *                local — pas de dépendance réseau sur le terrain) ;
 *  - ajouter() : endpoint AJAX appelé à chaque scan. Contrôleur avec
 *                un contrôle ouvert → l'équipement est ajouté au
 *                contrôle (s'il n'y est pas déjà) puis le client ouvre
 *                la fiche contrôle de l'équipement (décision 2026-10-20),
 *                qui ramène au scanner après enregistrement ; sinon →
 *                redirection côté client vers la fiche équipement.
 *
 * ⚠️ La caméra exige un contexte sécurisé (HTTPS ou localhost) :
 *    la page le détecte et affiche un message explicite.
 */
class ControleScanController extends AbstractController
{
    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
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

    // ==================================================================
    // Page de scan
    // ==================================================================

    public function page(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        $user = $this->session->get('user');
        $controle = null;
        if ($this->isGranted('ROLE_CONTROLLEUR') && isset($user['id'])) {
            $controle = $this->controleManager()->findActiveByUser((int) $user['id']);
        }

        return $this->render('controle_scan.twig', [
            'controle' => $controle,
        ]);
    }

    // ==================================================================
    // Endpoint AJAX : ajout de l'équipement scanné au contrôle ouvert
    // ==================================================================

    public function ajouter(Request $request): JsonResponse
    {
        $this->deniAccessUnlessGranted('ROLE_USER');

        try {
            $this->validateCsrf($request);
        } catch (\Throwable) {
            return new JsonResponse(
                ['statut' => 'erreur', 'message' => 'Jeton de sécurité invalide, rechargez la page.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $equipementId = $request->request->get('equipement_id');
        if (!is_string($equipementId) || !ctype_digit($equipementId)) {
            return new JsonResponse(
                ['statut' => 'erreur', 'message' => 'QR code invalide.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        $equipementId = (int) $equipementId;

        $equipement = $this->equipementManager()->findId($equipementId);
        if (!$equipement) {
            return new JsonResponse([
                'statut' => 'introuvable',
                'message' => 'Équipement non trouvé.',
            ]);
        }

        // Pas de parcours « contrôle » → le client redirige vers la
        // fiche équipement (même comportement que /qr/{id}).
        $user = $this->session->get('user');
        $controle = ($this->isGranted('ROLE_CONTROLLEUR') && isset($user['id']))
            ? $this->controleManager()->findActiveByUser((int) $user['id'])
            : null;

        if (!$controle) {
            return new JsonResponse([
                'statut' => 'hors_controle',
                'redirect' => '/equipements/equipement-' . $equipementId,
            ]);
        }

        $base = [
            'reference' => $equipement['reference'],
            'libelle' => $equipement['libelle'],
            'controle' => $controle['libelle'],
        ];

        // Déjà dans le contrôle ? (addEquipement() retourne true aussi
        // dans ce cas — le doublon est distingué ici pour l'affichage)
        $statut = 'ajoute';
        if ($this->controleLigneManager()->findByControleAndEquipement($controle['id'], $equipementId)) {
            $statut = 'deja';
        } else {
            $this->controleLigneManager()->addEquipement((int) $controle['id'], $equipementId);
        }

        // [2026-10-20] Ouverture de la fiche contrôle de l'équipement,
        // avec retour au scanner après enregistrement.
        $ligne = $this->controleLigneManager()->findByControleAndEquipement((int) $controle['id'], $equipementId);
        if ($ligne) {
            $base['redirect'] = '/admin/controles/update-ligne/' . $ligne['id'] . '?retour=scanner';
        }

        return new JsonResponse(['statut' => $statut] + $base);
    }
}
