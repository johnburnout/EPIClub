<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\FactureUploader;
use Epiclub\Engine\FactureUploaderInterface;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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
    // Helpers
    // ==================================================================

    private function makeController(): TestableAcquisitionController
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 42, 'username' => 'admin']);

        return new TestableAcquisitionController(
            $session,
            new FactureUploader($this->tmpUploadDir),
        );
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
}