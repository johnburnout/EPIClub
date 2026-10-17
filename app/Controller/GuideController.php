<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class GuideController extends AbstractController
{
    public function index(Request $request): Response
    {
        // [SÉCURITÉ #104] Décision produit 2026-10-17 : le guide est
        // réservé aux utilisateurs connectés (il était public par défaut).
        $this->deniAccessUnlessGranted('ROLE_USER');

        // Chemin vers le fichier Markdown
        $mdFile = __DIR__ . '/../../docs/guide_utilisateur.md';

        if (!file_exists($mdFile)) {
            $content = '# Guide non trouvé';
        } else {
            // Lire le fichier
            $content = file_get_contents($mdFile);
        }

        // Convertir le Markdown en HTML (utilisation de Parsedown)
        $parsedown = new \Parsedown();
        $html = $parsedown->text($content);

        return $this->render('guide.twig', [
            'guide_content' => $html
        ]);
    }
}