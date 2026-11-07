<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * Droits des fichiers écrits par la restauration et la mise à jour,
 * indépendants de l'umask du processus.
 *
 * [2026-11-07] En ligne de commande (bin/restaurer.php depuis SSH ou la
 * console d'hébergement), l'umask vaut souvent 077 : copy() créait alors
 * les photos en 0600, illisibles par Apache (403 « file permissions deny
 * server access ») tant qu'elles n'étaient pas corrigées à la main. Le
 * serveur web, lui, tourne sous un autre utilisateur que le propriétaire
 * des fichiers (Virtualmin : Apache membre du groupe du domaine).
 *
 * On AJOUTE des droits de lecture (fichiers ≥ 0644, dossiers ≥ 0755) sans
 * en retirer : un exécutable (vendor/bin/…) le reste.
 */
final class DroitsFichiers
{
    public const FICHIER = 0644;
    public const DOSSIER = 0755;

    /** Après copy() : droits de la source, plus la lecture pour tous. */
    public static function fichierCopie(string $source, string $destination): void
    {
        $droits = @fileperms($source);
        $base = $droits === false ? 0 : ($droits & 0777);
        @chmod($destination, $base | self::FICHIER);
    }

    /** Après mkdir() : lecture et traversée pour tous. */
    public static function dossier(string $chemin): void
    {
        $droits = @fileperms($chemin);
        $base = $droits === false ? 0 : ($droits & 0777);
        @chmod($chemin, $base | self::DOSSIER);
    }

    /**
     * mkdir récursif dont chaque dossier CRÉÉ reçoit ≥ 0755 (mkdir() applique
     * l'umask, y compris aux dossiers intermédiaires). Les dossiers déjà
     * présents ne sont pas modifiés.
     */
    public static function creerDossier(string $chemin): bool
    {
        if (is_dir($chemin)) {
            return true;
        }
        $parent = dirname($chemin);
        if ($parent !== $chemin && !self::creerDossier($parent)) {
            return false;
        }
        if (!@mkdir($chemin, self::DOSSIER) && !is_dir($chemin)) {
            return false;
        }
        self::dossier($chemin);
        return true;
    }
}
