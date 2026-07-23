<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadController extends AbstractController
{
    /**
     * Servir un fichier uploadé
     */
    public function serve(Request $request): Response
    {
        $path = $request->attributes->get('path');
        
        // Sécuriser le chemin (empêcher les traversées de répertoire)
        $path = str_replace(['..', '/../', '\\'], '', $path);
        
        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $path;
        
        if (!file_exists($filePath)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Fichier non trouvé');
        }
        
        // Déterminer le type MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        return new BinaryFileResponse($filePath, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"'
        ]);
    }
}