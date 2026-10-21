<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * Export / import complet de la base dans un fichier SQL.
 *
 * Abstraction introduite pour que SauvegardeService soit testable sans
 * serveur MySQL (suite Unit) ; l'implémentation réelle est
 * MySqlDatabaseDumper (couverte par la suite Integration).
 */
interface DatabaseDumperInterface
{
    /**
     * Écrit la structure et les données de toutes les tables dans $fichierSql.
     *
     * @return array<string, int> Nombre de lignes exportées, par table.
     */
    public function exporter(string $fichierSql): array;

    /**
     * Remplace TOUTE la base par le contenu de $fichierSql : les tables
     * existantes sont supprimées, puis le script est exécuté.
     *
     * @throws \RuntimeException si le fichier n'est pas un export EPIClub
     *                           ou si une instruction échoue.
     */
    public function importer(string $fichierSql): void;

    /**
     * @return string[] Migrations appliquées (table schema_migrations), triées.
     *                  Tableau vide si la table n'existe pas.
     */
    public function migrationsAppliquees(): array;
}
