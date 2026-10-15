<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ Dette 9] Test Integration — contraintes FOREIGN KEY sur
 * `acquisition` et `acquisition_ligne` (migration 005_foreign_keys).
 *
 * Vérifie via information_schema :
 *  - les 4 contraintes existent avec la bonne cible ;
 *  - acquisition_ligne.acquisition_id est ON DELETE CASCADE
 *    (les lignes n'ont pas d'existence indépendante) ;
 *  - les 3 autres sont RESTRICT (pas de suppression en cascade de
 *    données financières ou de comptes).
 *
 * ⚠️ Se skip si MySQL est indisponible OU si la migration 005 n'a pas
 * encore été appliquée (schéma_migrations) : le test devient effectif
 * dès que la base est migrée, sans casser les bases non migrées.
 *
 * ⚠️ Ne teste pas une méthode Domain et n'hérite pas de
 * AbstractControllerTestCase : serveur HTTP et login admin inutiles.
 */
final class ForeignKeysIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = $this->createPdo();
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL de test indisponible : ' . $e->getMessage());
            return;
        }

        if (!$this->migration005Applied()) {
            self::markTestSkipped(
                'Migration 005_foreign_keys non appliquée sur cette base. '
                . "Lancer la mise à jour (migrate) pour rendre ce test effectif."
            );
        }
    }

    public function testForeignKeysExistOnAcquisitionTables(): void
    {
        $fks = $this->fetchForeignKeys();

        $expected = [
            // contrainte => [table, colonne, table référencée, on_delete acceptés]
            // ⚠️ InnoDB rapporte RESTRICT (implicite) comme NO ACTION dans
            // information_schema — les deux sont sémantiquement identiques.
            'fk_acquisition_fournisseur' => ['acquisition', 'fournisseur_id', 'fournisseur', ['RESTRICT', 'NO ACTION']],
            'fk_acquisition_saisie_par' => ['acquisition', 'saisie_par', 'utilisateur', ['RESTRICT', 'NO ACTION']],
            'fk_acquisition_ligne_acquisition' => ['acquisition_ligne', 'acquisition_id', 'acquisition', ['CASCADE']],
            'fk_acquisition_ligne_categorie' => ['acquisition_ligne', 'categorie_id', 'categorie', ['RESTRICT', 'NO ACTION']],
        ];

        foreach ($expected as $constraint => [$table, $column, $refTable, $onDelete]) {
            self::assertArrayHasKey(
                $constraint,
                $fks,
                "Contrainte FK '$constraint' absente de $table.$column."
            );

            [$cTable, $cColumn, $cRefTable, $cOnDelete] = $fks[$constraint];
            self::assertSame($table, $cTable, "$constraint : mauvaise table");
            self::assertSame($column, $cColumn, "$constraint : mauvaise colonne");
            self::assertSame($refTable, $cRefTable, "$constraint : mauvaise table référencée");
            self::assertContains(
                $cOnDelete,
                $onDelete,
                "$constraint : ON DELETE '$cOnDelete' non attendu (attendu : " . implode(' ou ', $onDelete) . ').'
            );
        }
    }

    /**
    * @return array<string, array{0:string,1:string,2:string,3:string}>
    *         constraint => [table, colonne, table référencée, delete_rule]
    */
    private function fetchForeignKeys(): array
    {
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = :db
               AND k.TABLE_NAME IN ('acquisition', 'acquisition_ligne')
               AND k.REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $stmt->execute(['db' => $dbName]);

        $fks = [];
        foreach ($stmt->fetchAll() as $row) {
            $fks[$row['CONSTRAINT_NAME']] = [
                $row['TABLE_NAME'],
                $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'],
                $row['DELETE_RULE'],
            ];
        }
        return $fks;
    }

    private function migration005Applied(): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '005%'"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
    * Aligné sur AbstractControllerTestCase::createPdo()
    * (même mécanisme de lecture .env.local.php, mêmes options PDO).
    */
    private function createPdo(): \PDO
    {
        $envFile = dirname(__DIR__, 3) . '/.env.local.php';
        $config = file_exists($envFile) ? (include $envFile) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return new \PDO(
            'mysql:host=' . ($config['DB_HOST'] ?? '127.0.0.1')
            . ';dbname=' . ($config['DB_NAME'] ?? 'epiclub')
            . ';charset=utf8mb4',
            $config['DB_USER'] ?? 'root',
            $config['DB_PASS'] ?? '',
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
}
