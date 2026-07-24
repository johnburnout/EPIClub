<?php

namespace Epiclub\Engine;

class QrCodeReader
{
    protected string $storage;

    public function __construct()
    {
        // Nouveau chemin : public/images/qrcodes
        $this->storage = __DIR__ . '/../../public/images/qrcodes';
        
        if (!is_dir($this->storage)) {
            mkdir($this->storage, 0755, true);
        }
    }

    /**
     * Always read png (at this time)
     * @param string $filename The name + extension of file
     * @param bool $outputDirectly Si true, affiche directement l'image
     * @return string|null Contenu de l'image ou null si non trouvée
     */
    public function read(string $filename, bool $outputDirectly = true)
    {
        $filePath = $this->storage . '/' . $filename;
        
        if (file_exists($filePath)) {
            if ($outputDirectly) {
                header('Content-Type: image/png');
                readfile($filePath);
                exit;
            }
            return file_get_contents($filePath);
        }

        http_response_code(404);
        return null;
    }

    /**
     * Récupère le contenu d'un QR code en base64 (pour affichage inline)
     */
    public function readAsBase64(string $filename): ?string
    {
        $filePath = $this->storage . '/' . $filename;
        
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            return 'data:image/png;base64,' . base64_encode($content);
        }

        return null;
    }

    /**
     * Vérifie si un QR code existe
     */
    public function exists(string $filename): bool
    {
        return file_exists($this->storage . '/' . $filename);
    }

    /**
     * Récupère le chemin complet d'un fichier
     */
    public function getFilePath(string $filename): string
    {
        return $this->storage . '/' . $filename;
    }
}