<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\AbstractManager;

/**
 * [migration 019] Journal d'activité : connexions, contrôles et prêts ;
 * [2026-11-07] acquisitions, lots et suppressions d'équipements.
 *
 * Consultation et export texte : administrateurs ; réinitialisation :
 * super administrateurs (JournalActiviteController).
 */
class JournalActiviteManager extends AbstractManager
{
    public const TYPE_CONNEXION = 'connexion';
    public const TYPE_CONTROLE = 'controle';
    public const TYPE_PRET = 'pret';
    /** Opérations sur le journal lui-même (réinitialisation). */
    public const TYPE_JOURNAL = 'journal';
    /** [2026-11-07] Étapes d'une acquisition, de la création à la validation. */
    public const TYPE_ACQUISITION = 'acquisition';
    /** [2026-11-07] Lots (dissociation, regroupement, nombre) et suppressions d'équipements. */
    public const TYPE_EQUIPEMENT = 'equipement';
    /** [audit 2026-11-08 R4] Incidents de sécurité (limiteur de tentatives indisponible). */
    public const TYPE_SECURITE = 'securite';

    /** Libellés affichés (clé technique => libellé). */
    public const TYPES = [
        self::TYPE_CONNEXION => 'Connexion',
        self::TYPE_CONTROLE => 'Contrôle',
        self::TYPE_PRET => 'Prêt',
        self::TYPE_ACQUISITION => 'Acquisition',
        self::TYPE_EQUIPEMENT => 'Équipement',
        self::TYPE_SECURITE => 'Sécurité',
        self::TYPE_JOURNAL => 'Journal',
    ];

    /** « acquisition n° 12 (facture F-2026-01) » */
    public static function libelleAcquisition(array $acquisition): string
    {
        $facture = trim((string) ($acquisition['facture_reference'] ?? ''));
        return 'acquisition n° ' . (int) ($acquisition['id'] ?? 0) . ($facture !== '' ? " (facture {$facture})" : '');
    }

    /** « « 26/BAU/3 » (Baudrier Petzl) » */
    public static function libelleEquipement(array $equipement): string
    {
        $libelle = trim((string) ($equipement['libelle'] ?? ''));
        return '« ' . ($equipement['reference'] ?? '?') . ' »' . ($libelle !== '' ? " ({$libelle})" : '');
    }

    private const LONGUEUR_ACTION = 255;
    private const LONGUEUR_UTILISATEUR = 100;

    /**
     * Enregistre une action.
     *
     * @param array<string, mixed>|null $utilisateur Utilisateur de la session (id, prenom, nom, username)
     */
    public function enregistrer(string $type, string $action, ?array $utilisateur = null, ?string $ip = null): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf('Type de journal inconnu : "%s"', $type));
        }

        $stmt = $this->db->prepare(
            'INSERT INTO journal_activite (date_action, type, utilisateur_id, utilisateur, action, ip)
             VALUES (:date_action, :type, :utilisateur_id, :utilisateur, :action, :ip)'
        );
        $stmt->execute([
            'date_action' => date('Y-m-d H:i:s'),
            'type' => $type,
            'utilisateur_id' => isset($utilisateur['id']) ? (int) $utilisateur['id'] : null,
            'utilisateur' => mb_substr(self::nomAffiche($utilisateur), 0, self::LONGUEUR_UTILISATEUR),
            'action' => mb_substr($action, 0, self::LONGUEUR_ACTION),
            'ip' => $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null,
        ]);
    }

    /** « Prénom Nom (identifiant) » ; chaîne vide sans utilisateur. */
    public static function nomAffiche(?array $utilisateur): string
    {
        if ($utilisateur === null) {
            return '';
        }
        $nom = trim(($utilisateur['prenom'] ?? '') . ' ' . ($utilisateur['nom'] ?? ''));
        $identifiant = (string) ($utilisateur['username'] ?? '');
        if ($nom === '') {
            return $identifiant;
        }
        return $identifiant === '' ? $nom : "{$nom} ({$identifiant})";
    }

    /**
     * Nombre d'entrées correspondant aux filtres.
     *
     * @param array{type?: ?string, du?: ?string, au?: ?string, q?: ?string} $filtres
     */
    public function compter(array $filtres): int
    {
        [$where, $params] = self::clauseWhere($filtres);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM journal_activite{$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Entrées correspondant aux filtres, de la plus récente à la plus
     * ancienne. $limit <= 0 : toutes (export).
     *
     * @param array{type?: ?string, du?: ?string, au?: ?string, q?: ?string} $filtres
     * @return list<array<string, mixed>>
     */
    public function rechercher(array $filtres, int $limit = 0, int $offset = 0): array
    {
        [$where, $params] = self::clauseWhere($filtres);
        $sql = "SELECT * FROM journal_activite{$where} ORDER BY date_action DESC, id DESC"
            . $this->buildLimitClause($limit, $offset);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Vide le journal ; retourne le nombre d'entrées supprimées. */
    public function reinitialiser(): int
    {
        return (int) $this->db->exec('DELETE FROM journal_activite');
    }

    /**
     * Clause WHERE (pure, testable) : filtres validés en amont par le
     * contrôleur ; les valeurs inconnues sont ignorées.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function clauseWhere(array $filtres): array
    {
        $conditions = [];
        $params = [];

        $type = $filtres['type'] ?? null;
        if (is_string($type) && isset(self::TYPES[$type])) {
            $conditions[] = 'type = :type';
            $params['type'] = $type;
        }
        if (!empty($filtres['du'])) {
            $conditions[] = 'date_action >= :du';
            $params['du'] = $filtres['du'] . ' 00:00:00';
        }
        if (!empty($filtres['au'])) {
            $conditions[] = 'date_action <= :au';
            $params['au'] = $filtres['au'] . ' 23:59:59';
        }
        if (!empty($filtres['q'])) {
            $conditions[] = '(utilisateur LIKE :q1 OR action LIKE :q2)';
            $motif = '%' . addcslashes((string) $filtres['q'], '%_\\') . '%';
            $params['q1'] = $motif;
            $params['q2'] = $motif;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
