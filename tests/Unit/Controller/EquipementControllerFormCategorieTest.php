<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
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
 * [2026-10-24] Formulaire de modification d'un équipement : un
 * super-administrateur y change la catégorie (saisie comme une ligne
 * d'acquisition, catégorie inconnue créée, mot de confirmation
 * CATEGORIE) ; une référence libre est proposée à la volée pour la
 * nouvelle catégorie, sur l'année d'achat.
 */
final class EquipementControllerFormCategorieTest extends TestCase
{
    private Session $session;
    private ?array $enregistre = null;
    /** @var array<int, array> Catégories créées */
    private array $creees = [];
    /** @var array{0:string,1:int}|null Arguments du dernier suggest() */
    private ?array $suggestion = null;

    private const CATEGORIES = [
        1 => ['id' => 1, 'libelle' => 'Casques', 'est_epi' => 1],
        2 => ['id' => 2, 'libelle' => 'Cordes', 'est_epi' => 1],
        3 => ['id' => 3, 'libelle' => 'Tapis', 'est_epi' => 0],
    ];

    private function controller(string $role): TestableEquipementController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturnCallback(fn (int $id) => $id === 4 ? [
            'id' => 4, 'reference' => '24CAS003', 'libelle' => 'Casque', 'categorie_id' => 1,
            'emplacement_id' => null, 'statut' => 0, 'nombre_initial' => 1, 'acquisition_id' => 9,
        ] : null);
        $equipements->method('referenceExists')->willReturn(false);
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->enregistre = $e;
            return true;
        });

        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findId')->willReturnCallback(fn (int $id) => self::CATEGORIES[$id] ?? null);
        $categories->method('findAll')->willReturn(array_values(self::CATEGORIES));
        $categories->method('findOneByCriteria')->willReturnCallback(function (array $criteres) {
            foreach (self::CATEGORIES as $categorie) {
                if (mb_strtolower($categorie['libelle']) === mb_strtolower($criteres['libelle'])) {
                    return $categorie;
                }
            }
            return null;
        });
        $categories->method('save')->willReturnCallback(function (array $c) {
            $this->creees[] = $c;
            return '7';
        });

        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 9, 'facture_date' => '2024-05-14']);

        $controller = new TestableEquipementController(
            $this->session, $equipements, $categories,
            $this->createMock(EmplacementManager::class), $acquisitions,
        );
        $generateur = $this->createMock(ReferenceGenerator::class);
        $generateur->method('suggest')->willReturnCallback(function (string $libelle, int $annee) {
            $this->suggestion = [$libelle, $annee];
            return substr((string) $annee, -2) . strtoupper(substr($libelle, 0, 3)) . '001';
        });
        $controller->referenceGeneratorOverride = $generateur;

        return $controller;
    }

    private function editer(string $role, array $post = []): Response
    {
        $request = $post === []
            ? Request::create('/equipements/equipement_modification-4')
            : Request::create('/equipements/equipement_modification-4', 'POST', $post + ['csrf_token' => 'csrf', 'statut' => '0']);
        $request->attributes->set('id', '4');
        return $this->controller($role)->edit($request);
    }

    // ---------------- Formulaire ----------------

    public function testChampCategorieEditableSeulementPourLeSuperAdmin(): void
    {
        $html = (string) $this->editer('ROLE_SUPER_ADMIN')->getContent();
        self::assertStringContainsString('name="categorie_libelle"', $html);
        self::assertStringContainsString('list="categories"', $html);
        self::assertStringContainsString('<option value="Cordes">', $html);
        self::assertStringContainsString('<code>CATEGORIE</code>', $html);

        self::assertStringNotContainsString('name="categorie_libelle"', (string) $this->editer('ROLE_ADMIN')->getContent());
    }

    /** Balise <input> d'id donné dans le HTML. */
    private static function champ(string $html, string $id): string
    {
        self::assertSame(1, preg_match('/<input[^>]*\bid="' . $id . '"[^>]*>/', $html, $m), $id);
        return $m[0];
    }

    public function testReferenceEtCategorieVerrouilleesJusquALaRoueDentee(): void
    {
        $html = (string) $this->editer('ROLE_SUPER_ADMIN')->getContent();

        self::assertStringContainsString('id="deverrouiller-ref-cat"', $html);
        self::assertStringContainsString('fa-cog', $html);
        self::assertMatchesRegularExpression('/\sdisabled\b/', self::champ($html, 'categorie'));
        self::assertMatchesRegularExpression('/\sdisabled\b/', self::champ($html, 'reference'));
    }

    public function testChampsOuvertsAuRetourDuServeurAvecUneErreur(): void
    {
        $html = (string) $this->editer('ROLE_SUPER_ADMIN', [
            'categorie_libelle' => 'Cordes', 'confirmation_categorie' => '',
        ])->getContent();

        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertDoesNotMatchRegularExpression('/\sdisabled\b/', self::champ($html, 'categorie'));
        self::assertDoesNotMatchRegularExpression('/\sdisabled\b/', self::champ($html, 'reference'));
    }

    public function testPasDeRoueDenteePourUnAdmin(): void
    {
        self::assertStringNotContainsString('id="deverrouiller-ref-cat"', (string) $this->editer('ROLE_ADMIN')->getContent());
    }

    public function testChampsVerrouillesNonEnvoyesRienNeChange(): void
    {
        // Champs désactivés : ni catégorie ni référence dans le POST.
        $this->editer('ROLE_SUPER_ADMIN', ['remarques' => 'ok']);

        self::assertSame(1, $this->enregistre['categorie_id']);
        self::assertSame('24CAS003', $this->enregistre['reference']);
        self::assertSame([], $this->creees);
    }

    // ---------------- Enregistrement ----------------

    public function testSuperAdminChangeLaCategorie(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', [
            'categorie_libelle' => ' Cordes ', 'confirmation_categorie' => 'CATEGORIE',
        ]);

        self::assertSame(2, $this->enregistre['categorie_id']);
        self::assertSame([], $this->creees);
        self::assertStringContainsString('« Casques » → « Cordes »', $this->session->getFlashBag()->peek('success')[0]);
    }

    public function testCategorieEtReferenceChangentEnsemble(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', [
            'categorie_libelle' => 'Cordes', 'confirmation_categorie' => 'CATEGORIE',
            'reference' => '24COR001', 'confirmation_reference' => 'REFERENCE',
        ]);

        self::assertSame(2, $this->enregistre['categorie_id']);
        self::assertSame('24COR001', $this->enregistre['reference']);
    }

    public function testCategorieInconnueCreeeCommePourUneAcquisition(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', [
            'categorie_libelle' => 'longes', 'confirmation_categorie' => 'CATEGORIE',
        ]);

        self::assertSame([['libelle' => 'Longes', 'description' => '', 'image' => '', 'est_epi' => 1]], $this->creees);
        self::assertSame(7, $this->enregistre['categorie_id']);
        self::assertStringContainsString('nouvelle catégorie créée', $this->session->getFlashBag()->peek('success')[0]);
    }

    public function testCategorieInchangeeSansMotDeConfirmation(): void
    {
        foreach (['Casques', 'casques '] as $libelle) {
            $this->editer('ROLE_SUPER_ADMIN', ['categorie_libelle' => $libelle, 'remarques' => 'ok']);
            self::assertSame(1, $this->enregistre['categorie_id'], $libelle);
            self::assertSame('ok', $this->enregistre['remarques']);
        }
    }

    public function testSansMotDeConfirmationRefuse(): void
    {
        foreach (['', 'categorie', 'REFERENCE'] as $mot) {
            $response = $this->editer('ROLE_SUPER_ADMIN', [
                'categorie_libelle' => 'Nouvelle', 'confirmation_categorie' => $mot,
            ]);
            self::assertNull($this->enregistre, "confirmation « $mot »");
            self::assertSame([], $this->creees);
            self::assertStringContainsString('saisissez le mot CATEGORIE', (string) $response->getContent());
            // La saisie est conservée dans le formulaire
            self::assertStringContainsString('value="Nouvelle"', (string) $response->getContent());
        }
    }

    public function testCategorieVideOuTropLongueRefusee(): void
    {
        foreach (['', str_repeat('A', 65), "A\tB"] as $libelle) {
            $this->editer('ROLE_SUPER_ADMIN', ['categorie_libelle' => $libelle, 'confirmation_categorie' => 'CATEGORIE']);
            self::assertNull($this->enregistre);
            self::assertSame([], $this->creees);
        }
    }

    public function testErreurSurUnAutreChampNeCreePasLaCategorie(): void
    {
        $this->editer('ROLE_SUPER_ADMIN', [
            'categorie_libelle' => 'Longes', 'confirmation_categorie' => 'CATEGORIE',
            'reference' => '24LON001', 'confirmation_reference' => 'faux',
        ]);

        self::assertNull($this->enregistre);
        self::assertSame([], $this->creees);
    }

    public function testAdminNePeutPasChangerLaCategorie(): void
    {
        $this->editer('ROLE_ADMIN', ['categorie_libelle' => 'Cordes', 'confirmation_categorie' => 'CATEGORIE']);

        self::assertSame(1, $this->enregistre['categorie_id']);
        self::assertSame([], $this->creees);
    }

    // ---------------- Proposition à la volée ----------------

    private function suggerer(string $role, array $query): array
    {
        $response = $this->controller($role)->referenceSuggestion(
            Request::create('/equipements/reference-suggestion', 'GET', $query)
        );
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getContent(), true);
    }

    public function testReferenceProposeeSurLAnneeDAchat(): void
    {
        $data = $this->suggerer('ROLE_SUPER_ADMIN', ['id' => '4', 'categorie' => 'Tapis']);

        self::assertSame(['Tapis', 2024], $this->suggestion);
        self::assertSame(['reference' => '24TAP001', 'annee' => 2024, 'nouvelle' => false, 'est_epi' => false], $data);
    }

    public function testCategorieInconnueSignalee(): void
    {
        $data = $this->suggerer('ROLE_SUPER_ADMIN', ['id' => '4', 'categorie' => 'Longes']);

        self::assertTrue($data['nouvelle']);
        self::assertTrue($data['est_epi']);
        self::assertSame('24LON001', $data['reference']);
    }

    public function testPasDePropositionSansEquipementNiCategorieValide(): void
    {
        foreach ([['id' => '99', 'categorie' => 'Tapis'], ['id' => 'x', 'categorie' => 'Tapis'], ['id' => '4', 'categorie' => '']] as $query) {
            self::assertSame(['reference' => null], $this->suggerer('ROLE_SUPER_ADMIN', $query));
        }
        self::assertNull($this->suggestion);
    }

    public function testPropositionReserveeAuSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->suggerer('ROLE_ADMIN', ['id' => '4', 'categorie' => 'Tapis']);
    }

    public function testLibelleCategorie(): void
    {
        self::assertNull(EquipementController::erreurLibelleCategorie('Cordes à double'));
        self::assertNotNull(EquipementController::erreurLibelleCategorie(''));
        self::assertNotNull(EquipementController::erreurLibelleCategorie("\xC3"));
    }
}
