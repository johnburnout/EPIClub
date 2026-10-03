<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

final class AcquisitionControllerTest extends AbstractControllerTestCase
{
    // ==================================================================
    // Action : valider
    // ==================================================================

    public function testValiderAcquisitionSansFactureRedirigeVersModification(): void
    {
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

        $this->assertFlashContains('Impossible de valider', $response);
    }

    public function testValiderAcquisitionDejaValideeRedirigeSansAction(): void
    {
        $acqId = $this->fixture->createValidatedAcquisition('TEST-DEJA-VALIDEE');

        $response = $this->client->post("/admin/acquisitions/valider/$acqId");

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition-$acqId",
            $response
        );
    }

    public function testValiderAcquisitionSansCsrfEstRejetee(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-CSRF');

        $response = $this->client->postWithoutCsrf("/admin/acquisitions/valider/$acqId");

        self::assertContains(
            $response['status'],
            [302, 303, 403],
            "Un POST sans CSRF doit être rejeté, reçu : {$response['status']}"
        );
    }

    public function testValiderAcquisitionAnonymeRedirigeVersLogin(): void
    {
        $this->client->clearSession();

        $acqId = $this->fixture->createDraftAcquisition('TEST-ANON');

        $response = $this->client->postWithoutCsrf("/admin/acquisitions/valider/$acqId");

        $location = $response['headers']['location'] ?? '';
        self::assertTrue(
            $location === '/se_connecter' || $location === '/',
            "Redirection attendue vers /se_connecter ou /, reçu '$location'"
        );
    }

    // ==================================================================
    // Action : create
    // ==================================================================

    public function testCreateAcquisitionAvecDonneesValidesRedirige(): void
    {
        $reference = 'TEST-CREATE-' . uniqid();

        $response = $this->client->post('/admin/acquisitions/nouvelle', [
            'action'            => 'create',
            'facture_reference' => $reference,
            'facture_date'      => date('Y-m-d'),
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        self::assertContains(
            $response['status'],
            [301, 302, 303, 307, 308],
            "Création attendue avec redirection, reçu : {$response['status']}"
        );

        $location = $response['headers']['location'] ?? '';
        self::assertStringContainsString(
            '/admin/acquisitions/acquisition_modification-',
            $location,
            "Redirection attendue vers la modification, reçu '$location'"
        );
    }

    public function testCreateAcquisitionAvecReferenceDupliqueeAfficheErreur(): void
    {
        $existingRef = 'TEST-DUPLICATE-' . uniqid();
        $this->fixture->createDraftAcquisition($existingRef);

        $response = $this->client->post('/admin/acquisitions/nouvelle', [
            'action'            => 'create',
            'facture_reference' => $existingRef,
            'facture_date'      => date('Y-m-d'),
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('existe déjà', $response);
    }

    public function testCreateAcquisitionSansReferenceAfficheErreur(): void
    {
        $response = $this->client->post('/admin/acquisitions/nouvelle', [
            'action'            => 'create',
            'facture_reference' => '',
            'facture_date'      => date('Y-m-d'),
            'fournisseur_nom'   => 'Fournisseur Test',
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('référence de facture est obligatoire', $response);
    }

    // ==================================================================
    // Action : add_ligne
    // ==================================================================

    public function testAddLigneValideRedirigeAvecFlashSucces(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADD-LIGNE');

        $response = $this->client->post("/admin/acquisitions/acquisition_modification-$acqId", [
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'TEST-REF-LIGNE-' . uniqid(),
                'designation'       => 'Tournevis de test',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 3,
            ],
        ]);

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition_modification-$acqId",
            $response
        );
    }

    public function testAddLigneAvecReferenceDupliqueeAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADD-LIGNE-DUP');
        $existingRef = 'TEST-REF-DUP-' . uniqid();
        $this->fixture->createLigne($acqId, $existingRef);

        $response = $this->client->post("/admin/acquisitions/acquisition_modification-$acqId", [
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => $existingRef,
                'designation'       => 'Autre désignation',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 1,
            ],
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('existe déjà', $response);
    }

    public function testAddLigneSansCategorieAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADD-LIGNE-CAT');

        $response = $this->client->post("/admin/acquisitions/acquisition_modification-$acqId", [
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'TEST-REF-NO-CAT-' . uniqid(),
                'designation'       => 'Tournevis',
                'categorie_libelle' => '',
                'nombre'            => 1,
            ],
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('catégorie est obligatoire', $response);
    }

    public function testAddLigneSansDesignationAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADD-LIGNE-DES');

        $response = $this->client->post("/admin/acquisitions/acquisition_modification-$acqId", [
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'TEST-REF-NO-DES-' . uniqid(),
                'designation'       => '',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 1,
            ],
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('libellé est obligatoire', $response);
    }

    public function testAddLigneAvecNombreZeroAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADD-LIGNE-NB');

        $response = $this->client->post("/admin/acquisitions/acquisition_modification-$acqId", [
            'action' => 'add_ligne',
            'ligne'  => [
                'reference'         => 'TEST-REF-NB0-' . uniqid(),
                'designation'       => 'Tournevis',
                'categorie_libelle' => 'Outillage',
                'nombre'            => 0,
            ],
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('supérieur à 0', $response);
    }

    // ==================================================================
    // Action : delete
    // ==================================================================

    public function testDeleteAcquisitionValideeRefuseeAvecFlashErreur(): void
    {
        $acqId = $this->fixture->createValidatedAcquisition('TEST-DELETE-VALIDEE');

        $response = $this->client->post("/admin/acquisitions/acquisition_supprimer-$acqId");

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition-$acqId",
            $response
        );

        $this->assertFlashContains('Impossible de supprimer', $response);
    }

    public function testDeleteAcquisitionBrouillonRedirigeVersListe(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-DELETE-BROUILLON');

        $response = $this->client->post("/admin/acquisitions/acquisition_supprimer-$acqId");

        $this->assertRedirectsTo('/admin/acquisitions', $response);
    }

    // ==================================================================
    // Action : list
    // ==================================================================

    public function testListAcquisitionsAccessibleAdmin(): void
    {
        $response = $this->client->get('/admin/acquisitions');

        self::assertSame(200, $response['status']);
    }
}