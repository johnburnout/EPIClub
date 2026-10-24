<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Dissociation d'un lot depuis le formulaire de
 * modification (super-administrateur par la roue dentée ; admin par le
 * bouton « Dissocier le lot » depuis le 2026-10-24) : références
 * proposées selon le modèle (modifiables par le seul super-administrateur,
 * imposées à un admin), mot de confirmation DISSOCIER.
 */
final class EquipementControllerDissociationTest extends TestCase
{
    private Session $session;
    /** @var array[] Équipements enregistrés (ordre des appels à save()) */
    private array $sauves = [];
    /** @var string[] Références déjà prises */
    private array $prises = ['24COR003', '24COR011'];
    private array $equipement = [
        'id' => 4, 'acquisition_id' => 9, 'categorie_id' => 2, 'reference' => '24COR003', 'libelle' => 'Mousqueton',
        'code' => 'C-4', 'statut' => 0, 'controle_en_cours' => 0, 'emplacement_id' => null,
        'nombre' => 10, 'nombre_initial' => 10,
    ];

    private function controller(string $role): TestableEquipementController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $id === 4 ? $this->equipement : null);
        $equipements->method('referenceExists')->willReturnCallback(
            fn (string $ref, ?int $sauf = null) => in_array($ref, $this->prises, true)
        );
        $equipements->method('codeExists')->willReturn(false);
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->sauves[] = $e;
            return (string) (100 + count($this->sauves));
        });
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findId')->willReturnCallback(fn (int $id) => $id === 2 ? ['id' => 2, 'libelle' => 'Cordes'] : null);
        $categories->method('findAll')->willReturn([['id' => 2, 'libelle' => 'Cordes', 'est_epi' => 1]]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 9, 'facture_date' => '2024-05-14']);

        $controller = new TestableEquipementController($this->session, $equipements, $categories, $emplacements, $acquisitions);
        $generateur = $this->createMock(ReferenceGenerator::class);
        $generateur->method('suggest')->willReturn('24COR010');
        $generateur->method('compteurSiConforme')->willReturnCallback(
            fn (string $ref, int $cat, int $annee) => preg_match('/^24COR(\d{3})$/', $ref, $m) && $annee === 2024 ? (int) $m[1] : null
        );
        $generateur->method('referenceAuRang')->willReturnCallback(
            fn (int $cat, int $annee, int $n) => sprintf('%sCOR%03d', substr((string) $annee, -2), $n)
        );
        $controller->referenceGeneratorOverride = $generateur;

        return $controller;
    }

    private function dissocier(array $post, string $role = 'ROLE_SUPER_ADMIN', string $csrf = 'csrf'): Response
    {
        $request = Request::create('/equipements/equipement_dissocier-4', 'POST', $post + ['csrf_token' => $csrf]);
        $request->attributes->set('id', '4');
        return $this->controller($role)->dissocier($request);
    }

    private function flash(string $type): string
    {
        return implode(' | ', $this->session->getFlashBag()->peek($type));
    }

    // ---------------- Formulaire ----------------

    private function formulaire(string $role): string
    {
        $request = Request::create('/equipements/equipement_modification-4');
        $request->attributes->set('id', '4');
        return (string) $this->controller($role)->edit($request)->getContent();
    }

    public function testCarteDeDissociationMasqueeJusquALaRoueDentee(): void
    {
        $html = $this->formulaire('ROLE_SUPER_ADMIN');

        self::assertMatchesRegularExpression('/class="card mt-2 mb-3 border-warning carte-lot d-none" id="carte-dissociation"/', $html);
        self::assertStringContainsString('action="/equipements/equipement_dissocier-4"', $html);
        self::assertStringContainsString('<code>DISSOCIER</code>', $html);
    }

    public function testAdminAfficheLaCarteParSonBouton(): void
    {
        $html = $this->formulaire('ROLE_ADMIN');

        self::assertStringContainsString('id="afficher-lot"', $html);
        self::assertStringNotContainsString('id="deverrouiller-ref-cat"', $html);
        self::assertMatchesRegularExpression('/class="card mt-2 mb-3 border-warning carte-lot d-none" id="carte-dissociation"/', $html);
        self::assertStringContainsString('id="form-dissociation"', $html);
        self::assertStringContainsString('data-references-modifiables="0"', $html);
        self::assertStringContainsString('attribuées automatiquement', $html);
    }

    public function testSuperAdminPasseParLaRoueDentee(): void
    {
        $html = $this->formulaire('ROLE_SUPER_ADMIN');

        self::assertStringNotContainsString('id="afficher-lot"', $html);
        self::assertStringContainsString('data-references-modifiables="1"', $html);
    }

    public function testPasDeCartePourUnControleurNiPourUnEquipementUnique(): void
    {
        self::assertStringNotContainsString('id="carte-dissociation"', $this->formulaire('ROLE_CONTROLLEUR'));

        $this->equipement['nombre'] = $this->equipement['nombre_initial'] = 1;
        self::assertStringNotContainsString('id="carte-dissociation"', $this->formulaire('ROLE_ADMIN'));
    }

    public function testLotNonDissociableExpliquePourquoi(): void
    {
        $this->equipement['statut'] = 3;
        $html = $this->formulaire('ROLE_SUPER_ADMIN');

        self::assertStringContainsString('id="carte-dissociation"', $html);
        self::assertStringNotContainsString('id="form-dissociation"', $html);
        self::assertStringContainsString('ne peut pas être dissocié', $html);
    }

    // ---------------- Dissociation ----------------

    public function testDissociationEnLotsEtEquipementsIndividuels(): void
    {
        $response = $this->dissocier([
            'repartition' => '6, 3, 1', 'references' => ['24COR010', '24COR012'], 'confirmation_dissociation' => 'DISSOCIER',
        ]);

        self::assertSame('/equipements/equipement-4', $response->headers->get('Location'));
        self::assertSame(['id' => 4, 'nombre' => 6, 'nombre_initial' => 6], $this->sauves[0]);
        self::assertSame(['24COR010', 3], [$this->sauves[1]['reference'], $this->sauves[1]['nombre']]);
        self::assertSame(['24COR012', 1], [$this->sauves[2]['reference'], $this->sauves[2]['nombre']]);
        self::assertStringContainsString('24COR010, 24COR012', $this->flash('success'));
    }

    public function testRefus(): void
    {
        $base = ['repartition' => '6, 4', 'references' => ['24COR010'], 'confirmation_dissociation' => 'DISSOCIER'];
        $cas = [
            'somme' => [['repartition' => '6, 3'] + $base, 'doit être égale'],
            'une seule partie' => [['repartition' => '10'] + $base, 'au moins deux parties'],
            'référence manquante' => [['references' => []] + $base, 'obligatoire'],
            'référence prise' => [['references' => ['24COR011']] + $base, 'déjà attribuée'],
            "référence d'origine" => [['references' => ['24COR003']] + $base, 'utilisée deux fois'],
            'doublon' => [['repartition' => '6, 2, 2', 'references' => ['24COR010', '24COR010']] + $base, 'utilisée deux fois'],
            'mot' => [['confirmation_dissociation' => 'dissocier'] + $base, 'saisissez le mot DISSOCIER'],
        ];
        foreach ($cas as $nom => [$post, $message]) {
            $this->sauves = [];
            $response = $this->dissocier($post);
            self::assertSame([], $this->sauves, $nom);
            self::assertSame('/equipements/equipement_modification-4', $response->headers->get('Location'), $nom);
            self::assertStringContainsString($message, $this->flash('error'), $nom);
        }
    }

    public function testEquipementNonDissociableRefuse(): void
    {
        $this->equipement['controle_en_cours'] = 1;
        $this->dissocier(['repartition' => '6, 4', 'references' => ['24COR010'], 'confirmation_dissociation' => 'DISSOCIER']);

        self::assertSame([], $this->sauves);
        self::assertStringContainsString('contrôle en cours', $this->flash('error'));
    }

    public function testAdminDissocieAvecLesReferencesImposees(): void
    {
        // Les références postées par un admin sont ignorées : celles du
        // modèle sont recalculées (24COR011, déjà prise, est sautée).
        $this->dissocier(
            ['repartition' => '6, 2, 2', 'references' => ['FORGEE', 'AUTRE'], 'confirmation_dissociation' => 'DISSOCIER'],
            'ROLE_ADMIN'
        );

        self::assertSame(['id' => 4, 'nombre' => 6, 'nombre_initial' => 6], $this->sauves[0]);
        self::assertSame(['24COR010', '24COR012'], [$this->sauves[1]['reference'], $this->sauves[2]['reference']]);
    }

    public function testAdminSansReferenceProposableRefuse(): void
    {
        $this->equipement['categorie_id'] = null;
        $this->dissocier(
            ['repartition' => '6, 4', 'references' => ['24COR010'], 'confirmation_dissociation' => 'DISSOCIER'],
            'ROLE_ADMIN'
        );

        self::assertSame([], $this->sauves);
        self::assertStringContainsString('super-administrateur', $this->flash('error'));
    }

    public function testSuperAdminChoisitSesReferences(): void
    {
        $this->dissocier(['repartition' => '6, 4', 'references' => ['MAREF'], 'confirmation_dissociation' => 'DISSOCIER']);

        self::assertSame('MAREF', $this->sauves[1]['reference']);
    }

    public function testInterditAuControleur(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->dissocier(['repartition' => '6, 4'], 'ROLE_CONTROLLEUR');
    }

    public function testCsrfObligatoire(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->dissocier(['repartition' => '6, 4'], 'ROLE_SUPER_ADMIN', 'faux');
    }

    // ---------------- Références proposées ----------------

    private function proposees(array $query, string $role = 'ROLE_SUPER_ADMIN'): array
    {
        $response = $this->controller($role)->referencesDissociation(
            Request::create('/equipements/dissociation-references', 'GET', $query)
        );
        return json_decode((string) $response->getContent(), true)['references'];
    }

    public function testReferencesSuivantesLibresSurLAnneeDAchat(): void
    {
        // 24COR011 est déjà prise : sautée.
        self::assertSame(['24COR010', '24COR012', '24COR013'], $this->proposees(['id' => '4', 'nombre' => '3']));
    }

    public function testPasDeReferencesSansEquipementNiNombreValide(): void
    {
        foreach ([['id' => '99', 'nombre' => '2'], ['id' => '4', 'nombre' => '0'], ['id' => '4', 'nombre' => 'x']] as $query) {
            self::assertSame([], $this->proposees($query));
        }
    }

    public function testPropositionPourUnAdminPasPourUnControleur(): void
    {
        self::assertSame(['24COR010', '24COR012'], $this->proposees(['id' => '4', 'nombre' => '2'], 'ROLE_ADMIN'));

        $this->expectException(AccessDeniedException::class);
        $this->proposees(['id' => '4', 'nombre' => '2'], 'ROLE_CONTROLLEUR');
    }
}
