<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Dette 18 (#105) : Engine\DatabaseConnection était du code mort legacy
 * (zéro appelant hors de sa propre classe, dernier utilisateur de
 * `$_ENV` direct pour ouvrir PDO — la config passe par ConfigProvider
 * depuis #50). Suppression figée par ce test.
 */
class DatabaseConnectionRemovedTest extends TestCase
{
    public function testDatabaseConnectionClassIsRemoved(): void
    {
        self::assertFalse(
            class_exists(\Epiclub\Engine\DatabaseConnection::class, false),
            'DatabaseConnection : classe morte supprimée (Dette 18, #105)'
        );
    }
}
