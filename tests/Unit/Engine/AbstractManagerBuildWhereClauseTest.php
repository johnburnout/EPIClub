<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\AbstractManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sous-classe testable : expose buildWhereClause() en public
 * et court-circuite le constructeur (qui ouvre une connexion PDO).
 */
final class TestableAbstractManager extends AbstractManager
{
    public function __construct()
    {
        // no-op : pas de PDO en test unitaire
    }

    public function exposeBuildWhereClause(array $criteria): array
    {
        return $this->buildWhereClause($criteria);
    }
}

final class AbstractManagerBuildWhereClauseTest extends TestCase
{
    private TestableAbstractManager $manager;

    protected function setUp(): void
    {
        $this->manager = new TestableAbstractManager();
    }

    public function testEmptyCriteriaReturnsEmptyClauseAndParams(): void
    {
        [$clause, $params] = $this->manager->exposeBuildWhereClause([]);

        self::assertSame('', $clause);
        self::assertSame([], $params);
    }

    public function testSingleCriterionProducesWhereEquality(): void
    {
        [$clause, $params] = $this->manager->exposeBuildWhereClause(['id' => 42]);

        self::assertSame(' WHERE id=:id', $clause);
        self::assertSame(['id' => 42], $params);
    }

    public function testMultipleCriteriaAreJoinedWithAnd(): void
    {
        [$clause, $params] = $this->manager->exposeBuildWhereClause([
            'email'    => 'a@b.c',
            'username' => 'alice',
        ]);

        self::assertSame(' WHERE email=:email AND username=:username', $clause);
        self::assertSame(['email' => 'a@b.c', 'username' => 'alice'], $params);
    }

    public function testIterationOrderIsPreserved(): void
    {
        [$clause] = $this->manager->exposeBuildWhereClause([
            'b' => 1,
            'a' => 2,
            'c' => 3,
        ]);

        self::assertSame(' WHERE b=:b AND a=:a AND c=:c', $clause);
    }

    public function testValidColumnWithUnderscoreAndDigits(): void
    {
        [$clause, $params] = $this->manager->exposeBuildWhereClause([
            'reset_token' => 'abc',
            'col_2'       => 'x',
        ]);

        self::assertSame(' WHERE reset_token=:reset_token AND col_2=:col_2', $clause);
        self::assertSame(['reset_token' => 'abc', 'col_2' => 'x'], $params);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function invalidColumnProvider(): array
    {
        return [
            'space + injection'    => ['id OR 1=1'],
            'dash'                 => ['id-col'],
            'dot (table.column)'   => ['u.id'],
            'semicolon'            => ['id; DROP TABLE'],
            'starts with digit'    => ['1col'],
            'empty string'         => [''],
            'quote'                => ["id'"],
            'parenthesis'          => ['id)'],
        ];
    }

    #[DataProvider('invalidColumnProvider')]
    public function testInvalidColumnThrows(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->exposeBuildWhereClause([$column => 'x']);
    }

    public function testValueIsPassedThroughUnchangedInParams(): void
    {
        [$clause, $params] = $this->manager->exposeBuildWhereClause([
            'email' => "o'brien@x.y",
        ]);

        self::assertSame(' WHERE email=:email', $clause);
        self::assertSame(['email' => "o'brien@x.y"], $params);
    }
}