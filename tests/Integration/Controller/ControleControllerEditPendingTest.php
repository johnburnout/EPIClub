<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * [#113 / #114] Caractérisation HTTP des calculs faits AVANT filtrage
 * dans ControleController::edit() (décision produit du 2026-10-18,
 * option A).
 *
 * #113 : hasPending est calculé sur TOUTES les lignes — un filtre
 * actif qui masque les lignes « a_controler » ne doit PAS activer le
 * bouton « Clôturer » (aligné sur index() et la garde cloturer()).
 *
 * #114 : idsDejaAjoutes est calculé sur TOUTES les lignes — un
 * équipement déjà ajouté dont la ligne est masquée par le filtre ne
 * doit PAS réapparaître dans « équipements disponibles » (doublons
 * de lignes possibles sinon, addEquipement() n'a pas de garde).
 *
 * ⚠️ Prérequis : MySQL + compte admin/adminadmin (id lu par adminId()) —
 * le serveur HTTP est auto-démarré par AbstractControllerTestCase.
 */
final class ControleControllerEditPendingTest extends AbstractControllerTestCase
{

    /**
     * #113 — Filtre actif masquant une ligne « a_controler » :
     * le bouton « Clôturer » reste désactivé.
     */
    public function testCloturerStaysDisabledWhenFilterHidesPendingLigne(): void
    {
        [$controleId] = $this->createControleWithLignes([
            'a_controler',
            'controle_ok',
        ]);

        // Filtre : n'afficher que les lignes « controle_ok » → la ligne
        // pendante est masquée de la vue.
        $response = $this->client->get("/admin/controles/edit/$controleId?lignes_statut=controle_ok");

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringContainsString(
            'btn btn-success" disabled',
            $response['body'],
            '#113 : le bouton « Clôturer » doit rester désactivé (ligne pendante masquée par le filtre).'
        );
        self::assertStringContainsString(
            'encore marqués',
            html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '#113 : la mention « À contrôler » doit accompagner le bouton désactivé.'
        );
        self::assertStringNotContainsString(
            "/admin/controles/cloturer/$controleId",
            $response['body'],
            '#113 : le formulaire de clôture ne doit PAS être rendu.'
        );
    }

    /**
     * #113 — Cas témoin : aucune ligne pendante → clôture proposée.
     */
    public function testCloturerEnabledWhenNoPendingLigne(): void
    {
        [$controleId] = $this->createControleWithLignes(['controle_ok']);

        $response = $this->client->get("/admin/controles/edit/$controleId");

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringContainsString(
            "/admin/controles/cloturer/$controleId",
            $response['body'],
            '#113 : sans ligne pendante, le formulaire de clôture doit être rendu.'
        );
        self::assertStringNotContainsString(
            'btn btn-success" disabled',
            $response['body'],
            '#113 : le bouton désactivé ne doit pas être rendu.'
        );
    }

    /**
     * #114 — Un équipement déjà ajouté dont la ligne est masquée par
     * le filtre ne réapparaît PAS dans « équipements disponibles ».
     */
    public function testAddedEquipementNotReproposedWhenItsLigneIsFilteredOut(): void
    {
        [$controleId, $equipementAjoute, $equipementLibre] = $this->createControleWithLignes(
            ['controle_ok'],
            avecEquipementLibre: true
        );

        // Filtre : n'afficher que les lignes « a_controler » → la ligne
        // de l'équipement ajouté (controle_ok) est masquée de la vue.
        // limit élevé : les assertions portent sur toute la liste des
        // disponibles, indépendamment de la pagination.
        $response = $this->client->get(
            "/admin/controles/edit/$controleId?lignes_statut=a_controler&limit=1000"
        );

        self::assertSame(200, $response['status'], 'Body: ' . substr(strip_tags($response['body']), 0, 300));
        self::assertStringNotContainsString(
            'name="equipement_id" value="' . $equipementAjoute . '"',
            $response['body'],
            '#114 : un équipement déjà ajouté ne doit pas être re-proposé, même si sa ligne est filtrée.'
        );
        self::assertStringContainsString(
            'name="equipement_id" value="' . $equipementLibre . '"',
            $response['body'],
            '#114 (témoin) : un équipement jamais ajouté doit rester proposable.'
        );
    }

    // ==================================================================
    // Helper : contrôle + équipements + lignes
    // ==================================================================

    /**
     * Crée un contrôle ouvert avec une ligne par statut donné, plus
     * optionnellement un équipement libre (jamais ajouté).
     *
     * @param string[] $statuts Statuts des lignes à créer.
     * @return array{0: int, 1: int, 2: ?int} [controleId, premierEquipementId, equipementLibreId]
     */
    private function createControleWithLignes(array $statuts, bool $avecEquipementLibre = false): array
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-113-PENDING');
        $controleId = $this->fixture->createControle(
            controleurId: $this->adminId(),
            libelle: 'TEST-113-PENDING',
            statut: 'ouvert',
        );

        $premierEquipementId = null;
        foreach ($statuts as $i => $statut) {
            $equipementId = $this->fixture->createEquipement($acqId, 1, 'TEST-113-E' . $i);
            $this->fixture->createControleLigne($controleId, $equipementId, $statut);
            if ($premierEquipementId === null) {
                $premierEquipementId = $equipementId;
            }
        }

        $equipementLibreId = $avecEquipementLibre
            ? $this->fixture->createEquipement($acqId, 1, 'TEST-113-LIBRE')
            : null;

        return [$controleId, $premierEquipementId, $equipementLibreId];
    }
}
