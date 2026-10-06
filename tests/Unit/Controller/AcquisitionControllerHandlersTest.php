<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionController;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionControllerBuilder;
use Epiclub\Domain\AcquisitionValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Exception\DuplicateReferenceException;
use Epiclub\Exception\FactureUploadException;
use Epiclub\Process\AcquisitionProcess;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires des handlers d'AcquisitionController (Vague 7).
 *
 * ⚠️ Périmètre : seuls les scénarios qui échouent AVANT tout appel BDD
 * sont testés ici (référence vide, whitelist). Les scénarios nominaux
 * et les erreurs BDD restent couverts par la suite Integration.
 *
 * ⚠️ FactureUploader est final → non mockable directement, mais depuis
 * la Vague 8 il implémente FactureUploaderInterface (mockable). On
 * utilise l'interface mockée dans les tests où c'est utile.
 */
final class AcquisitionControllerHandlersTest extends TestCase
{
    private string $tmpUploadDir;

    protected function setUp(): void
    {
        $this->tmpUploadDir = sys_get_temp_dir() . '/epiclub_handler_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmpUploadDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpUploadDir);
    }

    // ==================================================================
    // handleCreateAction — cas d'erreur sans BDD
    // ==================================================================

    public function testHandleCreateActionReturnsHandlerResultWithErrorOnEmptyReference(): void
    {
        $controller = $this->makeController();
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => '',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        $result = $controller->callHandleCreateAction($request, []);

        self::assertNull($result->response);
        self::assertFalse($result->isRedirect());
        self::assertArrayHasKey('facture_reference', $result->formErrors);
        self::assertSame(
            'La référence de facture est obligatoire.',
            $result->formErrors['facture_reference']
        );
    }

    public function testHandleCreateActionRemontesWhitelistedAcquisitionOnError(): void
    {
        $controller = $this->makeController();
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => '',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
            'id'                => '999',
            'est_validee'       => '1',
        ]);

        $result = $controller->callHandleCreateAction($request, []);

        self::assertArrayNotHasKey('id', $result->acquisition);
        self::assertArrayNotHasKey('est_validee', $result->acquisition);

        self::assertSame('', $result->acquisition['facture_reference']);
        self::assertSame('2025-01-15', $result->acquisition['facture_date']);
        self::assertSame('Fournisseur Test', $result->acquisition['fournisseur_nom']);
        self::assertArrayHasKey('saisie_par', $result->acquisition);
        self::assertNull($result->acquisition['facture_document']);
    }

    public function testHandleCreateActionSkipsUploadWhenReferenceEmpty(): void
    {
        $controller = $this->makeController();
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => '',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        $result = $controller->callHandleCreateAction($request, []);

        self::assertNull($result->response);
        self::assertArrayHasKey('facture_reference', $result->formErrors);
        self::assertArrayNotHasKey('facture_document', $result->formErrors);
        self::assertDirectoryDoesNotExist($this->tmpUploadDir . '/factures');
    }
    
    public function testHandleCreateActionRemontesNullFactureDateOnMissingInput(): void
    {
        $controller = $this->makeController();
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => '',  // ← vide → court-circuit avant process
            'fournisseur_nom'   => 'Fournisseur Test',
            // facture_date volontairement absent
        ]);
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_reference', $result->formErrors);
        // facture_date doit valoir null (pas d'exception, pas de throw)
    self::assertNull($result->acquisition['facture_date']);
    }
    
    public function testHandleCreateActionAllowsEmptyFournisseurNomAndReachesProcess(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('findOneByCriteria')
        ->willReturn(null);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->method('upload')->willReturn(null);
        
        $process = $this->createMock(AcquisitionProcess::class);
        // ⚠️ Le process DOIT être appelé, même avec fournisseur_nom vide
        $process->expects(self::once())
        ->method('acquisition_process')
        ->willReturn(42);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => '',  // ← vide
        ]);
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }

    // ==================================================================
    // handleDeleteAction — cas sans BDD
    // ==================================================================

    public function testHandleDeleteActionReturnsRedirectOnValidatedAcquisition(): void
    {
        $controller = $this->makeController();
        $acquisition = [
            'id'               => 42,
            'est_validee'      => 1,
            'facture_document' => null,
        ];

        $result = $controller->callHandleDeleteAction($acquisition);

        self::assertNotNull($result->response);
        self::assertTrue($result->isRedirect());
        self::assertSame(
            '/admin/acquisitions/acquisition-42',
            $result->response->headers->get('Location')
        );
    }
    
    // ==================================================================
    // handleDeleteAction — brouillon, échec, lignes générées
    // ==================================================================
    
    public function testHandleDeleteActionDeletesLignesAndAcquisitionOnDraft(): void
    {
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByAcquisition')
        ->willReturn([
            ['id' => 10, 'equipements_generes' => 0],
            ['id' => 11, 'equipements_generes' => 0],
        ]);
        $ligneManager->expects(self::exactly(2))->method('delete');
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('delete')
        ->with(42);
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        );
        
        $acquisition = [
            'id'               => 42,
            'est_validee'      => 0,
            'facture_document' => null,
        ];
        
        $result = $controller->callHandleDeleteAction($acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleDeleteActionReturnsErrorFlashWhenDeleteThrows(): void
    {
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByAcquisition')->willReturn([]);
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('delete')
        ->willThrowException(new \RuntimeException('DB down'));
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        );
        
        $acquisition = [
            'id'               => 42,
            'est_validee'      => 0,
            'facture_document' => null,
        ];
        
        $result = $controller->callHandleDeleteAction($acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleDeleteActionRefusesWhenLigneHasGeneratedEquipements(): void
    {
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByAcquisition')
        ->willReturn([
            ['id' => 10, 'equipements_generes' => 1],  // ← génère → refus
        ]);
        $ligneManager->expects(self::never())->method('delete');
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::never())->method('delete');
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        );
        
        $acquisition = [
            'id'               => 42,
            'est_validee'      => 0,
            'facture_document' => null,
        ];
        
        $result = $controller->callHandleDeleteAction($acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleDeleteActionDeletesFactureDocumentWhenPresent(): void
    {
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->method('findByAcquisition')->willReturn([]);
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())->method('delete')->with(42);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        // ⚠️ delete DOIT être appelé une fois avec le chemin
        $uploader->expects(self::once())
        ->method('delete')
        ->with('factures/old.pdf')
        ->willReturn(true);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionLigneManager: $ligneManager,
        );
        
        $acquisition = [
            'id'               => 42,
            'est_validee'      => 0,
            'facture_document' => 'factures/old.pdf',  // ← présent
        ];
        
        $result = $controller->callHandleDeleteAction($acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions',
            $result->response->headers->get('Location')
        );
    }
    
    // ==================================================================
    // handleCreateAction — upload et process
    // ==================================================================
    
    public function testHandleCreateActionUploadsFactureOnSuccess(): void
    {
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willReturn('factures/abc123.pdf');
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('findOneByCriteria')
        ->with(['facture_reference' => 'FAC-001'])
        ->willReturn(null);
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->expects(self::once())
        ->method('acquisition_process')
        ->willReturn(42);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleCreateActionReturnsBadMimeErrorFromUploader(): void
    {
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willThrowException(new FactureUploadException(
            'Format de fichier non autorisé (PDF uniquement).',
        FactureUploadException::BAD_MIME,
        ));
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_document', $result->formErrors);
    self::assertSame(
            'Format de fichier non autorisé (PDF uniquement).',
            $result->formErrors['facture_document']
        );
    }
    
    public function testHandleCreateActionReturnsTooLargeErrorFromUploader(): void
    {
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willThrowException(new FactureUploadException(
            'Le fichier dépasse la taille maximale autorisée.',
        FactureUploadException::TOO_LARGE,
        ));
        
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_document', $result->formErrors);
    }
    
    public function testHandleCreateActionReturnsErrorOnDuplicateReference(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('findOneByCriteria')
        ->with(['facture_reference' => 'FAC-DUP'])
        ->willReturn(['id' => 7, 'facture_reference' => 'FAC-DUP']);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::never())->method('upload');
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->expects(self::never())->method('acquisition_process');
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-DUP',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_reference', $result->formErrors);
    self::assertStringContainsString(
            'existe déjà',
            $result->formErrors['facture_reference']
        );
    }
    
    public function testHandleCreateActionReturnsGeneralErrorWhenProcessThrows(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->method('upload')->willReturn('factures/abc.pdf');
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->expects(self::once())
        ->method('acquisition_process')
        ->willThrowException(new \RuntimeException('DB down'));
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('general', $result->formErrors);
    self::assertSame(
            'Une erreur est survenue lors de la création. Merci de réessayer.',
            $result->formErrors['general']
        );
    }
    
    public function testHandleCreateActionReturnsGeneralErrorWhenProcessReturnsZero(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->method('upload')->willReturn(null);
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('acquisition_process')->willReturn(0);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        
        $result = $controller->callHandleCreateAction($request, []);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('general', $result->formErrors);
    self::assertSame(
            'Erreur lors de la création de l\'acquisition.',
            $result->formErrors['general']
        );
    }
    
    // ==================================================================
    // handleUpdateAction — upload et save
    // ==================================================================
    
    public function testHandleUpdateActionUploadsNewFactureAndDeletesOldOne(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('findOneByCriteria')
        ->willReturn(null);
        $acquisitionManager->expects(self::once())
        ->method('save')
        ->willReturn(true);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willReturn('factures/new.pdf');
        $uploader->expects(self::once())
        ->method('delete')
        ->with('factures/old.pdf')
        ->willReturn(true);
        
        $fournisseurManager = $this->getMockBuilder(FournisseurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $fournisseurManager->method('findOneByCriteria')
        ->willReturn(['id' => 5, 'nom' => 'Fournisseur Test']);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        fournisseurManager: $fournisseurManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $acquisition = [
            'id'               => 42,
            'facture_document' => 'factures/old.pdf',
        ];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleUpdateActionPreservesOldFactureWhenUploadFails(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        $acquisitionManager->expects(self::never())->method('save');
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willThrowException(new FactureUploadException(
            'Format de fichier non autorisé.',
        FactureUploadException::BAD_MIME,
        ));
        $uploader->expects(self::never())->method('delete');
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $acquisition = [
            'id'               => 42,
            'facture_document' => 'factures/old.pdf',
        ];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_document', $result->formErrors);
        // Ancienne facture préservée
    self::assertSame('factures/old.pdf', $result->acquisition['facture_document']);
    }
    
    public function testHandleUpdateActionReturnsGeneralErrorOnSaveFailure(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        $acquisitionManager->expects(self::once())
        ->method('save')
        ->willThrowException(new \RuntimeException('DB down'));
        
        $fournisseurManager = $this->getMockBuilder(FournisseurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $fournisseurManager->method('findOneByCriteria')
        ->willReturn(['id' => 5, 'nom' => 'Fournisseur Test']);
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        fournisseurManager: $fournisseurManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        
        $acquisition = [
            'id'               => 42,
            'facture_document' => null,
        ];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('general', $result->formErrors);
    self::assertSame(
            'Une erreur est survenue lors de la mise à jour. Merci de réessayer.',
            $result->formErrors['general']
        );
    }
    
    public function testHandleUpdateActionReturnsErrorOnDuplicateReferenceFromOtherAcquisition(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->expects(self::once())
        ->method('findOneByCriteria')
        ->willReturn(['id' => 99, 'facture_reference' => 'FAC-DUP']);
        $acquisitionManager->expects(self::never())->method('save');
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-DUP',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        
        $acquisition = ['id' => 42, 'facture_document' => null];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('facture_reference', $result->formErrors);
    self::assertStringContainsString(
            'déjà utilisée',
            $result->formErrors['facture_reference']
        );
    }
    
    public function testHandleUpdateActionUploadsNewFactureWithoutDeletingWhenNoOldFacture(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        $acquisitionManager->expects(self::once())->method('save')->willReturn(true);
        
        $uploader = $this->createMock(FactureUploaderInterface::class);
        $uploader->expects(self::once())
        ->method('upload')
        ->willReturn('factures/new.pdf');
        // ⚠️ delete NE DOIT PAS être appelé : pas d'ancienne facture
        $uploader->expects(self::never())->method('delete');
        
        $fournisseurManager = $this->getMockBuilder(FournisseurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $fournisseurManager->method('findOneByCriteria')
        ->willReturn(['id' => 5, 'nom' => 'Fournisseur Test']);
        
        $controller = $this->makeControllerWith(
        uploader: $uploader,
        acquisitionManager: $acquisitionManager,
        fournisseurManager: $fournisseurManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);
        $request->files->set('facture_document', $this->makeUploadedFile());
        
        $acquisition = [
            'id'               => 42,
            'facture_document' => null,  // ← pas d'ancienne facture
        ];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleUpdateActionCreatesFournisseurWhenUnknown(): void
    {
        $acquisitionManager = $this->getMockBuilder(AcquisitionManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $acquisitionManager->method('findOneByCriteria')->willReturn(null);
        $acquisitionManager->expects(self::once())->method('save')->willReturn(true);
        
        $fournisseurManager = $this->getMockBuilder(FournisseurManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        // findOneByCriteria retourne null → passe dans le else
        $fournisseurManager->expects(self::once())
        ->method('findOneByCriteria')
        ->with(['nom' => 'Nouveau Fournisseur'])
        ->willReturn(null);
        // save doit être appelé et retourner le nouvel id
        $fournisseurManager->expects(self::once())
        ->method('save')
        ->with(['nom' => 'Nouveau Fournisseur'])
        ->willReturn(99);
        
        $controller = $this->makeControllerWith(
        acquisitionManager: $acquisitionManager,
        fournisseurManager: $fournisseurManager,
        );
        
        $request = $this->makePostRequest([
            'action'            => 'update',
            'facture_reference' => 'FAC-001',
            'facture_date'      => '2025-01-20',
            'fournisseur_nom'   => 'Nouveau Fournisseur',
        ]);
        
        $acquisition = [
            'id'               => 42,
            'facture_document' => null,
        ];
        
        $result = $controller->callHandleUpdateAction($request, $acquisition);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    // ==================================================================
    // handleAddLigneAction — avec mock AcquisitionValidatorInterface
    // ==================================================================
    
    public function testHandleAddLigneActionReturnsValidationErrorsFromValidator(): void
    {
        $validator = $this->createMock(AcquisitionValidatorInterface::class);
        $validator->expects(self::once())
        ->method('validateLigne')
        ->willReturn([
            'reference'   => 'Cette référence existe déjà.',
            'designation' => 'Le libellé est obligatoire.',
        ]);
        
        $controller = $this->makeControllerWithValidator($validator);
        $request = $this->makePostRequest([
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'REF-DUP',
                'designation'       => '',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 2,
            ],
        ]);
        
        $result = $controller->callHandleAddLigneAction($request, ['id' => 42]);
        
    self::assertNull($result->response);
    self::assertFalse($result->isRedirect());
        // Erreurs remappées par prefixLigneErrors()
    self::assertArrayHasKey('ligne_reference', $result->formErrors);
    self::assertSame(
            'Cette référence existe déjà.',
            $result->formErrors['ligne_reference']
        );
    self::assertArrayHasKey('ligne_designation', $result->formErrors);
        // ligneData remontée
    self::assertSame('REF-DUP', $result->ligneData['reference']);
    self::assertSame('', $result->ligneData['designation']);
    }
    
    public function testHandleAddLigneActionReturnsLigneDataOnEmptyReference(): void
    {
        // Court-circuit AVANT l'appel au validator
        $validator = $this->createMock(AcquisitionValidatorInterface::class);
        $validator->expects(self::never())->method('validateLigne');
        
        $controller = $this->makeControllerWithValidator($validator);
        $request = $this->makePostRequest([
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => '',
                'designation'       => 'Tournevis',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 1,
            ],
        ]);
        
        $result = $controller->callHandleAddLigneAction($request, ['id' => 42]);
        
    self::assertNull($result->response);
    self::assertArrayHasKey('ligne_reference', $result->formErrors);
    self::assertSame(
            'Veuillez remplir les champs de la ligne.',
            $result->formErrors['ligne_reference']
        );
        // ligneData remontée même en cas d'erreur
    self::assertSame('', $result->ligneData['reference']);
    self::assertSame('Tournevis', $result->ligneData['designation']);
    }
    
    // ==================================================================
    // handleAddLigneAction — succès et exception
    // ==================================================================
    
    public function testHandleAddLigneActionRedirectsOnSuccess(): void
    {
        $validator = $this->createMock(AcquisitionValidatorInterface::class);
        $validator->method('validateLigne')->willReturn([]);
        
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->expects(self::once())
        ->method('save')
        ->willReturn(1);
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->expects(self::once())
        ->method('categorie_process')
        ->willReturn(5);
        
        $controller = $this->makeControllerWith(
        validator: $validator,
        acquisitionLigneManager: $ligneManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'REF-001',
                'designation'       => 'Tournevis',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 2,
            ],
        ]);
        
        $result = $controller->callHandleAddLigneAction($request, ['id' => 42]);
        
    self::assertTrue($result->isRedirect());
    self::assertSame(
            '/admin/acquisitions/acquisition_modification-42',
            $result->response->headers->get('Location')
        );
    }
    
    public function testHandleAddLigneActionReturnsErrorOnDuplicateReferenceException(): void
    {
        $validator = $this->createMock(AcquisitionValidatorInterface::class);
        $validator->method('validateLigne')->willReturn([]);
        
        $ligneManager = $this->getMockBuilder(AcquisitionLigneManager::class)
        ->disableOriginalConstructor()
        ->getMock();
        $ligneManager->expects(self::once())
        ->method('save')
        ->willThrowException(new DuplicateReferenceException('Duplicate'));
        
        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('categorie_process')->willReturn(5);
        
        $controller = $this->makeControllerWith(
        validator: $validator,
        acquisitionLigneManager: $ligneManager,
        acquisitionProcess: $process,
        );
        
        $request = $this->makePostRequest([
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'REF-DUP',
                'designation'       => 'Tournevis',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 1,
            ],
        ]);
        
        $result = $controller->callHandleAddLigneAction($request, ['id' => 42]);
        
    self::assertFalse($result->isRedirect());
    self::assertArrayHasKey('ligne_reference', $result->formErrors);
    self::assertStringContainsString(
            'existe déjà',
            $result->formErrors['ligne_reference']
        );
    }
    
    // ==================================================================
    // Helpers
    // ==================================================================
    
    /**
    * Construit un contrôleur avec les mocks fournis.
    * Les paramètres null sont remplacés par des mocks silencieux du builder.
    *
    * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
    *    directement pour les classes concrètes (managers).
    */
    private function makeControllerWith(
        ?AcquisitionValidatorInterface $validator = null,
        ?FactureUploaderInterface $uploader = null,
        ?AcquisitionManager $acquisitionManager = null,
        ?AcquisitionLigneManager $acquisitionLigneManager = null,
        ?FournisseurManager $fournisseurManager = null,
        ?AcquisitionProcess $acquisitionProcess = null,
        ?Session $session = null,
    ): TestableAcquisitionController {
        $session ??= $this->makeSession();
        
        $builder = (new TestableAcquisitionControllerBuilder($this))
        ->withSession($session);
        
        if ($uploader !== null) {
            $builder->withFactureUploader($uploader);
        }
        if ($validator !== null) {
            $builder->withValidator($validator);
        }
        if ($acquisitionManager !== null) {
            $builder->withAcquisitionManager($acquisitionManager);
        }
        if ($acquisitionLigneManager !== null) {
            $builder->withAcquisitionLigneManager($acquisitionLigneManager);
        }
        if ($fournisseurManager !== null) {
            $builder->withFournisseurManager($fournisseurManager);
        }
        if ($acquisitionProcess !== null) {
            $builder->withAcquisitionProcess($acquisitionProcess);
        }
        
        return $builder->build();
    }
    
    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 42, 'username' => 'admin']);
        return $session;
    }

    private function makeController(): TestableAcquisitionController
    {
        return $this->makeControllerWith();
    }
    
    private function makeControllerWithValidator(AcquisitionValidatorInterface $validator): TestableAcquisitionController
    {
        return $this->makeControllerWith(validator: $validator);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function makePostRequest(array $post): Request
    {
        return Request::create(
            '/admin/acquisitions/nouvelle',
            'POST',
            $post,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
    
    private function makeUploadedFile(
        string $originalName = 'facture.pdf',
        string $mimeType = 'application/pdf',
    ): UploadedFile {
        $path = $this->tmpUploadDir . '/' . $originalName;
        file_put_contents($path, '%PDF-1.4 fake content');
        return new UploadedFile($path, $originalName, $mimeType, null, true);
    }
}