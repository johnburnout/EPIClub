<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\PretLigneManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Regroupement d'équipements identiques (même libellé, même
 * catégorie, même acquisition) en lot, depuis le formulaire de
 * modification (admin et plus), mot de confirmation REGROUPER.
 */
final class EquipementControllerRegroupementTest extends TestCase
{
    private Session $session;
    private ?array $sauve = null;
    /** @var int[] */
    private array $archives = [];
    /** @var int[] Équipements inscrits sur un prêt ouvert */
    private array $enPret = [];
    /** @var array<int, array> */
    private array $equipements;

    protected function setUp(): void
    {
        $base = ['acquisition_id' => 9, 'categorie_id' => 2, 'libelle' => 'Mousqueton', 'statut' => 0,
                 'controle_en_cours' => 0, 'emplacement_id' => null, 'nombre' => 1, 'nombre_initial' => 1,
                 'date_mise_en_service' => '2024-05-14', 'date_fin_utilisation' => null];
        $this->equipements = [
            4 => ['id' => 4, 'reference' => '24MOU001'] + $base,
            5 => ['id' => 5, 'reference' => '24MOU002'] + $base,
            6 => ['id' => 6, 'reference' => '24MOU003', 'nombre' => 2, 'nombre_initial' => 3] + $base,
            7 => ['id' => 7, 'reference' => '24MOU004', 'statut' => 1] + $base,     // statut différent
            8 => ['id' => 8, 'reference' => '24COR001', 'libelle' => 'Corde'] + $base, // pas identique
        ];
    }

    private function controller(string $role): TestableEquipementController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $this->equipements[$id] ?? null);
        $equipements->method('findByAcquisition')->willReturnCallback(fn () => array_values($this->equipements));
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->sauve = $e;
            return true;
        });
        $equipements->method('delete')->willReturnCallback(function (int $id) {
            $this->archives[] = $id;
            return true;
        });
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);

        $controller = new TestableEquipementController(
            $this->session, $equipements, $categories, $emplacements, $this->createMock(AcquisitionManager::class),
        );
        $prets = $this->createMock(PretLigneManager::class);
        $prets->method('equipementsDansUnPretOuvert')->willReturnCallback(fn () => $this->enPret);
        $controller->pretLigneManagerOverride = $prets;

        return $controller;
    }

    private function regrouper(array $post, string $role = 'ROLE_ADMIN', string $csrf = 'csrf'): Response
    {
        $request = Request::create('/equipements/equipement_regrouper-4', 'POST', $post + ['csrf_token' => $csrf]);
        $request->attributes->set('id', '4');
        return $this->controller($role)->regrouper($request);
    }

    private function formulaire(string $role): string
    {
        $request = Request::create('/equipements/equipement_modification-4');
        $request->attributes->set('id', '4');
        return (string) $this->controller($role)->edit($request)->getContent();
    }

    private function flash(string $type): string
    {
        return implode(' | ', $this->session->getFlashBag()->peek($type));
    }

    // ---------------- Formulaire ----------------

    public function testCarteDeRegroupementPourUnAdmin(): void
    {
        $html = $this->formulaire('ROLE_ADMIN');

        self::assertStringContainsString('<span>Regrouper en lot</span>', $html);
        self::assertStringContainsString('id="carte-regroupement"', $html);
        self::assertStringContainsString('<code>REGROUPER</code>', $html);
        self::assertMatchesRegularExpression('/value="5"\s+id="regrouper_5"[^>]*>/', $html);
        self::assertMatchesRegularExpression('/value="7"\s+id="regrouper_7"[^>]*disabled/', $html);
        self::assertStringContainsString('Impossible : statut différent', $html);
        self::assertStringNotContainsString('regrouper_8', $html);
    }

    public function testSuperAdminDissocieEtRegroupeParLaRoueDentee(): void
    {
        // Lot de 2 avec des équipements identiques : les deux cartes.
        $this->equipements[4]['nombre'] = $this->equipements[4]['nombre_initial'] = 2;
        $html = $this->formulaire('ROLE_SUPER_ADMIN');

        self::assertStringContainsString('Modifier la référence, la catégorie et le lot (dissocier, regrouper)', $html);
        self::assertStringNotContainsString('id="afficher-lot"', $html);
        // Cartes juste sous la roue dentée, avant le formulaire principal.
        $roue = strpos($html, 'id="deverrouiller-ref-cat"');
        $dissociation = strpos($html, 'id="carte-dissociation"');
        $regroupement = strpos($html, 'id="carte-regroupement"');
        $formulaire = strpos($html, '<form method="post" enctype="multipart/form-data">');
        self::assertNotFalse($dissociation);
        self::assertNotFalse($regroupement);
        self::assertTrue($roue < $dissociation && $dissociation < $regroupement && $regroupement < $formulaire);
    }

    public function testPasDeCartePourUnControleurNiSansEquipementIdentique(): void
    {
        self::assertStringNotContainsString('id="carte-regroupement"', $this->formulaire('ROLE_CONTROLLEUR'));

        $this->equipements = [4 => $this->equipements[4], 8 => $this->equipements[8]];
        self::assertStringNotContainsString('id="carte-regroupement"', $this->formulaire('ROLE_ADMIN'));
    }

    public function testEquipementEnPretSignale(): void
    {
        $this->enPret = [5];
        self::assertStringContainsString('Impossible : inscrit sur un prêt', $this->formulaire('ROLE_ADMIN'));

        $this->enPret = [4];
        self::assertStringContainsString('Cet équipement ne peut pas être regroupé (inscrit sur un prêt)', $this->formulaire('ROLE_ADMIN'));
    }

    // ---------------- Regroupement ----------------

    public function testRegroupement(): void
    {
        $response = $this->regrouper(['ids' => ['5', '6'], 'confirmation_regroupement' => 'REGROUPER']);

        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
        self::assertSame(['id' => 4, 'nombre' => 4, 'nombre_initial' => 5], $this->sauve);
        self::assertSame([5, 6], $this->archives);
        self::assertStringContainsString('24MOU002, 24MOU003', $this->flash('success'));
    }

    public function testRefus(): void
    {
        $cas = [
            'aucun' => [['ids' => []], 'Cochez au moins un'],
            'pas identique' => [['ids' => ['8']], 'identiques'],
            'inconnu' => [['ids' => ['99']], 'identiques'],
            'lui-même' => [['ids' => ['4']], 'identiques'],
            'statut différent' => [['ids' => ['7']], 'statut différent'],
            'mot' => [['ids' => ['5'], 'confirmation_regroupement' => 'regrouper'], 'saisissez le mot REGROUPER'],
        ];
        foreach ($cas as $nom => [$post, $message]) {
            $this->sauve = null;
            $this->archives = [];
            $response = $this->regrouper($post + ['confirmation_regroupement' => 'REGROUPER']);
            self::assertNull($this->sauve, $nom);
            self::assertSame([], $this->archives, $nom);
            self::assertSame('/equipements/equipement_modification-4', $response->headers->get('Location'), $nom);
            self::assertStringContainsString($message, $this->flash('error'), $nom);
        }
    }

    public function testEquipementEnPretRefuse(): void
    {
        $this->enPret = [5];
        $this->regrouper(['ids' => ['5'], 'confirmation_regroupement' => 'REGROUPER']);

        self::assertSame([], $this->archives);
        self::assertStringContainsString('inscrit sur un prêt', $this->flash('error'));
    }

    public function testInterditAuControleur(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->regrouper(['ids' => ['5'], 'confirmation_regroupement' => 'REGROUPER'], 'ROLE_CONTROLLEUR');
    }

    public function testCsrfObligatoire(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->regrouper(['ids' => ['5'], 'confirmation_regroupement' => 'REGROUPER'], 'ROLE_ADMIN', 'faux');
    }
}
