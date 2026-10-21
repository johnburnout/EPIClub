<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\SauvegardeService;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableSauvegardeController;
use Epiclub\Tests\Unit\Engine\Support\FakeDatabaseDumper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Contrôleur de sauvegarde : accès SUPER_ADMIN, CSRF, confirmation
 * saisie avant restauration.
 */
final class SauvegardeControllerTest extends TestCase
{
    private string $racine;
    private FakeDatabaseDumper $base;
    private SauvegardeService $service;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/epiclub-sauvegarde-ctrl-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/images/equipements', 0777, true);
        file_put_contents($this->racine . '/images/equipements/photo.webp', 'photo');

        $this->base = new FakeDatabaseDumper();
        $this->base->tables = ['utilisateur' => [['id' => 1]]];
        $this->service = new SauvegardeService(
            $this->base,
            ['images' => $this->racine . '/images', 'uploads' => $this->racine . '/uploads'],
            $this->racine . '/backups',
            '1.2.0',
            SauvegardeService::empreinte('cle'),
            ['001_initial'],
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->racine);
    }

    public function testReserveAuSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controleur('ROLE_ADMIN')->index(new Request());
    }

    public function testIndexListeLesSauvegardesDuServeur(): void
    {
        $nom = basename($this->service->creer());

        $reponse = $this->controleur()->index(new Request());

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString($nom, (string) $reponse->getContent());
        self::assertStringContainsString('/admin/sauvegarde/exporter', (string) $reponse->getContent());
    }

    public function testExporterExigeLeJetonCsrf(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controleur()->exporter($this->post('/admin/sauvegarde/exporter', ['csrf_token' => 'mauvais']));
    }

    public function testExporterRenvoieLArchiveEnTelechargement(): void
    {
        $reponse = $this->controleur()->exporter($this->post('/admin/sauvegarde/exporter'));

        self::assertInstanceOf(BinaryFileResponse::class, $reponse);
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringContainsString('epiclub-sauvegarde-', (string) $reponse->headers->get('Content-Disposition'));
    }

    public function testVerifierAfficheLaConfirmation(): void
    {
        $nom = basename($this->service->creer());

        $reponse = $this->controleur()->verifier($this->get($nom));

        self::assertStringContainsString('Archive vérifiée', (string) $reponse->getContent());
        self::assertStringContainsString('/admin/sauvegarde/restaurer/' . $nom, (string) $reponse->getContent());
    }

    public function testVerifierAfficheLeRefus(): void
    {
        mkdir($this->racine . '/backups', 0777, true);
        file_put_contents($this->racine . '/backups/abime.zip', 'pas un zip');

        $reponse = $this->controleur()->verifier($this->get('abime.zip'));

        self::assertStringContainsString('ne peut pas être restaurée', (string) $reponse->getContent());
        self::assertStringNotContainsString('/admin/sauvegarde/restaurer/', (string) $reponse->getContent());
    }

    public function testRestaurerSansLaConfirmationSaisieNeFaitRien(): void
    {
        $nom = basename($this->service->creer());
        $controleur = $this->controleur();

        $reponse = $controleur->restaurer($this->post('/x', ['confirmation' => 'oui'], $nom));

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('/admin/sauvegarde/verifier/' . $nom, $reponse->getTargetUrl());
        self::assertSame(0, $this->base->imports);
        self::assertSame(0, $controleur->migrations);
    }

    public function testRestaurerAvecConfirmation(): void
    {
        $nom = $this->service->nomTeleversement();
        rename($this->service->creer(), $this->racine . '/backups/' . $nom);
        $this->base->tables['utilisateur'][] = ['id' => 2];
        $session = $this->session();
        $controleur = $this->controleur(session: $session);

        $reponse = $controleur->restaurer($this->post('/x', ['confirmation' => ' RESTAURER '], $nom));

        self::assertSame('/admin/sauvegarde', $reponse->getTargetUrl());
        self::assertCount(1, $this->base->tables['utilisateur']);
        self::assertSame(1, $controleur->migrations);
        self::assertFileDoesNotExist($this->racine . '/backups/' . $nom, 'archive téléversée supprimée après usage');
        self::assertStringContainsString('Restauration terminée', implode(' ', $session->getFlashBag()->get('success')));
    }

    public function testSupprimer(): void
    {
        $nom = basename($this->service->creer());

        $this->controleur()->supprimer($this->post('/x', [], $nom));

        self::assertFileDoesNotExist($this->racine . '/backups/' . $nom);
    }

    // ------------------------------------------------------------------

    private function session(string $role = 'ROLE_SUPER_ADMIN'): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'role' => $role]);
        $session->set('csrf_token', 'jeton');
        return $session;
    }

    private function controleur(string $role = 'ROLE_SUPER_ADMIN', ?Session $session = null): TestableSauvegardeController
    {
        return new TestableSauvegardeController($session ?? $this->session($role), $this->service);
    }

    private function get(string $nom): Request
    {
        $request = new Request();
        $request->attributes->set('nom', $nom);
        return $request;
    }

    /** @param array<string, string> $donnees */
    private function post(string $url, array $donnees = [], ?string $nom = null): Request
    {
        $request = Request::create($url, 'POST', $donnees + ['csrf_token' => 'jeton']);
        if ($nom !== null) {
            $request->attributes->set('nom', $nom);
        }
        return $request;
    }
}
