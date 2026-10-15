<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ Dette 8] Tests Unit de UtilisateurManager::findPublicId() /
 * findPublicIds().
 *
 * Objectif : prouver que la projection publique EXCLUT les colonnes
 * sensibles (password, reset_token, reset_token_expires,
 * reset_email_sent_at) — contrairement à findId()/findIds() qui font
 * un SELECT * (exposition historique dans 'redacteur', cf. #71).
 *
 * Le SQL est capturé via willReturnCallback sur prepare() : les colonnes
 * sont en dur dans la requête, donc la chaîne SQL suffit à prouver
 * l'exclusion — pas besoin de base réelle (test Unit).
 */
final class UtilisateurManagerFindPublicTest extends TestCase
{
    private const SENSITIVE_COLUMNS = [
        'password',
        'reset_token',
        'reset_token_expires',
        'reset_email_sent_at',
    ];

    public function testFindPublicIdsReturnsEmptyArrayWithoutQueryOnEmptyInput(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');

        $result = (new TestableUtilisateurManager($pdo))->findPublicIds([]);

        self::assertSame([], $result);
    }

    public function testFindPublicIdsSelectsOnlyPublicColumns(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            self::assertStringContainsString('FROM utilisateur', $sql);
            self::assertStringContainsString('WHERE id IN (?,?)', $sql);

            foreach (self::SENSITIVE_COLUMNS as $column) {
                self::assertStringNotContainsString(
                    $column,
                    $sql,
                    "La projection publique ne doit pas contenir la colonne sensible '$column'."
                );
            }

            // Champs publics attendus par les vues (nom/prénom du rédacteur…)
            foreach (['id', 'nom', 'prenom', 'username'] as $column) {
                self::assertStringContainsString($column, $sql);
            }

            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 10, 'nom' => 'U10', 'prenom' => 'P10'],
                ['id' => 11, 'nom' => 'U11', 'prenom' => 'P11'],
            ]);
            return $stmt;
        });

        $result = (new TestableUtilisateurManager($pdo))->findPublicIds([10, 11]);

        self::assertSame([10, 11], array_keys($result));
        self::assertSame('U10', $result[10]['nom']);
    }

    public function testFindPublicIdSelectsOnlyPublicColumns(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            self::assertStringContainsString('FROM utilisateur', $sql);
            self::assertStringContainsString('WHERE id=:id', $sql);

            foreach (self::SENSITIVE_COLUMNS as $column) {
                self::assertStringNotContainsString(
                    $column,
                    $sql,
                    "La projection publique ne doit pas contenir la colonne sensible '$column'."
                );
            }

            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(['id' => 10, 'nom' => 'U10']);
            return $stmt;
        });

        $result = (new TestableUtilisateurManager($pdo))->findPublicId(10);

        self::assertIsArray($result);
        self::assertSame('U10', $result['nom']);
    }

    public function testFindPublicIdReturnsNullWhenUserNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableUtilisateurManager($pdo))->findPublicId(999999));
    }

    public function testPublicFieldsConstantExcludesSensitiveColumns(): void
    {
        foreach (self::SENSITIVE_COLUMNS as $column) {
            self::assertNotContains(
                $column,
                UtilisateurManager::PUBLIC_FIELDS,
                "PUBLIC_FIELDS ne doit pas contenir la colonne sensible '$column'."
            );
        }

        self::assertContains('nom', UtilisateurManager::PUBLIC_FIELDS);
        self::assertContains('prenom', UtilisateurManager::PUBLIC_FIELDS);
        self::assertContains('id', UtilisateurManager::PUBLIC_FIELDS);
    }
}
