<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires de EquipementController::edit() — issue #47.
 *
 * ⚠️ Périmètre : whitelist du statut. Les scénarios nominaux
 * (upload photo, rendu du formulaire) restent couverts par la
 * suite Integration.
 */
final class EquipementControllerHandlersTest extends TestCase
{
    // ==================================================================
    // edit() — whitelist du statut (issue #47)
    // ==================================================================

    public function testEditRejectsInvalidStatut(): void
    {
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // findId() appelé une fois AVANT le POST pour charger l'équipement
        $equipementManager->method('findId')->willReturn([
            'id'                => 7,
            'reference'         => 'REF-007',
            'libelle'           => 'Casque',
            'code'              => 'EQ-007',
            'categorie_id'      => 1,
            'statut'            => 0,
            'controle_en_cours' => 0,
            'photo'             => null,
        ]);
        // ⚠️ save() NE DOIT PAS être appelé si le statut est invalide
        $equipementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith(equipementManager: $equipementManager);

        $request = $this->makePostRequest([
            'id'             => '7',
            'statut'         => 'hacked',
            'emplacement_id' => '',
            'remarques'      => 'test',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/equipements', $result->headers->get('Location'));
    }

    public function testEditRejectsEmptyStatut(): void
    {
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id'                => 7,
            'reference'         => 'REF-007',
            'libelle'           => 'Casque',
            'code'              => 'EQ-007',
            'categorie_id'      => 1,
            'statut'            => 0,
            'controle_en_cours' => 0,
            'photo'             => null,
        ]);
        $equipementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith(equipementManager: $equipementManager);

        $request = $this->makePostRequest([
            'id'     => '7',
            'statut' => '',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/equipements', $result->headers->get('Location'));
    }

    public function testEditRejectsOutOfRangeStatut(): void
    {
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id'                => 7,
            'reference'         => 'REF-007',
            'libelle'           => 'Casque',
            'code'              => 'EQ-007',
            'categorie_id'      => 1,
            'statut'            => 0,
            'controle_en_cours' => 0,
            'photo'             => null,
        ]);
        $equipementManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith(equipementManager: $equipementManager);

        $request = $this->makePostRequest([
            'id'     => '7',
            'statut' => '3',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/equipements', $result->headers->get('Location'));
    }

    /**
     * @return array<string, array{0:string, 1:int}>
     */
    public static function validStatutProvider(): array
    {
        return [
            'statut 0 → Disponible'     => ['0', 0],
            'statut 1 → En maintenance' => ['1', 1],
            'statut 2 → Hors service'   => ['2', 2],
        ];
    }

    #[DataProvider('validStatutProvider')]
    public function testEditAcceptsValidStatut(
        string $statutRaw,
        int $expectedStatut,
    ): void {
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $equipementManager->method('findId')->willReturn([
            'id'                => 7,
            'reference'         => 'REF-007',
            'libelle'           => 'Casque',
            'code'              => 'EQ-007',
            'categorie_id'      => 1,
            'statut'            => 0,
            'controle_en_cours' => 0,
            'photo'             => null,
        ]);
        // ⚠️ save() DOIT être appelé avec statut === int attendu
        $equipementManager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $e) use ($expectedStatut) {
                return isset($e['statut'])
                    && $e['statut'] === $expectedStatut
                    && (int) $e['id'] === 7;
            }))
            ->willReturn(true);

        $controller = $this->makeControllerWith(equipementManager: $equipementManager);

        $request = $this->makePostRequest([
            'id'             => '7',
            'statut'         => $statutRaw,
            'emplacement_id' => '',
            'remarques'      => 'test',
        ]);

        $result = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/equipements', $result->headers->get('Location'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(
        ?EquipementManager $equipementManager = null,
        ?Session $session = null,
    ): TestableEquipementController {
        $session ??= $this->makeSession();

        $builder = (new TestableEquipementControllerBuilder($this))
            ->withSession($session);

        if ($equipementManager !== null) {
            $builder->withEquipementManager($equipementManager);
        }

        return $builder->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        // ⚠️ edit() exige ROLE_ADMIN (pas ROLE_CONTROLLEUR)
        //    user['id'] = 42 → propriétaire potentiel (non requis pour edit)
        $session->set('user', [
            'id'       => 42,
            'username' => 'admin',
            'role'     => 'ROLE_ADMIN',
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
            '/equipements/edit',
            'POST',
            $post,
        );
    }
}