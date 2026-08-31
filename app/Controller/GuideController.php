<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class GuideController extends AbstractController
{
    public function index(Request $request): Response
    {
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