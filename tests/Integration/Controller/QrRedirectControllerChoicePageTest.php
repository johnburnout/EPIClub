<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Controller;

/**
 * [FIX #75] Test Integration de la route /qr/choix/{id}.
 *
 * Régression corrigée : le template choice_page.twig était cherché
 * à la racine de templates/ alors qu'il est dans templates/qr/.
 * Résultat : Twig\Error\LoaderError → 500.
 *
 * ⚠️ Nécessite un serveur HTTP + un équipement en base (créé par le test).
 * La route nécessite ROLE_USER (le setUp d'AbstractControllerTestCase
 * fait loginAsAdmin).
 *
 * ⚠️ Catégorie id=5 est utilisée car elle existe en base test
 * (vérifié : ids 4, 5, 6 existent, pas 1).
 */
final class QrRedirectControllerChoicePageTest extends AbstractControllerTestCase
{
    private const CATEGORIE_ID = 5;

    public function testChoicePageRenders200WithContent(): void
    {
        $acqId = $this->fixture->createDraftAcquisition('TEST-75-CHOICE');

        $equipementId = $this->fixture->createEquipement(
            acquisitionId: $acqId,
            categorieId: self::CATEGORIE_ID,
            reference: 'TEST-75-EQUIP',
            libelle: 'Équipement #75',
        );

        $response = $this->client->get('/qr/choix/' . $equipementId);

        self::assertSame(
            200,
            $response['status'],
            'Body: ' . substr(strip_tags($response['body']), 0, 500)
        );

        // Le template affiche la référence dans le titre (h4).
        self::assertStringContainsString('TEST-75-EQUIP', $response['body']);

        // Le template affiche aussi le libellé (h5).
        self::assertStringContainsString('Équipement #75', $response['body']);
    }
}