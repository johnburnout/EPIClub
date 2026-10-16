<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\QrRedirectController;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableQrRedirectController;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR #73] Tests Unit de QrRedirectController.
 *
 * ⚠️ Périmètre : seuls les cas testables SANS déclencher header()+exit
 * sont couverts. Les méthodes publiques (redirect, choicePage,
 * generateQr, downloadQr, viewQr, saveQr, apiGenerateQr) appellent
 * header()+exit, ce qui tue le processus PHP — non testables en Unit.
 *
 * ⚠️ Note : AbstractController::__construct() appelle
 * Session::get('csrf_token') pour alimenter le global Twig.
 * Les mocks Session doivent donc tolérer cet appel.
 */
final class QrRedirectControllerTest extends TestCase
{
    /**
     * Test rouge avant fix #73 :
     * QrRedirectController::__construct() faisait 6 new XxxManager()
     * en dur, ouvrant 4 connexions PDO à chaque instanciation.
     * Après fix : les managers sont fournis par des factories
     * protected, mockables en test.
     */
    public function testConstructDoesNotThrow(): void
    {
        $session = $this->makeSessionMock();
        $equipementManager = $this->getMockBuilder(EquipementManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controleManager = $this->getMockBuilder(ControleManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $controller = new TestableQrRedirectController(
            $session,
            $equipementManager,
            $controleManager,
        );

        self::assertInstanceOf(QrRedirectController::class, $controller);
    }

    /**
    * Vérifie que la factory equipementManager() est protected et typée.
    *
    * ⚠️ On ne teste PAS le comportement de la factory (retourner un
    * mock) : ReflectionMethod::invoke() sur la classe PARENTE exécute
    * le code parent, pas la surcharge du testable — et tester le
    * parent ouvrirait une vraie connexion PDO (cf. Dette 10).
    *
    * On teste donc le CONTRAT Vague 11 : la méthode existe, est
    * protected, ne prend pas d'argument, retourne EquipementManager.
    */
    public function testEquipementManagerFactoryContract(): void
    {
        $reflection = new \ReflectionMethod(QrRedirectController::class, 'equipementManager');
        
    self::assertTrue($reflection->isProtected(), 'La factory doit être protected');
    self::assertSame(0, $reflection->getNumberOfRequiredParameters());
    self::assertSame(EquipementManager::class, (string) $reflection->getReturnType());
    }

    /**
     * getCurrentUser() retourne null si la session n'a pas d'utilisateur.
     */
    public function testGetCurrentUserReturnsNullWhenNoUserInSession(): void
    {
        $session = $this->makeSessionMock(user: null);

        $controller = new TestableQrRedirectController($session);

        $reflection = new \ReflectionMethod(QrRedirectController::class, 'getCurrentUser');
        $result = $reflection->invoke($controller);

        self::assertNull($result);
    }

    /**
     * getCurrentUser() retourne l'utilisateur si présent dans la session.
     */
    public function testGetCurrentUserReturnsUserFromSession(): void
    {
        $user = ['id' => 42, 'username' => 'admin'];
        $session = $this->makeSessionMock(user: $user);

        $controller = new TestableQrRedirectController($session);

        $reflection = new \ReflectionMethod(QrRedirectController::class, 'getCurrentUser');
        $result = $reflection->invoke($controller);

        self::assertSame($user, $result);
    }

    /**
     * [FIX #94 - Dette 12] addFlash() écrit dans le FlashBag Symfony
     * (lu par session.getFlashBag.all dans les templates) — et plus
     * dans $_SESSION['flash_bag'], clé jamais consommée : les messages
     * des écrans QR étaient silencieusement perdus.
     */
    public function testAddFlashWritesToSymfonyFlashBag(): void
    {
        $flashBag = new \Symfony\Component\HttpFoundation\Session\Flash\FlashBag();
        $session = $this->makeSessionMock();
        $session->method('getFlashBag')->willReturn($flashBag);

        $controller = new TestableQrRedirectController($session);

        $reflection = new \ReflectionMethod(QrRedirectController::class, 'addFlash');
        $reflection->invoke($controller, 'danger', 'Équipement non trouvé');

        self::assertSame(['Équipement non trouvé'], $flashBag->peek('danger'));
        self::assertArrayNotHasKey('flash_bag', $_SESSION ?? []);
    }

    /**
     * [Dette 12] Les méthodes privées mixtes getControleById() /
     * getUtilisateurById() (chaînes method_exists sur les managers)
     * étaient du code mort : zéro appel dans tout le projet.
     * Elles ont été supprimées — ce test fige la suppression.
     * [#96] Même traitement pour la factory qrCodeReader() : la classe
     * QrCodeReader était entièrement morte (zéro appelant de read /
     * readAsBase64 / exists / getFilePath) et contenait le dernier
     * header()+exit du projet.
     */
    public function testDeadMethodExistsHelpersAreRemoved(): void
    {
        self::assertFalse(
            method_exists(QrRedirectController::class, 'getControleById'),
            'getControleById() : code mort supprimé (#94)'
        );
        self::assertFalse(
            method_exists(QrRedirectController::class, 'getUtilisateurById'),
            'getUtilisateurById() : code mort supprimé (#94)'
        );
        self::assertFalse(
            method_exists(QrRedirectController::class, 'qrCodeReader'),
            'qrCodeReader() : classe morte supprimée (#96)'
        );
        self::assertFalse(
            class_exists(\Epiclub\Engine\QrCodeReader::class, false),
            'QrCodeReader : classe morte supprimée (#96)'
        );
    }

    /**
     * Construit un mock Session tolérant : retourne la valeur fournie
     * pour 'user', null pour toute autre clé (dont 'csrf_token' appelé
     * par AbstractController::__construct()).
     *
     * @param array<string,mixed>|null $user
     */
    private function makeSessionMock(?array $user = null): Session
    {
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) use ($user) {
                if ($key === 'user') {
                    return $user;
                }
                return $default;
            }
        );
        return $session;
    }
}