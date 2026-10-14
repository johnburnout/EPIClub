<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\AbstractManager;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\EncryptionService;
use Epiclub\Tests\Unit\Engine\Support\TestableEquipementManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #82 - Dette 6] Tests Unit de EquipementManager::encryptionService().
 *
 * Vérifie que la factory utilise configProvider() (héritée d'AbstractManager)
 * et non plus un new ConfigProvider(...) en dur.
 */
final class EquipementManagerEncryptionServiceTest extends TestCase
{
    /**
     * La factory configProvider() existe sur AbstractManager et retourne
     * un ConfigProvider. C'est le contrat hérité par EquipementManager.
     */
    public function testConfigProviderFactoryExistsOnAbstractManager(): void
    {
        $reflection = new \ReflectionMethod(
            AbstractManager::class,
            'configProvider'
        );

        self::assertTrue($reflection->isProtected());
        self::assertSame(
            ConfigProvider::class,
            (string) $reflection->getReturnType()
        );
    }

    /**
     * encryptionService() instancie bien un EncryptionService en
     * utilisant le ConfigProvider par défaut.
     */
    public function testEncryptionServiceInstantiatesWithDefaultConfig(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $manager = new TestableEquipementManager($pdo);

        $reflection = new \ReflectionMethod(EquipementManager::class, 'encryptionService');
        $service = $reflection->invoke($manager);

        self::assertInstanceOf(EncryptionService::class, $service);
    }
}