<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * Export / import SQL de la base MySQL / MariaDB, en PHP pur (PDO).
 *
 * Pas de dépendance à `mysqldump` : l'outil n'est pas toujours
 * disponible (ni exécutable par PHP) sur les hébergements mutualisés.
 *
 * ─── Format du fichier ───
 *  - 1re ligne : ENTETE (reconnaissance d'un export EPIClub) ;
 *  - UNE instruction SQL par ligne, terminée par « ; » ;
 *  - lignes « -- » = commentaires.
 * Les chaînes sont échappées par PDO::quote() avec les antislashs
 * (sql_mode sans NO_BACKSLASH_ESCAPES, forcé en début de session) : un
 * retour à la ligne dans une valeur devient « \n » et ne coupe donc
 * jamais une instruction. L'import lit le fichier ligne à ligne, sans
 * analyseur SQL et sans tout charger en mémoire.
 *
 * Les octets sont copiés tels quels (connexion utf8mb4 des deux côtés) :
 * une donnée mal encodée en base est restaurée à l'identique, ni pire
 * ni réparée.
 */
final class MySqlDatabaseDumper implements DatabaseDumperInterface
{
    public const ENTETE = '-- EPIClub sauvegarde base v1';

    /** Taille cible d'une instruction INSERT (sous max_allowed_packet). */
    private const TAILLE_LOT = 512 * 1024;

    private const SQL_MODE = "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'";

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function exporter(string $fichierSql): array
    {
        $this->pdo->exec(self::SQL_MODE);

        $f = fopen($fichierSql, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Impossible de créer le fichier SQL : ' . $fichierSql);
        }

        $comptes = [];
        try {
            $this->ecrire($f, self::ENTETE);
            $this->ecrire($f, '-- Créé le ' . date('c'));
            $this->ecrire($f, 'SET NAMES utf8mb4;');
            $this->ecrire($f, self::SQL_MODE . ';');
            $this->ecrire($f, 'SET FOREIGN_KEY_CHECKS = 0;');

            foreach ($this->tables() as $table) {
                $comptes[$table] = $this->exporterTable($f, $table);
            }

            $this->ecrire($f, 'SET FOREIGN_KEY_CHECKS = 1;');
            $this->ecrire($f, '-- Fin');
        } finally {
            fclose($f);
        }

        return $comptes;
    }

    public function importer(string $fichierSql): void
    {
        $f = fopen($fichierSql, 'rb');
        if ($f === false) {
            throw new \RuntimeException('Impossible de lire le fichier SQL : ' . $fichierSql);
        }

        try {
            $premiere = fgets($f);
            if ($premiere === false || rtrim($premiere, "\r\n") !== self::ENTETE) {
                throw new \RuntimeException("Le fichier SQL n'est pas un export EPIClub.");
            }

            $this->pdo->exec(self::SQL_MODE);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->viderBase();

            $numero = 1;
            while (($ligne = fgets($f)) !== false) {
                $numero++;
                $ligne = rtrim($ligne, "\r\n");
                if ($ligne === '' || str_starts_with($ligne, '--')) {
                    continue;
                }
                try {
                    $this->pdo->exec($ligne);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(sprintf(
                        'Échec de l\'instruction ligne %d : %s',
                        $numero,
                        $e->getMessage()
                    ), 0, $e);
                }
            }
        } finally {
            fclose($f);
            try {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
                // connexion perdue : rien à rétablir côté session
            }
        }
    }

    public function migrationsAppliquees(): array
    {
        if (!in_array('schema_migrations', $this->tables(), true)) {
            return [];
        }
        $versions = $this->pdo
            ->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $versions);
    }

    // ------------------------------------------------------------------

    /** @return string[] Tables de base (hors vues), triées. */
    private function tables(): array
    {
        $lignes = $this->pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        $tables = [];
        foreach ($lignes as $l) {
            if (($l[1] ?? '') === 'BASE TABLE') {
                $tables[] = (string) $l[0];
            }
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** Supprime toutes les tables et vues de la base courante. */
    private function viderBase(): void
    {
        $lignes = $this->pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        foreach ($lignes as $l) {
            $nom = $this->identifiant((string) $l[0]);
            if (($l[1] ?? '') === 'VIEW') {
                $this->pdo->exec('DROP VIEW IF EXISTS ' . $nom);
            } else {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $nom);
            }
        }
    }

    /**
     * @param resource $f
     * @return int Nombre de lignes exportées.
     */
    private function exporterTable($f, string $table): int
    {
        $nom = $this->identifiant($table);

        $creation = $this->pdo->query('SHOW CREATE TABLE ' . $nom)->fetch(\PDO::FETCH_NUM);
        $sqlCreation = preg_replace('/\s*\R\s*/', ' ', (string) $creation[1]);

        $this->ecrire($f, '');
        $this->ecrire($f, '-- Table ' . $table);
        $this->ecrire($f, 'DROP TABLE IF EXISTS ' . $nom . ';');
        $this->ecrire($f, $sqlCreation . ';');

        // Colonnes à exporter (les colonnes générées sont recalculées par
        // le serveur et refusent une valeur explicite).
        $colonnes = [];
        $binaires = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM ' . $nom)->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            if (stripos((string) ($c['Extra'] ?? ''), 'GENERATED') !== false) {
                continue;
            }
            $colonnes[] = (string) $c['Field'];
            $type = strtolower((string) $c['Type']);
            $binaires[(string) $c['Field']] = str_contains($type, 'blob') || str_contains($type, 'binary');
        }
        if ($colonnes === []) {
            return 0;
        }

        $listeColonnes = implode(', ', array_map([$this, 'identifiant'], $colonnes));
        $debut = 'INSERT INTO ' . $nom . ' (' . $listeColonnes . ') VALUES ';

        // Lecture non bufferisée : la table n'est pas chargée entière en
        // mémoire (aucune autre requête n'est lancée pendant le parcours).
        $nonBufferise = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        // PHP ≥ 8.4 : Pdo\Mysql::ATTR_USE_BUFFERED_QUERY (l'ancienne
        // constante PDO::MYSQL_ATTR_* est dépréciée depuis PHP 8.5).
        $attribut = class_exists(\Pdo\Mysql::class)
            ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
            : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        if ($nonBufferise) {
            $this->pdo->setAttribute($attribut, false);
        }
        try {
            $stmt = $this->pdo->query('SELECT ' . $listeColonnes . ' FROM ' . $nom);
            $compte = $this->ecrireDonnees($f, $stmt, $debut, $colonnes, $binaires);
        } finally {
            if ($nonBufferise) {
                $this->pdo->setAttribute($attribut, true);
            }
        }

        return $compte;
    }

    /**
     * @param resource            $f
     * @param string[]            $colonnes
     * @param array<string, bool> $binaires
     */
    private function ecrireDonnees($f, \PDOStatement $stmt, string $debut, array $colonnes, array $binaires): int
    {
        $compte = 0;
        $lot = [];
        $tailleLot = 0;
        while (($ligne = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $valeurs = [];
            foreach ($colonnes as $col) {
                $valeurs[] = $this->valeur($ligne[$col], $binaires[$col]);
            }
            $tuple = '(' . implode(', ', $valeurs) . ')';
            $lot[] = $tuple;
            $tailleLot += strlen($tuple) + 2;
            $compte++;

            if ($tailleLot >= self::TAILLE_LOT) {
                $this->ecrire($f, $debut . implode(', ', $lot) . ';');
                $lot = [];
                $tailleLot = 0;
            }
        }
        $stmt->closeCursor();
        if ($lot !== []) {
            $this->ecrire($f, $debut . implode(', ', $lot) . ';');
        }

        return $compte;
    }

    private function valeur(mixed $v, bool $binaire): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        $v = (string) $v;
        if ($binaire) {
            return $v === '' ? "''" : '0x' . bin2hex($v);
        }
        return $this->pdo->quote($v);
    }

    private function identifiant(string $nom): string
    {
        return '`' . str_replace('`', '``', $nom) . '`';
    }

    /** @param resource $f */
    private function ecrire($f, string $ligne): void
    {
        // Garde-fou du format « une instruction par ligne ».
        if (str_contains($ligne, "\n") || str_contains($ligne, "\r")) {
            throw new \RuntimeException('Retour à la ligne inattendu dans une instruction SQL exportée.');
        }
        if (fwrite($f, $ligne . "\n") === false) {
            throw new \RuntimeException("Écriture impossible dans le fichier SQL (disque plein ?).");
        }
    }
}
