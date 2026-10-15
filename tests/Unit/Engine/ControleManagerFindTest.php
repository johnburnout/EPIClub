<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableControleManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de ControleManager
 * (méthodes de lecture).
 *
 * ⚠️ Caractérisation stricte, iso-comportement :
 *  - findAll joint le prénom/nom du contrôleur (LEFT JOIN utilisateur)
 *    et applique l'ordre 'date_debut DESC' par défaut.
 *  - findId retourne `null` quand absent (contrat uniformisé #89 —
 *    auparavant fetch() brut → false, divergence corrigée).
 *  - findActiveByUser : statut 'ouvert' + LIMIT 1, `?: null`.
 */
final class ControleManagerFindTest extends TestCase
{
    public function testFindAllJoinsControleurAndAppliesDefaultOrder(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 1, 'prenom' => 'Ada', 'nom' => 'Lovelace'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleManager($pdo))->findAll();

        self::assertStringContainsString('LEFT JOIN utilisateur u', $captured);
        self::assertStringContainsString('ORDER BY date_debut DESC', $captured);
        self::assertStringNotContainsString('LIMIT', $captured);
        self::assertSame('Ada', $rows[0]['prenom']);
    }

    public function testFindAllAppliesLimitAndOffset(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        (new TestableControleManager($pdo))->findAll('nom ASC', 5, 10);

        self::assertStringContainsString('ORDER BY nom ASC', $captured);
        self::assertStringContainsString('LIMIT 5 OFFSET 10', $captured);
    }

    public function testFindIdReturnsNullWhenNotFound(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        $result = (new TestableControleManager($pdo))->findId(999);

        self::assertStringContainsString('FROM controle WHERE id = :id', $captured);
        self::assertNull($result, 'Contrat uniformisé (#89) : null quand absent.');
    }

    public function testFindActiveByUserQueriesOpenControle(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$capturedSql, &$capturedParams) {
            $capturedSql = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
            $stmt->method('fetch')->willReturn(['id' => 7, 'statut' => 'ouvert']);
            return $stmt;
        });

        $result = (new TestableControleManager($pdo))->findActiveByUser(42);

        self::assertStringContainsString("statut = 'ouvert'", $capturedSql);
        self::assertStringContainsString('LIMIT 1', $capturedSql);
        self::assertSame(['user_id' => 42], $capturedParams);
        self::assertSame(7, $result['id']);
    }

    public function testFindActiveByUserReturnsNullWhenNone(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull((new TestableControleManager($pdo))->findActiveByUser(42));
    }

    // ==================================================================
    // save() — caractérisation INSERT/UPDATE (refactor #90)
    // ==================================================================

    public function testSaveWithoutIdInsertsAndReturnsLastInsertId(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableControleManager($pdo))->save([
            'libelle' => 'C1', 'date_debut' => '2026-01-01', 'date_fin' => null,
            'statut' => 'ouvert', 'controleur_id' => 1, 'cree_par' => 1,
        ]);

        self::assertStringStartsWith('INSERT INTO controle', $captured->sql);
        self::assertStringContainsString('cree_par', $captured->sql);
        self::assertSame('42', (string) $result);
    }

    public function testSaveCreateDefaultsHashRemarquesToNullWhenAbsent(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        (new TestableControleManager($pdo))->save([
            'libelle' => 'C1', 'date_debut' => '2026-01-01', 'date_fin' => null,
            'statut' => 'ouvert', 'controleur_id' => 1, 'cree_par' => 1,
        ]);

        self::assertArrayHasKey('hash_remarques', $captured->params);
        self::assertNull($captured->params['hash_remarques']);
    }

    public function testSaveWithIdUpdatesViaPatch(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableControleManager($pdo))->save([
            'id' => 5, 'statut' => 'cloture', 'champ_inconnu' => 'ignore',
        ]);

        self::assertStringStartsWith('UPDATE controle', $captured->sql);
        // _patch filtre les champs non autorisés
        self::assertArrayNotHasKey('champ_inconnu', $captured->params);
        self::assertTrue($result);
    }

    /**
    * PDO mock pour save() : capture le SQL et les params de la
    * première requête, simule lastInsertId.
    *
    * @return array{0: \PDO, 1: \stdClass}
    */
    private function makePdoCapturingInsertUpdate(): array
    {
        $captured = new \stdClass();
        $captured->sql = null;
        $captured->params = null;

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($captured) {
            $captured->sql = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (?array $params = null) use ($captured) {
                $captured->params = $params ?? [];
                return true;
            });
            return $stmt;
        });
        $pdo->method('lastInsertId')->willReturn('42');

        return [$pdo, $captured];
    }

}
