<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Domain\DemandeModification;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\TicketManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\MailerService;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [2026-10-24] Demandes de modification d'un équipement (tickets,
 * migration 018).
 *
 *  - Un contrôleur ou un administrateur demande, depuis la fiche d'un
 *    équipement, les modifications que son rôle ne permet pas, avec une
 *    remarque chacune : un ticket par rôle requis (DemandeModification).
 *  - Les personnes capables de les faire (rôle requis ou plus) sont
 *    prévenues par mail (sans relance tant qu'elles ne se sont pas
 *    reconnectées) et par leur tableau de bord.
 *  - L'une d'elles fait la modification ou la refuse (motif obligatoire) :
 *    le ticket est clos, disparaît des tableaux de bord et le demandeur
 *    en est notifié sur le sien.
 */
class TicketController extends AbstractController
{
    /** Longueur maximale de la réponse au demandeur. */
    public const REPONSE_MAX = 2000;

    /** Rôles qui traitent des tickets. */
    private const ROLES_TRAITANTS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    // ==================================================================
    // Factories — mockables en test Unit
    // ==================================================================

    protected function ticketManager(): TicketManager
    {
        return new TicketManager();
    }

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return new UtilisateurManager();
    }

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }

    protected function mailerService(): MailerServiceInterface
    {
        return new MailerService($this->configProvider());
    }

    /** Date et heure courantes — surchargeable en test. */
    protected function maintenant(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Rôles dont l'utilisateur connecté peut traiter les tickets. */
    private function rolesTraitables(): array
    {
        return array_values(array_filter(self::ROLES_TRAITANTS, fn (string $r) => $this->isGranted($r)));
    }

    private function utilisateurId(): int
    {
        return (int) ($this->session->get('user')['id'] ?? 0);
    }

    // ==================================================================
    // Demande (fiche équipement)
    // ==================================================================

    /**
     * GET/POST /equipements/demande_modification-{id}
     */
    public function demander(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $id = $this->getValidId($request);
        $equipement = $id !== null ? $this->equipementManager()->findId($id) : null;
        if (!$equipement) {
            $this->session->getFlashBag()->add('error', 'Équipement non trouvé.');
            return $this->redirectTo('/equipements');
        }

        $user = $this->session->get('user');
        $demandables = DemandeModification::typesDemandables($user['role'] ?? null, $equipement);
        $erreur = null;
        $cochees = [];
        $remarques = [];

        if ($request->getMethod() === 'POST') {
            $this->validateCsrf($request);
            $cochees = $request->request->all('types');
            $remarques = $request->request->all('remarques');
            $parRole = DemandeModification::repartir($demandables, $cochees, $remarques);
            if (is_string($parRole)) {
                $erreur = $parRole;
            } else {
                $tickets = [];
                foreach ($parRole as $role => $modifications) {
                    $tickets[$role] = $this->ticketManager()->creer(
                        (int) $equipement['id'], $this->utilisateurId(), $role, $modifications, $this->maintenant()
                    );
                }
                error_log(sprintf(
                    '[TicketController] Demande sur l\'équipement id=%d : ticket(s) %s (utilisateur id=%d)',
                    $equipement['id'], implode(',', $tickets), $this->utilisateurId()
                ));
                $this->notifier($equipement, $tickets);
                $this->session->getFlashBag()->add('success', count($tickets) > 1
                    ? sprintf('Demande envoyée : %d tickets ouverts (administrateurs et super-administrateurs).', count($tickets))
                    : 'Demande envoyée : un ticket est ouvert. Vous serez notifié sur votre tableau de bord.');
                return $this->redirectTo('/equipements/equipement-' . $equipement['id']);
            }
        }

        return $this->render('ticket_demande.twig', [
            'equipement' => $equipement,
            'demandables' => $demandables,
            'roles_requis' => array_map(
                static fn (array $t) => $t[1],
                array_intersect_key(DemandeModification::TYPES, $demandables)
            ),
            'cochees' => array_map('strval', $cochees),
            'remarques' => $remarques,
            'erreur' => $erreur,
            'remarque_max' => DemandeModification::REMARQUE_MAX,
        ]);
    }

    /**
     * Prévient par mail les personnes capables de traiter les tickets
     * créés (un mail par personne), sans relance tant qu'elles ne se
     * sont pas reconnectées. Au mieux : un échec n'empêche pas la demande.
     *
     * @param array<string, int> $tickets rôle requis => id du ticket
     */
    private function notifier(array $equipement, array $tickets): void
    {
        try {
            $club = $this->clubManager()->findParameters() ?: [];
            if (empty($club['email'])) {
                error_log('[TicketController] Email du club non configuré : aucun mail de ticket envoyé.');
                return;
            }
            $utilisateurs = $this->utilisateurManager()->findDestinatairesTicket();
            $parPersonne = [];
            foreach ($tickets as $role => $ticketId) {
                foreach (DemandeModification::destinatairesMail($utilisateurs, $role, $this->utilisateurId()) as $u) {
                    $parPersonne[(int) $u['id']]['personne'] = $u;
                    $parPersonne[(int) $u['id']]['tickets'][] = $ticketId;
                }
            }
            if ($parPersonne === []) {
                return;
            }
            $base = $this->getBaseUrl();
            $demandeur = $this->session->get('user');
        } catch (\Throwable $e) {
            error_log('[TicketController] Notification par mail impossible : ' . $e->getMessage());
            return;
        }

        foreach ($parPersonne as $id => ['personne' => $personne, 'tickets' => $ids]) {
            try {
                $email = $this->createEmail(
                    $club['email'],
                    $personne['email'],
                    sprintf('Demande de modification : %s', $equipement['reference']),
                    'email/ticket_nouveau.twig',
                    [
                        'club' => $club,
                        'personne' => $personne,
                        'demandeur' => $demandeur,
                        'equipement' => $equipement,
                        'liens' => array_map(static fn (int $t) => $base . '/tickets/ticket-' . $t, $ids),
                        'lien_tableau_de_bord' => $base . '/tableau_de_bord',
                    ]
                );
                $this->mailerService()->sendEmail($email);
                $this->utilisateurManager()->marquerMailTicket($id, $this->maintenant());
            } catch (\Throwable $e) {
                error_log(sprintf('[TicketController] Mail de ticket non envoyé (utilisateur id=%d) : %s', $id, $e->getMessage()));
            }
        }
    }

    // ==================================================================
    // Liste, consultation, traitement
    // ==================================================================

    /**
     * GET /tickets — tickets à traiter (administrateurs) et demandes
     * de l'utilisateur.
     */
    public function liste(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $manager = $this->ticketManager();
        return $this->render('ticket_list.twig', [
            'a_traiter' => $manager->findOuvertsPourRoles($this->rolesTraitables()),
            'mes_demandes' => $manager->findParDemandeur($this->utilisateurId()),
            'libelles' => self::libelles(),
        ]);
    }

    /**
     * GET /tickets/ticket-{id} — accessible au demandeur et aux personnes
     * capables de le traiter. Consulté par le demandeur une fois clos, il
     * quitte son tableau de bord.
     */
    public function voir(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $id = $this->getValidId($request);
        $ticket = $id !== null ? $this->ticketManager()->findId($id) : null;
        if (!$ticket) {
            $this->session->getFlashBag()->add('error', 'Ticket non trouvé.');
            return $this->redirectTo('/tickets');
        }

        $estDemandeur = (int) $ticket['demandeur_id'] === $this->utilisateurId();
        $capable = $this->isGranted((string) $ticket['role_requis']);
        if (!$estDemandeur && !$capable) {
            throw new AccessDeniedException('Accès refusé.');
        }
        if ($estDemandeur && $ticket['statut'] !== TicketManager::STATUT_OUVERT && empty($ticket['vu_par_demandeur'])) {
            $this->ticketManager()->marquerVu((int) $ticket['id'], $this->utilisateurId());
        }

        return $this->render('ticket_show.twig', [
            'ticket' => $ticket,
            'libelles' => self::libelles(),
            'peut_traiter' => $capable && $ticket['statut'] === TicketManager::STATUT_OUVERT,
            'reponse_max' => self::REPONSE_MAX,
        ]);
    }

    /**
     * POST /tickets/ticket_traiter-{id} — decision = fait | refuse.
     */
    public function traiter(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        $this->validateCsrf($request);

        $id = $this->getValidId($request);
        $ticket = $id !== null ? $this->ticketManager()->findId($id) : null;
        if (!$ticket) {
            $this->session->getFlashBag()->add('error', 'Ticket non trouvé.');
            return $this->redirectTo('/tickets');
        }
        $this->deniAccessUnlessGranted((string) $ticket['role_requis']);

        $flash = $this->session->getFlashBag();
        $retour = '/tickets/ticket-' . $id;
        $decision = (string) $request->request->get('decision', '');
        $reponse = trim((string) $request->request->get('reponse', ''));
        if (!in_array($decision, [TicketManager::STATUT_FAIT, TicketManager::STATUT_REFUSE], true)) {
            $flash->add('error', 'Décision invalide.');
            return $this->redirectTo($retour);
        }
        if ($decision === TicketManager::STATUT_REFUSE && $reponse === '') {
            $flash->add('error', 'Indiquez le motif du refus.');
            return $this->redirectTo($retour);
        }
        if (mb_strlen($reponse) > self::REPONSE_MAX) {
            $flash->add('error', sprintf('La réponse ne doit pas dépasser %d caractères.', self::REPONSE_MAX));
            return $this->redirectTo($retour);
        }

        if (!$this->ticketManager()->cloturer($id, $decision, $this->utilisateurId(), $reponse !== '' ? $reponse : null, $this->maintenant())) {
            $flash->add('note', 'Ce ticket a déjà été traité.');
            return $this->redirectTo($retour);
        }

        error_log(sprintf(
            '[TicketController] Ticket id=%d %s (utilisateur id=%d)',
            $id, $decision === TicketManager::STATUT_FAIT ? 'fait' : 'refusé', $this->utilisateurId()
        ));
        $flash->add('success', $decision === TicketManager::STATUT_FAIT
            ? 'Ticket clos : modification faite. Le demandeur en est notifié.'
            : 'Ticket clos : demande refusée. Le demandeur en est notifié.');
        return $this->redirectTo('/tickets');
    }

    /** type => libellé, pour les vues. */
    public static function libelles(): array
    {
        return array_map(static fn (array $t) => $t[0], DemandeModification::TYPES);
    }
}
