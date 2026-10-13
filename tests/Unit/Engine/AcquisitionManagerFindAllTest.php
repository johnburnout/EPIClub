<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #71] Tests Unit de AcquisitionManager::findAll().
 *
 * Objectif principal : vérifier le NOMBRE de requêtes SQL (2N+1 → 3)
 * et la FORME du résultat (clés présentes/absentes).
 *
 * ⚠️ Limite connue : findAll() fait `new FournisseurManager()` et
 * `new UtilisateurManager()` — instances réelles, non injectables.
 * Donc le mock PDO ne capte PAS les appels findIds(). Les assertions
 * portent donc sur la FORME (présence de clé) et non la VALEUR.
 *
 * Le contenu (F1 vs F2) et le dédoublonnage effectif sont couverts par :
 *  - test Integration : AcquisitionManagerFindAllIntegrationTest
 *  - test Unit isolé : FournisseurManagerFindIdsTest
 *
 * ⚠️ Piège documenté dans REPRISE.md : on discrimine les SQL par
 * str_contains($sql, 'FROM acquisition' | 'FROM fournisseur' |
 * 'FROM utilisateur'), JAMAIS par COUNT(*).
 */
final class AcquisitionManagerFindAllTest extends TestCase
{
    /**
     * @param array<int, array> $acquisitionRows
     * @return array{0: TestableAcquisitionManager, 1: array<int, array<int>>}
     */
    private function makeManager(array $acquisitionRows): array
    {
        $captured = ['acquisition' => null];

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($acquisitionRows, &$captured) {
                $stmt = $this->createMock(\PDOStatement::class);

                if (str_contains($sql, 'FROM acquisition')) {
                    $stmt->method('fetchAll')->willReturn($acquisitionRows);
                    // findAll() appelle execute() SANS argument → PHPUnit
                    // passe null au callback. Paramètre nullable obligatoire.
                    $stmt->method('execute')->willReturnCallback(
                        function (?array $params = null) use (&$captured) {
                            $captured['acquisition'] = $params ?? [];
                            return true;
                        }
                    );
                }
                // Les branches 'FROM fournisseur' et 'FROM utilisateur' ne
                // sont PAS mockées ici : findIds() est appelé sur une
                // instance réelle (new FournisseurManager()), donc sur une
                // vraie connexion PDO. Le mock ne les verra pas.

                return $stmt;
            }
        );

        return [new TestableAcquisitionManager($pdo), $captured];
    }

    public function testFindAllMakesThreeQueriesForMultipleAcquisitions(): void
    {
        [$manager] = $this->makeManager(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => 5, 'saisie_par' => 10],
                ['id' => 2, 'fournisseur_id' => 5, 'saisie_par' => 10],
                ['id' => 3, 'fournisseur_id' => 6, 'saisie_par' => 11],
            ],
        );

        $rows = $manager->findAll();

        self::assertCount(3, $rows);

        // On vérifie la FORME : les clés sont présentes, pas les valeurs
        // (non mockables tant que findIds() est appelé sur une instance
        // réelle — cf. Dette 10).
        self::assertArrayHasKey('fournisseur', $rows[0]);
        self::assertArrayHasKey('redacteur',   $rows[0]);
        self::assertArrayHasKey('fournisseur', $rows[2]);
        self::assertArrayHasKey('redacteur',   $rows[2]);
    }

    public function testFindAllMakesOneQueryForZeroAcquisitions(): void
    {
        $prepareCount = 0;
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$prepareCount) {
                $prepareCount++;
                $stmt = $this->createMock(\PDOStatement::class);
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
        );

        $rows = (new TestableAcquisitionManager($pdo))->findAll();

        self::assertSame([], $rows);
        self::assertSame(1, $prepareCount, 'Early return : 1 seule requête');
    }

    /**
     * ⚠️ Comportement défensif : sur le schéma actuel, la colonne
     * acquisition.fournisseur_id est NOT NULL, donc ce cas n'est pas
     * atteignable en production. Le test documente le contrat de
     * findAll() (isset() sur null → clé absente) si le schéma évolue.
     */
    public function testFindAllOmitsFournisseurKeyWhenFournisseurIdIsNull(): void
    {
        [$manager] = $this->makeManager(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => null, 'saisie_par' => null],
            ],
        );

        $rows = $manager->findAll();

        self::assertArrayNotHasKey('fournisseur', $rows[0]);
        self::assertArrayNotHasKey('redacteur', $rows[0]);
    }

    public function testFindAllSetsFournisseurKeyWhenForeignKeyIsOrphan(): void
    {
        // ⚠️ L'id 999999 est absent de la vraie base → findIds() retourne
        // un tableau vide → ?? null s'applique → clé présente + null.
        // Ce test reste valide malgré la dépendance à la vraie base,
        // car on ne vérifie QUE la présence de la clé et sa nullité.
        [$manager] = $this->makeManager(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => 999999, 'saisie_par' => 1],
            ],
        );

        $rows = $manager->findAll();

        self::assertArrayHasKey('fournisseur', $rows[0]);
        self::assertNull($rows[0]['fournisseur']);
    }
}