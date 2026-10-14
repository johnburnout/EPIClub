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
    
    /**
    * [REFACTOR #71] Crée une acquisition avec FK contrôlées, pour
    * caractériser AcquisitionManager::findAll().
    *
    * Contrairement à createDraftAcquisition() / createValidatedAcquisition(),
    * fournisseur_id et saisie_par sont explicitement paramétrables.
    *
    * ⚠️ Sur le schéma actuel, `acquisition.fournisseur_id` est NOT NULL :
    *    passer `null` déclenche une PDOException (SQLSTATE 23000 / 1048).
    *    Le paramètre reste `?int` pour rester générique si le schéma évolue.
    *    Idem pour `saisie_par` si la colonne est NOT NULL.
    *
    * ⚠️ Le cas FK orpheline (fournisseur_id → id absent) nécessite
    *    SET FOREIGN_KEY_CHECKS=0 côté appelant.
    *
    * @throws \PDOException si une colonne NOT NULL reçoit null,
    *                       ou si la FK est invalide et checks activés.
    */
    public function createAcquisitionForFindAll(
        string $reference,
        ?int $fournisseurId,
        ?int $saisiePar,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO acquisition
            (fournisseur_id, facture_reference, facture_date, facture_document, saisie_par, est_validee)
            VALUES (:fid, :ref, :date, NULL, :sp, 0)"
        );
        $stmt->execute([
            'fid'  => $fournisseurId,
            'ref'  => $reference,
            'date' => date('Y-m-d'),
            'sp'   => $saisiePar,
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
    * [TEST #75] Crée un équipement de test minimal.
    *
    * @param int    $acquisitionId ID d'acquisition (existant en base)
    * @param int    $categorieId   ID de catégorie (existant en base)
    * @param string $reference     Référence unique (préfixe TEST- pour cleanup)
    * @param string $libelle       Libellé affiché
    * @return int ID de l'équipement créé
    */
    public function createEquipement(
        int $acquisitionId,
        int $categorieId,
        string $reference = 'TEST-EQUIP',
        string $libelle = 'Équipement de test',
    ): int {
        // `code` est UNIQUE — on le dérive pour éviter les collisions.
        $code = 'CODE-' . $reference . '-' . bin2hex(random_bytes(4));
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO club_equipement
            (acquisition_id, categorie_id, reference, libelle, code)
            VALUES (:aid, :cid, :ref, :lib, :code)"
        );
        $stmt->execute([
            'aid'  => $acquisitionId,
            'cid'  => $categorieId,
            'ref'  => $reference,
            'lib'  => $libelle,
            'code' => $code,
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
        
        // [TEST #75] Filet de sécurité : équipements de test créés
        // avec une acquisition non-TEST- (cas rare).
        $stmt = $this->pdo->query(
            "SELECT id FROM club_equipement WHERE reference LIKE 'TEST-%'"
        );
        $equipementIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($equipementIds as $id) {
            $this->pdo->prepare("DELETE FROM controle_ligne WHERE equipement_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM club_equipement WHERE id = ?")->execute([$id]);
        }
        
        // [REFACTOR #79] Nettoyer les catégories de test.
        // ⚠️ Après les acquisitions, pour éviter que des acquisition_ligne
        //    ne pointent vers des catégories supprimées (pas de FK en base).
        $this->pdo->exec("DELETE FROM categorie WHERE libelle LIKE 'TEST-%'");
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
    
    /**
    * [REFACTOR #79 - Dette 11] Crée une catégorie de test.
    *
    * `libelle` est UNIQUE : on y injecte un suffixe aléatoire pour
    * éviter les collisions entre tests. Le préfixe TEST- permet le
    * cleanup via cleanupTestData().
    *
    * @param string $libelle Libellé (préfixe TEST- pour cleanup)
    * @param int    $estEpi  1 = EPI, 0 = non-EPI
    * @return int ID de la catégorie créée
    */
    public function createCategorie(
        string $libelle = 'TEST-CATEGORIE',
        int $estEpi = 1,
    ): int {
        // libelle est UNIQUE — on y injecte un suffixe pour éviter
        // les collisions entre tests parallèles ou successifs.
        $uniqueLibelle = $libelle . '-' . bin2hex(random_bytes(4));
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO categorie (libelle, est_epi)
            VALUES (:lib, :epi)"
        );
        $stmt->execute([
            'lib' => $uniqueLibelle,
            'epi' => $estEpi,
        ]);
        
        return (int) $this->pdo->lastInsertId();
    }
}