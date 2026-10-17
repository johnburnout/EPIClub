<?php

namespace Epiclub\Engine;

/**
 * [#109] Résolution du chemin du fichier de configuration par défaut.
 *
 * Point de dérivation unique pour les points d'accès historiques à
 * `.env.local.php` (constructeur d'AbstractManager, factories
 * configProvider() d'AbstractManager et d'AbstractController).
 *
 * ─── Seam de test ───
 * Quand la constante EPICLUB_CONFIG_FILE est définie (bootstrap PHPUnit,
 * tests/bootstrap.php), elle prend la priorité : la suite Unit devient
 * indépendante du `.env.local.php` gitignore (68 tests lisaient ce
 * fichier à la construction des Managers / ConfigProvider).
 *
 * En production (constante absente), comportement inchangé : le vrai
 * `.env.local.php` à la racine du projet est utilisé.
 */
final class ConfigFile
{
    /**
     * Chemin du fichier de configuration par défaut.
     */
    public static function defaultPath(): string
    {
        if (defined('EPICLUB_CONFIG_FILE')) {
            return EPICLUB_CONFIG_FILE;
        }

        return __DIR__ . '/../../.env.local.php';
    }
}
