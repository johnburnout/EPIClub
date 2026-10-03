<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Support;

use PDO;

/**
 * Helpers pour préparer et nettoyer une base de données de test.
 *
 * Schéma réel EPIClub :
 *   - acquisition         (singulier, table principale)
 *   - acquisition_ligne   (singulier, lignes filles)
 *
 * Aucune autre table ne référence acquisition_id.
 */
final class DatabaseFixture
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Crée une acquisition brouillon (sans facture).
     *
     * @return int ID de l'acquisition
     */
    public function createDraftAcquisition(
        string $reference = 'TEST-DRAFT',
        ?string $factureDocument = null,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO acquisition
                (fournisseur_id, facture_reference, facture_date, facture_document, saisie_par, est_validee)
             VALUES (1, :ref, :date, :doc, 1, 0)"
        );
        $stmt->execute([
            'ref'  => $reference,
            'date' => date('Y-m-d'),
            'doc'  => $factureDocument,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Crée une acquisition validée (avec facture).
     */
    public function createValidatedAcquisition(
        string $reference = 'TEST-VALIDATED',
        string $factureDocument = 'factures/test.pdf',
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO acquisition
                (fournisseur_id, facture_reference, facture_date, facture_document, saisie_par, est_validee)
             VALUES (1, :ref, :date, :doc, 1, 1)"
        );
        $stmt->execute([
            'ref'  => $reference,
            'date' => date('Y-m-d'),
            'doc'  => $factureDocument,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Crée une ligne d'acquisition.
     */
    public function createLigne(
        int $acquisitionId,
        string $reference = 'TEST-LIGNE',
        string $designation = 'Ligne de test',
        int $categorieId = 1,
        int $nombre = 1,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO acquisition_ligne
                (acquisition_id, reference, designation, categorie_id, nombre, equipements_generes, regrouper_en_lot)
             VALUES (:aid, :ref, :des, :cat, :nb, 0, 0)"
        );
        $stmt->execute([
            'aid' => $acquisitionId,
            'ref' => $reference,
            'des' => $designation,
            'cat' => $categorieId,
            'nb'  => $nombre,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Supprime une acquisition et ses dépendances.
     *
     * Ordre : lignes → acquisition
     * (aucune autre table ne référence acquisition_id).
     */
    public function deleteAcquisition(int $id): void
    {
        // Supprimer les lignes filles d'abord
        $this->pdo->prepare("DELETE FROM acquisition_ligne WHERE acquisition_id = ?")->execute([$id]);

        // Puis l'acquisition
        $this->pdo->prepare("DELETE FROM acquisition WHERE id = ?")->execute([$id]);
    }

    /**
     * Supprime toutes les acquisitions de test (préfixe TEST-).
     */
    public function cleanupTestData(): void
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM acquisition WHERE facture_reference LIKE 'TEST-%'"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $this->deleteAcquisition((int) $id);
        }
    }
}