<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\AlerteLimiteur;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableAbstractController;
use Epiclub\Tests\Unit\Controller\Support\TestableIndexController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [SÉCURITÉ — audit 2026-11-08 R4] Bandeau « limiteur de tentatives
 * indisponible » : visible du seul super-admin, jusqu'à son acquittement.
 */
final class AlerteLimiteurBandeauTest extends TestCase
{
    private const TITRE = 'Limiteur de tentatives de connexion indisponible';

    private AlerteLimiteur $alerte;
    private string $journalErreurs;

    protected function setUp(): void
    {
        $this->journalErreurs = (string) ini_set('error_log', '/dev/null');
        $this->alerte = AlerteLimiteur::parDefaut();
        $this->alerte->acquitter();
    }

    protected function tearDown(): void
    {
        $this->alerte->acquitter();
        ini_set('error_log', $this->journalErreurs);
    }

    public function testPasDeBandeauSansPanne(): void
    {
        self::assertStringNotContainsString(self::TITRE, $this->page('ROLE_SUPER_ADMIN'));
    }

    public function testBandeauPourLeSuperAdmin(): void
    {
        $this->alerte->signaler('connexion', new \PDOException("Table 'login_throttle' <b>absente</b>"));

        $page = $this->page('ROLE_SUPER_ADMIN');

        self::assertStringContainsString(self::TITRE, $page);
        self::assertStringContainsString('connexion', $page);
        self::assertStringContainsString('/admin/alerte-limiteur/acquitter', $page);
        // Message technique échappé.
        self::assertStringContainsString('&lt;b&gt;absente&lt;/b&gt;', $page);
    }

    public function testPasDeBandeauPourUnAdministrateur(): void
    {
        $this->alerte->signaler('connexion', new \RuntimeException('panne'));

        self::assertStringNotContainsString(self::TITRE, $this->page('ROLE_ADMIN'));
    }

    public function testAcquittementParLeSuperAdmin(): void
    {
        $this->alerte->signaler('connexion', new \RuntimeException('panne'));
        $session = $this->session('ROLE_SUPER_ADMIN');

        $reponse = (new TestableIndexController($session))
            ->acquitterAlerteLimiteur(Request::create('/admin/alerte-limiteur/acquitter', 'POST'));

        self::assertSame(302, $reponse->getStatusCode());
        self::assertNull($this->alerte->etat());
        self::assertNotEmpty($session->getFlashBag()->get('success'));
    }

    public function testAcquittementRefuseAUnAdministrateur(): void
    {
        $this->alerte->signaler('connexion', new \RuntimeException('panne'));

        try {
            (new TestableIndexController($this->session('ROLE_ADMIN')))
                ->acquitterAlerteLimiteur(Request::create('/admin/alerte-limiteur/acquitter', 'POST'));
            self::fail('AccessDeniedException attendue');
        } catch (AccessDeniedException) {
            self::assertNotNull($this->alerte->etat());
        }
    }

    private function page(string $role): string
    {
        return (string) (new TestableAbstractController($this->session($role)))
            ->render('user_forgot_password_confirm.twig')
            ->getContent();
    }

    private function session(string $role): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'jdupont', 'prenom' => 'Jean', 'nom' => 'Dupont', 'role' => $role]);
        $session->set('csrf_token', 'jeton');
        return $session;
    }
}
