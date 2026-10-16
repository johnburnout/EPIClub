<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableEquipementManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #92 - Vague 15] Tests de caractérisation du save() de
 * EquipementManager, après migration vers filteredInsert() /
 * filteredUpdate().
 *
 * Contrat figé :
 *  - _create applique les coercitions historiques : nombre ?? 1,
 *    est_epi ?? 1, controle_en_cours ?? 0 — y compris sur un null
 *    explicite — et remplit les colonnes absentes à null ;
 *  - _patch produit un SET dynamique : mise à jour partielle possible,
 *    clés non autorisées ignorées, id bindé pour le WHERE.
 */
final class EquipementManagerSaveTest extends TestCase
{
    public function testSaveCreateAppliesHistoricalDefaults(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableEquipementManager($pdo))->save([
            'reference' => 'CASQ-01', 'libelle' => 'Casque', 'categorie_id' => 3,
        ]);

        self::assertStringStartsWith('INSERT INTO club_equipement', $captured->sql);
        self::assertSame('42', (string) $result);
        // Coercitions ?? historiques
        self::assertSame(1, $captured->params['nombre']);
        self::assertSame(1, $captured->params['est_epi']);
        self::assertSame(0, $captured->params['controle_en_cours']);
        // Remplissage null des colonnes absentes
        self::assertNull($captured->params['acquisition_id']);
        self::assertNull($captured->params['photo']);
    }

    public function testSaveCreateCoercesExplicitNullToDefaults(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        (new TestableEquipementManager($pdo))->save([
            'reference' => 'CASQ-02', 'nombre' => null, 'est_epi' => null,
        ]);

        // ?? historique : un null explicite retombe sur le défaut
        self::assertSame(1, $captured->params['nombre']);
        self::assertSame(1, $captured->params['est_epi']);
    }

    public function testSaveWithIdUpdatesPartially(): void
    {
        [$pdo, $captured] = $this->makePdoCapturingInsertUpdate();

        $result = (new TestableEquipementManager($pdo))->save([
            'id' => 9, 'statut' => 'reforme', 'champ_inconnu' => 'ignore',
        ]);

        self::assertStringStartsWith('UPDATE club_equipement', $captured->sql);
        self::assertStringContainsString('statut=:statut', $captured->sql);
        self::assertStringNotContainsString('nombre', $captured->sql);
        self::assertArrayNotHasKey('champ_inconnu', $captured->params);
        self::assertSame(9, $captured->params['id']);
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
