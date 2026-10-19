<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ImageUploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * [SÉCURITÉ — audit 2026-10-19 S1] ImageUploader ne doit jamais écrire
 * autre chose qu'une image réencodée, quel que soit le fichier reçu.
 */
final class ImageUploaderTest extends TestCase
{
    private string $dir;
    private string $tmp;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/epiclub_imgup_' . bin2hex(random_bytes(4));
        $this->dir = $base . '/cible/';
        $this->tmp = $base . '/source/';
        mkdir($this->dir, 0700, true);
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->tmp] as $d) {
            foreach (glob($d . '{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            @rmdir($d);
        }
        @rmdir(dirname($this->dir));
    }

    /**
     * Fichiers piégés présentés avec un nom d'image.
     */
    public static function fichiersMalveillantsProvider(): array
    {
        return [
            'HTML déguisé en .jpg' => ['photo.jpg', '<html><body><script>alert(document.cookie)</script></body></html>'],
            'SVG avec script'      => ['logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'PHP déguisé en .png'  => ['image.png', '<?php system($_GET["c"]); ?>'],
            'polyglotte GIF + PHP' => ['anim.gif', "GIF89a<?php system(\$_GET['c']); ?>"],
            'texte brut'           => ['note.webp', 'ceci n est pas une image'],
        ];
    }

    #[DataProvider('fichiersMalveillantsProvider')]
    public function testRefuseLesFichiersQuiNeSontPasDesImages(string $nomClient, string $contenu): void
    {
        $upload = $this->upload($nomClient, $contenu);

        try {
            (new ImageUploader($this->dir))->save($upload, 'photo');
            self::fail('Le fichier aurait dû être refusé.');
        } catch (\InvalidArgumentException) {
            // attendu
        }

        self::assertSame([], $this->fichiersCrees(), 'Aucun fichier ne doit être écrit dans le dossier cible.');
    }

    public function testReencodeUneVraieImageEtPerdLeContenuAnnexe(): void
    {
        // PNG valide suivi d'une charge utile : seuls les pixels doivent survivre.
        $png = $this->pngValide(40, 20) . '<script>alert(1)</script><?php echo 1; ?>';
        $upload = $this->upload('vacances.png', $png);

        $nom = (new ImageUploader($this->dir))->save($upload, 'photo');

        self::assertMatchesRegularExpression('/^photo-[0-9a-f]{16}\.(webp|jpg)$/', $nom);
        self::assertSame([$nom], $this->fichiersCrees());

        $ecrit = (string) file_get_contents($this->dir . $nom);
        self::assertStringNotContainsString('<script>', $ecrit);
        self::assertStringNotContainsString('<?php', $ecrit);
        self::assertNotFalse(@imagecreatefromstring($ecrit), 'Le fichier écrit doit être une image valide.');
    }

    public function testRedimensionneAuPlusALaDimensionMaximale(): void
    {
        $nom = (new ImageUploader($this->dir, 100))->save($this->upload('grande.png', $this->pngValide(400, 200)), 'x');

        [$largeur, $hauteur] = getimagesize($this->dir . $nom);
        self::assertSame(100, $largeur);
        self::assertSame(50, $hauteur);
    }

    public function testLePrefixeEstNettoye(): void
    {
        $nom = (new ImageUploader($this->dir))->save($this->upload('a.png', $this->pngValide(5, 5)), '../../evil.php');

        self::assertMatchesRegularExpression('/^evilphp-[0-9a-f]{16}\.(webp|jpg)$/', $nom);
        self::assertFileExists($this->dir . $nom);
    }

    // ------------------------------------------------------------------

    private function upload(string $nomClient, string $contenu): UploadedFile
    {
        $chemin = $this->tmp . bin2hex(random_bytes(4));
        file_put_contents($chemin, $contenu);
        return new UploadedFile($chemin, $nomClient, null, null, true);
    }

    private function pngValide(int $largeur, int $hauteur): string
    {
        $img = imagecreatetruecolor($largeur, $hauteur);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        unset($img); // imagedestroy() est dépréciée en PHP 8.5 (no-op depuis 8.0)
        return (string) ob_get_clean();
    }

    /** @return string[] */
    private function fichiersCrees(): array
    {
        return array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
    }
}
