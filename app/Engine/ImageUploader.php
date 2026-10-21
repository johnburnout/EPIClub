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
 * [2026-10-21] L'orientation EXIF des photos JPEG (téléphone tenu
 * vertical : pixels en paysage + « tourner de 90° ») est appliquée aux
 * pixels AVANT le réencodage, qui perd l'EXIF : sans cela la photo
 * ressortait couchée. Lecture par un petit analyseur interne : aucune
 * dépendance à l'extension PHP exif.
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

        if ($mimeType === 'image/jpeg') {
            $source = self::appliquerOrientation(
                $source,
                self::orientationExif((string) $contenu)
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

    /**
     * Orientation EXIF (tag 0x0112) d'un JPEG : 1 à 8, 1 si absente ou
     * illisible. Analyse les segments jusqu'au premier APP1 « Exif ».
     */
    public static function orientationExif(string $jpeg): int
    {
        $longueur = strlen($jpeg);
        if ($longueur < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $pos = 2;
        while ($pos + 4 <= $longueur) {
            if ($jpeg[$pos] !== "\xFF") {
                return 1;
            }
            $marqueur = ord($jpeg[$pos + 1]);
            if ($marqueur === 0xD9 || $marqueur === 0xDA) {
                return 1; // fin d'image ou début des données : pas d'EXIF
            }
            $taille = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($taille < 2 || $pos + 2 + $taille > $longueur) {
                return 1;
            }
            if ($marqueur === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                return self::orientationTiff(substr($jpeg, $pos + 10, $taille - 8));
            }
            $pos += 2 + $taille;
        }
        return 1;
    }

    private static function orientationTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $ordre = substr($tiff, 0, 2);
        if ($ordre !== 'II' && $ordre !== 'MM') {
            return 1;
        }
        $u16 = $ordre === 'II' ? 'v' : 'n';
        $u32 = $ordre === 'II' ? 'V' : 'N';

        $ifd = unpack($u32, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $entrees = unpack($u16, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $entrees; $i++) {
            $entree = $ifd + 2 + 12 * $i;
            if ($entree + 12 > strlen($tiff)) {
                return 1;
            }
            if (unpack($u16, substr($tiff, $entree, 2))[1] === 0x0112) {
                $valeur = unpack($u16, substr($tiff, $entree + 8, 2))[1];
                return $valeur >= 1 && $valeur <= 8 ? $valeur : 1;
            }
        }
        return 1;
    }

    /**
     * Remet les pixels « à l'endroit » selon l'orientation EXIF.
     * imagerotate() tourne dans le sens inverse des aiguilles d'une montre.
     */
    public static function appliquerOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $tourner = static function (\GdImage $i, int $angle): \GdImage {
            $r = imagerotate($i, $angle, 0);
            return $r === false ? $i : $r;
        };

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 3:
                return $tourner($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;
            case 5:
                $image = $tourner($image, 270);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 6:
                return $tourner($image, 270);
            case 7:
                $image = $tourner($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 8:
                return $tourner($image, 90);
            default:
                return $image;
        }
    }
}
