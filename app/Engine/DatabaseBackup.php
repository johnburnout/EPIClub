<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * Sauvegarde SQL automatique de la base AVANT l'application de migrations
 * (mise à jour intégrée, bin/migrate.php).
 *
 * Fichier : var/backups/db_AAAA-MM-JJ_HHMMSS.sql, produit par
 * MySqlDatabaseDumper (PHP pur : pas de mysqldump, pas de shell, pas de
 * mot de passe exposé). Restauration :
 *   mysql -u UTILISATEUR -p BASE < var/backups/db_….sql
 * (ou depuis la page Sauvegarde / restauration, via une archive).
 *
 * Écriture atomique (.part puis renommage) : un fichier db_*.sql présent
 * est toujours complet. Seuls les N derniers sont conservés.
 */
final class DatabaseBackup
{
    public const RETENTION_DEFAUT = 5;

    /** Espace disque libre minimal exigé avant de lancer le dump. */
    private const ESPACE_MINIMAL = 50 * 1024 * 1024;

    /** Au-delà, un avertissement est journalisé (dump volumineux). */
    private const TAILLE_AVERTISSEMENT = 200 * 1024 * 1024;

    private const MOTIF = '/^db_\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?\.sql$/';

    public function __construct(
        private DatabaseDumperInterface $dumper,
        private string $dossier,
    ) {
        $this->dossier = rtrim($this->dossier, '/');
    }

    /**
     * Rétention lue dans la configuration (BACKUP_RETENTION), au moins 1.
     */
    public static function retention(mixed $valeur): int
    {
        $n = filter_var($valeur, FILTER_VALIDATE_INT);
        return $n === false || $n < 1 ? self::RETENTION_DEFAUT : $n;
    }

    /**
     * @return string Chemin du fichier créé.
     * @throws \RuntimeException si le dump n'a pas pu être écrit entièrement.
     */
    public function dump(): string
    {
        $this->preparerDossier();

        $libre = @disk_free_space($this->dossier);
        if ($libre !== false && $libre < self::ESPACE_MINIMAL) {
            throw new \RuntimeException(sprintf(
                'Espace disque insuffisant pour sauvegarder la base (%d Mo libres).',
                (int) ($libre / 1048576)
            ));
        }

        $base = 'db_' . date('Y-m-d_His');
        $nom = $base . '.sql';
        for ($i = 2; file_exists($this->dossier . '/' . $nom); $i++) {
            $nom = $base . '-' . $i . '.sql';
        }
        $chemin = $this->dossier . '/' . $nom;
        $partiel = $chemin . '.part';

        try {
            $this->dumper->exporter($partiel);
            if (!is_file($partiel) || filesize($partiel) === 0) {
                throw new \RuntimeException('dump vide');
            }
            if (!rename($partiel, $chemin)) {
                throw new \RuntimeException('renommage impossible');
            }
        } catch (\Throwable $e) {
            @unlink($partiel);
            throw new \RuntimeException('Sauvegarde de la base impossible : ' . $e->getMessage(), 0, $e);
        }

        $taille = (int) filesize($chemin);
        if ($taille > self::TAILLE_AVERTISSEMENT) {
            error_log(sprintf('[DatabaseBackup] Dump volumineux : %s (%d Mo).', $nom, (int) ($taille / 1048576)));
        }

        return $chemin;
    }

    /**
     * Ne garde que les $garder sauvegardes db_*.sql les plus récentes.
     */
    public function cleanup(int $garder = self::RETENTION_DEFAUT): void
    {
        $garder = max(1, $garder);
        $fichiers = array_values(array_filter(
            scandir($this->dossier) ?: [],
            static fn (string $f) => (bool) preg_match(self::MOTIF, $f)
        ));
        // Tri chronologique par l'horodatage du nom, puis par le suffixe
        // « -2 », « -3 »… (dumps dans la même seconde), plus récents d'abord.
        $cle = static function (string $f): array {
            preg_match('/^db_(\d{4}-\d{2}-\d{2}_\d{6})(?:-(\d+))?\.sql$/', $f, $m);
            return [$m[1], (int) ($m[2] ?? 1)];
        };
        usort($fichiers, static fn (string $a, string $b) => $cle($b) <=> $cle($a));
        foreach (array_slice($fichiers, $garder) as $ancien) {
            @unlink($this->dossier . '/' . $ancien);
        }
    }

    private function preparerDossier(): void
    {
        if (!is_dir($this->dossier) && !@mkdir($this->dossier, 0750, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException('Dossier de sauvegarde impossible à créer : ' . $this->dossier);
        }
        if (!is_writable($this->dossier)) {
            throw new \RuntimeException('Dossier de sauvegarde non inscriptible : ' . $this->dossier);
        }
        $htaccess = $this->dossier . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Sauvegardes : jamais servies par HTTP.\nRequire all denied\n");
        }
    }
}
