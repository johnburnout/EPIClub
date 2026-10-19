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
        $mdFile = $this->guideFilePath();

        if (!file_exists($mdFile)) {
            $content = '# Guide non trouvé';
        } else {
            // Lire le fichier
            $content = file_get_contents($mdFile);
        }

        // Convertir le Markdown en HTML (utilisation de Parsedown)
        // [SÉCURITÉ — audit C12] Safe mode : le HTML brut contenu dans le
        // Markdown (<script>, <img onerror>, etc.) est échappé au lieu
        // d'être rendu tel quel. Le template utilise {{ guide_content|raw }},
        // donc sans safe mode toute modification malveillante de
        // docs/guide_utilisateur.md devient une XSS stockée.
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);
        $html = $parsedown->text($content);

        return $this->render('guide.twig', [
            'guide_content' => $html
        ]);
    }

    /**
     * Chemin du fichier Markdown du guide — surchargé en test Unit pour
     * injecter un contenu contrôlé (test d'échappement XSS, audit C12).
     */
    protected function guideFilePath(): string
    {
        return __DIR__ . '/../../docs/guide_utilisateur.md';
    }
}