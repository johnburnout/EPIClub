<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableClubController;
use Epiclub\Tests\Unit\Controller\Support\TestableClubControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [VAGUE 11 — issue #63]
 *
 * Vérifie que ClubController::show() utilise bien la factory
 * clubManager() (mockable) au lieu d'instancier ClubManager en dur.
 *
 * Sans ce refactor, show() ouvre PDO à chaque affichage de la page
 * /admin/club et le test Unit devient impossible.
 */
final class ClubControllerTest extends TestCase
{
    private const CSRF_TOKEN = 'test-csrf-token';

    // ==================================================================
    // Cas nominal GET
    // ==================================================================

    public function testShowGetRendersViewWithClubAndActivites(): void
    {
        $existingClub = [
            'id'          => 1,
            'nom'         => 'Club Test',
            'activite'    => 'Escalade',
            'description' => 'Un club sympa',
            'email'       => 'contact@club.test',
            'phone'       => '0102030405',
        ];

        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->expects(self::once())
            ->method('findParameters')
            ->willReturn($existingClub);
        $clubManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($clubManager);
        $request = Request::create('/admin/club', 'GET');

        $response = $controller->show($request);

        self::assertInstanceOf(Response::class, $response);
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // Validation nom
    // ==================================================================

    public function testShowPostWithEmptyNomFailsValidation(): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);
        $clubManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => '',
            'activite'    => '',
            'description' => '',
            'email'       => '',
            'phone'       => '',
        ]);

        $response = $controller->show($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertInstanceOf(Response::class, $response);
    }

    public function testShowPostWithWhitespaceOnlyNomFailsValidation(): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);
        $clubManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => '   ',
            'activite'    => '',
            'description' => '',
            'email'       => '',
            'phone'       => '',
        ]);

        $response = $controller->show($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // Validation email (dataProvider)
    // ==================================================================

    /**
     * @return array<string, array{string, bool}>
     */
    public static function emailProvider(): array
    {
        return [
            'vide accepté'          => ['', false],
            'valide simple'         => ['contact@club.test', false],
            'valide avec plus'      => ['club+info@club.test', false],
            'invalide sans @'       => ['pas-un-email', true],
            'invalide sans domaine' => ['user@', true],
            'invalide sans user'    => ['@club.test', true],
        ];
    }

    /** @return array<string, array{string, bool}> */
    public static function delaiEgareProvider(): array
    {
        return [
            'valide'      => ['18', false],
            'zéro'        => ['0', true],
            'trop grand'  => ['121', true],
            'texte'       => ['un an', true],
        ];
    }

    /** [migration 017] Délai avant suppression d'un équipement égaré. */
    #[DataProvider('delaiEgareProvider')]
    public function testShowPostDelaiSuppressionEgare(string $delai, bool $expectError): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);
        if ($expectError) {
            $clubManager->expects(self::never())->method('save');
        } else {
            $clubManager->expects(self::once())->method('save')
                ->with(self::callback(fn(array $c) => $c['delai_suppression_egare_mois'] === 18));
        }

        $response = $this->makeControllerWith($clubManager)->show($this->postRequest([
            'nom' => 'Club Test', 'activite' => '', 'description' => '', 'email' => '', 'phone' => '',
            'delai_suppression_egare_mois' => $delai,
        ]));

        self::assertSame(!$expectError, $response instanceof RedirectResponse);
    }

    #[DataProvider('emailProvider')]
    public function testShowPostEmailValidation(string $email, bool $expectError): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);

        if ($expectError) {
            $clubManager->expects(self::never())->method('save');
        } else {
            $clubManager->expects(self::once())->method('save');
        }

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => 'Club Test',
            'activite'    => '',
            'description' => '',
            'email'       => $email,
            'phone'       => '',
        ]);

        $response = $controller->show($request);

        if ($expectError) {
            self::assertNotInstanceOf(RedirectResponse::class, $response);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
        }
    }

    // ==================================================================
    // Validation activité (whitelist self::ACTIVITES)
    // ==================================================================

    /**
     * @return array<string, array{string, bool}>
     */
    public static function activiteProvider(): array
    {
        return [
            'vide accepté'       => ['', false],
            'Alpinisme'          => ['Alpinisme', false],
            'Escalade'           => ['Escalade', false],
            'Spéléologie'        => ['Spéléologie', false],
            'Canoé-Kayac'        => ['Canoé-Kayac', false],
            'Multi-activités'    => ['Multi-activités', false],
            'hors whitelist'     => ['Plongée', true],
            'injection SQL-like' => ["Escalade'; DROP TABLE club;--", true],
            'casse différente'   => ['escalade', true],
        ];
    }

    #[DataProvider('activiteProvider')]
    public function testShowPostActiviteWhitelist(string $activite, bool $expectError): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);

        if ($expectError) {
            $clubManager->expects(self::never())->method('save');
        } else {
            $clubManager->expects(self::once())->method('save');
        }

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => 'Club Test',
            'activite'    => $activite,
            'description' => '',
            'email'       => '',
            'phone'       => '',
        ]);

        $response = $controller->show($request);

        if ($expectError) {
            self::assertNotInstanceOf(RedirectResponse::class, $response);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
        }
    }

    // ==================================================================
    // Cas nominal POST
    // ==================================================================

    public function testShowPostValidSavesAndRedirects(): void
    {
        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn([
            'id'   => 1,
            'nom'  => 'Ancien nom',
        ]);

        $saved = null;
        $clubManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $club) use (&$saved) {
                $saved = $club;
                return 1;
            });

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => 'Nouveau nom',
            'activite'    => 'Escalade',
            'description' => 'Description mise à jour',
            'email'       => 'contact@club.test',
            'phone'       => '0605040302',
        ]);

        $response = $controller->show($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/club', $response->getTargetUrl());

        self::assertIsArray($saved);
        self::assertSame('Nouveau nom', $saved['nom']);
        self::assertSame('Escalade', $saved['activite']);
        self::assertSame('contact@club.test', $saved['email']);
        self::assertSame('0605040302', $saved['phone']);
    }

    // ==================================================================
    // Troncature défensive du nom (varchar(64))
    // ==================================================================

    public function testShowPostTruncatesNomTo64Chars(): void
    {
        $longNom = str_repeat('a', 100);

        $clubManager = $this->mockManager(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['id' => 1]);

        $saved = null;
        $clubManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $club) use (&$saved) {
                $saved = $club;
                return 1;
            });

        $controller = $this->makeControllerWith($clubManager);
        $request = $this->postRequest([
            'nom'         => $longNom,
            'activite'    => '',
            'description' => '',
            'email'       => '',
            'phone'       => '',
        ]);

        $controller->show($request);

        self::assertIsArray($saved);
        self::assertSame(64, mb_strlen($saved['nom']));
        self::assertSame(str_repeat('a', 64), $saved['nom']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Construit une Request POST avec le token CSRF posé explicitement.
     *
     * On utilise replace() PUIS set() :
     *  - replace() écrase tout le body avec $params
     *  - set() garantit la présence du token même si $params l'a perdu
     *
     * C'est ceinture + bretelles, mais ça évite les surprises avec
     * ParameterBag::replace() et les valeurs null.
     *
     * @param array<string, mixed> $params
     */
    private function postRequest(array $params): Request
    {
        $request = Request::create('/admin/club', 'POST');
        $request->request->replace($params);
        $request->request->set('csrf_token', self::CSRF_TOKEN);
        return $request;
    }

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

    private function makeControllerWith(?ClubManager $clubManager = null): TestableClubController
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', [
            'id'       => 1,
            'username' => 'superadmin',
            'role'     => 'ROLE_SUPER_ADMIN',
        ]);
        $session->set('csrf_token', self::CSRF_TOKEN);

        $builder = (new TestableClubControllerBuilder($this))
            ->withSession($session);

        if ($clubManager !== null) {
            $builder->withClubManager($clubManager);
        }

        return $builder->build();
    }
}