<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Support;

use PDO;

/**
 * Helpers pour préparer et nettoyer une base de données de test.
 */
final class DatabaseFixture
{
    public function __construct(private PDO $pdo)
    {
    }

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
     */
    public function deleteAcquisition(int $id): void
    {
        // 1. Équipements générés
        $this->pdo->prepare("DELETE FROM club_equipement WHERE acquisition_id = ?")->execute([$id]);

        // 2. Lignes d'acquisition
        $this->pdo->prepare("DELETE FROM acquisition_ligne WHERE acquisition_id = ?")->execute([$id]);

        // 3. Acquisition
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

        // Nettoyer aussi les équipements orphelins
        $this->pdo->exec(
            "DELETE ce FROM club_equipement ce
             LEFT JOIN acquisition a ON a.id = ce.acquisition_id
             WHERE a.id IS NULL"
        );
    }
    
    /**
    * Crée un contrôle de test.
    *
    * @param int         $controleurId    ID utilisateur (FK utilisateur.id)
    * @param string      $libelle         Libellé, doit commencer par 'TEST-' (cleanup)
    * @param string      $statut          ouvert | en_cours | cloture
    * @param string|null $hashRemarques   Ciphertext base64, ou null
    */
    public function createControle(
        int $controleurId,
        string $libelle = 'TEST-CONTROLE',
        string $statut = 'ouvert',
        ?string $hashRemarques = null,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO controle
            (libelle, date_debut, date_fin, statut, controleur_id, cree_par, hash_remarques)
            VALUES (:lib, :debut, :fin, :statut, :ctr, :ctr, :hash)"
        );
        $stmt->execute([
            'lib'    => $libelle,
            'debut'  => date('Y-m-d H:i:s'),
            'fin'    => $statut === 'cloture' ? date('Y-m-d H:i:s') : null,
            'statut' => $statut,
            'ctr'    => $controleurId,
            'hash'   => $hashRemarques,
        ]);
        
        return (int) $this->pdo->lastInsertId();
    }
}