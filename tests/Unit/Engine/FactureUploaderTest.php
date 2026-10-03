<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\FactureUploader;
use Epiclub\Exception\FactureUploadException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires de FactureUploader (issue #40).
 *
 * Utilise un dossier temporaire réel (sys_get_temp_dir()) pour valider
 * les branches mkdir/move. Les UploadedFile sont créés à la volée à
 * partir de fixtures minimales (fichiers vides ou PDF minimal).
 */
final class FactureUploaderTest extends TestCase
{
    private string $tmpDir;
    private FactureUploader $uploader;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/epiclub_test_uploads_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
        $this->uploader = new FactureUploader($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ==================================================================
    // upload() — cas nominaux
    // ==================================================================

    public function testUploadReturnsNullWhenNoFileProvided(): void
    {
        self::assertNull($this->uploader->upload(null));
    }

    public function testUploadAcceptsPdfAndReturnsRelativePath(): void
    {
        $file = $this->makeUploadedFile('facture.pdf', 'application/pdf', "%PDF-1.4\n%test\n");

        $relative = $this->uploader->upload($file);

        self::assertNotNull($relative);
        self::assertStringStartsWith('factures/', $relative);
        self::assertStringEndsWith('.pdf', $relative);
        self::assertFileExists($this->tmpDir . '/' . $relative);
    }

    public function testUploadAcceptsJpeg(): void
    {
        $file = $this->makeUploadedFile('scan.jpg', 'image/jpeg', "\xFF\xD8\xFF\xE0binary");

        $relative = $this->uploader->upload($file);

        self::assertNotNull($relative);
        self::assertStringEndsWith('.jpg', $relative);
    }

    public function testUploadAcceptsPng(): void
    {
        // PNG 1×1 transparent réel (base64) — finfo doit le détecter
        // comme image/png, sinon le service le rejette (BAD_MIME).
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        
        $file = $this->makeUploadedFile('scan.png', 'image/png', $pngBytes);
        
        $relative = $this->uploader->upload($file);
        
    self::assertNotNull($relative);
    self::assertStringEndsWith('.png', $relative);
    }

    public function testUploadCreatesFacturesSubdirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->tmpDir . '/factures');

        $file = $this->makeUploadedFile('facture.pdf', 'application/pdf', '%PDF-1.4');
        $this->uploader->upload($file);

        self::assertDirectoryExists($this->tmpDir . '/factures');
    }

    public function testUploadGeneratesUniqueFilenames(): void
    {
        $file1 = $this->makeUploadedFile('facture.pdf', 'application/pdf', '%PDF-1.4');
        $file2 = $this->makeUploadedFile('facture.pdf', 'application/pdf', '%PDF-1.4');

        $rel1 = $this->uploader->upload($file1);
        $rel2 = $this->uploader->upload($file2);

        self::assertNotSame($rel1, $rel2);
    }

    // ==================================================================
    // upload() — erreurs
    // ==================================================================

    public function testUploadRejectsDisallowedMimeType(): void
    {
        $file = $this->makeUploadedFile('script.exe', 'application/x-msdownload', 'MZbinary');

        try {
            $this->uploader->upload($file);
            self::fail('FactureUploadException attendue');
        } catch (FactureUploadException $e) {
            self::assertSame(FactureUploadException::BAD_MIME, $e->getErrorCode());
            self::assertStringContainsString('Format', $e->getMessage());
        }
    }

    public function testUploadRejectsInvalidUploadedFile(): void
    {
        // UploadedFile avec une erreur PHP (UPLOAD_ERR_INI_SIZE = 1)
        $file = new UploadedFile(
            __FILE__,
            'huge.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true // test mode
        );

        try {
            $this->uploader->upload($file);
            self::fail('FactureUploadException attendue');
        } catch (FactureUploadException $e) {
            self::assertSame(FactureUploadException::INVALID, $e->getErrorCode());
        }
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function testDeleteRemovesExistingFile(): void
    {
        $file = $this->makeUploadedFile('facture.pdf', 'application/pdf', '%PDF-1.4');
        $relative = $this->uploader->upload($file);

        self::assertTrue($this->uploader->delete($relative));
        self::assertFileDoesNotExist($this->tmpDir . '/' . $relative);
    }

    public function testDeleteReturnsTrueOnMissingFile(): void
    {
        self::assertTrue($this->uploader->delete('factures/inexistant.pdf'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Crée un UploadedFile à partir d'un contenu en mémoire.
     * En mode test, Symfony permet de déplacer le fichier vers un autre
     * répertoire (copy au lieu de move), ce qui évite de manipuler
     * un vrai upload HTTP.
     */
    private function makeUploadedFile(string $name, string $mimeType, string $content): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'epiclub_upload_');
        file_put_contents($tmpFile, $content);

        return new UploadedFile(
            $tmpFile,
            $name,
            $mimeType,
            null,
            true // test mode : le fichier peut être déplacé sans être un vrai upload
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}