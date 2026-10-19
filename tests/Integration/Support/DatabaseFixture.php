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
        // 0. Lignes de contrôle pointant vers les équipements de
        //    l'acquisition — y compris celles rattachées à un contrôle
        //    NON TEST- (ex. équipement de test scanné dans un contrôle
        //    réel ouvert) : fk_controle_ligne_equipement n'a pas de
        //    ON DELETE CASCADE et bloquerait la suppression des
        //    équipements (SQLSTATE 23000).
        $this->pdo->prepare(
            "DELETE cl FROM controle_ligne cl
             JOIN club_equipement ce ON ce.id = cl.equipement_id
             WHERE ce.acquisition_id = ?"
        )->execute([$id]);

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
        // [#113] Nettoyer les contrôles de test et leurs lignes AVANT
        // les acquisitions : fk_controle_ligne_equipement n'a pas de
        // ON DELETE CASCADE — des lignes pointant vers des équipements
        // de test bloqueraient leur suppression (PDOException).
        $this->pdo->exec(
            "DELETE cl FROM controle_ligne cl
             JOIN controle c ON c.id = cl.controle_id
             WHERE c.libelle LIKE 'TEST-%'"
        );
        $this->pdo->exec("DELETE FROM controle WHERE libelle LIKE 'TEST-%'");

        // [IMPORT v2] Les acquisitions groupées par date d'achat portent
        // une référence synthétique IMP-AAAA-MM-JJ (import sans facture) :
        // elles doivent être nettoyées elles aussi, sinon leurs lignes
        // bloquent la suppression des catégories TEST- (FK migration 005).
        $stmt = $this->pdo->query(
            "SELECT id FROM acquisition
             WHERE facture_reference LIKE 'TEST-%'
                OR facture_reference LIKE 'IMP-%'"
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

        // [IMPORT] Fournisseurs créés par les tests d'import Excel/CSV
        // (find-or-create par nom). Après les acquisitions (FK).
        $this->pdo->exec("DELETE FROM fournisseur WHERE nom LIKE 'TEST-%'");
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
    * [#113] Crée une ligne de contrôle de test.
    *
    * Nettoyée par cleanupTestData() via le contrôle TEST- parent
    * (suppression des lignes avant celle du contrôle et des
    * équipements).
    *
    * @param int    $controleId   ID du contrôle (existant en base)
    * @param int    $equipementId ID de l'équipement (existant en base)
    * @param string $statut       a_controler | controle_ok | controle_ko | hors_service
    * @return int ID de la ligne créée
    */
    public function createControleLigne(
        int $controleId,
        int $equipementId,
        string $statut = 'a_controler',
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO controle_ligne
            (controle_id, equipement_id, remarque, date_controle, statut)
            VALUES (:cid, :eid, NULL, NULL, :statut)"
        );
        $stmt->execute([
            'cid'    => $controleId,
            'eid'    => $equipementId,
            'statut' => $statut,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
    * Récupère la ligne de contrôle liant un contrôle et un équipement.
    *
    * @return array<string,mixed>|null La ligne complète, ou null si absente
    */
    public function findControleLigne(int $controleId, int $equipementId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM controle_ligne
             WHERE controle_id = :cid AND equipement_id = :eid"
        );
        $stmt->execute([
            'cid' => $controleId,
            'eid' => $equipementId,
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
    * [REFACTOR #79 - Dette 11] Crée une catégorie de test.
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