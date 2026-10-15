<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ Dette 9] Tests Unit de
 * UtilisateurManager::hasAcquisitionsSaisies().
 *
 * Garde applicative du futur FOREIGN KEY acquisition.saisie_par
 * (RESTRICT) : sans elle, la suppression d'un utilisateur ayant saisi
 * des acquisitions créait des acquisitions orphelines ('redacteur'
 * inaccessible, cf. Dette 8 pour l'exposition de ces données).
 */
final class UtilisateurManagerHasAcquisitionsSaisiesTest extends TestCase
{
    public function testHasAcquisitionsSaisiesQueriesAcquisitionTable(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('1');
            return $stmt;
        });

        $result = (new TestableUtilisateurManager($pdo))->hasAcquisitionsSaisies(3);

        self::assertTrue($result);
        self::assertIsString($captured);
        self::assertStringContainsString('FROM acquisition', $captured);
        self::assertStringContainsString('saisie_par', $captured);
    }

    public function testHasAcquisitionsSaisiesReturnsFalseWhenCountIsZero(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('0');
            return $stmt;
        });

        self::assertFalse((new TestableUtilisateurManager($pdo))->hasAcquisitionsSaisies(3));
    }
}
