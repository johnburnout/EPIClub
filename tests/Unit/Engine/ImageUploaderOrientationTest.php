<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ImageUploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * [2026-10-21] Photos de téléphone : l'orientation EXIF est appliquée aux
 * pixels avant le réencodage (qui perd l'EXIF). Sans cela, une photo
 * prise téléphone vertical ressortait couchée.
 *
 * Image source : 200×100 (paysage), bloc rouge dans le coin haut-gauche
 * des pixels stockés. Pour chaque orientation EXIF, la norme dit où ce
 * coin doit apparaître une fois l'image affichée à l'endroit.
 */
final class ImageUploaderOrientationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/epiclub_orient_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * @return array<string, array{int, int, int, string}>
     *         orientation, largeur et hauteur attendues, coin du bloc rouge
     */
    public static function orientations(): array
    {
        return [
            '1 normale'                   => [1, 200, 100, 'haut-gauche'],
            '2 miroir horizontal'         => [2, 200, 100, 'haut-droite'],
            '3 rotation 180°'             => [3, 200, 100, 'bas-droite'],
            '4 miroir vertical'           => [4, 200, 100, 'bas-gauche'],
            '5 transposition'             => [5, 100, 200, 'haut-gauche'],
            '6 téléphone vertical (90°)'  => [6, 100, 200, 'haut-droite'],
            '7 transversale'              => [7, 100, 200, 'bas-droite'],
            '8 rotation 270°'             => [8, 100, 200, 'bas-gauche'],
        ];
    }

    #[DataProvider('orientations')]
    public function testLaPhotoEstRemiseALEndroit(int $orientation, int $largeur, int $hauteur, string $coin): void
    {
        $jpeg = self::avecOrientation(self::jpegSource(), $orientation, $orientation % 2 === 0);
        self::assertSame($orientation, ImageUploader::orientationExif($jpeg));

        $nom = (new ImageUploader($this->dir))->save($this->upload($jpeg));
        $image = imagecreatefromstring((string) file_get_contents($this->dir . $nom));

        self::assertSame([$largeur, $hauteur], [imagesx($image), imagesy($image)]);
        self::assertSame($coin, self::coinRouge($image));
    }

    public function testSansExifOrientationNormale(): void
    {
        self::assertSame(1, ImageUploader::orientationExif(self::jpegSource()));
        self::assertSame(1, ImageUploader::orientationExif('pas un jpeg'));
        self::assertSame(1, ImageUploader::orientationExif("\xFF\xD8\xFF\xE1\x00"));
    }

    public function testValeurHorsNormeIgnoree(): void
    {
        self::assertSame(1, ImageUploader::orientationExif(self::avecOrientation(self::jpegSource(), 9)));
    }

    // ------------------------------------------------------------------

    private static function jpegSource(): string
    {
        $img = imagecreatetruecolor(200, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 0, 0, 49, 39, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagejpeg($img, null, 95);
        return (string) ob_get_clean();
    }

    /**
     * Insère un segment APP1 « Exif » (IFD0 réduit au tag Orientation)
     * juste après le marqueur SOI, comme le fait un appareil photo.
     */
    private static function avecOrientation(string $jpeg, int $orientation, bool $grosBoutiste = false): string
    {
        $u16 = $grosBoutiste ? 'n' : 'v';
        $u32 = $grosBoutiste ? 'N' : 'V';
        $tiff = ($grosBoutiste ? 'MM' : 'II') . pack($u16, 42) . pack($u32, 8)
            . pack($u16, 1)                                   // 1 entrée
            . pack($u16, 0x0112) . pack($u16, 3) . pack($u32, 1)
            . pack($u16, $orientation) . "\0\0"
            . pack($u32, 0);                                  // pas d'IFD suivant
        $app1 = "Exif\0\0" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    private static function coinRouge(\GdImage $image): string
    {
        $l = imagesx($image);
        $h = imagesy($image);
        $points = [
            'haut-gauche' => [(int) ($l * 0.1), (int) ($h * 0.1)],
            'haut-droite' => [(int) ($l * 0.9), (int) ($h * 0.1)],
            'bas-gauche'  => [(int) ($l * 0.1), (int) ($h * 0.9)],
            'bas-droite'  => [(int) ($l * 0.9), (int) ($h * 0.9)],
        ];
        $rouges = [];
        foreach ($points as $nom => [$x, $y]) {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            if ($c['red'] > 200 && $c['green'] < 80 && $c['blue'] < 80) {
                $rouges[] = $nom;
            }
        }
        return implode(',', $rouges);
    }

    private function upload(string $contenu): UploadedFile
    {
        $chemin = $this->dir . 'source-' . bin2hex(random_bytes(4));
        file_put_contents($chemin, $contenu);
        return new UploadedFile($chemin, 'photo.jpg', null, null, true);
    }
}
