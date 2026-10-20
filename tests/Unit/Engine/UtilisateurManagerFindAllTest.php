<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ #93 - Dette 8 étendue] findAll() de UtilisateurManager
 * projette PUBLIC_FIELDS : les colonnes sensibles (password,
 * reset_token, ...) ne quittent plus la base sur les listes.
 *
 * Contrat figé :
 *  - SELECT explicite des PUBLIC_FIELDS, jamais SELECT * ;
 *  - tri et pagination conservés (buildOrderClause/buildLimitClause) ;
 *  - les lignes sont retournées telles quelles.
 */
final class UtilisateurManagerFindAllTest extends TestCase
{
    public function testFindAllProjectsPublicFieldsOnly(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo([
            ['id' => 1, 'nom' => 'Lovelace', 'prenom' => 'Ada'],
        ]);

        $rows = (new TestableUtilisateurManager($pdo))->findAll();

        self::assertStringStartsWith('SELECT id, nom, prenom, username, email, role, date_creation, est_protege FROM utilisateur', $captured->sql);
        self::assertStringNotContainsString('SELECT *', $captured->sql);
        self::assertStringNotContainsString('password', $captured->sql);
        self::assertStringNotContainsString('reset_token', $captured->sql);
        self::assertStringNotContainsString('last_activity', $captured->sql);
        self::assertStringNotContainsString('controle_en_cours_id', $captured->sql);
        self::assertSame('Ada', $rows[0]['prenom']);
    }

    public function testFindAllKeepsOrderAndLimit(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo([]);

        (new TestableUtilisateurManager($pdo))->findAll('nom ASC', 5, 10);

        self::assertStringContainsString('ORDER BY nom ASC', $captured->sql);
        self::assertStringContainsString('LIMIT 5 OFFSET 10', $captured->sql);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{0: \PDO, 1: \stdClass}
     */
    private function makeCapturingPdo(array $rows): array
    {
        $captured = new \stdClass();
        $captured->sql = null;

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($captured, $rows) {
            $captured->sql = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn($rows);
            return $stmt;
        });

        return [$pdo, $captured];
    }
}
