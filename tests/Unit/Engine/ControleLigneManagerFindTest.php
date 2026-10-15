<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableControleLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de ControleLigneManager
 * (méthodes de lecture).
 *
 * ⚠️ Caractérisation stricte, iso-comportement :
 *  - findByControle / findByEquipement joignent respectivement
 *    club_equipement (référence/libelle) et controle (statut).
 *  - findByControleAndEquipement : `?: null` quand absente.
 *  - findId retourne `null` quand absent (contrat uniformisé #89 —
 *    auparavant fetch() brut → false, divergence corrigée).
 */
final class ControleLigneManagerFindTest extends TestCase
{
    public function testFindByControleJoinsEquipementReference(): void
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
            $stmt->method('fetchAll')->willReturn([
                ['id' => 1, 'reference' => 'EQ-1', 'libelle' => 'Mousqueton'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleLigneManager($pdo))->findByControle(5);

        self::assertStringContainsString('JOIN club_equipement ce', $capturedSql);
        self::assertStringContainsString('WHERE cl.controle_id = :controle_id', $capturedSql);
        self::assertSame(['controle_id' => 5], $capturedParams);
        self::assertSame('EQ-1', $rows[0]['reference']);
    }

    public function testFindByEquipementJoinsControleStatut(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([
                ['id' => 2, 'controle_statut' => 'cloture'],
            ]);
            return $stmt;
        });

        $rows = (new TestableControleLigneManager($pdo))->findByEquipement(9);

        self::assertStringContainsString('JOIN controle c', $captured);
        self::assertStringContainsString('c.statut as controle_statut', $captured);
        self::assertSame('cloture', $rows[0]['controle_statut']);
    }

    public function testFindByControleAndEquipementReturnsNullWhenAbsent(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        self::assertNull(
            (new TestableControleLigneManager($pdo))->findByControleAndEquipement(1, 2)
        );
    }

    public function testFindIdReturnsNullWhenNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetch')->willReturn(false);
            return $stmt;
        });

        $result = (new TestableControleLigneManager($pdo))->findId(999);

        self::assertNull($result, 'Contrat uniformisé (#89) : null quand absent.');
    }

    // ==================================================================
    // save() — caractérisation INSERT/UPDATE (refactor #90)
    // ==================================================================

    public function testSaveWithoutIdInsertsAndReturnsLastInsertId(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableControleLigneManager($pdo))->save([
            'controle_id' => 1, 'equipement_id' => 2,
        ]);

        self::assertStringStartsWith('INSERT INTO controle_ligne', $captured->sql);
        self::assertSame('42', (string) $result);
    }

    public function testSaveWithIdUpdatesViaPatch(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableControleLigneManager($pdo))->save([
            'id' => 3, 'statut' => 'controle_ok', 'intrus' => 'x',
        ]);

        self::assertStringStartsWith('UPDATE controle_ligne', $captured->sql);
        self::assertArrayNotHasKey('intrus', $captured->params);
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
