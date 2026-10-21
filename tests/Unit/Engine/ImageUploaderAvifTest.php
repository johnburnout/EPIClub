<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ImageUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * [2026-10-21] Images AVIF : converties en WebP quand le GD du serveur
 * sait les lire, refusées avec un message clair sinon.
 */
final class ImageUploaderAvifTest extends TestCase
{
    private const AVIF = __DIR__ . '/../../Fixtures/images/rouge-64x48.avif';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/epiclub_avif_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Le dossier contient le sous-dossier « cible/ » quand la
        // conversion a réussi : suppression récursive.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testLeFichierDeTestEstBienUnAvif(): void
    {
        self::assertSame('image/avif', $this->upload()->getMimeType());
    }

    public function testServeurSansAvifMessageClairEtRienDEcrit(): void
    {
        try {
            (new ImageUploader($this->dir . 'cible/', 1000, false))->save($this->upload());
            self::fail('AVIF accepté sans prise en charge');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(ImageUploader::MESSAGE_AVIF_NON_PRIS_EN_CHARGE, $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->dir . 'cible/');
    }

    public function testServeurAvecAvifConversionEnWebp(): void
    {
        if (!ImageUploader::avifDisponible()) {
            self::markTestSkipped('Le GD de cette machine ne lit pas l\'AVIF (gd_info « AVIF Support »).');
        }

        $nom = (new ImageUploader($this->dir . 'cible/'))->save($this->upload(), 'photo');

        self::assertMatchesRegularExpression('/^photo-[0-9a-f]{16}\.(webp|jpg)$/', $nom);
        $image = imagecreatefromstring((string) file_get_contents($this->dir . 'cible/' . $nom));
        self::assertSame([64, 48], [imagesx($image), imagesy($image)]);
        $c = imagecolorsforindex($image, imagecolorat($image, 32, 24));
        self::assertGreaterThan(180, $c['red']);
        self::assertLessThan(80, $c['green']);
    }

    public function testDetectionCoherenteAvecGd(): void
    {
        self::assertSame(
            function_exists('imagecreatefromavif') && !empty(gd_info()['AVIF Support']),
            ImageUploader::avifDisponible()
        );
    }

    private function upload(): UploadedFile
    {
        $chemin = $this->dir . 'source-' . bin2hex(random_bytes(4));
        copy(self::AVIF, $chemin);
        return new UploadedFile($chemin, 'photo.avif', null, null, true);
    }
}
