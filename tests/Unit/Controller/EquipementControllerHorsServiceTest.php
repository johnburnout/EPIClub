<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [MÉTIER 2026-10-21] « Hors service » ne s'attribue et ne se lève qu'à la
 * clôture d'un contrôle ; la suppression exige un équipement déclaré hors
 * service par son dernier contrôle clôturé, et le rôle administrateur.
 */
final class EquipementControllerHorsServiceTest extends TestCase
{
    private const CSRF = 'csrf';

    private Session $session;
    /** @var EquipementManager&MockObject */
    private EquipementManager $equipements;
    /** @var list<array<string, mixed>> */
    private array $enregistres = [];
    private bool $supprime = false;

    protected function setUp(): void
    {
        $this->equipements = $this->createMock(EquipementManager::class);
        $this->equipements->method('save')->willReturnCallback(function (array $e) {
            $this->enregistres[] = $e;
            return true;
        });
        $this->equipements->method('delete')->willReturnCallback(function () {
            $this->supprime = true;
            return true;
        });
        $this->connecter('ROLE_ADMIN');
    }

    // ------------------------------------------------------------------
    // Fiche
    // ------------------------------------------------------------------

    public function testFormulaireNeProposePasHorsService(): void
    {
        $this->equipement(['statut' => 0]);

        $html = (string) $this->controleur()->edit($this->get())->getContent();

        self::assertStringContainsString('<option value="1"', $html);
        self::assertStringNotContainsString('<option value="2"', $html);
    }

    public function testFormulaireDUnEquipementHorsServiceVerrouilleLeStatut(): void
    {
        $this->equipement(['statut' => 2]);

        $html = (string) $this->controleur()->edit($this->get())->getContent();

        self::assertStringContainsString('<input type="hidden" name="statut" value="2">', $html);
        self::assertStringNotContainsString('<select class="form-select " name="statut"', $html);
        self::assertStringContainsString('seul un nouveau contrôle peut le lever', $html);
    }

    public function testAttribuerHorsServiceALaMainEstRefuse(): void
    {
        $this->equipement(['statut' => 0]);

        $reponse = $this->controleur()->edit($this->post(['statut' => '2']));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame([], $this->enregistres);
        self::assertStringContainsString(htmlspecialchars(EquipementController::MESSAGE_HS_ATTRIBUE, ENT_QUOTES), (string) $reponse->getContent());
    }

    public function testLeverHorsServiceALaMainEstRefuse(): void
    {
        $this->equipement(['statut' => 2]);

        $reponse = $this->controleur()->edit($this->post(['statut' => '0']));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame([], $this->enregistres);
        self::assertStringContainsString(htmlspecialchars(EquipementController::MESSAGE_HS_LEVE, ENT_QUOTES), (string) $reponse->getContent());
    }

    public function testUnEquipementHorsServiceResteModifiablePourLeReste(): void
    {
        $this->equipement(['statut' => 2]);

        $this->controleur()->edit($this->post(['statut' => '2', 'remarques' => 'rangé au local']));

        self::assertCount(1, $this->enregistres);
        self::assertSame(2, $this->enregistres[0]['statut']);
        self::assertSame('rangé au local', $this->enregistres[0]['remarques']);
    }

    // ------------------------------------------------------------------
    // Modification groupée
    // ------------------------------------------------------------------

    public function testModificationGroupeeRefuseHorsService(): void
    {
        $this->equipements->method('findByIds')->willReturn([['id' => 3, 'reference' => 'R3', 'statut' => 0]]);

        $reponse = $this->controleur()->modificationGroupee($this->groupe(['statut' => '2']));

        self::assertSame(200, $reponse->getStatusCode());
        self::assertSame([], $this->enregistres);
    }

    public function testModificationGroupeeNeLevePasHorsService(): void
    {
        $this->equipements->method('findByIds')->willReturn([
            ['id' => 3, 'reference' => 'R3', 'statut' => 0],
            ['id' => 5, 'reference' => 'R5', 'statut' => 2],
        ]);

        $this->controleur()->modificationGroupee($this->groupe(['statut' => '1']));

        self::assertSame([['id' => 3, 'statut' => 1]], $this->enregistres, 'R5 hors service : rien à enregistrer');
        $avertissement = implode(' ', $this->session->getFlashBag()->get('warning'));
        self::assertStringContainsString('R5', $avertissement);
        self::assertStringNotContainsString('R3', $avertissement);
    }

    // ------------------------------------------------------------------
    // Suppression
    // ------------------------------------------------------------------

    public function testSuppressionReserveeAuxAdministrateurs(): void
    {
        $this->connecter('ROLE_CONTROLLEUR');
        $this->equipement(['statut' => 2]);
        $this->historique(['hors_service']);

        try {
            $this->controleur()->delete($this->suppression());
            self::fail('suppression acceptée');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($this->supprime);
    }

    public function testSuppressionDUnEquipementDeclareHorsServiceParLeDernierControle(): void
    {
        $this->equipement(['statut' => 2]);
        $this->historique(['hors_service', 'controle_ok']);

        $reponse = $this->controleur()->delete($this->suppression());

        self::assertTrue($this->supprime);
        self::assertSame('/equipements', $reponse->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function suppressionsRefusees(): iterable
    {
        yield 'pas hors service'               => [['statut' => 1], ['hors_service']];
        yield 'hors service sans contrôle'     => [['statut' => 2], []];
        yield 'dernier contrôle non HS'        => [['statut' => 2], ['controle_ok', 'hors_service']];
        yield 'contrôle en cours'              => [['statut' => 2, 'controle_en_cours' => 1], ['hors_service']];
    }

    /**
     * @param array<string, mixed> $equipement
     * @param list<string>         $historique
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('suppressionsRefusees')]
    public function testSuppressionRefusee(array $equipement, array $historique): void
    {
        $this->equipement($equipement);
        $this->historique($historique);

        $reponse = $this->controleur()->delete($this->suppression());

        self::assertFalse($this->supprime);
        self::assertSame('/equipements/equipement-7', $reponse->headers->get('Location'));
        self::assertNotEmpty($this->session->getFlashBag()->get('error'));
    }

    // ------------------------------------------------------------------

    private function connecter(string $role): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'u', 'role' => $role]);
        $this->session->set('csrf_token', self::CSRF);
    }

    /** @param array<string, mixed> $valeurs */
    private function equipement(array $valeurs): void
    {
        $this->equipements->method('findId')->willReturn($valeurs + [
            'id' => 7, 'reference' => 'R7', 'libelle' => 'Casque', 'categorie_id' => null,
            'controle_en_cours' => 0, 'photo' => null, 'remarques' => null, 'acquisition_id' => null,
        ]);
    }

    /** @param list<string> $statuts Statuts des lignes, contrôle le plus récent d'abord. */
    private function historique(array $statuts): void
    {
        $this->equipements->method('getHistoriqueControles')->willReturn(
            array_map(static fn (string $s) => ['statut' => $s], $statuts)
        );
    }

    private function controleur(): TestableEquipementController
    {
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);

        return new TestableEquipementController(
            $this->session,
            $this->equipements,
            $categories,
            $emplacements,
            $this->createMock(AcquisitionManager::class),
        );
    }

    private function get(): Request
    {
        $r = Request::create('/equipements/equipement_modification-7');
        $r->attributes->set('id', '7');
        return $r;
    }

    /** @param array<string, string> $donnees */
    private function post(array $donnees): Request
    {
        $r = Request::create('/equipements/equipement_modification-7', 'POST', $donnees + [
            'csrf_token' => self::CSRF, 'emplacement_id' => '', 'remarques' => '',
        ]);
        $r->attributes->set('id', '7');
        return $r;
    }

    /** @param array<string, string> $donnees */
    private function groupe(array $donnees): Request
    {
        return Request::create('/equipements/modification-groupee', 'POST', $donnees + [
            'appliquer' => ['statut' => '1'], 'ids' => ['3', '5'], 'csrf_token' => self::CSRF,
        ]);
    }

    private function suppression(): Request
    {
        $r = Request::create('/equipements/equipement_supprimer-7', 'POST', ['csrf_token' => self::CSRF]);
        $r->attributes->set('id', '7');
        return $r;
    }
}
