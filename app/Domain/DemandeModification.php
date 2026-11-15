<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Engine\Traducteur;
use Epiclub\Engine\Session;

/**
 * [2026-10-24] Demande de modification d'un équipement (tickets).
 *
 * Catalogue des modifications qu'un contrôleur ou un administrateur peut
 * demander, avec le rôle requis pour les faire. Ne sont proposées que
 * celles que le rôle du demandeur ne permet pas (un contrôleur modifie
 * déjà l'emplacement, un administrateur le statut, les dates…). Une
 * demande donne un ticket par rôle requis.
 */
final class DemandeModification
{
    /** Longueur maximale d'une remarque. */
    public const REMARQUE_MAX = 2000;

    /**
     * type => [libellé, rôle requis, condition] — dans l'ordre d'affichage.
     * Condition : 'lot' (lot d'au moins 2 éléments restants) ou null.
     */
    public const TYPES = [
        'statut' => ['Statut', 'ROLE_ADMIN', null],
        'dates' => ['Dates (mise en service, fin d\'utilisation)', 'ROLE_ADMIN', null],
        'nombre' => ['Nombre actuel d\'éléments du lot', 'ROLE_ADMIN', 'lot'],
        'remarques' => ['Remarques, photo ou notice', 'ROLE_ADMIN', null],
        'dissociation' => ['Dissocier le lot', 'ROLE_ADMIN', 'lot'],
        'regroupement' => ['Regrouper en lot (équipements identiques)', 'ROLE_ADMIN', null],
        'libelle' => ['Libellé', 'ROLE_ADMIN', null],
        'reference' => ['Référence', 'ROLE_SUPER_ADMIN', null],
        'categorie' => ['Catégorie', 'ROLE_SUPER_ADMIN', null],
        'lot' => ['Lot / nombre initial', 'ROLE_SUPER_ADMIN', null],
        'acquisition' => ['Référence ou date de l\'acquisition', 'ROLE_SUPER_ADMIN', null],
    ];

    /**
     * [Localisation] Libellés de TYPES dans la langue de l'utilisateur.
     *
     * @return array<string, string> type => libellé
     */
    public static function libelles(): array
    {
        return [
            'statut' => Traducteur::t('Statut'),
            'dates' => Traducteur::t("Dates (mise en service, fin d'utilisation)"),
            'nombre' => Traducteur::t("Nombre actuel d'éléments du lot"),
            'remarques' => Traducteur::t('Remarques, photo ou notice'),
            'dissociation' => Traducteur::t('Dissocier le lot'),
            'regroupement' => Traducteur::t('Regrouper en lot (équipements identiques)'),
            'libelle' => Traducteur::t('Libellé'),
            'reference' => Traducteur::t('Référence'),
            'categorie' => Traducteur::t('Catégorie'),
            'lot' => Traducteur::t('Lot / nombre initial'),
            'acquisition' => Traducteur::t("Référence ou date de l'acquisition"),
        ];
    }

    /** Libellé d'un type (le type lui-même s'il est inconnu). */
    public static function libelle(string $type): string
    {
        return self::libelles()[$type] ?? $type;
    }

    /** Rôle requis pour faire une modification du type donné. */
    public static function roleRequis(string $type): ?string
    {
        return self::TYPES[$type][1] ?? null;
    }

    /**
     * Types que le rôle donné peut demander pour cet équipement :
     * ceux qui requièrent un rôle plus élevé.
     *
     * @return array<string, string> type => libellé
     */
    public static function typesDemandables(?string $role, array $equipement): array
    {
        $niveau = Session::niveau($role);
        $lot = (int) ($equipement['nombre_initial'] ?? 1) > 1 && (int) ($equipement['nombre'] ?? 1) > 1;
        $types = [];
        foreach (self::TYPES as $type => [, $requis, $condition]) {
            if (Session::niveau($requis) > $niveau && ($condition !== 'lot' || $lot)) {
                $types[$type] = self::libelle($type);
            }
        }
        return $types;
    }

    /**
     * Valide la demande et la répartit par rôle requis.
     *
     * @param array<string, string> $demandables Résultat de typesDemandables()
     * @param mixed                 $cochees     Types cochés (POST)
     * @param mixed                 $remarques   type => remarque (POST)
     * @return array<string, array<string, string>>|string
     *         rôle requis => [type => remarque], ou message d'erreur
     */
    public static function repartir(array $demandables, mixed $cochees, mixed $remarques): array|string
    {
        $cochees = is_array($cochees) ? array_values(array_unique(array_map('strval', $cochees))) : [];
        $remarques = is_array($remarques) ? $remarques : [];
        if ($cochees === []) {
            return Traducteur::t('Cochez au moins une modification à demander.');
        }

        $parRole = [];
        foreach (array_keys($demandables) as $type) {
            if (!in_array($type, $cochees, true)) {
                continue;
            }
            $remarque = trim((string) ($remarques[$type] ?? ''));
            if ($remarque === '') {
                return Traducteur::t('« {type} » : la remarque est obligatoire.', ['type' => self::libelle($type)]);
            }
            if (mb_strlen($remarque) > self::REMARQUE_MAX) {
                return Traducteur::t('« {type} » : la remarque ne doit pas dépasser {max} caractères.', ['type' => self::libelle($type), 'max' => self::REMARQUE_MAX]);
            }
            $parRole[(string) self::roleRequis($type)][$type] = $remarque;
        }
        if (count($parRole, COUNT_RECURSIVE) - count($parRole) !== count($cochees)) {
            return Traducteur::t('Modification demandée non autorisée.');
        }
        return $parRole;
    }

    /**
     * Destinataires d'un mail de ticket : personnes du rôle requis ou plus
     * (hors demandeur), jamais prévenues, ou reconnectées depuis le dernier
     * mail de ticket (pas de relance sinon : le tableau de bord suffit).
     *
     * @param list<array> $utilisateurs Lignes avec id, role, email,
     *                                  last_activity, ticket_mail_envoye_le
     * @return list<array>
     */
    public static function destinatairesMail(array $utilisateurs, string $roleRequis, int $demandeurId): array
    {
        $niveau = Session::niveau($roleRequis);
        return array_values(array_filter($utilisateurs, static function (array $u) use ($niveau, $demandeurId): bool {
            if ((int) $u['id'] === $demandeurId || Session::niveau($u['role'] ?? null) < $niveau || empty($u['email'])) {
                return false;
            }
            $dernierMail = $u['ticket_mail_envoye_le'] ?? null;
            return $dernierMail === null
                || (!empty($u['last_activity']) && (string) $u['last_activity'] > (string) $dernierMail);
        }));
    }
}
