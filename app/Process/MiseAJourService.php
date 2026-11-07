<?php

declare(strict_types=1);

namespace Epiclub\Process;

use Epiclub\Engine\DatabaseBackup;
use Epiclub\Engine\DroitsFichiers;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\MigrationManager;
use Epiclub\Exception\MiseAJourException;
use ZipArchive;

/**
 * Mise à jour d'une instance vers la dernière release, partagée par la page
 * « Mise à jour » (AppUpdateController) et bin/mettre-a-jour.php (console
 * d'hébergement, docs/virtualmin.md § 5).
 *
 * Le verrou de l'instance (var/epiclub.lock) est pris par l'appelant, avant
 * tout appel à GitHub.
 *
 * Déroulé : release distante → téléchargement → somme de contrôle → contrôle
 * du zip → extraction → archive complète exigée (vendor/) → sauvegarde SQL si
 * migrations en attente → copie des fichiers → migrations → version.txt →
 * remplacement de vendor/. Toute erreur avant la copie laisse l'instance
 * intacte ; après, le code est INTERROMPUE.
 */
final class MiseAJourService
{
    // [audit 2026-10-19 F2] 'public' n'est PLUS exclu : l'exclure
    // empêchait toute mise à jour de index.php, des .htaccess et des
    // assets. La protection contre un zip compromis relève de la
    // vérification d'intégrité de l'archive (audit S3), pas de cette liste.
    public const EXCLUDED_DIRS = ['var', 'vendor', '.git', '_storage', 'config', 'setup'];

    // [audit 2026-10-19 F2] Données des utilisateurs dans le docroot
    // (photos d'équipement, QR codes, images de catégorie) : jamais
    // écrasées ni complétées par une release. Seuls les .htaccess de
    // protection de ce dossier sont mis à jour.
    public const USER_DATA_DIR = 'public/images';
    public const USER_DATA_ALLOWED_FILES = ['.htaccess'];
    public const EXCLUDED_FILES = ['.env', '.env.local', '.env.local.php', 'version.txt'];

    /**
     * @param \Closure(): \PDO               $connexion  Base de l'instance (migrations).
     * @param \Closure(string): (string|null) $sauvegarde Sauvegarde SQL avant migrations
     *                                                   (dossier migrations/ livré → fichier créé ou null).
     */
    public function __construct(
        private readonly GitHubReleaseProviderInterface $releases,
        private readonly string $racine,
        private readonly string $dossierTemp,
        private readonly \Closure $connexion,
        private readonly \Closure $sauvegarde,
    ) {
    }

    public function versionActuelle(): string
    {
        $fichier = $this->racine . '/version.txt';
        return is_file($fichier) ? trim((string) file_get_contents($fichier)) : 'v0.0.0';
    }

    /**
     * Version installée et version disponible, sans rien modifier.
     *
     * @return array{actuelle: string, disponible: string|null, mise_a_jour_disponible: bool, archive_prete: bool}
     */
    public function verifier(): array
    {
        $actuelle = $this->versionActuelle();
        $release = $this->releases->getLatestRelease();
        $plusRecente = $release !== null && version_compare(ltrim($release['tag'], 'v'), ltrim($actuelle, 'v'), '>');

        return [
            'actuelle'               => $actuelle,
            'disponible'             => $release['tag'] ?? null,
            'mise_a_jour_disponible' => $plusRecente,
            'archive_prete'          => $plusRecente && !empty($release['bundle']),
        ];
    }

    /**
     * @param callable(string): void $etape       Une ligne par étape (journal, affichage).
     * @param bool                   $forcer      Réinstaller même si la version distante
     *                                            n'est pas plus récente (page web).
     * @param callable(): void|null  $avantCopie  Juste avant la copie des fichiers (le code
     *                                            en cours d'exécution va être remplacé).
     * @param callable(): void|null  $avantVendor Juste avant le remplacement de vendor/.
     * @return array{ancienne: string, version: string, a_jour: bool, sauvegarde: string|null, migrations: string[]}
     * @throws MiseAJourException
     */
    public function mettreAJour(callable $etape, bool $forcer = false, ?callable $avantCopie = null, ?callable $avantVendor = null): array
    {
        $ancienne = $this->versionActuelle();
        $latest = $this->releases->getLatestRelease();
        if (!$latest) {
            throw new MiseAJourException('Impossible de récupérer la version distante.', MiseAJourException::PREREQUIS);
        }
        $version = $latest['tag'];
        if (!$forcer && !version_compare(ltrim($version, 'v'), ltrim($ancienne, 'v'), '>')) {
            $etape('Déjà à jour : ' . $ancienne . '.');
            return ['ancienne' => $ancienne, 'version' => $ancienne, 'a_jour' => true, 'sauvegarde' => null, 'migrations' => []];
        }
        $etape('Mise à jour de ' . $ancienne . ' vers ' . $version . '.');

        if (!is_dir($this->dossierTemp)) {
            mkdir($this->dossierTemp, 0755, true);
        }
        $extractPath = $this->dossierTemp . '/extracted';

        try {
            $zipContent = $this->releases->downloadUrl($latest['zip_url']);
            if ($zipContent === '') {
                throw new MiseAJourException('Le fichier téléchargé est vide.', MiseAJourException::ERREUR);
            }

            // [audit 2026-10-19 F1] Somme de contrôle de l'archive complète.
            try {
                $this->verifierSommeDeControle($latest, $zipContent);
            } catch (\RuntimeException $e) {
                error_log('[MiseAJour] Archive rejetée : ' . $e->getMessage());
                throw new MiseAJourException('Archive invalide : le fichier téléchargé a été rejeté (somme de contrôle).', MiseAJourException::ARCHIVE_REJETEE, $e);
            }
            $etape('Archive ' . $version . ' téléchargée et vérifiée.');

            $tempZip = $this->dossierTemp . '/release.zip';
            file_put_contents($tempZip, $zipContent);
            unset($zipContent);

            $zip = new ZipArchive();
            if ($zip->open($tempZip) !== true) {
                throw new MiseAJourException('Impossible d\'ouvrir l\'archive téléchargée.', MiseAJourException::ARCHIVE_REJETEE);
            }

            // [SÉCURITÉ — audit C1] Validation des entrées AVANT extraction.
            try {
                self::assertZipEntriesSafe($zip);
            } catch (\RuntimeException $e) {
                $zip->close();
                @unlink($tempZip);
                error_log('[MiseAJour] Archive rejetée : ' . $e->getMessage());
                throw new MiseAJourException('Archive invalide : le fichier téléchargé a été rejeté pour des raisons de sécurité.', MiseAJourException::ARCHIVE_REJETEE, $e);
            }

            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0755, true);
            }
            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                throw new MiseAJourException('Erreur lors de l\'extraction de l\'archive.', MiseAJourException::ERREUR);
            }
            $zip->close();
            unlink($tempZip);

            $sourceDir = null;
            foreach (scandir($extractPath) ?: [] as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
                    $sourceDir = $extractPath . '/' . $item;
                    break;
                }
            }
            if ($sourceDir === null) {
                throw new MiseAJourException('Aucun dossier trouvé après extraction.', MiseAJourException::ARCHIVE_REJETEE);
            }

            // [v1.2.0-beta.2] Sans vendor/ livré, on n'installe RIEN (classmap
            // « authoritative » en place : les nouvelles classes seraient ignorées).
            if (!is_file($sourceDir . '/vendor/autoload.php')) {
                error_log('[MiseAJour] Archive sans vendor/ refusée pour ' . $version);
                throw new MiseAJourException("L'archive complète de la version " . $version . ' n\'est pas encore disponible '
                    . '(GitHub la prépare quelques minutes après la publication). Rien n\'a été modifié : '
                    . 'réessayez dans quelques minutes.', MiseAJourException::ARCHIVE_REJETEE);
            }

            // [v1.4.0] Sauvegarde SQL AVANT toute modification si la release
            // apporte des migrations. Échec → rien n'est copié ni migré.
            try {
                $sauvegarde = ($this->sauvegarde)($sourceDir . '/migrations');
            } catch (\Throwable $e) {
                error_log('[MiseAJour] Mise à jour annulée, sauvegarde impossible : ' . $e->getMessage());
                throw new MiseAJourException('La base de données n\'a pas pu être sauvegardée avant les migrations : '
                    . 'mise à jour annulée, rien n\'a été modifié. Consultez les journaux du serveur '
                    . '(espace disque, droits d\'écriture sur var/backups/).', MiseAJourException::SAUVEGARDE_IMPOSSIBLE, $e);
            }
            if ($sauvegarde !== null) {
                $etape('Base sauvegardée avant les migrations : ' . basename($sauvegarde) . '.');
            }

            if ($avantCopie !== null) {
                $avantCopie();
            }
            self::copyFiles($sourceDir, $this->racine);
            $etape('Fichiers copiés.');

            // Migrations de base de données.
            try {
                $migrations = (new MigrationManager(($this->connexion)(), $this->racine . '/migrations'))
                    ->migrate(static function (string $v) use ($etape): void {
                        $etape('Migration appliquée : ' . $v);
                    });
            } catch (\Throwable $e) {
                error_log(sprintf('[MiseAJour] Migration failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
                throw new MiseAJourException('Les fichiers ont été mis à jour mais les migrations '
                    . 'de base de données ont échoué. Consultez les logs '
                    . 'serveur avant de relancer l\'application.', MiseAJourException::INTERROMPUE, $e);
            }
            $etape($migrations === [] ? 'Base déjà à jour.' : count($migrations) . ' migration(s) appliquée(s).');

            file_put_contents($this->racine . '/version.txt', $version);

            if ($avantVendor !== null) {
                $avantVendor();
            }
            // [audit 2026-10-19 F1] vendor/ de l'archive complète, remplacé d'un bloc.
            try {
                self::remplacerVendor($sourceDir . '/vendor', $this->racine . '/vendor');
            } catch (\Throwable $e) {
                error_log('[MiseAJour] vendor/ non remplacé : ' . $e->getMessage());
                throw new MiseAJourException('Les fichiers et la base sont à jour, mais les dépendances (vendor/) '
                    . "n'ont pas pu être remplacées. L'ancienne version a été conservée : "
                    . 'consultez les logs serveur.', MiseAJourException::INTERROMPUE, $e);
            }
            $etape('Dépendances (vendor/) remplacées. Version actuelle : ' . $version . '.');

            return ['ancienne' => $ancienne, 'version' => $version, 'a_jour' => false, 'sauvegarde' => $sauvegarde, 'migrations' => $migrations];
        } finally {
            self::deleteDirectory($extractPath);
            $this->viderDossierTemp();
        }
    }

    /**
     * [v1.4.0] Sauvegarde SQL si la release apporte des migrations non encore
     * appliquées.
     *
     * @param \Closure(): string[] $appliquees
     * @return string|null Fichier créé, ou null si aucune migration en attente.
     */
    public static function sauvegarderSiMigrationsEnAttente(string $migrationsLivrees, \Closure $appliquees, DatabaseBackup $backup, mixed $retention): ?string
    {
        $livrees = array_map(
            static fn (string $f) => basename($f, '.sql'),
            glob(rtrim($migrationsLivrees, '/') . '/*.sql') ?: []
        );
        if ($livrees === [] || array_diff($livrees, $appliquees()) === []) {
            return null;
        }
        $fichier = $backup->dump();
        $backup->cleanup(DatabaseBackup::retention($retention));
        return $fichier;
    }

    /**
     * [audit 2026-10-19 F1] Vérifie l'archive contre la somme SHA-256 publiée
     * (obligatoire pour une archive complète).
     *
     * @param array<string, mixed> $release
     * @throws \RuntimeException
     */
    public function verifierSommeDeControle(array $release, string $zipContent): void
    {
        $shaUrl = $release['sha256_url'] ?? null;

        if (!is_string($shaUrl) || $shaUrl === '') {
            if (!empty($release['bundle'])) {
                throw new \RuntimeException('somme de contrôle absente pour une archive complète');
            }
            return;
        }

        $publie = $this->releases->downloadUrl($shaUrl);
        if (!preg_match('/\b([a-f0-9]{64})\b/i', $publie, $m)) {
            throw new \RuntimeException('fichier de somme de contrôle illisible');
        }

        if (!hash_equals(strtolower($m[1]), hash('sha256', $zipContent))) {
            throw new \RuntimeException('somme de contrôle différente');
        }
    }

    /**
     * [SÉCURITÉ — audit C1] Rejette « .. », chemins absolus et backslashes.
     *
     * @throws \RuntimeException
     */
    public static function assertZipEntriesSafe(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw new \RuntimeException("Entrée zip #$i illisible");
            }
            if (str_contains($name, '..') || preg_match('#^[/\\\\]#', $name) || str_contains($name, '\\')) {
                throw new \RuntimeException(sprintf('Entrée zip malveillante : %s', $name));
            }
        }
    }

    /**
     * [audit 2026-10-19 F1] Remplace vendor/ d'un bloc ; l'ancien est restauré
     * si la bascule échoue.
     *
     * @throws \RuntimeException
     */
    public static function remplacerVendor(string $nouveau, string $cible): void
    {
        if (!is_file($nouveau . '/autoload.php')) {
            throw new \RuntimeException('vendor/ livré incomplet');
        }

        $ancien = $cible . '.ancien-' . date('YmdHis');
        $avaitUnVendor = is_dir($cible);

        if ($avaitUnVendor && !rename($cible, $ancien)) {
            throw new \RuntimeException('impossible de mettre l\'ancien vendor/ de côté');
        }

        // rename() échoue entre deux systèmes de fichiers : repli sur une copie.
        if (!@rename($nouveau, $cible)) {
            try {
                self::copierDossier($nouveau, $cible);
            } catch (\Throwable $e) {
                self::deleteDirectory($cible);
                if ($avaitUnVendor) {
                    rename($ancien, $cible);
                }
                throw new \RuntimeException('remplacement de vendor/ impossible : ' . $e->getMessage(), 0, $e);
            }
        }

        if ($avaitUnVendor) {
            self::deleteDirectory($ancien);
        }
    }

    /** Copie récursive de la release vers l'installation, en respectant isExcluded(). */
    public static function copyFiles(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $target . '/' . $relativePath;

            if (self::isExcluded($relativePath)) {
                continue;
            }

            // [2026-11-07] Droits indépendants de l'umask (CLI : 077).
            if ($item->isDir()) {
                DroitsFichiers::creerDossier($targetPath);
            } else {
                DroitsFichiers::creerDossier(dirname($targetPath));
                copy($item->getPathname(), $targetPath);
                DroitsFichiers::fichierCopie($item->getPathname(), $targetPath);
            }
        }
    }

    public static function isExcluded(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === self::USER_DATA_DIR || str_starts_with($path, self::USER_DATA_DIR . '/')) {
            return !in_array(basename($path), self::USER_DATA_ALLOWED_FILES, true);
        }

        foreach (self::EXCLUDED_DIRS as $dir) {
            if (str_starts_with($path, $dir . '/') || $path === $dir) {
                return true;
            }
        }
        return in_array(basename($path), self::EXCLUDED_FILES, true);
    }

    public function viderDossierTemp(): void
    {
        // Le cache de la release (latest_release.json) est conservé.
        foreach (['release.zip', 'extracted'] as $nom) {
            $chemin = $this->dossierTemp . '/' . $nom;
            if (is_dir($chemin)) {
                self::deleteDirectory($chemin);
            } elseif (is_file($chemin)) {
                @unlink($chemin);
            }
        }
    }

    private static function copierDossier(string $source, string $cible): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        if (!DroitsFichiers::creerDossier($cible)) {
            throw new \RuntimeException("mkdir $cible");
        }
        foreach ($iterator as $item) {
            $chemin = $cible . '/' . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) {
                if (!DroitsFichiers::creerDossier($chemin)) {
                    throw new \RuntimeException("mkdir $chemin");
                }
            } elseif (!copy($item->getPathname(), $chemin)) {
                throw new \RuntimeException("copy $chemin");
            } else {
                DroitsFichiers::fichierCopie($item->getPathname(), $chemin);
            }
        }
    }

    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
