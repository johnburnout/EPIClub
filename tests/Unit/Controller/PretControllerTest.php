<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\PretController;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\PretLigneManager;
use Epiclub\Domain\PretManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [Prêt de matériel — migration 016] Cycle d'une fiche de prêt :
 * saisie → en cours (matériel « Prêté ») → terminé (vérification du retour).
 */
final class PretControllerTest extends TestCase
{
    private const CSRF = 'csrf';

    private Session $session;
    /** @var PretManager&MockObject */
    private PretManager $prets;
    /** @var PretLigneManager&MockObject */
    private PretLigneManager $lignes;
    /** @var EquipementManager&MockObject */
    private EquipementManager $equipements;
    private bool $moduleActif = true;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 5, 'username' => 'c', 'role' => 'ROLE_CONTROLLEUR', 'nom' => 'Nom', 'prenom' => 'Prénom']);
        $this->session->set('csrf_token', self::CSRF);
        $this->prets = $this->createMock(PretManager::class);
        $this->lignes = $this->createMock(PretLigneManager::class);
        $this->equipements = $this->createMock(EquipementManager::class);
    }

    private function controleur(): PretController
    {
        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['nom' => 'Club alpin', 'pret_actif' => $this->moduleActif ? 1 : 0]);
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([['id' => 1, 'libelle' => 'Casques']]);

        return new class($this->session, $this->prets, $this->lignes, $this->equipements, $categories, $club) extends PretController {
            public function __construct(Session $s, private $p, private $l, private $e, private $c, private $club)
            {
                parent::__construct($s);
            }
            protected function pretManager(): PretManager { return $this->p; }
            protected function pretLigneManager(): PretLigneManager { return $this->l; }
            protected function equipementManager(): EquipementManager { return $this->e; }
            protected function categorieManager(): CategorieManager { return $this->c; }
            protected function clubManager(): ClubManager { return $this->club; }
            protected function clubManagerModules(): ClubManager { return $this->club; }
            protected function aujourdhui(): string { return '2026-10-21'; }
            protected function updateLastActivity() {}
        };
    }

    /** @param array<string, mixed> $donnees */
    private function post(string $url, array $donnees, array $attributs = []): Request
    {
        $r = Request::create($url, 'POST', $donnees + ['csrf_token' => self::CSRF]);
        foreach ($attributs as $k => $v) {
            $r->attributes->set($k, $v);
        }
        return $r;
    }

    private function get(string $url, array $attributs = []): Request
    {
        $r = Request::create($url, 'GET');
        foreach ($attributs as $k => $v) {
            $r->attributes->set($k, $v);
        }
        return $r;
    }

    /** @return array<string, mixed> */
    private function pret(string $statut, array $autres = []): array
    {
        return $autres + [
            'id' => 12, 'statut' => $statut,
            'emprunteur_nom' => 'Dupont', 'emprunteur_prenom' => 'Marie',
            'emprunteur_telephone' => '0612345678', 'emprunteur_licence' => 'L-1',
            'date_pret' => '2026-10-21', 'date_retour_prevue' => '2026-10-28', 'date_retour' => null,
            'remarques' => null, 'signature' => null, 'date_signature' => null,
            'created_at' => '2026-10-21 09:00:00', 'date_validation' => null, 'date_fin' => null,
            'cree_par_nom' => 'Nom', 'cree_par_prenom' => 'P', 'valide_par_nom' => null, 'valide_par_prenom' => null,
            'termine_par_nom' => null, 'termine_par_prenom' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function ligne(int $id, int $equipementId, array $autres = []): array
    {
        return $autres + [
            'id' => $id, 'pret_id' => 12, 'equipement_id' => $equipementId, 'etat_retour' => null,
            'reference' => 'CAS00' . $equipementId, 'libelle' => 'Casque', 'photo' => null,
            'equipement_statut' => 0, 'controle_en_cours' => 0, 'date_fin_utilisation' => null,
            'deleted_at' => null, 'categorie_libelle' => 'Casques',
        ];
    }

    private function flashes(string $type): array
    {
        return $this->session->getFlashBag()->peek($type);
    }

    // ------------------------------------------------------------------

    public function testModuleDesactiveRedirigeVersLeTableauDeBord(): void
    {
        $this->moduleActif = false;
        $reponse = $this->controleur()->list($this->get('/prets'));

        self::assertSame('/tableau_de_bord', $reponse->headers->get('Location'));
    }

    public function testSimpleUtilisateurRefuse(): void
    {
        $this->session->set('user', ['id' => 6, 'role' => 'ROLE_USER']);
        $this->expectException(AccessDeniedException::class);
        $this->controleur()->list($this->get('/prets'));
    }

    public function testCreationEnregistreUneFicheEnSaisie(): void
    {
        $this->prets->expects(self::once())->method('save')
            ->with(self::callback(fn(array $d) => $d['statut'] === 'saisie' && $d['cree_par'] === 5 && $d['emprunteur_nom'] === 'Dupont'))
            ->willReturn(12);

        $reponse = $this->controleur()->create($this->post('/prets/nouveau', [
            'emprunteur_nom' => 'Dupont', 'emprunteur_prenom' => 'Marie',
            'date_pret' => '2026-10-21', 'date_retour_prevue' => '2026-10-28',
        ]));

        self::assertSame('/prets/pret-12', $reponse->headers->get('Location'));
    }

    public function testAjoutRefuseLeMaterielIndisponibleEtAjouteLeReste(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));
        $this->equipements->method('findIds')->willReturn([
            7 => ['id' => 7, 'reference' => 'CAS007', 'statut' => 0, 'controle_en_cours' => 0],
            8 => ['id' => 8, 'reference' => 'CAS008', 'statut' => 1, 'controle_en_cours' => 0],
        ]);
        $this->lignes->method('findPretOuvertPourEquipement')->willReturn(null);
        $this->lignes->expects(self::once())->method('ajouter')->with(12, 7)->willReturn(true);

        $reponse = $this->controleur()->ajouter($this->post('/prets/ajouter', [
            'pret_id' => '12', 'ids' => ['7', '8'], 'retour' => '/equipements?page=2',
        ]));

        self::assertSame('/equipements?page=2', $reponse->headers->get('Location'));
        self::assertStringContainsString('CAS007', $this->flashes('success')[0]);
        self::assertStringContainsString('CAS008', $this->flashes('error')[0]);
    }

    public function testAjoutImpossibleSurUnPretEnCours(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('en_cours'));
        $this->lignes->expects(self::never())->method('ajouter');

        $this->controleur()->ajouter($this->post('/prets/ajouter', ['pret_id' => '12', 'equipement_id' => '7']));

        self::assertNotEmpty($this->flashes('error'));
    }

    public function testScanDejaSurLePret(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));
        $this->equipements->method('findId')->willReturn(['id' => 7, 'reference' => 'CAS007', 'libelle' => 'Casque']);
        $this->equipements->method('findIds')->willReturn([7 => ['id' => 7, 'reference' => 'CAS007', 'statut' => 0]]);
        $this->lignes->method('findPretOuvertPourEquipement')->willReturn(['id' => 12]);
        $this->lignes->expects(self::never())->method('ajouter');

        $reponse = $this->controleur()->scanAjouter($this->post('/prets/scanner/ajouter', ['pret_id' => '12', 'equipement_id' => '7']));
        $data = json_decode((string) $reponse->getContent(), true);

        self::assertSame('deja', $data['statut']);
    }

    public function testValidationPasseLeMaterielEnPreteAvecSignature(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));
        $this->lignes->method('findByPret')->willReturn([$this->ligne(1, 7), $this->ligne(2, 8)]);
        $this->lignes->method('findPretOuvertPourEquipement')->willReturn(null);
        $this->prets->method('equipementsPourMiseAJour')->willReturn([
            7 => ['id' => 7, 'reference' => 'CAS007', 'statut' => 0, 'controle_en_cours' => 0],
            8 => ['id' => 8, 'reference' => 'CAS008', 'statut' => 0, 'controle_en_cours' => 0],
        ]);
        $this->prets->expects(self::once())->method('changerStatutEquipements')->with([7, 8], 3);
        $this->prets->expects(self::once())->method('commit');
        $this->prets->expects(self::once())->method('save')->with(self::callback(
            fn(array $d) => $d['statut'] === 'en_cours' && $d['valide_par'] === 5 && str_starts_with($d['signature'], 'data:image/png;base64,')
        ));

        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $signature = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

        $reponse = $this->controleur()->valider($this->post('/prets/pret-12/valider', ['signature' => $signature], ['id' => '12']));

        self::assertSame('/prets/pret-12', $reponse->headers->get('Location'));
        self::assertNotEmpty($this->flashes('success'));
    }

    public function testValidationAnnuleeSiUnMaterielEstPasseEnControle(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));
        $this->lignes->method('findByPret')->willReturn([$this->ligne(1, 7)]);
        $this->lignes->method('findPretOuvertPourEquipement')->willReturn(null);
        $this->prets->method('equipementsPourMiseAJour')->willReturn([
            7 => ['id' => 7, 'reference' => 'CAS007', 'statut' => 0, 'controle_en_cours' => 1],
        ]);
        $this->prets->expects(self::never())->method('changerStatutEquipements');
        $this->prets->expects(self::never())->method('save');
        $this->prets->expects(self::once())->method('rollBack');

        $this->controleur()->valider($this->post('/prets/pret-12/valider', [], ['id' => '12']));

        self::assertStringContainsString('en cours de contrôle', $this->flashes('error')[0]);
    }

    public function testValidationImpossibleSansLicence(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie', ['emprunteur_licence' => '']));
        $this->lignes->method('findByPret')->willReturn([$this->ligne(1, 7)]);
        $this->prets->expects(self::never())->method('beginTransaction');

        $reponse = $this->controleur()->valider($this->post('/prets/pret-12/valider', [], ['id' => '12']));

        self::assertSame('/prets/pret-12', $reponse->headers->get('Location'));
        self::assertStringContainsString('numéro de licence', $this->flashes('error')[0]);
    }

    public function testRetourVerifieChaqueMaterielEtChangeSonStatut(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('termine'));
        $this->lignes->method('findByPret')->willReturn([
            $this->ligne(1, 7), $this->ligne(2, 8), $this->ligne(3, 9),
            $this->ligne(4, 10, ['etat_retour' => 'disponible']),
        ]);
        $this->prets->method('equipementsPourMiseAJour')->willReturn([
            7 => ['id' => 7, 'statut' => 3], 8 => ['id' => 8, 'statut' => 3], 10 => ['id' => 10, 'statut' => 0],
        ]);
        $verifiees = [];
        $this->prets->method('enregistrerRetourLigne')->willReturnCallback(function (int $id, string $etat) use (&$verifiees) {
            $verifiees[$id] = $etat;
        });
        $statuts = [];
        $this->prets->method('changerStatutEquipements')->willReturnCallback(function (array $ids, int $s) use (&$statuts) {
            $statuts[$ids[0]] = $s;
        });

        $this->controleur()->retour($this->post('/prets/pret-12/retour', [
            // ligne 3 sans état : reste à vérifier ; ligne 4 déjà vérifiée : ignorée
            'etats' => ['1' => 'disponible', '2' => 'egare', '4' => 'egare', '99' => 'egare'],
        ], ['id' => '12']));

        self::assertSame([1 => 'disponible', 2 => 'egare'], $verifiees);
        self::assertSame([7 => 0, 8 => 4], $statuts);
        self::assertStringContainsString('Reste 1 matériel', $this->flashes('success')[0]);
    }

    public function testRetourImpossibleAvantLaFinDuPret(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('en_cours'));
        $this->prets->expects(self::never())->method('enregistrerRetourLigne');

        $this->controleur()->retour($this->post('/prets/pret-12/retour', ['etats' => ['1' => 'disponible']], ['id' => '12']));

        self::assertNotEmpty($this->flashes('error'));
    }

    public function testTerminerEnregistreLaDateDeRetour(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('en_cours'));
        $this->prets->expects(self::once())->method('save')->with(self::callback(
            fn(array $d) => $d['statut'] === 'termine' && $d['date_retour'] === '2026-10-24' && $d['termine_par'] === 5
        ));

        $this->controleur()->terminer($this->post('/prets/pret-12/terminer', ['date_retour' => '2026-10-24'], ['id' => '12']));
    }

    public function testPretEnCoursSeulLeTelephoneEstModifiable(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('en_cours'));
        $this->prets->expects(self::once())->method('save')->with(['emprunteur_telephone' => '0700000000', 'id' => 12]);

        $this->controleur()->show($this->post('/prets/pret-12', [
            'emprunteur_nom' => 'Autre', 'emprunteur_telephone' => '0700000000',
        ], ['id' => '12']));
    }

    public function testSuppressionReserveeAuxFichesEnSaisie(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('termine'));
        $this->prets->expects(self::never())->method('delete');

        $this->controleur()->supprimer($this->post('/prets/pret-12/supprimer', [], ['id' => '12']));
    }

    public function testFicheEnSaisieAfficheLeMaterielPretable(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));
        $this->lignes->method('findByPret')->willReturn([$this->ligne(1, 7, ['equipement_statut' => 1])]);
        $this->lignes->method('equipementsDansUnPretOuvert')->willReturn([7]);
        $this->equipements->method('findAll')->willReturn([
            ['id' => 7, 'reference' => 'CAS007', 'libelle' => 'Casque', 'statut' => 0, 'categorie_id' => 1],
            ['id' => 8, 'reference' => 'CAS008', 'libelle' => 'Casque rouge', 'statut' => 0, 'categorie_id' => 1],
            ['id' => 9, 'reference' => 'CAS009', 'libelle' => 'Casque HS', 'statut' => 2, 'categorie_id' => 1],
        ]);

        $html = (string) $this->controleur()->show($this->get('/prets/pret-12', ['id' => '12']))->getContent();

        self::assertStringContainsString('Prêt n° 12', $html);
        self::assertStringContainsString('value="8"', $html);
        self::assertStringNotContainsString('CAS009', $html);
        // Ligne devenue indisponible (en maintenance) : signalée
        self::assertStringContainsString('statut « En maintenance »', $html);
        self::assertStringContainsString('/prets/pret-12/scanner', $html);
    }

    public function testFichePdfDUnPretEnCours(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('en_cours', ['date_validation' => '2026-10-21 10:00:00']));
        $this->lignes->method('findByPret')->willReturn([$this->ligne(1, 7, ['equipement_statut' => 3])]);

        $reponse = $this->controleur()->pdf($this->get('/prets/pret-12/pdf', ['id' => '12']));

        self::assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $reponse->getContent());
    }

    public function testPasDePdfEnSaisie(): void
    {
        $this->prets->method('findId')->willReturn($this->pret('saisie'));

        $reponse = $this->controleur()->pdf($this->get('/prets/pret-12/pdf', ['id' => '12']));

        self::assertSame('/prets/pret-12', $reponse->headers->get('Location'));
    }

    public function testRetourSurRefuseLesUrlsExternes(): void
    {
        self::assertSame('/equipements?q=casque&page=2', PretController::retourSur('/equipements?q=casque&page=2', '/d'));
        self::assertSame('/d', PretController::retourSur('//evil.example', '/d'));
        self::assertSame('/d', PretController::retourSur('https://evil.example', '/d'));
        self::assertSame('/d', PretController::retourSur("/x\r\nLocation: y", '/d'));
        self::assertSame('/d', PretController::retourSur(['/x'], '/d'));
    }
}
