<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleController;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [VAGUE 11 — issue #61]
 *
 * Vérifie que ControleController::edit() utilise bien les factories
 * categorieManager() / emplacementManager() (mockables) au lieu
 * d'instancier CategorieManager / EmplacementManager en dur.
 *
 * Sans ce refactor, edit() ouvre PDO à chaque rendu du formulaire
 * (N+1 sur les équipements), et le test Unit devient impossible.
 */
final class ControleControllerEditManagersTest extends TestCase
{
    /**
     * edit() doit appeler categorieManager() pour chaque équipement
     * ayant une categorie_id, et emplacementManager() pour chaque
     * équipement ayant un emplacement_id.
     *
     * Le test passe un contrôle minimal (ouvert, propriétaire = user),
     * un équipement disponible avec les deux FK renseignées, et vérifie
     * que les mocks injectés via le builder sont bien sollicités.
     */
    public function testEditUsesInjectedCategorieAndEmplacementManagers(): void
    {
        // --- Fixtures minimales ---
        $controle = [
            'id'             => 5,
            'statut'         => 'ouvert',
            'controleur_id'  => 42,
            'date_debut'     => date('Y-m-d H:i:s'),
            'hash_remarques' => null,
        ];

        $equipement = [
            'id'             => 100,
            'reference'      => 'REF-100',
            'libelle'        => 'Casque',
            'photo'          => null,
            'categorie_id'   => 7,
            'emplacement_id' => 3,
        ];

        // --- Mocks managers ---
        $controleManager = $this->mockManager(ControleManager::class);
        $controleManager->method('findId')->willReturn($controle);

        $ligneManager = $this->mockManager(ControleLigneManager::class);
        $ligneManager->method('findByControle')->willReturn([]);

        $equipementManager = $this->mockManager(EquipementManager::class);
        $equipementManager->method('findAll')->willReturn([$equipement]);

        // ⚠️ C'est LE point du test : les factories doivent être appelées.
        // Si edit() contient encore `new CategorieManager()` en dur,
        // ces expectations ne seront pas satisfaites → test rouge.
        $categorieManager = $this->mockManager(CategorieManager::class);
        $categorieManager->expects(self::atLeastOnce())
            ->method('findId')
            ->with(7)
            ->willReturn(['id' => 7, 'libelle' => 'EPI', 'est_epi' => 1]);
        $categorieManager->method('findAll')->willReturn([]);

        $emplacementManager = $this->mockManager(EmplacementManager::class);
        $emplacementManager->expects(self::atLeastOnce())
            ->method('findId')
            ->with(3)
            ->willReturn(['id' => 3, 'libelle' => 'Local A']);
        $emplacementManager->method('findAll')->willReturn([]);

        $controller = $this->makeControllerWith(
            controleManager:       $controleManager,
            controleLigneManager:  $ligneManager,
            equipementManager:     $equipementManager,
            categorieManager:      $categorieManager,
            emplacementManager:    $emplacementManager,
        );

        $request = Request::create('/admin/controles/edit/5', 'GET', ['id' => '5']);

        $response = $controller->edit($request);

        self::assertInstanceOf(Response::class, $response);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function mockManager(string $class): object
    {
        return (new \PHPUnit\Framework\MockObject\MockBuilder($this, $class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeControllerWith(
        ?ControleManager $controleManager = null,
        ?ControleLigneManager $controleLigneManager = null,
        ?EquipementManager $equipementManager = null,
        ?CategorieManager $categorieManager = null,
        ?EmplacementManager $emplacementManager = null,
    ): TestableControleController {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'                   => 42,
            'username'             => 'controleur',
            'role'                 => 'ROLE_CONTROLLEUR',
            'controle_en_cours_id' => null,
        ]);
        $session->set('csrf_token', 'test-csrf-token');

        $builder = (new TestableControleControllerBuilder($this))
            ->withSession($session);

        if ($controleManager !== null) {
            $builder->withControleManager($controleManager);
        }
        if ($controleLigneManager !== null) {
            $builder->withControleLigneManager($controleLigneManager);
        }
        if ($equipementManager !== null) {
            $builder->withEquipementManager($equipementManager);
        }
        // ⚠️ Ces deux méthodes n'existent pas encore dans le Builder.
        //    Le test ne compilera pas tant que l'étape 1.5 ne sera pas faite.
        if ($categorieManager !== null) {
            $builder->withCategorieManager($categorieManager);
        }
        if ($emplacementManager !== null) {
            $builder->withEmplacementManager($emplacementManager);
        }

        return $builder->build();
    }
}