<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Exception\MailDeliveryException;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurController;
use Epiclub\Tests\Unit\Controller\Support\TestableUtilisateurControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mime\Email;

/**
 * [2026-10-24] Création d'un utilisateur : un mail l'en informe
 * (identifiant, rôle, lien de connexion), sans jamais son mot de passe.
 * Un échec d'envoi n'empêche pas la création. Le mail de bienvenue peut
 * être renvoyé depuis la fiche utilisateur.
 */
final class UtilisateurControllerMailCreationTest extends TestCase
{
    private Session $session;
    /** @var list<Email> */
    private array $mails = [];
    private bool $panneSmtp = false;
    private bool $sauve = false;

    private function creer(array $club = ['nom' => 'Club Alpin', 'email' => 'club@club.test']): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 42, 'username' => 'admin', 'role' => 'ROLE_SUPER_ADMIN']);
        $this->session->set('csrf_token', 'csrf');

        $manager = $this->createMock(UtilisateurManager::class);
        $manager->method('save')->willReturnCallback(function () {
            $this->sauve = true;
            return 1;
        });
        $clubManager = $this->createMock(ClubManager::class);
        $clubManager->method('findParameters')->willReturn($club);
        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->method('sendEmail')->willReturnCallback(function (Email $e) {
            if ($this->panneSmtp) {
                throw new MailDeliveryException('SMTP indisponible');
            }
            $this->mails[] = $e;
        });

        /** @var TestableUtilisateurController $controller */
        $controller = (new TestableUtilisateurControllerBuilder($this))
            ->withSession($this->session)
            ->withUtilisateurManager($manager)
            ->build();
        $controller->clubManagerOverride = $clubManager;
        $controller->mailerOverride = $mailer;

        $controller->edit(Request::create('/admin/utilisateurs/nouveau', 'POST', [
            'csrf_token' => 'csrf', 'nom' => 'Martin', 'prenom' => 'Lucie', 'username' => 'lmartin',
            'email' => 'lucie@club.test', 'role' => 'ROLE_CONTROLLEUR', 'password' => 'motdepasse-secret-42',
        ]));
    }

    private function flashes(string $type): string
    {
        return implode(' | ', $this->session->getFlashBag()->peek($type));
    }

    public function testMailDInformationSansLeMotDePasse(): void
    {
        $this->creer();

        self::assertTrue($this->sauve);
        self::assertCount(1, $this->mails);
        $mail = $this->mails[0];
        self::assertSame('lucie@club.test', $mail->getTo()[0]->getAddress());
        self::assertSame('club@club.test', $mail->getFrom()[0]->getAddress());
        self::assertSame('Votre compte Club Alpin', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('lmartin', $html);
        self::assertStringContainsString('Contrôleur', $html);
        self::assertStringContainsString('https://club.test/se_connecter', $html);
        self::assertStringContainsString('https://club.test/mot_de_passe_oublie', $html);
        self::assertStringNotContainsString('motdepasse-secret-42', $html);
        self::assertStringContainsString('Un mail l\'en informe', $this->flashes('success'));
    }

    public function testEchecDEnvoiNEmpechePasLaCreation(): void
    {
        $this->panneSmtp = true;
        $this->creer();

        self::assertTrue($this->sauve);
        self::assertSame("L'utilisateur a été créé.", $this->flashes('success'));
        self::assertStringContainsString("n'a pas pu être envoyé", $this->flashes('note'));
    }

    // ---------------- Renvoi depuis la fiche ----------------

    private const COMPTES = [
        5 => ['id' => 5, 'nom' => 'Martin', 'prenom' => 'Lucie', 'username' => 'lmartin', 'email' => 'lucie@club.test',
              'role' => 'ROLE_CONTROLLEUR', 'password' => '$2y$10$hashSecret', 'est_protege' => 0],
        6 => ['id' => 6, 'nom' => 'Chef', 'prenom' => 'Sam', 'username' => 'sam', 'email' => 'sam@club.test',
              'role' => 'ROLE_SUPER_ADMIN', 'password' => '$2y$10$hashSecret', 'est_protege' => 0],
    ];

    private function controleurPour(string $acteur): TestableUtilisateurController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 42, 'username' => 'admin', 'role' => $acteur]);
        $this->session->set('csrf_token', 'csrf');

        $manager = $this->createMock(UtilisateurManager::class);
        $manager->method('findId')->willReturnCallback(fn (int $id) => self::COMPTES[$id] ?? null);
        $manager->method('findAll')->willReturn(array_values(self::COMPTES));
        $clubManager = $this->createMock(ClubManager::class);
        $clubManager->method('findParameters')->willReturn(['nom' => 'Club Alpin', 'email' => 'club@club.test']);
        $mailer = $this->createMock(MailerServiceInterface::class);
        $mailer->method('sendEmail')->willReturnCallback(function (Email $e) {
            if ($this->panneSmtp) {
                throw new MailDeliveryException('SMTP indisponible');
            }
            $this->mails[] = $e;
        });

        /** @var TestableUtilisateurController $controller */
        $controller = (new TestableUtilisateurControllerBuilder($this))
            ->withSession($this->session)
            ->withUtilisateurManager($manager)
            ->build();
        $controller->clubManagerOverride = $clubManager;
        $controller->mailerOverride = $mailer;
        return $controller;
    }

    private function renvoyer(int $id, string $acteur = 'ROLE_ADMIN', string $csrf = 'csrf'): string
    {
        $request = Request::create('/admin/utilisateurs/utilisateur_bienvenue-' . $id, 'POST', ['csrf_token' => $csrf]);
        $request->attributes->set('id', (string) $id);
        return (string) $this->controleurPour($acteur)->renvoyerBienvenue($request)->headers->get('Location');
    }

    public function testBoutonSurLaFiche(): void
    {
        $request = Request::create('/admin/utilisateurs/utilisateur-5');
        $request->attributes->set('id', '5');
        $html = (string) $this->controleurPour('ROLE_ADMIN')->show($request)->getContent();

        self::assertStringContainsString('form="bienvenue-utilisateur-form"', $html);
        self::assertStringContainsString('action="/admin/utilisateurs/utilisateur_bienvenue-5"', $html);
    }

    public function testRenvoiDuMailSansLeHashDuMotDePasse(): void
    {
        self::assertSame('/admin/utilisateurs/utilisateur-5', $this->renvoyer(5));

        self::assertCount(1, $this->mails);
        self::assertSame('lucie@club.test', $this->mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('lmartin', (string) $this->mails[0]->getHtmlBody());
        self::assertStringNotContainsString('hashSecret', (string) $this->mails[0]->getHtmlBody());
        self::assertStringContainsString('renvoyé à lucie@club.test', $this->flashes('success'));
    }

    public function testRenvoiEnEchec(): void
    {
        $this->panneSmtp = true;
        self::assertSame('/admin/utilisateurs/utilisateur-5', $this->renvoyer(5));

        self::assertStringContainsString("n'a pas pu être envoyé", $this->flashes('error'));
    }

    public function testUnAdminNeRenvoiePasAUnSuperAdmin(): void
    {
        self::assertSame('/admin/utilisateurs', $this->renvoyer(6));
        self::assertSame([], $this->mails);

        $this->renvoyer(6, 'ROLE_SUPER_ADMIN');
        self::assertCount(1, $this->mails);
    }

    public function testRenvoiCsrfObligatoire(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->renvoyer(5, 'ROLE_ADMIN', 'faux');
    }

    public function testRenvoiInterditAuControleur(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->renvoyer(5, 'ROLE_CONTROLLEUR');
    }

    public function testSansEmailDuClubPasDEnvoi(): void
    {
        $this->creer(['nom' => 'Club Alpin', 'email' => '']);

        self::assertTrue($this->sauve);
        self::assertSame([], $this->mails);
        self::assertStringContainsString("n'a pas pu être envoyé", $this->flashes('note'));
    }
}
