<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\AcquisitionValidatorInterface;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Engine\Session;
use Epiclub\Process\AcquisitionProcess;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * [REFACTOR VAGUE 8] Builder pour TestableAcquisitionController.
 *
 * ⚠️ PHPUnit 11 : createMock() est protected → on utilise MockBuilder
 *    directement pour instancier les mocks depuis cette classe externe.
 *
 * ⚠️ La Session fournie par défaut NE CONTIENT PAS user['id'] :
 *    cela évite que AbstractController::updateLastActivity() n'ouvre
 *    une connexion PDO via UtilisateurManager.
 */
final class TestableAcquisitionControllerBuilder
{
    private Session $session;
    private FactureUploaderInterface $uploader;
    private AcquisitionValidatorInterface $validator;
    private AcquisitionManager $acquisitionManager;
    private AcquisitionLigneManager $acquisitionLigneManager;
    private FournisseurManager $fournisseurManager;
    private CategorieManager $categorieManager;
    private EquipementManager $equipementManager;
    private AcquisitionProcess $acquisitionProcess;

    public function __construct(private readonly TestCase $testCase)
    {
        $this->session                 = $this->mockSession();
        $this->uploader                = $this->mockInterface(FactureUploaderInterface::class);
        $this->validator               = $this->mockInterface(AcquisitionValidatorInterface::class);
        $this->acquisitionManager      = $this->mockConcrete(AcquisitionManager::class);
        $this->acquisitionLigneManager = $this->mockConcrete(AcquisitionLigneManager::class);
        $this->fournisseurManager      = $this->mockConcrete(FournisseurManager::class);
        $this->categorieManager        = $this->mockConcrete(CategorieManager::class);
        $this->equipementManager       = $this->mockConcrete(EquipementManager::class);
        $this->acquisitionProcess      = $this->mockConcrete(AcquisitionProcess::class);
    }

    public function withSession(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function withFactureUploader(FactureUploaderInterface $uploader): self
    {
        $this->uploader = $uploader;
        return $this;
    }

    public function withValidator(AcquisitionValidatorInterface $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    public function withAcquisitionManager(AcquisitionManager $manager): self
    {
        $this->acquisitionManager = $manager;
        return $this;
    }

    public function withAcquisitionLigneManager(AcquisitionLigneManager $manager): self
    {
        $this->acquisitionLigneManager = $manager;
        return $this;
    }

    public function withFournisseurManager(FournisseurManager $manager): self
    {
        $this->fournisseurManager = $manager;
        return $this;
    }

    public function withCategorieManager(CategorieManager $manager): self
    {
        $this->categorieManager = $manager;
        return $this;
    }

    public function withEquipementManager(EquipementManager $manager): self
    {
        $this->equipementManager = $manager;
        return $this;
    }

    public function withAcquisitionProcess(AcquisitionProcess $process): self
    {
        $this->acquisitionProcess = $process;
        return $this;
    }

    public function build(): TestableAcquisitionController
    {
        return new TestableAcquisitionController(
            $this->session,
            $this->uploader,
            $this->validator,
            $this->acquisitionManager,
            $this->acquisitionLigneManager,
            $this->fournisseurManager,
            $this->categorieManager,
            $this->equipementManager,
            $this->acquisitionProcess,
        );
    }

    // ==================================================================
    // Helpers internes — utilisent MockBuilder (public en PHPUnit 11)
    // ==================================================================

    /**
     * @template T of object
     * @param class-string<T> $interface
     * @return T
     */
    private function mockInterface(string $interface): object
    {
        return (new MockBuilder($this->testCase, $interface))->getMock();
    }

    /**
     * Mock une classe concrète en désactivant le constructeur
     * (évite AbstractManager → PDO).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function mockConcrete(string $class): object
    {
        return (new MockBuilder($this->testCase, $class))
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function mockSession(): Session
    {
        $session = (new MockBuilder($this->testCase, Session::class))->getMock();
        $session->method('get')->willReturn(null);
        $session->method('isAuthenticated')->willReturn(true);
        $session->method('isGranted')->willReturn(true);
        return $session;
    }
}