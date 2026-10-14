<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\FournisseurManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #71 / #80] Tests Unit de AcquisitionManager::findAll().
 *
 * Objectif : vérifier le NOMBRE de requêtes SQL (2N+1 → 3) ET la FORME
 * du résultat (clés présentes/absentes, valeurs).
 *
 * [REFACTOR #80 - Dette 10] Grâce aux factories fournisseurManager()
 * et utilisateurManager() extraites dans AcquisitionManager, on peut
 * désormais mocker les managers imbriqués et compter les requêtes
 * ET vérifier les valeurs (plus seulement la forme).
 *
 * ⚠️ Piège documenté dans REPRISE.md : on discrimine les SQL par
 * str_contains($sql, 'FROM acquisition'), JAMAIS par COUNT(*).
 */
final class AcquisitionManagerFindAllTest extends TestCase
{
    /**
    * @param array<int, array> $acquisitionRows
    * @param array<int, array> $fournisseurRows
    * @param array<int, array> $utilisateurRows
    * @return array{0: TestableAcquisitionManager, 1: \stdClass}
    */
    private function makeManagerWithMocks(
        array $acquisitionRows,
        array $fournisseurRows = [],
        array $utilisateurRows = [],
    ): array {
        // [REFACTOR #80] Compteur sous forme d'objet (stdClass) :
        // un tableau serait copié par valeur à la sortie de la méthode,
        // alors que les closures capturent par handle. Les incrémentations
        // restent donc visibles après findAll().
        $counters = new \stdClass();
        $counters->acquisition = 0;
        $counters->fournisseur = 0;
        $counters->utilisateur = 0;
        
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($acquisitionRows, $counters) {
                $stmt = $this->createMock(\PDOStatement::class);
                if (str_contains($sql, 'FROM acquisition')) {
                    $counters->acquisition++;
                    $stmt->method('fetchAll')->willReturn($acquisitionRows);
                    $stmt->method('execute')->willReturn(true);
                }
                return $stmt;
            }
        );
        
        $fournisseurManager = $this->getMockBuilder(FournisseurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $fournisseurManager->method('findIds')->willReturnCallback(
            function (array $ids) use ($fournisseurRows, $counters) {
                $counters->fournisseur++;
                $indexed = [];
                foreach ($fournisseurRows as $row) {
                    if (in_array((int) $row['id'], $ids, true)) {
                        $indexed[$row['id']] = $row;
                    }
                }
                return $indexed;
            }
        );
        
        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $utilisateurManager->method('findIds')->willReturnCallback(
            function (array $ids) use ($utilisateurRows, $counters) {
                $counters->utilisateur++;
                $indexed = [];
                foreach ($utilisateurRows as $row) {
                    if (in_array((int) $row['id'], $ids, true)) {
                        $indexed[$row['id']] = $row;
                    }
                }
                return $indexed;
            }
        );
        
        $manager = (new TestableAcquisitionManager($pdo))
        ->withFournisseurManager($fournisseurManager)
        ->withUtilisateurManager($utilisateurManager);
        
        return [$manager, $counters];
    }

    public function testFindAllMakesThreeQueriesForMultipleAcquisitions(): void
    {
        [$manager, $counters] = $this->makeManagerWithMocks(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => 5, 'saisie_par' => 10],
                ['id' => 2, 'fournisseur_id' => 5, 'saisie_par' => 10],
                ['id' => 3, 'fournisseur_id' => 6, 'saisie_par' => 11],
            ],
            fournisseurRows: [
                ['id' => 5, 'nom' => 'F5'],
                ['id' => 6, 'nom' => 'F6'],
            ],
            utilisateurRows: [
                ['id' => 10, 'nom' => 'U10'],
                ['id' => 11, 'nom' => 'U11'],
            ],
        );

        $rows = $manager->findAll();

        self::assertCount(3, $rows);
        self::assertSame(1, $counters->acquisition, '1 SELECT acquisition');
        self::assertSame(1, $counters->fournisseur, '1 findIds fournisseur');
        self::assertSame(1, $counters->utilisateur, '1 findIds utilisateur');

        // [REFACTOR #80] Vérification des valeurs (possible depuis le mock)
        self::assertSame('F5', $rows[0]['fournisseur']['nom']);
        self::assertSame('U10', $rows[0]['redacteur']['nom']);
        self::assertSame('F6', $rows[2]['fournisseur']['nom']);
        self::assertSame('U11', $rows[2]['redacteur']['nom']);
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
        [$manager] = $this->makeManagerWithMocks(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => null, 'saisie_par' => null],
            ],
        );

        $rows = $manager->findAll();

        self::assertArrayNotHasKey('fournisseur', $rows[0]);
        self::assertArrayNotHasKey('redacteur', $rows[0]);
    }

    public function testFindAllSetsFournisseurKeyToNullWhenForeignKeyIsOrphan(): void
    {
        [$manager, $counters] = $this->makeManagerWithMocks(
            acquisitionRows: [
                ['id' => 1, 'fournisseur_id' => 999999, 'saisie_par' => 1],
            ],
            fournisseurRows: [], // pas de fournisseur 999999
            utilisateurRows: [
                ['id' => 1, 'nom' => 'U1'],
            ],
        );

        $rows = $manager->findAll();

        self::assertArrayHasKey('fournisseur', $rows[0]);
        self::assertNull($rows[0]['fournisseur']);
        self::assertArrayHasKey('redacteur', $rows[0]);
        self::assertSame('U1', $rows[0]['redacteur']['nom']);
    }
}