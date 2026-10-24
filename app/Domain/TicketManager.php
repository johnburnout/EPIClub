<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * [2026-10-24] Tickets de demande de modification d'un équipement
 * (migration 018, cf. DemandeModification).
 */
class TicketManager extends AbstractManager
{
    public const STATUT_OUVERT = 'ouvert';
    public const STATUT_FAIT = 'fait';
    public const STATUT_REFUSE = 'refuse';

    /** Colonnes lues avec chaque ticket (équipement, demandeur, traitant). */
    private const SELECT = "SELECT t.*,
            e.reference AS equipement_reference, e.libelle AS equipement_libelle,
            e.acquisition_id AS equipement_acquisition_id, e.deleted_at AS equipement_supprime,
            d.prenom AS demandeur_prenom, d.nom AS demandeur_nom,
            tp.prenom AS traite_par_prenom, tp.nom AS traite_par_nom
        FROM ticket t
        LEFT JOIN club_equipement e ON e.id = t.equipement_id
        LEFT JOIN utilisateur d ON d.id = t.demandeur_id
        LEFT JOIN utilisateur tp ON tp.id = t.traite_par";

    protected function tableName(): string
    {
        return 'ticket';
    }

    /**
     * Crée un ticket et ses modifications (type => remarque).
     */
    public function creer(int $equipementId, int $demandeurId, string $roleRequis, array $modifications, string $maintenant): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ticket (equipement_id, demandeur_id, role_requis, statut, date_creation)
                 VALUES (:equipement, :demandeur, :role, 'ouvert', :date)"
            );
            $stmt->execute([
                'equipement' => $equipementId,
                'demandeur' => $demandeurId,
                'role' => $roleRequis,
                'date' => $maintenant,
            ]);
            $id = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'INSERT INTO ticket_modification (ticket_id, type, remarque) VALUES (:ticket, :type, :remarque)'
            );
            foreach ($modifications as $type => $remarque) {
                $stmt->execute(['ticket' => $id, 'type' => $type, 'remarque' => $remarque]);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Ticket avec ses modifications, ou null. */
    public function findId(int $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }
        return $this->avecModifications([$ticket])[0];
    }

    /**
     * Tickets ouverts traitables par ces rôles, les plus anciens d'abord.
     *
     * @param string[] $roles
     */
    public function findOuvertsPourRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $marqueurs = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare(self::SELECT
            . " WHERE t.statut = 'ouvert' AND t.role_requis IN ($marqueurs) ORDER BY t.date_creation, t.id");
        $stmt->execute(array_values($roles));
        return $this->avecModifications($stmt->fetchAll());
    }

    /** Demandes d'une personne, les plus récentes d'abord. */
    public function findParDemandeur(int $demandeurId, int $limite = 50): array
    {
        $stmt = $this->db->prepare(self::SELECT
            . ' WHERE t.demandeur_id = :demandeur ORDER BY t.date_creation DESC, t.id DESC LIMIT ' . max(1, $limite));
        $stmt->execute(['demandeur' => $demandeurId]);
        return $this->avecModifications($stmt->fetchAll());
    }

    /** Demandes closes que le demandeur n'a pas encore consultées. */
    public function findClosNonVus(int $demandeurId): array
    {
        $stmt = $this->db->prepare(self::SELECT
            . " WHERE t.demandeur_id = :demandeur AND t.statut <> 'ouvert' AND t.vu_par_demandeur = 0
                ORDER BY t.date_traitement DESC, t.id DESC");
        $stmt->execute(['demandeur' => $demandeurId]);
        return $this->avecModifications($stmt->fetchAll());
    }

    /** Le demandeur a consulté son ticket clos : la notification disparaît. */
    public function marquerVu(int $id, int $demandeurId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ticket SET vu_par_demandeur = 1
              WHERE id = :id AND demandeur_id = :demandeur AND statut <> 'ouvert'"
        );
        $stmt->execute(['id' => $id, 'demandeur' => $demandeurId]);
    }

    /**
     * Clôt un ticket encore ouvert (fait ou refusé). false s'il a déjà
     * été traité entre-temps par quelqu'un d'autre.
     */
    public function cloturer(int $id, string $statut, int $traitePar, ?string $reponse, string $maintenant): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE ticket
                SET statut = :statut, traite_par = :traite_par, reponse = :reponse, date_traitement = :date
              WHERE id = :id AND statut = 'ouvert'"
        );
        $stmt->execute([
            'statut' => $statut,
            'traite_par' => $traitePar,
            'reponse' => $reponse,
            'date' => $maintenant,
            'id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Ajoute à chaque ticket la liste de ses modifications (type, remarque). */
    private function avecModifications(array $tickets): array
    {
        if ($tickets === []) {
            return [];
        }
        $ids = array_map(static fn (array $t) => (int) $t['id'], $tickets);
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT ticket_id, type, remarque FROM ticket_modification WHERE ticket_id IN ($marqueurs) ORDER BY id"
        );
        $stmt->execute($ids);
        $parTicket = [];
        foreach ($stmt->fetchAll() as $m) {
            $parTicket[(int) $m['ticket_id']][] = ['type' => $m['type'], 'remarque' => $m['remarque']];
        }
        foreach ($tickets as &$t) {
            $t['modifications'] = $parTicket[(int) $t['id']] ?? [];
        }
        unset($t);
        return $tickets;
    }
}
