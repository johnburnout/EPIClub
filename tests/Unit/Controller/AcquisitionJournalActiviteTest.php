<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\AcquisitionController;
use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidatorInterface;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Engine\Session;
use Epiclub\Process\AcquisitionProcess;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionController;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionControllerBuilder;
use Epiclub\Tests\Unit\Domain\Support\InMemoryJournalActiviteManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-11-07] Étapes d'une acquisition inscrites au journal d'activité :
 * création, modification, ajout de ligne, suppression, validation.
 */
final class AcquisitionJournalActiviteTest extends TestCase
{
    private InMemoryJournalActiviteManager $journal;
    private Session $session;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournalActiviteManager();
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 42, 'username' => 'admin', 'role' => 'ROLE_ADMIN']);
        $this->session->set('csrf_token', 'csrf');
    }

    public function testCreation(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findOneByCriteria')->willReturn(null);
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->method('upload')->willReturn(null);
        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('acquisition_process')->willReturn(12);

        $c = $this->controleur(fn (TestableAcquisitionControllerBuilder $b) => $b
            ->withAcquisitionManager($acquisitions)->withFactureUploader($uploader)->withAcquisitionProcess($process));
        $c->callHandleCreateAction($this->post(['facture_reference' => 'FAC-001', 'facture_date' => '2025-01-15', 'fournisseur_nom' => 'Vertical']), []);

        self::assertSame(["Création de l'acquisition n° 12 (facture FAC-001)"], $this->journal->actions('acquisition'));
        self::assertSame('127.0.0.1', $this->journal->entrees[0]['ip']);
    }

    public function testModification(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findOneByCriteria')->willReturn(null);
        $acquisitions->method('save')->willReturn(true);
        $fournisseurs = $this->createMock(FournisseurManager::class);
        $fournisseurs->method('findOneByCriteria')->willReturn(['id' => 3]);

        $c = $this->controleur(fn (TestableAcquisitionControllerBuilder $b) => $b
            ->withAcquisitionManager($acquisitions)->withFournisseurManager($fournisseurs));
        $c->callHandleUpdateAction(
            $this->post(['facture_reference' => 'FAC-002', 'facture_date' => '2025-01-20', 'fournisseur_nom' => 'Vertical']),
            ['id' => 12, 'facture_document' => null]
        );

        self::assertSame(["Modification de l'acquisition n° 12 (facture FAC-002)"], $this->journal->actions('acquisition'));
    }

    public function testSuppressionDUnBrouillon(): void
    {
        $lignes = $this->createMock(AcquisitionLigneManager::class);
        $lignes->method('findByAcquisition')->willReturn([
            ['id' => 10, 'equipements_generes' => 0],
            ['id' => 11, 'equipements_generes' => 0],
        ]);

        $c = $this->controleur(fn (TestableAcquisitionControllerBuilder $b) => $b->withAcquisitionLigneManager($lignes));
        $c->callHandleDeleteAction(['id' => 12, 'facture_reference' => 'FAC-001', 'est_validee' => 0, 'facture_document' => null]);

        self::assertSame(["Suppression de l'acquisition n° 12 (facture FAC-001) (non validée, 2 lignes)"], $this->journal->actions('acquisition'));
    }

    public function testSuppressionRefuseeNonJournalisee(): void
    {
        $c = $this->controleur();
        $c->callHandleDeleteAction(['id' => 12, 'est_validee' => 1, 'facture_document' => null]);

        self::assertSame([], $this->journal->entrees);
    }

    public function testValidationAvecLesEquipementsGeneres(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 12, 'facture_reference' => 'FAC-001', 'est_validee' => 0]);
        $validateur = $this->createMock(AcquisitionValidatorInterface::class);
        $validateur->method('performValidation')->willReturn([
            'route' => '/admin/acquisitions/acquisition-12', 'type' => 'success', 'message' => 'ok', 'critical' => false,
        ]);
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findByAcquisition')->willReturn([
            ['reference' => '25BAU002'], ['reference' => '25BAU001'], ['reference' => '25COR001'],
        ]);

        $c = $this->controleur(fn (TestableAcquisitionControllerBuilder $b) => $b
            ->withAcquisitionManager($acquisitions)->withValidator($validateur)->withEquipementManager($equipements));
        $c->valider($this->valider());

        self::assertSame(
            ["Validation de l'acquisition n° 12 (facture FAC-001) : 3 équipements générés (25BAU001, 25BAU002, 25COR001)"],
            $this->journal->actions('acquisition')
        );
    }

    public function testValidationEchoueeNonJournalisee(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 12, 'facture_reference' => 'FAC-001', 'est_validee' => 0]);
        $validateur = $this->createMock(AcquisitionValidatorInterface::class);
        $validateur->method('performValidation')->willReturn([
            'route' => '/admin/acquisitions/acquisition_modification-12', 'type' => 'error', 'message' => 'non', 'critical' => true,
        ]);

        $c = $this->controleur(fn (TestableAcquisitionControllerBuilder $b) => $b
            ->withAcquisitionManager($acquisitions)->withValidator($validateur));
        $c->valider($this->valider());

        self::assertSame([], $this->journal->entrees);
    }

    public function testLibelleDUneLigne(): void
    {
        self::assertSame('BAU-01 – Baudrier Petzl × 4 (lot)', AcquisitionController::libelleLigne(
            ['reference' => 'BAU-01', 'designation' => 'Baudrier Petzl', 'nombre' => 4, 'regrouper_en_lot' => 1]
        ));
        self::assertSame('BAU-01 – Baudrier × 2', AcquisitionController::libelleLigne(['reference' => 'BAU-01', 'designation' => 'Baudrier', 'nombre' => 2]));
        self::assertSame('BAU-01', AcquisitionController::libelleLigne(['reference' => 'BAU-01', 'designation' => '', 'nombre' => 1]));
    }

    // ------------------------------------------------------------------

    /** @param (callable(TestableAcquisitionControllerBuilder): TestableAcquisitionControllerBuilder)|null $configurer */
    private function controleur(?callable $configurer = null): TestableAcquisitionController
    {
        $builder = (new TestableAcquisitionControllerBuilder($this))->withSession($this->session);
        if ($configurer !== null) {
            $builder = $configurer($builder);
        }
        $c = $builder->build();
        $c->journalOverride = $this->journal;
        return $c;
    }

    /** @param array<string, mixed> $donnees */
    private function post(array $donnees): Request
    {
        return Request::create('/admin/acquisitions/nouvelle', 'POST', $donnees + ['csrf_token' => 'csrf']);
    }

    private function valider(): Request
    {
        $r = Request::create('/admin/acquisitions/valider/12', 'POST', ['csrf_token' => 'csrf']);
        $r->attributes->set('id', '12');
        return $r;
    }
}
