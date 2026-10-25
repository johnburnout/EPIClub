<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * L'installeur web (setup/) doit-il être servi ? Décision du bootstrap.
 *
 *  - SETUP_COMPLETE posé : installation terminée ;
 *  - base non configurée, injoignable ou sans compte : installeur ;
 *  - au moins un compte, sans SETUP_COMPLETE : installation antérieure
 *    au drapeau (repli historique) → terminée, SAUF si l'assistant est
 *    en cours (SETUP_EN_COURS, posé à l'étape 1) ET que la requête vient
 *    de la session qui a créé le super-administrateur. Sans cette
 *    exception, les étapes SMTP, Club et Catégories étaient sautées dès
 *    la création du compte.
 *
 * Tout autre visiteur voit l'instance comme installée : il ne peut ni
 * reconfigurer le SMTP ni changer de base pendant que l'installateur
 * termine l'assistant.
 */
final class EtatInstallation
{
    /** Clé de session posée par l'étape « admin » de l'assistant. */
    public const SESSION_ADMIN_CREE = 'setup_admin_cree';

    /**
     * @param array<string, mixed>   $config               Contenu de .env.local.php.
     * @param callable(): int        $compterUtilisateurs  Nombre de comptes ; peut lever une exception.
     * @param callable(): bool       $sessionInstallateur  Requête de la session qui a créé le compte ?
     */
    public static function installeurRequis(array $config, callable $compterUtilisateurs, callable $sessionInstallateur): bool
    {
        if (!empty($config['SETUP_COMPLETE'])) {
            return false;
        }
        if (empty($config['DB_HOST']) || empty($config['DB_NAME'])) {
            return true;
        }

        try {
            $comptes = $compterUtilisateurs();
        } catch (\Throwable $e) {
            // Table utilisateur absente ou base injoignable → installeur.
            error_log('[bootstrap] SETUP_COMPLETE fallback failed: ' . $e->getMessage());
            return true;
        }

        if ($comptes === 0) {
            return true;
        }

        return !empty($config['SETUP_EN_COURS']) && $sessionInstallateur();
    }
}
