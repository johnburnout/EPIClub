<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use Epiclub\Exception\MailDeliveryException;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUserRegisterController;
use Epiclub\Tests\Unit\Controller\Support\TestableAppUserRegisterControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires des handlers d'AppUserRegisterController (issue #51).
 *
 * Périmètre : forgotPassword() et resetPassword() — validation, appels
 * aux managers/services, cas d'erreur. Les scénarios nominaux et les
 * erreurs BDD restent couverts par la suite Integration.
 */
final class AppUserRegisterControllerHandlersTest extends TestCase
{
    // ==================================================================
    // forgotPassword() — validation email
    // ==================================================================

    public function testForgotPasswordRejectsInvalidEmail(): void
    {
        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->expects(self::never())->method('findOneByCriteria');

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest([
            'email' => 'not-an-email',
        ]);

        $response = $controller->forgotPassword($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // forgotPassword() — anti-énumération
    // ==================================================================

    public function testForgotPasswordDoesNotRevealUnknownEmail(): void
    {
        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn(null);
        $utilisateurManager->expects(self::never())->method('setResetToken');

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest(['email' => 'unknown@example.com']);

        $response = $controller->forgotPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/mot_de_passe_oublie/confirmation',
            $response->headers->get('Location')
        );
    }

    // ==================================================================
    // forgotPassword() — anti-spam
    // ==================================================================

    public function testForgotPasswordRejectsRepeatedRequest(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_email_sent_at' => (new \DateTime('-1 minute'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::never())->method('setResetToken');

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest(['email' => 'user@example.com']);

        $response = $controller->forgotPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/mot_de_passe_oublie/confirmation',
            $response->headers->get('Location')
        );
    }

    // ==================================================================
    // forgotPassword() — cas nominal
    // ==================================================================

    public function testForgotPasswordCallsSetResetToken(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_email_sent_at' => null,
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::once())
            ->method('setResetToken')
            ->with(
                self::equalTo(42),
                self::matchesRegularExpression('/^[a-f0-9]{64}$/'),
                self::isType('string'),
                self::isType('string'),
            )
            ->willReturn(true);

        $clubManager = $this->getMockBuilder(ClubManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $clubManager->method('findParameters')->willReturn(['email' => 'club@example.com']);

        $controller = $this->makeControllerWith(
            utilisateurManager: $utilisateurManager,
            clubManager: $clubManager,
        );

        $request = $this->makePostRequest(['email' => 'user@example.com']);

        $response = $controller->forgotPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/mot_de_passe_oublie/confirmation',
            $response->headers->get('Location')
        );
    }

    public function testForgotPasswordSendsEmailWhenClubEmailConfigured(): void
    {
        $user = ['id' => 42, 'email' => 'user@example.com', 'reset_email_sent_at' => null];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->method('setResetToken')->willReturn(true);

        $clubManager = $this->getMockBuilder(ClubManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $clubManager->method('findParameters')->willReturn(['email' => 'club@example.com']);

        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->expects(self::once())->method('sendEmail');

        $controller = $this->makeControllerWith(
            utilisateurManager: $utilisateurManager,
            clubManager: $clubManager,
            mailerService: $mailer,
        );

        $request = $this->makePostRequest(['email' => 'user@example.com']);

        $controller->forgotPassword($request);
    }

    public function testForgotPasswordDoesNotSendEmailWhenClubEmailMissing(): void
    {
        $user = ['id' => 42, 'email' => 'user@example.com', 'reset_email_sent_at' => null];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->method('setResetToken')->willReturn(true);

        $clubManager = $this->getMockBuilder(ClubManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $clubManager->method('findParameters')->willReturn(['email' => '']);

        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->expects(self::never())->method('sendEmail');

        $controller = $this->makeControllerWith(
            utilisateurManager: $utilisateurManager,
            clubManager: $clubManager,
            mailerService: $mailer,
        );

        $request = $this->makePostRequest(['email' => 'user@example.com']);

        $controller->forgotPassword($request);
    }

    public function testForgotPasswordIgnoresMailDeliveryFailure(): void
    {
        $user = ['id' => 42, 'email' => 'user@example.com', 'reset_email_sent_at' => null];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->method('setResetToken')->willReturn(true);

        $clubManager = $this->getMockBuilder(ClubManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $clubManager->method('findParameters')->willReturn(['email' => 'club@example.com']);

        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->method('sendEmail')->willThrowException(
            new MailDeliveryException('SMTP down')
        );

        $controller = $this->makeControllerWith(
            utilisateurManager: $utilisateurManager,
            clubManager: $clubManager,
            mailerService: $mailer,
        );

        $request = $this->makePostRequest(['email' => 'user@example.com']);

        $response = $controller->forgotPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // ==================================================================
    // resetPassword() — cas d'erreur token
    // ==================================================================

    public function testResetPasswordRedirectsWhenTokenMissing(): void
    {
        $controller = $this->makeControllerWith();

        $request = Request::create('/regenerer_mot_de_passe', 'GET');

        $response = $controller->resetPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->headers->get('Location'));
    }

    public function testResetPasswordRedirectsWhenTokenInvalid(): void
    {
        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn(null);

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = Request::create('/regenerer_mot_de_passe', 'GET', ['token' => 'invalid']);

        $response = $controller->resetPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->headers->get('Location'));
    }

    public function testResetPasswordClearsTokenWhenExpired(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_token' => 'tok',
            'reset_token_expires' => (new \DateTime('-1 hour'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::once())
            ->method('clearResetToken')
            ->with(42);

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = Request::create('/regenerer_mot_de_passe', 'GET', ['token' => 'tok']);

        $response = $controller->resetPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->headers->get('Location'));
    }

    // ==================================================================
    // resetPassword() — validation mot de passe
    // ==================================================================

    public function testResetPasswordRejectsShortPassword(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_token' => 'tok',
            'reset_token_expires' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest([
            'token' => 'tok',
            'password' => '12345',
            'confirm_password' => '12345',
        ]);

        $response = $controller->resetPassword($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testResetPasswordRejectsMismatchedPasswords(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_token' => 'tok',
            'reset_token_expires' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest([
            'token' => 'tok',
            'password' => 'validpassword',
            'confirm_password' => 'different',
        ]);

        $response = $controller->resetPassword($request);

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // resetPassword() — cas nominal
    // ==================================================================

    public function testResetPasswordSavesNewPasswordAndClearsToken(): void
    {
        $user = [
            'id' => 42,
            'email' => 'user@example.com',
            'reset_token' => 'tok',
            'reset_token_expires' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ];

        $utilisateurManager = $this->getMockBuilder(UtilisateurManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $utilisateurManager->method('findOneByCriteria')->willReturn($user);
        $utilisateurManager->expects(self::once())->method('save');
        $utilisateurManager->expects(self::once())
            ->method('clearResetToken')
            ->with(42);

        $controller = $this->makeControllerWith(utilisateurManager: $utilisateurManager);

        $request = $this->makePostRequest([
            'token' => 'tok',
            'password' => 'validpassword',
            'confirm_password' => 'validpassword',
        ]);

        $response = $controller->resetPassword($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/se_connecter', $response->headers->get('Location'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(
        ?UtilisateurManager $utilisateurManager = null,
        ?ClubManager $clubManager = null,
        ?MailerServiceInterface $mailerService = null,
        ?Session $session = null,
    ): TestableAppUserRegisterController {
        $session ??= $this->makeSession();

        $builder = (new TestableAppUserRegisterControllerBuilder($this))
            ->withSession($session);

        if ($utilisateurManager !== null) {
            $builder->withUtilisateurManager($utilisateurManager);
        }
        if ($clubManager !== null) {
            $builder->withClubManager($clubManager);
        }
        if ($mailerService !== null) {
            $builder->withMailerService($mailerService);
        }

        return $builder->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    /**
     * @param array<string,mixed> $post
     */
    private function makePostRequest(array $post): Request
    {
        $post['csrf_token'] = $post['csrf_token'] ?? 'test-csrf-token';

        return Request::create(
            '/mot_de_passe_oublie',
            'POST',
            $post,
        );
    }
}