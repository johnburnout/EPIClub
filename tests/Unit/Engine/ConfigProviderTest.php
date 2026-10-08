<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ConfigProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de ConfigProvider (issue #50a).
 *
 * ⚠️ Chaque test crée un fichier de config temporaire réel
 *    (sys_get_temp_dir()), le charge, puis le supprime au tearDown.
 */
final class ConfigProviderTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/epiclub_config_test_' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // ==================================================================
    // Constructeur
    // ==================================================================

    public function testConstructorThrowsWhenFileMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        new ConfigProvider('/tmp/does-not-exist-' . uniqid() . '.php');
    }

    public function testConstructorThrowsWhenFileDoesNotReturnArray(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn 'not-an-array';\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an array');

        new ConfigProvider($this->tmpFile);
    }

    // ==================================================================
    // get()
    // ==================================================================

    public function testGetReturnsExistingValue(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['DB_HOST' => 'localhost'];\n");

        $provider = new ConfigProvider($this->tmpFile);

        self::assertSame('localhost', $provider->get('DB_HOST'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['DB_HOST' => 'localhost'];\n");

        $provider = new ConfigProvider($this->tmpFile);

        self::assertNull($provider->get('UNKNOWN_KEY'));
        self::assertSame('fallback', $provider->get('UNKNOWN_KEY', 'fallback'));
    }

    public function testGetPreservesFalsyValues(): void
    {
        file_put_contents(
            $this->tmpFile,
            "<?php\nreturn ['DEBUG' => 0, 'EMPTY' => '', 'NULL_VAL' => null];\n"
        );

        $provider = new ConfigProvider($this->tmpFile);

        // ⚠️ On utilise has() et non isset() pour distinguer null de absent
        self::assertSame(0, $provider->get('DEBUG'));
        self::assertSame('', $provider->get('EMPTY'));
        self::assertNull($provider->get('NULL_VAL'));
    }

    // ==================================================================
    // has()
    // ==================================================================

    public function testHasDistinguishesNullFromMissing(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['PRESENT_NULL' => null];\n");

        $provider = new ConfigProvider($this->tmpFile);

        self::assertTrue($provider->has('PRESENT_NULL'));
        self::assertFalse($provider->has('ABSENT'));
    }

    // ==================================================================
    // set() — en mémoire uniquement
    // ==================================================================

    public function testSetModifiesValueInMemory(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['KEY' => 'old'];\n");

        $provider = new ConfigProvider($this->tmpFile);
        $provider->set('KEY', 'new');

        self::assertSame('new', $provider->get('KEY'));
    }

    public function testSetDoesNotWriteToDiskImmediately(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['KEY' => 'old'];\n");
        $originalContent = file_get_contents($this->tmpFile);

        $provider = new ConfigProvider($this->tmpFile);
        $provider->set('KEY', 'new');

        // Le fichier sur disque ne doit PAS avoir changé
        self::assertSame($originalContent, file_get_contents($this->tmpFile));
    }

    public function testSetAddsNewKey(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn [];\n");

        $provider = new ConfigProvider($this->tmpFile);
        $provider->set('NEW_KEY', 'value');

        self::assertTrue($provider->has('NEW_KEY'));
        self::assertSame('value', $provider->get('NEW_KEY'));
    }

    // ==================================================================
    // all()
    // ==================================================================

    public function testAllReturnsFullConfiguration(): void
    {
        file_put_contents(
            $this->tmpFile,
            "<?php\nreturn ['A' => 1, 'B' => 2, 'C' => 'three'];\n"
        );

        $provider = new ConfigProvider($this->tmpFile);

        self::assertSame(['A' => 1, 'B' => 2, 'C' => 'three'], $provider->all());
    }

    // ==================================================================
    // Cache
    // ==================================================================

    public function testFileIsReadOnceAtConstruction(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn ['KEY' => 'first'];\n");

        $provider = new ConfigProvider($this->tmpFile);

        // Modifie le fichier APRÈS le constructeur
        file_put_contents($this->tmpFile, "<?php\nreturn ['KEY' => 'second'];\n");

        // Le provider doit garder la valeur lue au constructeur (cache)
        self::assertSame('first', $provider->get('KEY'));
    }

    // ==================================================================
    // getFilePath()
    // ==================================================================

    public function testGetFilePathReturnsConstructorPath(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn [];\n");

        $provider = new ConfigProvider($this->tmpFile);

        self::assertSame($this->tmpFile, $provider->getFilePath());
    }
}