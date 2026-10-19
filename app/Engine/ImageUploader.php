<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * [SÉCURITÉ — audit 2026-10-19 S1] Enregistrement sûr d'une image
 * téléversée dans un dossier servi par le serveur web.
 *
 * Le fichier client n'est JAMAIS copié tel quel :
 *   1. liste blanche MIME (détection serveur via finfo, non spoofable) ;
 *   2. décodage GD — un fichier qui n'est pas une vraie image échoue ;
 *   3. réencodage en WebP (repli JPEG) : seuls les pixels sont conservés,
 *      tout contenu annexe (HTML, script, PHP en polyglotte, EXIF) est
 *      perdu ;
 *   4. nom de fichier entièrement généré côté serveur, extension fixe.
 *
 * Conséquence : impossible de déposer un .html, .svg, .php ou tout
 * autre fichier exécutable / interprétable par le navigateur.
 */
final class ImageUploader
{
    public const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Plafond de pixels avant décodage (anti « image bomb »). */
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly string $targetDir,
        private readonly int $maxDimension = 1000,
    ) {
    }

    /**
     * @param string $prefix Fragment de nom déjà nettoyé ([A-Za-z0-9_-])
     * @return string Nom du fichier créé (sans chemin)
     *
     * @throws \InvalidArgumentException fichier refusé (type, contenu)
     * @throws \RuntimeException         erreur d'écriture
     */
    public function save(UploadedFile $file, string $prefix = 'image'): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Erreur de téléversement : ' . $file->getErrorMessage());
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException(
                'Type de fichier non autorisé. Formats acceptés : JPEG, PNG, WebP, GIF.'
            );
        }

        $infos = @getimagesize($file->getPathname());
        if ($infos === false || $infos[0] < 1 || $infos[1] < 1
            || $infos[0] * $infos[1] > self::MAX_PIXELS
        ) {
            throw new \InvalidArgumentException('Image invalide ou trop grande.');
        }

        $contenu = file_get_contents($file->getPathname());
        $source = $contenu === false ? false : @imagecreatefromstring($contenu);
        if ($source === false) {
            throw new \InvalidArgumentException(
                "Impossible de décoder l'image. Fichier corrompu ou format non supporté."
            );
        }

        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $ratio = min($this->maxDimension / $largeur, $this->maxDimension / $hauteur, 1);
        $nouvelleLargeur = max(1, (int) ($largeur * $ratio));
        $nouvelleHauteur = max(1, (int) ($hauteur * $ratio));

        $image = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle(
            $image, 0, 0, $nouvelleLargeur, $nouvelleHauteur,
            imagecolorallocatealpha($image, 255, 255, 255, 127)
        );
        imagecopyresampled($image, $source, 0, 0, 0, 0,
            $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);

        $dir = rtrim($this->targetDir, '/') . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le dossier d'images.");
        }

        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'image';
        $base = $prefix . '-' . bin2hex(random_bytes(8));

        $nom = $base . '.webp';
        $ok = function_exists('imagewebp') && @imagewebp($image, $dir . $nom, 80);
        if (!$ok) {
            $nom = $base . '.jpg';
            $ok = @imagejpeg($image, $dir . $nom, 85);
        }

        unset($source); // imagedestroy() est dépréciée en PHP 8.5 (no-op depuis 8.0)
        unset($image); // imagedestroy() est dépréciée en PHP 8.5 (no-op depuis 8.0)

        if (!$ok) {
            throw new \RuntimeException("Échec de l'enregistrement de l'image.");
        }

        return $nom;
    }
}
