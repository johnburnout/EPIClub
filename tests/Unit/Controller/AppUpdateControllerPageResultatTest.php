<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\AppUpdateController;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * [v1.5.1] Mise à jour v1.4.0 → v1.5.0 : la page de résultat, rendue par
 * l'ancien code avec les gabarits déjà remplacés, appelait pret_actif()
 * (fonction Twig inconnue de l'ancien code) → erreur après une mise à
 * jour pourtant réussie, et vendor/ non remplacé.
 */
final class AppUpdateControllerPageResultatTest extends TestCase
{
    private string $dossier = '';

    protected function tearDown(): void
    {
        if ($this->dossier !== '' && is_dir($this->dossier)) {
            array_map('unlink', glob($this->dossier . '/*') ?: []);
            rmdir($this->dossier);
        }
    }

    /**
     * Le principe du correctif : des gabarits compilés AVANT leur
     * remplacement sur le disque restent utilisés pour la requête en cours
     * (y compris le parent, chargé explicitement).
     */
    public function testGabaritsPrechargesSurviventAuRemplacementDesFichiers(): void
    {
        $this->dossier = sys_get_temp_dir() . '/epiclub-twig-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
        file_put_contents($this->dossier . '/_layout.twig', 'ANCIEN[{% block body %}{% endblock %}]');
        file_put_contents($this->dossier . '/update_result.twig', '{% extends "_layout.twig" %}{% block body %}{{ message }}{% endblock %}');

        $twig = new Environment(new FilesystemLoader($this->dossier));
        $twig->load('update_result.twig');
        $twig->load('_layout.twig');

        // La « nouvelle version » appelle une fonction inconnue de l'ancien code.
        file_put_contents($this->dossier . '/_layout.twig', '{{ fonction_de_la_nouvelle_version() }}{% block body %}{% endblock %}');
        file_put_contents($this->dossier . '/update_result.twig', '{% extends "_layout.twig" %}{% block body %}{{ fonction_de_la_nouvelle_version() }}{% endblock %}');

        self::assertSame('ANCIEN[OK]', $twig->render('update_result.twig', ['message' => 'OK']));
    }

    public function testRepliHtmlSiLaPageDeResultatNePeutEtreRendue(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'role' => 'ROLE_SUPER_ADMIN']);
        $provider = $this->createMock(GitHubReleaseProviderInterface::class);

        $controleur = new class($session, $provider) extends AppUpdateController {
            public function __construct(Session $s, private $p) { parent::__construct($s); }
            protected function githubReleaseProvider(): GitHubReleaseProviderInterface { return $this->p; }
            protected function updateLastActivity() {}
            public function render(string $template, array $data = []): Response
            {
                throw new \Twig\Error\SyntaxError('Unknown "pret_actif" function in "_layout.twig".');
            }
            public function resultatPourTest(array $data): Response { return $this->resultat($data); }
        };

        $reponse = $controleur->resultatPourTest(['success' => true, 'message' => 'Mise à jour réussie. Version actuelle : 1.5.1 <b>']);

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('✅ Mise à jour réussie', (string) $reponse->getContent());
        self::assertStringContainsString('1.5.1 &lt;b&gt;', (string) $reponse->getContent());
        self::assertStringContainsString('/admin/update', (string) $reponse->getContent());
    }
}
