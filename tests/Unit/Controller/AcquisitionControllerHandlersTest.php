<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Engine\FactureUploader;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires des handlers d'AcquisitionController (Vague 7).
 *
 * Vérifie que les handlers retournent un HandlerResult avec :
 *  - response    : RedirectResponse en cas de succès, null sinon
 *  - acquisition : remontée en cas d'erreur (pour réaffichage)
 *  - formErrors  : erreurs indexées par champ
 *
 * ⚠️ Périmètre : seuls les scénarios qui échouent AVANT tout appel BDD
 * sont testés ici (référence vide, whitelist). Les scénarios nominaux
 * et les erreurs BDD (référence dupliquée, échec save) restent couverts
 * par la suite Integration (AcquisitionControllerTest).
 *
 * ⚠️ FactureUploader est final → non mockable. On utilise une vraie
 * instance avec un répertoire temporaire, et on vérifie l'absence
 * d'effet de bord (pas de dossier factures/ créé).
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
            'id'                => '999',           // ← tentative d'injection
            'est_validee'       => '1',             // ← tentative d'injection
        ]);

        $result = $controller->callHandleCreateAction($request, []);

        // La whitelist doit avoir ignoré id et est_validee
        self::assertArrayNotHasKey('id', $result->acquisition);
        self::assertArrayNotHasKey('est_validee', $result->acquisition);

        // Mais les champs légitimes doivent être présents
        self::assertSame('', $result->acquisition['facture_reference']);
        self::assertSame('2025-01-15', $result->acquisition['facture_date']);
        self::assertSame('Fournisseur Test', $result->acquisition['fournisseur_nom']);
        self::assertArrayHasKey('saisie_par', $result->acquisition);
        self::assertNull($result->acquisition['facture_document']);
    }

    /**
     * [VAGUE 7] Vérifie que l'upload n'est PAS tenté quand une erreur
     * bloquante (référence vide) court-circuite le handler.
     *
     * Preuve par effet de bord : si upload() était appelé, il créerait
     * le sous-dossier factures/ dans le répertoire temporaire.
     */
    public function testHandleCreateActionSkipsUploadWhenReferenceEmpty(): void
    {
        $controller = $this->makeController();
        $request = $this->makePostRequest([
            'action'            => 'create',
            'facture_reference' => '',   // ← erreur bloquante
            'facture_date'      => '2025-01-15',
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        $result = $controller->callHandleCreateAction($request, []);

        self::assertNull($result->response);
        self::assertArrayHasKey('facture_reference', $result->formErrors);
        self::assertArrayNotHasKey('facture_document', $result->formErrors);
        // Preuve : upload() n'a pas été appelé, donc factures/ n'existe pas
        self::assertDirectoryDoesNotExist($this->tmpUploadDir . '/factures');
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeController(): TestableAcquisitionController
    {
        // ⚠️ Session ici = Epiclub\Engine\Session (extends Symfony Session).
        // Elle hérite du constructeur parent → attend un SessionStorageInterface.
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 42, 'username' => 'admin']);

        // Pas de validator injecté : handleCreateAction ne l'utilise pas.
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