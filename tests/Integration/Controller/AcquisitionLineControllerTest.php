<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

final class AcquisitionLineControllerTest extends AbstractControllerTestCase
{
    // ==================================================================
    // Action : modifyLine
    // ==================================================================

    public function testModifyLineAvecReferenceInchangeeNeDeclenchePasDUplicite(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-MODIFY-LIGNE');
        $ref = 'TEST-REF-MOD-' . uniqid();
        $ligneId = $this->fixture->createLigne($acqId, $ref, 'Ancienne designation');

        $response = $this->client->post("/admin/acquisitions/ligne_modification-$ligneId", [
            'reference'         => $ref,
            'designation'       => 'Nouvelle designation',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 2,
        ]);

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition_modification-$acqId",
            $response
        );
    }

    public function testModifyLineAvecReferenceDupliqueeAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-MODIFY-DUP');
        $ref1 = 'TEST-REF-1-' . uniqid();
        $ref2 = 'TEST-REF-2-' . uniqid();
        $this->fixture->createLigne($acqId, $ref1, 'Ligne 1');
        $ligne2Id = $this->fixture->createLigne($acqId, $ref2, 'Ligne 2');

        $response = $this->client->post("/admin/acquisitions/ligne_modification-$ligne2Id", [
            'reference'         => $ref1,
            'designation'       => 'Ligne 2 modifiée',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('existe déjà', $response);
    }

    public function testModifyLineSansDesignationAfficheErreur(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-MODIFY-NO-DES');
        $ligneId = $this->fixture->createLigne($acqId, 'TEST-REF-' . uniqid(), 'Designation');

        $response = $this->client->post("/admin/acquisitions/ligne_modification-$ligneId", [
            'reference'         => 'TEST-REF-' . uniqid(),
            'designation'       => '',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ]);

        self::assertSame(200, $response['status']);
        $this->assertPageContainsError('libellé est obligatoire', $response);
    }

    public function testModifyLineSurAcquisitionValideeEstRefusee(): void
    {
        $acqId = $this->fixture->createValidatedAcquisition('TEST-MODIFY-VALIDEE');
        $ligneId = $this->fixture->createLigne($acqId, 'TEST-REF-VALIDEE-' . uniqid());

        $response = $this->client->post("/admin/acquisitions/ligne_modification-$ligneId", [
            'reference'         => 'TEST-NEW-REF-' . uniqid(),
            'designation'       => 'Modifiée',
            'categorie_libelle' => 'Outillage',
            'nombre'            => 1,
        ]);

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition-$acqId",
            $response
        );
        $this->assertFlashContains('validée', $response);
    }

    // ==================================================================
    // Action : deleteLine
    // ==================================================================

    public function testDeleteLineValideRedirigeVersModification(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-DELETE-LIGNE');
        $ligneId = $this->fixture->createLigne($acqId, 'TEST-REF-DEL-' . uniqid());

        $response = $this->client->post("/admin/acquisitions/ligne_supprimer-$ligneId");

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition_modification-$acqId",
            $response
        );
    }

    public function testDeleteLineSurAcquisitionValideeEstRefusee(): void
    {
        $acqId = $this->fixture->createValidatedAcquisition('TEST-DEL-LIGNE-VALIDEE');
        $ligneId = $this->fixture->createLigne($acqId, 'TEST-DEL-VAL-' . uniqid());

        $response = $this->client->post("/admin/acquisitions/ligne_supprimer-$ligneId");

        $this->assertRedirectsTo(
            "/admin/acquisitions/acquisition-$acqId",
            $response
        );
        $this->assertFlashContains('validée', $response);
    }
}