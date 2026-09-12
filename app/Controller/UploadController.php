<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadController extends AbstractController
{
    /**
     * Servir un fichier uploadé.
     */
    public function serve(Request $request): BinaryFileResponse
    {
        // Les fichiers uploadés ne doivent pas être accessibles aux visiteurs anonymes.
        $this->deniAccessUnlessGranted('ROLE_USER');
        
        $path = (string) $request->attributes->get('path');
        
        // Protection contre le path traversal : realpath() résout les .. et les
        // liens symboliques, puis on vérifie que le résultat reste dans uploads/.
        $uploadsDir = $this->getUploadsDir();
        $realBase = realpath($uploadsDir);
        $realPath = realpath($uploadsDir . ltrim($path, '/'));
        
        if ($realBase === false
            || $realPath === false
            || !str_starts_with($realPath, $realBase)
        ) {
            throw new NotFoundHttpException('Fichier non trouvé');
        }
        
        // Déterminer le type MIME
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';
        
        return new BinaryFileResponse($realPath, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($realPath) . '"',
        ]);
    }

    /**
     * Retourne le chemin absolu du dossier des uploads privés.
     * _storage/ est à la racine du projet, hors document_root.
     */
    private function getUploadsDir(): string
    {
        return dirname(__DIR__, 2) . '/_storage/uploads/';
    }
}