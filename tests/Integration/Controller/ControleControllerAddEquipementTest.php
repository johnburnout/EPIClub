<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * Caractérisation HTTP du parcours « ajout d'équipement à un contrôle ».
 *
 * Décision produit (2026-10-18) : après le clic sur « Contrôler » dans
 * la liste des équipements disponibles, la fiche contrôle de
 * l'équipement ajouté doit s'ouvrir directement (redirection vers
 * /admin/controles/update-ligne/{ligne_id}) — en plus de l'ajout de
 * l'équipement dans la liste des équipements contrôlés.
 *
 * ⚠️ Prérequis : MySQL + compte admin/adminadmin (id = 2 attendu) —
 * le serveur HTTP est auto-démarré par AbstractControllerTestCase.
 */
final class ControleControllerAddEquipementTest extends AbstractControllerTestCase
{
    private const ADMIN_ID = 2;

    /**
     * Parcours complet : liste des disponibles → clic « Contrôler » →
     * redirection vers la fiche contrôle → fiche affichée → équipement
     * présent dans « Équipements déjà dans le contrôle ».
     */
    public function testAddEquipementOpensLigneForm(): void
    {
        // Arrange : contrôle ouvert + équipement libre (jamais ajouté)
        $acqId = $this->fixture->createDraftAcquisition('TEST-ADDEQ');
        $controleId = $this->fixture->createControle(
            controleurId: self::ADMIN_ID,
            libelle: 'TEST-ADDEQ',
            statut: 'ouvert',
        );
        $equipementId = $this->fixture->createEquipement($acqId, 1, 'TEST-ADDEQ-E1');

        // 1. La page du contrôle propose l'équipement avec le bouton « Contrôler »
        $page = $this->client->get("/admin/controles/edit/$controleId?limit=1000");
        self::assertSame(200, $page['status'], 'Body: ' . substr(strip_tags($page['body']), 0, 300));
        self::assertStringContainsString(
            "/admin/controles/add-equipement/$controleId",
            $page['body'],
            'Le formulaire d\'ajout doit être rendu sur la page du contrôle.'
        );
        self::assertStringContainsString(
            'name="equipement_id" value="' . $equipementId . '"',
            $page['body'],
            'L\'équipement libre doit être proposé dans la liste des disponibles.'
        );

        // 2. Clic sur « Contrôler » → POST add-equipement
        $response = $this->client->post("/admin/controles/add-equipement/$controleId", [
            'equipement_id' => $equipementId,
        ]);

        // 3. La ligne est créée en base avec le statut « a_controler »
        $ligne = $this->fixture->findControleLigne($controleId, $equipementId);
        self::assertNotNull($ligne, 'La ligne de contrôle doit être créée en base.');
        self::assertSame('a_controler', $ligne['statut']);

        // 4. La redirection ouvre la fiche contrôle de l'équipement ajouté
        $this->assertRedirectsTo("/admin/controles/update-ligne/{$ligne['id']}", $response);

        // 5. La fiche contrôle s'affiche (formulaire statut / date / remarque)
        $fiche = $this->client->get("/admin/controles/update-ligne/{$ligne['id']}");
        self::assertSame(200, $fiche['status'], 'Body: ' . substr(strip_tags($fiche['body']), 0, 300));
        self::assertStringContainsString(
            'Modifier la ligne de contrôle',
            $fiche['body'],
            'La fiche contrôle de l\'équipement doit s\'afficher après l\'ajout.'
        );
        self::assertStringContainsString('name="statut"', $fiche['body']);
        self::assertStringContainsString('name="date_controle"', $fiche['body']);
        self::assertStringContainsString('name="remarque"', $fiche['body']);

        // 6. L'équipement apparaît dans « Équipements déjà dans le contrôle »
        //    et n'est plus proposé dans les disponibles.
        $page = $this->client->get("/admin/controles/edit/$controleId?limit=1000");
        self::assertSame(200, $page['status']);
        self::assertStringContainsString(
            "/admin/controles/update-ligne/{$ligne['id']}",
            $page['body'],
            'La ligne créée doit apparaître dans la liste des équipements du contrôle.'
        );
        self::assertStringNotContainsString(
            'name="equipement_id" value="' . $equipementId . '"',
            $page['body'],
            'L\'équipement ajouté ne doit plus être proposé dans les disponibles.'
        );
    }
}
