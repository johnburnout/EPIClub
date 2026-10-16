<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableEmplacementManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #88] Tests de caractérisation de EmplacementManager
 * (méthodes de lecture).
 */
final class EmplacementManagerFindTest extends TestCase
{
    public function testFindAllAppliesOrderAndLimit(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([['id' => 1, 'nom' => 'Salle A']]);
            return $stmt;
        });

        $rows = (new TestableEmplacementManager($pdo))->findAll('nom ASC', 5, 0);

        self::assertStringContainsString('FROM emplacement', $captured);
        self::assertStringContainsString('ORDER BY nom ASC', $captured);
        self::assertStringContainsString('LIMIT 5 OFFSET 0', $captured);
        self::assertSame('Salle A', $rows[0]['nom']);
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

        self::assertNull((new TestableEmplacementManager($pdo))->findId(999));
        self::assertStringContainsString('FROM emplacement WHERE id=:id', $captured);
    }

    public function testHasEquipementsQueriesClubEquipement(): void
    {
        $captured = null;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('3');
            return $stmt;
        });

        self::assertTrue((new TestableEmplacementManager($pdo))->hasEquipements(7));
        self::assertStringContainsString('FROM club_equipement', $captured);
        self::assertStringContainsString('emplacement_id', $captured);
    }

    // ==================================================================
    // save() — caractérisation INSERT/UPDATE (refactor #90)
    // ==================================================================

    public function testSaveWithoutIdInsertsAndReturnsLastInsertId(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        // #91 : la fixture utilise la vraie colonne 'libelle' — les clés
        // arbitraires ('nom') sont désormais filtrées par filteredInsert().
        $result = (new TestableEmplacementManager($pdo))->save(['libelle' => 'Salle B']);

        self::assertStringStartsWith('INSERT INTO emplacement', $captured->sql);
        self::assertSame('42', (string) $result);
    }

    public function testSaveWithIdUpdates(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableEmplacementManager($pdo))->save(['id' => 2, 'libelle' => 'Salle C']);

        self::assertStringStartsWith('UPDATE emplacement', $captured->sql);
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
