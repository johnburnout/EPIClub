<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\AbstractManager;
use PHPUnit\Framework\TestCase;

/**
 * Sous-classe testable : injecte un PDO mocké, déclare tableName()
 * et expose filteredInsert()/filteredUpdate() en public.
 */
final class TestableFilteredWriteManager extends AbstractManager
{
    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
    }

    protected function tableName(): string
    {
        return 'test_table';
    }

    public function exposeFilteredInsert(array $data, array $fields, array $defaults = []): bool
    {
        return $this->filteredInsert($data, $fields, $defaults);
    }

    public function exposeFilteredUpdate(array $data, array $fields, array $defaults = []): bool
    {
        return $this->filteredUpdate($data, $fields, $defaults);
    }
}

/**
 * [REFACTOR #91 - Vague 15] Tests unitaires des helpers d'écriture
 * génériques filteredInsert()/filteredUpdate() (AbstractManager).
 *
 * Contrat vérifié :
 *  - filtrage : seules les clés listées dans $fields sont écrites ;
 *  - défauts : $defaults complète les clés absentes (sans écraser
 *    une valeur fournie, y compris null explicite) ;
 *  - SQL dynamique : seules les colonnes fournies figurent dans le
 *    INSERT / le SET (mise à jour partielle possible) ;
 *  - UPDATE : WHERE id=:id, id pris dans $data['id'], jamais dans le SET ;
 *  - aucun champ valide / id manquant → RuntimeException ;
 *  - nom de colonne invalide → InvalidArgumentException.
 */
final class AbstractManagerFilteredWriteTest extends TestCase
{
    // ==================================================================
    // filteredInsert()
    // ==================================================================

    public function testInsertFiltersAndBuildsSqlFromProvidedFields(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        $result = (new TestableFilteredWriteManager($pdo))->exposeFilteredInsert(
            ['nom' => 'Petzl', 'intrus' => 'x'],
            ['nom', 'email']
        );

        self::assertTrue($result);
        self::assertSame('INSERT INTO test_table (nom) VALUES (:nom)', $captured->sql);
        self::assertSame(['nom' => 'Petzl'], $captured->params);
    }

    public function testInsertAppliesDefaultsForMissingKeysOnly(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        (new TestableFilteredWriteManager($pdo))->exposeFilteredInsert(
            ['nom' => 'Petzl', 'email' => null], // null explicite conservé
            ['nom', 'email', 'phone'],
            ['email' => 'def@aut.fr', 'phone' => null]
        );

        self::assertSame(
            'INSERT INTO test_table (email, phone, nom) VALUES (:email, :phone, :nom)',
            $captured->sql
        );
        self::assertSame(['email' => null, 'phone' => null, 'nom' => 'Petzl'], $captured->params);
    }

    public function testInsertThrowsWhenNoValidField(): void
    {
        [$pdo] = $this->makeCapturingPdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('aucun champ valide');

        (new TestableFilteredWriteManager($pdo))->exposeFilteredInsert(
            ['intrus' => 'x'],
            ['nom', 'email']
        );
    }

    public function testInsertRejectsInvalidColumnName(): void
    {
        [$pdo] = $this->makeCapturingPdo();

        $this->expectException(\InvalidArgumentException::class);

        (new TestableFilteredWriteManager($pdo))->exposeFilteredInsert(
            ['nom;DROP' => 'x'],
            ['nom;DROP']
        );
    }

    // ==================================================================
    // filteredUpdate()
    // ==================================================================

    public function testUpdateBuildsPartialSetAndBindsIdSeparately(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        $result = (new TestableFilteredWriteManager($pdo))->exposeFilteredUpdate(
            ['id' => 7, 'statut' => 'cloture', 'intrus' => 'x'],
            ['statut', 'commentaire']
        );

        self::assertTrue($result);
        self::assertSame('UPDATE test_table SET statut=:statut WHERE id=:id', $captured->sql);
        // id bindé pour le WHERE, jamais dans le SET
        self::assertSame(['statut' => 'cloture', 'id' => 7], $captured->params);
        self::assertStringNotContainsString('id=:id,', $captured->sql);
    }

    public function testUpdateAppliesDefaultsSoSetCoversAllFields(): void
    {
        [$pdo, $captured] = $this->makeCapturingPdo();

        (new TestableFilteredWriteManager($pdo))->exposeFilteredUpdate(
            ['id' => 3, 'libelle' => 'Casque'],
            ['libelle', 'description'],
            ['description' => null]
        );

        self::assertSame(
            'UPDATE test_table SET description=:description, libelle=:libelle WHERE id=:id',
            $captured->sql
        );
        self::assertSame(['description' => null, 'libelle' => 'Casque', 'id' => 3], $captured->params);
    }

    public function testUpdateThrowsWhenNoValidField(): void
    {
        [$pdo] = $this->makeCapturingPdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('aucun champ valide');

        (new TestableFilteredWriteManager($pdo))->exposeFilteredUpdate(
            ['id' => 7, 'intrus' => 'x'],
            ['statut']
        );
    }

    public function testUpdateThrowsWhenIdMissing(): void
    {
        [$pdo] = $this->makeCapturingPdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ID manquant');

        (new TestableFilteredWriteManager($pdo))->exposeFilteredUpdate(
            ['statut' => 'cloture'],
            ['statut']
        );
    }

    public function testUpdateRejectsInvalidColumnName(): void
    {
        [$pdo] = $this->makeCapturingPdo();

        $this->expectException(\InvalidArgumentException::class);

        (new TestableFilteredWriteManager($pdo))->exposeFilteredUpdate(
            ['id' => 1, 'col;DROP' => 'x'],
            ['col;DROP']
        );
    }

    /**
     * PDO mock : capture le SQL et les params de la requête préparée.
     *
     * @return array{0: \PDO, 1: \stdClass}
     */
    private function makeCapturingPdo(): array
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

        return [$pdo, $captured];
    }
}
