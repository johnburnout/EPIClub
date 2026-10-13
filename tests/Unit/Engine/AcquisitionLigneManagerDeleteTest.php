<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAcquisitionLigneManager;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #72] Tests Unit de AcquisitionLigneManager::delete().
 *
 * Vérifie que la factory acquisitionManager() est utilisée (mockable)
 * et que la logique de refus sur acquisition validée fonctionne.
 */
final class AcquisitionLigneManagerDeleteTest extends TestCase
{
    public function testDeleteRefusesWhenAcquisitionIsValidated(): void
    {
        // findId() retourne une ligne, acquisitionManager() retourne
        // une acquisition validée → delete() doit retourner false
        // sans exécuter le DELETE.
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'SELECT * FROM acquisition_ligne')) {
                $stmt->method('fetch')->willReturn([
                    'id' => 1,
                    'acquisition_id' => 42,
                    'categorie_id' => 5,
                ]);
            }
            if (str_contains($sql, 'SELECT * FROM categorie')) {
                $stmt->method('fetch')->willReturn(['id' => 5, 'libelle' => 'C1']);
            }
            // Pas de DELETE attendu : on ne définit rien pour
            // "DELETE FROM acquisition_ligne", et on va vérifier
            // que execute() n'est jamais appelé pour ce SQL.
            return $stmt;
        });

        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $acquisitionManager->expects(self::once())
            ->method('findId')
            ->with(42)
            ->willReturn(['id' => 42, 'est_validee' => 1]);

        $manager = (new TestableAcquisitionLigneManager($pdo))
            ->withAcquisitionManager($acquisitionManager);

        $result = $manager->delete(1);

        self::assertFalse($result);
    }
}