<?php

namespace Epiclub\Engine;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;

class QrCodeGenerator
{
    protected string $storage;

    public function __construct()
    {
        $this->storage = __DIR__ . '/../../public/images/qrcodes';
        
        if (!is_dir($this->storage)) {
            mkdir($this->storage, 0755, true);
        }
    }

    /**
     * Always generate png (at this time)
     * @return string The filename (name + extension)
     */
    public function generate(string $name, string $data): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            logoPath: __DIR__ . '/assets/bender.png',
            logoResizeToWidth: 50,
            logoPunchoutBackground: true
        );

        $filename = $name . '.png';
        $filePath = $this->storage . '/' . $filename;

        $result = $builder->build();
        $result->saveToFile($filePath);

        return $filename;
    }

    /**
     * Génère un QR code et retourne son contenu binaire (pour affichage direct)
     * Version compatible avec v6.1.3
     */
    public function generateFromData(string $data, int $size = 300, bool $withLabel = true): string
    {
        // Pour la version 6.1.3, on utilise le Builder avec constructeur
        // Le label n'est pas supporté directement dans cette version
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        // Ajouter le logo s'il existe (optionnel)
        $logoPath = __DIR__ . '/assets/bender.png';
        if (file_exists($logoPath)) {
            $builder->logoPath($logoPath);
            $builder->logoResizeToWidth(50);
            $builder->logoPunchoutBackground(true);
        }

        $result = $builder->build();
        return $result->getString();
    }

    /**
     * Génère un QR code et le sauvegarde avec un nom personnalisé
     */
    public function generateAndSave(string $name, string $data, int $size = 300): string
    {
        $filename = $name . '.png';
        $filePath = $this->storage . '/' . $filename;

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        // Ajouter le logo s'il existe
        $logoPath = __DIR__ . '/assets/bender.png';
        if (file_exists($logoPath)) {
            $builder->logoPath($logoPath);
            $builder->logoResizeToWidth(50);
            $builder->logoPunchoutBackground(true);
        }

        $result = $builder->build();
        $result->saveToFile($filePath);

        return $filePath;
    }

    /**
     * Vérifie si un QR code existe déjà
     */
    public function exists(string $name): bool
    {
        return file_exists($this->storage . '/' . $name . '.png');
    }

    /**
     * Supprime un QR code
     */
    public function delete(string $name): bool
    {
        $filePath = $this->storage . '/' . $name . '.png';
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Récupère le chemin de stockage
     */
    public function getStoragePath(): string
    {
        return $this->storage;
    }
    
    /**
     * Récupère l'URL publique d'un QR code
     */
    public function getPublicUrl(string $filename): string
    {
        return '/images/qrcodes/' . $filename;
    }
}