<?php

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\ConfigFile;
use Epiclub\Engine\ConfigProvider;
use PHPUnit\Framework\TestCase;

/**
 * [#109] Seam de configuration — les tests Unit ne doivent plus dépendre
 * du `.env.local.php` gitignored : le bootstrap PHPUnit définit
 * EPICLUB_CONFIG_FILE vers la fixture commitée.
 */
final class ConfigFileTest extends TestCase
{
    public function testDefaultPathFollowsTestConstant(): void
    {
        self::assertTrue(
            defined('EPICLUB_CONFIG_FILE'),
            'Le bootstrap PHPUnit doit définir EPICLUB_CONFIG_FILE.'
        );
        self::assertSame(EPICLUB_CONFIG_FILE, ConfigFile::defaultPath());
        self::assertSame(
            realpath(__DIR__ . '/../../Fixtures/env.test.php'),
            realpath(ConfigFile::defaultPath())
        );
    }

    public function testDefaultPathIsLoadableByConfigProvider(): void
    {
        $config = new ConfigProvider(ConfigFile::defaultPath());

        // Clés présentes dans la fixture — la config de test est complète.
        self::assertSame('epiclub', $config->get('DB_NAME'));
        self::assertSame('AES-256-CBC', $config->get('CIPHER_METHOD'));
        self::assertNotEmpty($config->get('SECRET_KEY'));
    }

    public function testManagerConstructionDoesNotReadGitignoredEnvFile(): void
    {
        // [#109] Avant le correctif, AbstractManager::__construct() incluait
        // littéralement {projet}/.env.local.php : 68 tests Unit échouaient
        // quand le fichier gitignored était absent. La simple construction
        // (PDO réel via la fixture) suffit à prouver l'indépendance.
        $manager = new \Epiclub\Domain\CategorieManager();
        self::assertInstanceOf(\Epiclub\Domain\CategorieManager::class, $manager);
    }
}
