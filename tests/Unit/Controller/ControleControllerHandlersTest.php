<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleController;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests unitaires des handlers d'ControleController (issue #35).
 *
 * ⚠️ Périmètre : seuls les scénarios qui échouent AVANT tout appel BDD
 * sont testés ici (whitelist du statut). Les scénarios nominaux et les
 * erreurs BDD restent couverts par la suite Integration.
 */
final class ControleControllerHandlersTest extends TestCase
{
    // ==================================================================
    // updateLigne — whitelist du statut (commit 2)
    // ==================================================================

    public function testUpdateLigneRejectsInvalidStatut(): void
    {
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ligneManager->method('findId')->willReturn([
            'id' => 10,
            'controle_id' => 5,
            'equipement_id' => 42,
            'statut' => 'a_controler',
        ]);
        // ⚠️ save() NE DOIT PAS être appelé si le statut est invalide
        $ligneManager->expects(self::never())->method('save');

        $controleManager = $this->getMockBuilder(ControleManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controleManager->method('findId')->willReturn([
            'id' => 5,
            'statut' => 'ouvert',
            'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'),
        ]);

        $controller = $this->makeControllerWith(
            controleManager: $controleManager,
            controleLigneManager: $ligneManager,
        );

        $request = $this->makePostRequest([
            'id'            => '10',
            'statut'        => 'hacked',
            'remarque'      => 'test',
            'date_controle' => '2026-01-01 10:00:00',
        ]);

        $result = $controller->updateLigne($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame(
            '/admin/controles/edit/5',
            $result->headers->get('Location')
        );
    }

    public function testUpdateLigneAcceptsValidStatut(): void
    {
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ligneManager->method('findId')->willReturn([
            'id' => 10,
            'controle_id' => 5,
            'equipement_id' => 42,
            'statut' => 'a_controler',
        ]);
        // ⚠️ save() DOIT être appelé une fois avec le statut whitelisté
        $ligneManager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $ligne) {
                return $ligne['statut'] === 'controle_ok';
            }))
            ->willReturn(true);

        $controleManager = $this->getMockBuilder(ControleManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controleManager->method('findId')->willReturn([
            'id' => 5,
            'statut' => 'ouvert',
            'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'),
        ]);

        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id' => 42,
            'controle_en_cours' => 0,
        ]);
        $equipementManager->expects(self::once())->method('save');

        $controller = $this->makeControllerWith(
            controleManager: $controleManager,
            controleLigneManager: $ligneManager,
            equipementManager: $equipementManager,
        );

        $request = $this->makePostRequest([
            'id'            => '10',
            'statut'        => 'controle_ok',
            'remarque'      => 'test',
            'date_controle' => '2026-01-01 10:00:00',
        ]);

        $result = $controller->updateLigne($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame(
            '/admin/controles/edit/5',
            $result->headers->get('Location')
        );
    }

    public function testUpdateLigneRejectsEmptyStatut(): void
    {
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ligneManager->method('findId')->willReturn([
            'id' => 10,
            'controle_id' => 5,
            'equipement_id' => 42,
            'statut' => 'a_controler',
        ]);
        $ligneManager->expects(self::never())->method('save');

        $controleManager = $this->getMockBuilder(ControleManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controleManager->method('findId')->willReturn([
            'id' => 5,
            'statut' => 'ouvert',
            'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'),
        ]);

        $controller = $this->makeControllerWith(
            controleManager: $controleManager,
            controleLigneManager: $ligneManager,
        );

        $request = $this->makePostRequest([
            'id'     => '10',
            'statut' => '',  // ← vide
        ]);

        $result = $controller->updateLigne($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame(
            '/admin/controles/edit/5',
            $result->headers->get('Location')
        );
    }
    
    // ==================================================================
    // cloturer — switch exhaustif sur ControleLigneStatut (commit 3)
    // ==================================================================
    
    public function testCloturerLogsUnexpectedStatutAndSkipsEquipementStatut(): void
    {
        $controleManager = $this->getMockBuilder(ControleManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $controleManager->method('findId')->willReturn([
            'id'               => 5,
            'statut'           => 'ouvert',
            'controleur_id'    => 42,
            'date_debut'       => date('Y-m-d H:i:s'),
            'hash_remarques'   => null,
        ]);
        $controleManager->expects(self::once())->method('save');
        
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByControle')->willReturn([
            [
                'id'            => 10,
                'controle_id'   => 5,
                'equipement_id' => 42,
                'statut'        => 'inconnu',   // ← HORS ENUM
                'remarque'      => '',
            ],
        ]);
        // Pas de remarque non vide → pas de save de ligne
        
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id'                => 42,
            'statut'            => 0,           // ← valeur initiale
            'controle_en_cours' => 1,
        ]);
        // ⚠️ statut DOIT rester 0, controle_en_cours DOIT passer à 0
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(static function (array $e) {
            return (int) $e['statut'] === 0
            && (int) $e['controle_en_cours'] === 0;
        }));
        
        $controller = $this->makeControllerWith(
        controleManager: $controleManager,
        controleLigneManager: $ligneManager,
        equipementManager: $equipementManager,
        );
        
        $request = $this->makePostRequest([
            'id' => '5',
        ]);
        
        $result = $controller->cloturer($request);
        
    self::assertInstanceOf(RedirectResponse::class, $result);
    self::assertSame(
            '/admin/controles',
            $result->headers->get('Location')
        );
    }
    
    /**
    * @return array<string, array{0:string, 1:int}>
    */
    public static function statutMappingProvider(): array
    {
        return [
            'hors_service → 2' => ['hors_service', 2],
            'controle_ko → 1'  => ['controle_ko', 1],
            'controle_ok → 0'  => ['controle_ok', 0],
        ];
    }
    
    /**
    * @dataProvider statutMappingProvider
    */
    #[DataProvider('statutMappingProvider')]
    public function testCloturerMapsStatutToEquipementStatut(
        string $statutLigne,
        int $expectedEquipementStatut,
    ): void {
        $controleManager = $this->getMockBuilder(ControleManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $controleManager->method('findId')->willReturn([
            'id'               => 5,
            'statut'           => 'ouvert',
            'controleur_id'    => 42,
            'date_debut'       => date('Y-m-d H:i:s'),
            'hash_remarques'   => null,
        ]);
        $controleManager->expects(self::once())->method('save');
        
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByControle')->willReturn([
            [
                'id'            => 10,
                'controle_id'   => 5,
                'equipement_id' => 42,
                'statut'        => $statutLigne,
                'remarque'      => '',
            ],
        ]);
        
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id'                => 42,
            'statut'            => 0,
            'controle_en_cours' => 1,
        ]);
        $equipementManager->expects(self::once())
        ->method('save')
        ->with(self::callback(static function (array $e) use ($expectedEquipementStatut) {
            return (int) $e['statut'] === $expectedEquipementStatut
            && (int) $e['controle_en_cours'] === 0;
        }));
        
        $controller = $this->makeControllerWith(
        controleManager: $controleManager,
        controleLigneManager: $ligneManager,
        equipementManager: $equipementManager,
        );
        
        $request = $this->makePostRequest([
            'id' => '5',
        ]);
        
        $result = $controller->cloturer($request);
        
    self::assertInstanceOf(RedirectResponse::class, $result);
    self::assertSame(
            '/admin/controles',
            $result->headers->get('Location')
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Construit un contrôleur avec les mocks fournis.
     * Les paramètres null sont remplacés par des mocks silencieux du builder.
     */
    private function makeControllerWith(
        ?ControleManager $controleManager = null,
        ?ControleLigneManager $controleLigneManager = null,
        ?EquipementManager $equipementManager = null,
        ?Session $session = null,
    ): TestableControleController {
        $session ??= $this->makeSession();

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

        return $builder->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        // ⚠️ user['id'] = 42 → propriétaire du contrôle (controleur_id = 42)
        //    user['role'] = ROLE_CONTROLLEUR → passe deniAccessUnlessGranted()
        //    user['controle_en_cours_id'] = null → évite un warning PHP 8
        //       à la fin de cloturer() quand il compare avec l'id clôturé
        $session->set('user', [
            'id'                     => 42,
            'username'               => 'controleur',
            'role'                   => 'ROLE_CONTROLLEUR',
            'controle_en_cours_id'   => null,
        ]);
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    /**
    * @param array<string,mixed> $post
    */
    private function makePostRequest(array $post): Request
    {
        // ⚠️ Le token CSRF doit correspondre à celui setté dans makeSession()
        $post['csrf_token'] = $post['csrf_token'] ?? 'test-csrf-token';
        
        return Request::create(
            '/admin/controles/edit',
            'POST',
            $post,
        );
    }
    
    public function testUpdateLigneTruncatesLongRemarque(): void
    {
        $longRemarque = str_repeat('x', 3000); // > 2000
        
        $ligneManager = $this->getMockBuilder(ControleLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findId')->willReturn([
            'id' => 10,
            'controle_id' => 5,
            'equipement_id' => 42,
            'statut' => 'a_controler',
        ]);
        // ⚠️ save() DOIT être appelé avec remarque tronquée à 2000 chars
        $ligneManager->expects(self::once())
        ->method('save')
        ->with(self::callback(static function (array $l) {
            return mb_strlen($l['remarque']) === 2000;
        }))
        ->willReturn(true);
        
        $controleManager = $this->getMockBuilder(ControleManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $controleManager->method('findId')->willReturn([
            'id' => 5,
            'statut' => 'ouvert',
            'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'),
        ]);
        
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id' => 42,
            'controle_en_cours' => 0,
        ]);
        $equipementManager->expects(self::once())->method('save');
        
        $controller = $this->makeControllerWith(
        controleManager: $controleManager,
        controleLigneManager: $ligneManager,
        equipementManager: $equipementManager,
        );
        
        $request = $this->makePostRequest([
            'id'            => '10',
            'statut'        => 'controle_ok',
            'remarque'      => $longRemarque,
            'date_controle' => '2026-01-01 10:00:00',
        ]);
        
        $controller->updateLigne($request);
    }
}