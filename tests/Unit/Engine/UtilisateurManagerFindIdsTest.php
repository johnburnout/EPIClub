<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #71] Tests Unit de UtilisateurManager::findIds().
 *
 * Vérifie :
 *  - $ids vide → [] sans requête SQL ;
 *  - résultat indexé par id (array_column(..., null, 'id')) ;
 *  - déduplication des IDs avant le IN (...).
 *
 * ⚠️ findIds() fait un SELECT * (comportement historique de findId()),
 * donc les colonnes sensibles (password, reset_token) sont incluses.
 * C'est volontaire (iso-comportement #71) — cf. Dette 8 dans REPRISE.md.
 */
final class UtilisateurManagerFindIdsTest extends TestCase
{
    public function testFindIdsReturnsEmptyArrayWithoutQueryOnEmptyInput(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $result = (new TestableUtilisateurManager($pdo))->findIds([]);

        self::assertSame([], $result);
    }

    public function testFindIdsIndexesResultById(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            self::assertStringContainsString('FROM utilisateur', $sql);
            self::assertStringContainsString('WHERE id IN (?,?)', $sql);
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 10, 'username' => 'u1', 'password' => 'hash1'],
                ['id' => 11, 'username' => 'u2', 'password' => 'hash2'],
            ]);
            return $stmt;
        });

        $result = (new TestableUtilisateurManager($pdo))->findIds([10, 11]);

        self::assertSame([10, 11], array_keys($result));
        self::assertSame('u1', $result[10]['username']);
    }

    public function testFindIdsDeduplicatesInputIds(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$captured) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use (&$captured) {
                $captured = $params;
                return true;
            });
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        (new TestableUtilisateurManager($pdo))->findIds([10, 10, 11, 10]);

        self::assertSame([10, 11], $captured);
    }
}