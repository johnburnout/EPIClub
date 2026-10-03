<?php

declare(strict_types=1);

namespace Epiclub\Tests\Domain;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidator;
use Epiclub\Process\AcquisitionProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de AcquisitionValidator (issue #34).
 *
 * Couvre les branches non testables via l'UI, notamment :
 * - failureRoute quand la facture est manquante (bouton désactivé dans
 *   acquisition_show.twig empêche le test manuel).
 * - exclusion d'ID lors de la modification d'une ligne.
 * - gestion des exceptions du process métier.
 */
final class AcquisitionValidatorTest extends TestCase
{
    // ==================================================================
    // validateLigne() — cas nominaux
    // ==================================================================

    public function testValidateLigneAcceptsCompleteValidLigne(): void
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->method('findByReference')->willReturn(null);

        $validator = new AcquisitionValidator(
            $ligneManager,
            $this->createMock(AcquisitionManager::class),
            $this->createMock(AcquisitionProcess::class),
        );

        $errors = $validator->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis cruciforme',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 3,
        ]);

        self::assertSame([], $errors);
    }

    // ==================================================================
    // validateLigne() — reference
    // ==================================================================

    public function testValidateLigneRejectsEmptyReference(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => '',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 2,
        ]);

        self::assertSame('La référence est obligatoire.', $errors['reference']);
    }

    public function testValidateLigneRejectsWhitespaceOnlyReference(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => '   ',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 2,
        ]);

        self::assertSame('La référence est obligatoire.', $errors['reference']);
    }

    public function testValidateLigneRejectsDuplicateReference(): void
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->method('findByReference')->willReturn(['id' => 7]);

        $validator = new AcquisitionValidator(
            $ligneManager,
            $this->createMock(AcquisitionManager::class),
            $this->createMock(AcquisitionProcess::class),
        );

        $errors = $validator->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 2,
        ]);

        self::assertSame('Cette référence existe déjà.', $errors['reference']);
    }

    public function testValidateLigneExcludesCurrentIdOnUpdate(): void
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->expects(self::once())
            ->method('findByReference')
            ->with('REF-001', 42)   // ← excludeId bien transmis
            ->willReturn(null);      // ← pas de doublon trouvé

        $validator = new AcquisitionValidator(
            $ligneManager,
            $this->createMock(AcquisitionManager::class),
            $this->createMock(AcquisitionProcess::class),
        );

        $errors = $validator->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ], 42);

        self::assertSame([], $errors);
    }

    public function testValidateLigneWithoutExcludeIdPassesNull(): void
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->expects(self::once())
            ->method('findByReference')
            ->with('REF-001', null)   // ← création : pas d'exclusion
            ->willReturn(null);

        $validator = new AcquisitionValidator(
            $ligneManager,
            $this->createMock(AcquisitionManager::class),
            $this->createMock(AcquisitionProcess::class),
        );

        $validator->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ]);
    }

    // ==================================================================
    // validateLigne() — designation
    // ==================================================================

    public function testValidateLigneRejectsEmptyDesignation(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => '',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ]);

        self::assertSame('Le libellé est obligatoire.', $errors['designation']);
    }

    // ==================================================================
    // validateLigne() — categorie_libelle
    // ==================================================================

    public function testValidateLigneRejectsEmptyCategorie(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => '',
            'nombre'            => 1,
        ]);

        self::assertSame('La catégorie est obligatoire.', $errors['categorie_libelle']);
    }

    // ==================================================================
    // validateLigne() — nombre
    // ==================================================================

    public function testValidateLigneRejectsZeroNombre(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 0,
        ]);

        self::assertSame('Le nombre doit être supérieur à 0.', $errors['nombre']);
    }

    public function testValidateLigneRejectsNegativeNombre(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            'nombre'            => -5,
        ]);

        self::assertSame('Le nombre doit être supérieur à 0.', $errors['nombre']);
    }

    public function testValidateLigneRejectsMissingNombreKey(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => 'REF-001',
            'designation'       => 'Tournevis',
            'categorie_libelle' => 'Outillage',
            // 'nombre' absent
        ]);

        self::assertSame('Le nombre doit être supérieur à 0.', $errors['nombre']);
    }

    // ==================================================================
    // validateLigne() — cumul d'erreurs
    // ==================================================================

    public function testValidateLigneAccumulatesAllErrors(): void
    {
        $errors = $this->makeValidator()->validateLigne([
            'reference'         => '',
            'designation'       => '',
            'categorie_libelle' => '',
            'nombre'            => 0,
        ]);

        self::assertCount(4, $errors);
        self::assertArrayHasKey('reference', $errors);
        self::assertArrayHasKey('designation', $errors);
        self::assertArrayHasKey('categorie_libelle', $errors);
        self::assertArrayHasKey('nombre', $errors);
    }

    // ==================================================================
    // performValidation() — facture manquante (branche failureRoute)
    // ==================================================================

    /**
     * ⚠️ CAS CRITIQUE non testable via l'UI :
     * le bouton "Valider" est désactivé dans acquisition_show.twig
     * quand la facture est absente. Ce test couvre la branche backend.
     */
    public function testPerformValidationFailsWithoutFacture(): void
    {
        $result = $this->makeValidator()->performValidation(
            ['id' => 7, 'facture_document' => null],
            successRoute: '/admin/acquisitions/acquisition-7',
            failureRoute: '/admin/acquisitions/acquisition_modification-7',
        );

        self::assertSame('error', $result['type']);
        self::assertSame('/admin/acquisitions/acquisition_modification-7', $result['route']);
        self::assertTrue($result['critical']);
        self::assertStringContainsString('facture', $result['message']);
    }

    public function testPerformValidationTreatsEmptyStringFactureAsMissing(): void
    {
        $result = $this->makeValidator()->performValidation(
            ['id' => 7, 'facture_document' => ''],
            successRoute: '/ok',
            failureRoute: '/ko',
        );

        self::assertSame('error', $result['type']);
        self::assertSame('/ko', $result['route']);
        self::assertTrue($result['critical']);
    }

    // ==================================================================
    // performValidation() — succès
    // ==================================================================

    public function testPerformValidationSucceeds(): void
    {
        $acqManager = $this->createMock(AcquisitionManager::class);
        $acqManager->expects(self::once())->method('save');

        $process = $this->createMock(AcquisitionProcess::class);
        $process->expects(self::once())
            ->method('validerAcquisition')
            ->with(7);

        $validator = new AcquisitionValidator(
            $this->createMock(AcquisitionLigneManager::class),
            $acqManager,
            $process,
        );

        $result = $validator->performValidation(
            ['id' => 7, 'facture_document' => 'factures/x.pdf'],
            successRoute: '/admin/acquisitions/acquisition-7',
            failureRoute: '/admin/acquisitions/acquisition_modification-7',
        );

        self::assertSame('success', $result['type']);
        self::assertSame('/admin/acquisitions/acquisition-7', $result['route']);
        self::assertFalse($result['critical']);
        self::assertStringContainsString('validée', $result['message']);
    }

    public function testPerformValidationMarksAcquisitionAsValidated(): void
    {
        $acqManager = $this->createMock(AcquisitionManager::class);
        $savedAcquisition = null;
        $acqManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (array $acq) use (&$savedAcquisition): void {
                $savedAcquisition = $acq;
            });

        $validator = new AcquisitionValidator(
            $this->createMock(AcquisitionLigneManager::class),
            $acqManager,
            $this->createMock(AcquisitionProcess::class),
        );

        $validator->performValidation(
            ['id' => 7, 'facture_document' => 'factures/x.pdf', 'est_validee' => 0],
            successRoute: '/ok',
            failureRoute: '/ko',
        );

        self::assertNotNull($savedAcquisition);
        self::assertSame(1, $savedAcquisition['est_validee']);
    }

    // ==================================================================
    // performValidation() — exception du process
    // ==================================================================

    public function testPerformValidationCatchesProcessException(): void
    {
        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('validerAcquisition')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $validator = new AcquisitionValidator(
            $this->createMock(AcquisitionLigneManager::class),
            $this->createMock(AcquisitionManager::class),
            $process,
        );

        $result = $validator->performValidation(
            ['id' => 7, 'facture_document' => 'factures/x.pdf'],
            successRoute: '/ok',
            failureRoute: '/ko',
        );

        self::assertSame('error', $result['type']);
        self::assertSame('/ko', $result['route']);
        self::assertTrue($result['critical']);
    }

    /**
     * [SÉCURITÉ] Le message d'erreur ne doit PAS exposer l'exception
     * au client (fuite d'information).
     */
    public function testPerformValidationDoesNotLeakExceptionMessage(): void
    {
        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('validerAcquisition')
            ->willThrowException(new \RuntimeException('SECRET_DB_PASSWORD_IN_ERROR'));

        $validator = new AcquisitionValidator(
            $this->createMock(AcquisitionLigneManager::class),
            $this->createMock(AcquisitionManager::class),
            $process,
        );

        $result = $validator->performValidation(
            ['id' => 7, 'facture_document' => 'factures/x.pdf'],
            successRoute: '/ok',
            failureRoute: '/ko',
        );

        self::assertStringNotContainsString('SECRET_DB_PASSWORD_IN_ERROR', $result['message']);
    }

    /**
     * [ROBUSTESSE] Même en cas d'exception, le save() ne doit pas
     * être appelé (l'acquisition ne doit pas être marquée validée).
     */
    public function testPerformValidationDoesNotSaveOnException(): void
    {
        $acqManager = $this->createMock(AcquisitionManager::class);
        $acqManager->expects(self::never())->method('save');

        $process = $this->createMock(AcquisitionProcess::class);
        $process->method('validerAcquisition')
            ->willThrowException(new \RuntimeException('boom'));

        $validator = new AcquisitionValidator(
            $this->createMock(AcquisitionLigneManager::class),
            $acqManager,
            $process,
        );

        $validator->performValidation(
            ['id' => 7, 'facture_document' => 'factures/x.pdf'],
            successRoute: '/ok',
            failureRoute: '/ko',
        );
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function makeValidator(): AcquisitionValidator
    {
        $ligneManager = $this->createMock(AcquisitionLigneManager::class);
        $ligneManager->method('findByReference')->willReturn(null);

        return new AcquisitionValidator(
            $ligneManager,
            $this->createMock(AcquisitionManager::class),
            $this->createMock(AcquisitionProcess::class),
        );
    }
}