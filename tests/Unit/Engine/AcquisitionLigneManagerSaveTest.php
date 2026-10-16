<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #92 - Vague 15] Tests de caractérisation du save() de
 * AcquisitionLigneManager, après migration vers filteredInsert() /
 * filteredUpdate().
 *
 * Contrat figé :
 *  - _create force TOUJOURS equipements_generes=0, même si l'appelant
 *    fournit une valeur (règle métier historique) ;
 *  - regrouper_en_lot ?? 0 ;
 *  - une violation UNIQUE (MySQL 1062) est traduite en
 *    DuplicateReferenceException, en INSERT comme en UPDATE.
 */
final class AcquisitionLigneManagerSaveTest extends TestCase
{
    public function testSaveCreateForcesEquipementsGeneresToZero(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableAcquisitionLigneManager($pdo))->save([
            'acquisition_id' => 1, 'reference' => 'REF-1', 'designation' => 'Casques',
            'categorie_id' => 2, 'nombre' => 10,
            'equipements_generes' => 5, // tentative de contournement : ignorée
        ]);

        self::assertStringStartsWith('INSERT INTO acquisition_ligne', $captured->sql);
        self::assertSame('42', (string) $result);
        self::assertSame(0, $captured->params['equipements_generes']);
        self::assertSame(0, $captured->params['regrouper_en_lot']);
    }

    public function testSaveCreateTranslatesDuplicateKey(): void
    {
        $pdo = $this->makePdoThrowingDuplicate();

        $this->expectException(DuplicateReferenceException::class);
        $this->expectExceptionMessage("La référence 'REF-1' existe déjà.");

        (new TestableAcquisitionLigneManager($pdo))->save([
            'acquisition_id' => 1, 'reference' => 'REF-1', 'designation' => 'Casques',
            'categorie_id' => 2, 'nombre' => 10,
        ]);
    }

    public function testSavePatchTranslatesDuplicateKey(): void
    {
        $pdo = $this->makePdoThrowingDuplicate();

        $this->expectException(DuplicateReferenceException::class);
        $this->expectExceptionMessage('déjà utilisée par une autre ligne');

        (new TestableAcquisitionLigneManager($pdo))->save([
            'id' => 7, 'reference' => 'REF-1',
        ]);
    }

    public function testSaveWithIdUpdatesViaPatch(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableAcquisitionLigneManager($pdo))->save([
            'id' => 7, 'designation' => 'Baudriers', 'champ_inconnu' => 'ignore',
        ]);

        self::assertStringStartsWith('UPDATE acquisition_ligne', $captured->sql);
        self::assertStringContainsString('designation=:designation', $captured->sql);
        self::assertArrayNotHasKey('champ_inconnu', $captured->params);
        self::assertSame(7, $captured->params['id']);
        self::assertTrue($result);
    }

    /**
     * PDO mock : capture le SQL et les params, simule lastInsertId.
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

    /**
     * PDO mock dont execute() lève une violation UNIQUE MySQL (1062).
     *
     * PDOException::getCode() porte le SQLSTATE en CHAÎNE côté driver
     * ('23000') : le code est injecté par réflexion, le constructeur
     * natif n'accepte qu'un int.
     */
    private function makePdoThrowingDuplicate(): \PDO
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willThrowException($this->makeDuplicateException());
            return $stmt;
        });

        return $pdo;
    }

    private function makeDuplicateException(): \PDOException
    {
        $e = new \PDOException("Duplicate entry 'REF-1' for key 'reference'");
        $code = new \ReflectionProperty(\Exception::class, 'code');
        $code->setValue($e, '23000');
        $e->errorInfo = ['23000', 1062, "Duplicate entry 'REF-1' for key 'reference'"];
        return $e;
    }
}
