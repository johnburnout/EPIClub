<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\FactureUploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload d'une facture (PDF/JPG/PNG) vers un répertoire privé.
 *
 * Extrait de AcquisitionController::uploadFacture() (issue #40) :
 *   - le contrôleur ne connaît plus ni mkdir, ni move, ni parse_ini
 *   - la logique est testable unitairement, sans HTTP
 *   - les erreurs remontent via FactureUploadException (plus de
 *     $lastUploadError mutable)
 *
 * Le répertoire d'uploads est injecté au constructeur : le service
 * ne connaît pas la structure du projet.
 */
final class FactureUploader implements FactureUploaderInterface
{
    /**
     * Taille max métier, indépendante de la config PHP.
     * La limite effective = min(post_max_size, upload_max_filesize, BUSINESS_MAX).
     */
    private const BUSINESS_MAX_BYTES = 10 * 1024 * 1024; // 10 Mo

    /** Types MIME autorisés pour une facture. */
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(private string $uploadsDir)
    {
    }

    /**
     * Upload un fichier dans {uploadsDir}/factures/.
     *
     * @return string|null  Chemin relatif ('factures/xxx.pdf') en cas de
     *                      succès, null si aucun fichier fourni.
     * @throws FactureUploadException en cas d'erreur (taille, mime, I/O).
     */
    public function upload(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        if (!$file->isValid()) {
            throw new FactureUploadException(
                'Erreur de téléchargement : ' . $file->getErrorMessage(),
                FactureUploadException::INVALID
            );
        }

        $maxBytes = $this->getMaxUploadBytes();
        if ($file->getSize() > $maxBytes) {
            throw new FactureUploadException(
                'Le fichier dépasse la taille maximum autorisée ('
                    . round($maxBytes / 1024 / 1024) . ' Mo).',
                FactureUploadException::TOO_LARGE
            );
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            throw new FactureUploadException(
                'Type de fichier non autorisé. Formats acceptés : PDF, JPG, PNG.',
                FactureUploadException::BAD_MIME
            );
        }

        $targetDir = $this->facturesDir();
        $this->ensureDir($targetDir);

        $filename = $this->generateFilename($file);

        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            error_log('[FactureUploader] move failed: ' . $e->getMessage());
            throw new FactureUploadException(
                'Erreur lors de l\'enregistrement du fichier.',
                FactureUploadException::IO_ERROR,
                $e
            );
        }

        return 'factures/' . $filename;
    }

    /**
     * Supprime un fichier relatif précédemment uploadé.
     * Best-effort : log en cas d'échec, ne throw pas (appelé dans update/delete).
     */
    public function delete(string $relativePath): bool
    {
        $absolute = rtrim($this->uploadsDir, '/') . '/' . ltrim($relativePath, '/');

        if (!file_exists($absolute)) {
            return true;
        }

        if (!unlink($absolute)) {
            error_log("[FactureUploader] Failed to delete facture: $absolute");
            return false;
        }

        return true;
    }

    // ==================================================================
    // Internes
    // ==================================================================

    private function facturesDir(): string
    {
        return rtrim($this->uploadsDir, '/') . '/factures/';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new FactureUploadException(
                    'Impossible de créer le dossier de téléchargement.',
                    FactureUploadException::IO_ERROR
                );
            }
        }

        if (!is_writable($dir)) {
            throw new FactureUploadException(
                'Le dossier de téléchargement n\'est pas accessible en écriture.',
                FactureUploadException::IO_ERROR
            );
        }
    }

    private function generateFilename(UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?: 'bin';
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'bin';

        return 'facture_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * Limite effective = min(post_max_size, upload_max_filesize, BUSINESS_MAX).
     * Les valeurs -1 (illimité) sont ignorées.
     */
    private function getMaxUploadBytes(): int
    {
        $postMax   = $this->parseIniSize(ini_get('post_max_size'));
        $uploadMax = $this->parseIniSize(ini_get('upload_max_filesize'));

        $limits = array_filter(
            [$postMax, $uploadMax, self::BUSINESS_MAX_BYTES],
            fn($v) => $v > 0
        );

        return $limits ? min($limits) : self::BUSINESS_MAX_BYTES;
    }

    /**
     * Convertit une valeur php.ini ('8M', '1G', '512K') en octets.
     * Retourne 0 si vide, la valeur int brute si pas d'unité.
     */
    private function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
}