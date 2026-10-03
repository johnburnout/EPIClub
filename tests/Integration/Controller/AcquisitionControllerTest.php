<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

final class AcquisitionControllerTest extends AbstractControllerTestCase
{
    public function testValiderAcquisitionSansFactureRedirigeVersModification(): void
    {
        // [DEBUG] Vérifier qui est connecté
        $homePage = $this->client->get('/tableau_de_bord');
        //error_log("[DEBUG] tableau_de_bord status: " . $homePage['status']);
        //error_log("[DEBUG] body contains 'admin': " . (str_contains($homePage['body'], 'admin') ? 'yes' : 'no'));
        
        $acqId = $this->fixture->createDraftAcquisition('TEST-SANS-FACTURE');
        $response = $this->client->post("/admin/acquisitions/valider/$acqId");
        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition_modification-$acqId",
            $response
        );
    }

    public function testValiderAcquisitionSansFactureAfficheFlashErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-FLASH-ERR');

        $response = $this->client->post("/admin/acquisitions/valider/$acqId");

        // Le flash doit contenir "Impossible de valider"
        $this->assertFlashContains('Impossible de valider', $response);
    }

    public function testValiderAcquisitionDejaValideeRedirigeSansAction(): void
    {
        // Given : une acquisition DÉJÀ validée
        $acqId = $this->fixture->createValidatedAcquisition('TEST-DEJA-VALIDEE');

        // When : on tente de la revalider
        $response = $this->client->post("/admin/acquisitions/valider/$acqId");

        // Then : redirection vers la page show (comportement du contrôleur)
        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition-$acqId",
            $response
        );
    }

    public function testValiderAcquisitionSansCsrfEstRejetee(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-CSRF');

        // When : POST sans CSRF token
        $response = $this->client->postWithoutCsrf("/admin/acquisitions/valider/$acqId");

        // Then : rejet (403 ou redirect vers /)
        self::assertContains(
            $response['status'],
            [302, 303, 403],
            "Un POST sans CSRF doit être rejeté, reçu : {$response['status']}"
        );
    }

    public function testValiderAcquisitionAnonymeRedirigeVersLogin(): void
    {
        // On vide la session pour simuler un anonyme
        $this->client->clearSession();
        
        $acqId = $this->fixture->createDraftAcquisition('TEST-ANON');
        
        $response = $this->client->postWithoutCsrf("/admin/acquisitions/valider/$acqId");
        
        // L'app redirige d'abord vers / via le handler AccessDeniedException
        // (public/index.php), puis / redirige en cascade vers /se_connecter.
        // Les deux emplacements sont acceptables.
    self::assertContains(
            $response['status'],
            [301, 302, 303, 307, 308],
            "Une redirection est attendue, reçu : {$response['status']}"
        );
        
        $location = $response['headers']['location'] ?? '';
    self::assertTrue(
            $location === '/se_connecter'
            || $location === '/'
            || str_contains($location, '/se_connecter'),
            "Redirection attendue vers /se_connecter ou /, reçu '$location'"
        );
    }
}