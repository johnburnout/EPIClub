<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\SauvegardeException;
use ZipArchive;

/**
 * Sauvegarde et restauration complètes d'une instance EPIClub :
 * base de données + fichiers des utilisateurs (photos d'équipement,
 * images du club et des catégories, QR codes, factures).
 *
 * ─── Contenu d'une archive (.zip) ───
 *   manifest.json          description et sommes de contrôle (SHA-256)
 *   base/epiclub.sql       export complet de la base (MySqlDatabaseDumper)
 *   fichiers/<dossier>/…   fichiers des utilisateurs, par dossier logique
 *                          (« images » = public/images, « uploads » =
 *                          _storage/uploads)
 *
 * La configuration (.env.local.php : accès base, SMTP, SECRET_KEY) n'est
 * JAMAIS incluse : l'archive peut circuler sans exposer les secrets. Le
 * manifeste porte seulement une empreinte de SECRET_KEY, pour refuser une
 * restauration qui rendrait les données chiffrées illisibles.
 *
 * ─── Garde-fous de la restauration ───
 *  1. analyse complète AVANT de toucher à quoi que ce soit : structure
 *     de l'archive (pas de chemin « .. », extensions en liste blanche),
 *     sommes de contrôle, version de l'application, migrations connues,
 *     empreinte de SECRET_KEY ;
 *  2. sauvegarde automatique de l'état courant (« filet ») ;
 *  3. en cas d'échec pendant l'application, retour automatique au filet ;
 *  4. une seule opération à la fois (verrou fichier).
 */
final class SauvegardeService
{
    public const FORMAT = 1;
    public const APPLICATION = 'EPIClub';
    public const MANIFESTE = 'manifest.json';
    public const FICHIER_SQL = 'base/epiclub.sql';
    public const PREFIXE_FICHIERS = 'fichiers/';

    /** Seuls types de fichiers téléversables dans EPIClub (images, factures). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    public const TYPE_MANUELLE = 'manuelle';
    public const TYPE_AVANT_RESTAURATION = 'avant-restauration';
    public const TYPE_TELEVERSEE = 'televersee';

    /** Nombre de sauvegardes « avant restauration » conservées sur le serveur. */
    public const CONSERVER_AVANT_RESTAURATION = 3;

    /** Noms d'archives acceptés (le dossier n'est jamais parcouru au-delà). */
    private const MOTIF_NOM = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.zip$/';

    /** @var resource|null */
    private $verrou = null;

    /**
     * @param array<string, string> $dossiers             Dossier logique => chemin absolu.
     * @param string[]              $migrationsDisponibles Migrations présentes sur le disque.
     */
    public function __construct(
        private DatabaseDumperInterface $dumper,
        private array $dossiers,
        private string $dossierSauvegardes,
        private string $version,
        private string $empreinteCle,
        private array $migrationsDisponibles,
    ) {
        foreach (array_keys($this->dossiers) as $prefixe) {
            if (!preg_match('/^[a-z]+$/', $prefixe)) {
                throw new \InvalidArgumentException('Nom de dossier logique invalide : ' . $prefixe);
            }
        }
        $this->dossierSauvegardes = rtrim($this->dossierSauvegardes, '/');
    }

    /**
     * Empreinte non réversible de SECRET_KEY, enregistrée dans le manifeste.
     */
    public static function empreinte(string $secretKey): string
    {
        return substr(hash('sha256', 'epiclub-sauvegarde|' . $secretKey), 0, 16);
    }

    public function dossierSauvegardes(): string
    {
        return $this->dossierSauvegardes;
    }

    // ==================================================================
    // CRÉATION
    // ==================================================================

    /**
     * Crée une archive complète dans le dossier des sauvegardes.
     *
     * @return string Chemin absolu de l'archive créée.
     */
    public function creer(string $type = self::TYPE_MANUELLE): string
    {
        $this->verrouiller();
        try {
            return $this->creerSansVerrou($type);
        } finally {
            $this->deverrouiller();
        }
    }

    private function creerSansVerrou(string $type, bool $rotation = true): string
    {
        $this->preparerDossier();

        $suffixe = $type === self::TYPE_AVANT_RESTAURATION ? '-avant-restauration' : '';
        $base = 'epiclub-sauvegarde-' . date('Ymd-His') . $suffixe;
        $nom = $base . '.zip';
        for ($i = 2; file_exists($this->dossierSauvegardes . '/' . $nom); $i++) {
            $nom = $base . '-' . $i . '.zip';
        }
        $chemin = $this->dossierSauvegardes . '/' . $nom;
        $partiel = $chemin . '.part';

        $tmp = $this->dossierTemporaire();
        try {
            $sql = $tmp . '/epiclub.sql';
            $tables = $this->dumper->exporter($sql);

            $fichiers = [];
            $sommes = [];
            foreach ($this->dossiers as $prefixe => $dossier) {
                foreach ($this->fichiersUtilisateur($dossier) as $relatif => $absolu) {
                    $entree = self::PREFIXE_FICHIERS . $prefixe . '/' . $relatif;
                    $fichiers[$entree] = $absolu;
                    $sommes[$entree] = hash_file('sha256', $absolu);
                }
            }
            ksort($sommes, SORT_STRING);

            $manifeste = [
                'format'        => self::FORMAT,
                'application'   => self::APPLICATION,
                'version'       => $this->version,
                'cree_le'       => date('c'),
                'type'          => $type,
                'migrations'    => $this->dumper->migrationsAppliquees(),
                'tables'        => $tables,
                'empreinte_cle' => $this->empreinteCle,
                'base'          => [
                    'fichier' => self::FICHIER_SQL,
                    'taille'  => filesize($sql),
                    'sha256'  => hash_file('sha256', $sql),
                ],
                'fichiers'      => $sommes,
            ];

            $zip = new ZipArchive();
            if ($zip->open($partiel, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Impossible de créer l\'archive ' . $partiel);
            }
            $zip->addFromString(
                self::MANIFESTE,
                json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $zip->addFile($sql, self::FICHIER_SQL);
            foreach ($fichiers as $entree => $absolu) {
                $zip->addFile($absolu, $entree);
                // Images et PDF sont déjà compressés : on les stocke tels quels.
                $zip->setCompressionName($entree, ZipArchive::CM_STORE);
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Écriture de l\'archive impossible (disque plein ?).');
            }

            if (!rename($partiel, $chemin)) {
                throw new \RuntimeException('Impossible de finaliser l\'archive ' . $chemin);
            }
        } catch (\Throwable $e) {
            @unlink($partiel);
            error_log('[SauvegardeService] creer : ' . $e->getMessage());
            throw $e instanceof SauvegardeException
                ? $e
                : new SauvegardeException('La sauvegarde n\'a pas pu être créée. Consultez les journaux du serveur.', 0, $e);
        } finally {
            $this->supprimerArborescence($tmp);
        }

        if ($rotation && $type === self::TYPE_AVANT_RESTAURATION) {
            $this->rotation();
        }

        return $chemin;
    }

    // ==================================================================
    // LISTE / ACCÈS AUX ARCHIVES DU SERVEUR
    // ==================================================================

    /**
     * @return list<array{nom: string, taille: int, date: int, type: string}>
     *         Archives présentes dans le dossier des sauvegardes, plus récentes d'abord.
     */
    public function lister(): array
    {
        if (!is_dir($this->dossierSauvegardes)) {
            return [];
        }
        $liste = [];
        foreach (scandir($this->dossierSauvegardes) ?: [] as $nom) {
            if (!preg_match(self::MOTIF_NOM, $nom)) {
                continue;
            }
            $chemin = $this->dossierSauvegardes . '/' . $nom;
            if (!is_file($chemin) || is_link($chemin)) {
                continue;
            }
            $liste[] = [
                'nom'    => $nom,
                'taille' => (int) filesize($chemin),
                'date'   => (int) filemtime($chemin),
                'type'   => $this->typeDepuisNom($nom),
            ];
        }
        usort($liste, static fn ($a, $b) => [$b['date'], $b['nom']] <=> [$a['date'], $a['nom']]);
        return $liste;
    }

    /**
     * Chemin absolu d'une archive du dossier des sauvegardes, à partir de
     * son seul nom (jamais d'un chemin fourni par le client).
     *
     * @throws SauvegardeException si le nom est invalide ou l'archive absente.
     */
    public function chemin(string $nom): string
    {
        if (!preg_match(self::MOTIF_NOM, $nom)) {
            throw new SauvegardeException('Nom de sauvegarde invalide.');
        }
        $chemin = $this->dossierSauvegardes . '/' . $nom;
        if (!is_file($chemin) || is_link($chemin)) {
            throw new SauvegardeException('Sauvegarde introuvable : ' . $nom);
        }
        return $chemin;
    }

    public function supprimer(string $nom): void
    {
        $chemin = $this->chemin($nom);
        if (!@unlink($chemin)) {
            throw new SauvegardeException('Impossible de supprimer la sauvegarde ' . $nom . '.');
        }
    }

    /**
     * Nom à donner à une archive téléversée avant son analyse.
     */
    public function nomTeleversement(): string
    {
        return 'televersee-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    }

    /**
     * Prépare le dossier des sauvegardes (création + protection HTTP).
     */
    public function preparerDossier(): void
    {
        if (!is_dir($this->dossierSauvegardes) && !@mkdir($this->dossierSauvegardes, 0750, true)) {
            throw new SauvegardeException('Le dossier des sauvegardes ne peut pas être créé (droits d\'écriture sur var/ ?).');
        }
        $htaccess = $this->dossierSauvegardes . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Archives de sauvegarde : jamais servies par HTTP.\nRequire all denied\n");
        }
    }

    /**
     * Supprime les archives téléversées non restaurées plus anciennes que $ageMax secondes.
     */
    public function purgerTeleversements(int $ageMax = 86400): void
    {
        foreach ($this->lister() as $archive) {
            if ($archive['type'] === self::TYPE_TELEVERSEE && $archive['date'] < time() - $ageMax) {
                @unlink($this->dossierSauvegardes . '/' . $archive['nom']);
            }
        }
    }

    // ==================================================================
    // ANALYSE
    // ==================================================================

    /**
     * Vérifie entièrement une archive SANS rien modifier.
     *
     * @return array<string, mixed> Le manifeste, complété de 'nb_fichiers'.
     * @throws SauvegardeException  au premier défaut, avec un message lisible.
     */
    public function analyser(string $chemin): array
    {
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new SauvegardeException('Le fichier n\'est pas une archive ZIP valide.');
        }

        try {
            $entrees = $this->entreesValides($zip);

            if (!isset($entrees[self::MANIFESTE])) {
                throw new SauvegardeException('Cette archive n\'est pas une sauvegarde EPIClub (manifeste absent).');
            }
            $manifeste = json_decode((string) $zip->getFromName(self::MANIFESTE), true);
            if (!is_array($manifeste)
                || ($manifeste['application'] ?? null) !== self::APPLICATION
                || !isset($manifeste['format'], $manifeste['version'], $manifeste['base']['sha256'])
            ) {
                throw new SauvegardeException('Cette archive n\'est pas une sauvegarde EPIClub (manifeste illisible).');
            }
            if ((int) $manifeste['format'] !== self::FORMAT) {
                throw new SauvegardeException('Format de sauvegarde non pris en charge par cette version d\'EPIClub.');
            }

            $this->verifierCompatibilite($manifeste);
            $this->verifierContenu($zip, $entrees, $manifeste);

            $manifeste['nb_fichiers'] = count((array) ($manifeste['fichiers'] ?? []));
            return $manifeste;
        } finally {
            $zip->close();
        }
    }

    /**
     * Contrôle la liste des entrées (anti « zip slip » et liste blanche).
     *
     * @return array<string, true> Entrées fichiers (hors dossiers).
     */
    private function entreesValides(ZipArchive $zip): array
    {
        $entrees = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = (string) $zip->getNameIndex($i);
            if (str_ends_with($nom, '/')) {
                $this->assertCheminSur(rtrim($nom, '/'));
                continue;
            }
            $this->assertCheminSur($nom);

            if ($nom !== self::MANIFESTE && $nom !== self::FICHIER_SQL && !$this->estFichierUtilisateurValide($nom)) {
                throw new SauvegardeException('L\'archive contient un fichier non autorisé : ' . $nom);
            }
            $entrees[$nom] = true;
        }
        return $entrees;
    }

    private function assertCheminSur(string $nom): void
    {
        if ($nom === ''
            || str_contains($nom, '\\')
            || str_contains($nom, "\0")
            || str_starts_with($nom, '/')
            || preg_match('/^[A-Za-z]:/', $nom)
        ) {
            throw new SauvegardeException('L\'archive contient un chemin invalide.');
        }
        foreach (explode('/', $nom) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new SauvegardeException('L\'archive contient un chemin invalide.');
            }
        }
    }

    private function estFichierUtilisateurValide(string $nom): bool
    {
        if (!str_starts_with($nom, self::PREFIXE_FICHIERS)) {
            return false;
        }
        $segments = explode('/', substr($nom, strlen(self::PREFIXE_FICHIERS)));
        if (count($segments) < 2 || !array_key_exists($segments[0], $this->dossiers)) {
            return false;
        }
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }
        return $this->extensionAutorisee($nom);
    }

    /** @param array<string, mixed> $manifeste */
    private function verifierCompatibilite(array $manifeste): void
    {
        $versionArchive = ltrim((string) $manifeste['version'], 'v');
        $versionInstallee = ltrim($this->version, 'v');
        if (version_compare($versionArchive, $versionInstallee, '>')) {
            throw new SauvegardeException(sprintf(
                'Cette sauvegarde provient d\'une version plus récente d\'EPIClub (%s) que celle installée (%s). Mettez d\'abord EPIClub à jour.',
                $versionArchive,
                $versionInstallee
            ));
        }

        $inconnues = array_diff((array) ($manifeste['migrations'] ?? []), $this->migrationsDisponibles);
        if ($inconnues !== []) {
            throw new SauvegardeException(
                'Cette sauvegarde contient des évolutions de la base inconnues de cette version ('
                . implode(', ', $inconnues) . '). Mettez d\'abord EPIClub à jour.'
            );
        }

        if (!hash_equals($this->empreinteCle, (string) ($manifeste['empreinte_cle'] ?? ''))) {
            throw new SauvegardeException(
                'La clé de chiffrement (SECRET_KEY) de cette installation diffère de celle de l\'installation '
                . 'd\'origine : les données chiffrées deviendraient illisibles. Reprenez la valeur SECRET_KEY '
                . 'de l\'installation d\'origine dans .env.local.php, puis recommencez.'
            );
        }
    }

    /**
     * Chaque fichier attendu est présent avec la bonne somme, et rien d'autre.
     *
     * @param array<string, true>  $entrees
     * @param array<string, mixed> $manifeste
     */
    private function verifierContenu(ZipArchive $zip, array $entrees, array $manifeste): void
    {
        $attendus = [self::FICHIER_SQL => (string) $manifeste['base']['sha256']];
        foreach ((array) ($manifeste['fichiers'] ?? []) as $entree => $somme) {
            $entree = (string) $entree;
            if (!$this->estFichierUtilisateurValide($entree)) {
                throw new SauvegardeException('Le manifeste référence un fichier non autorisé : ' . $entree);
            }
            $attendus[$entree] = (string) $somme;
        }

        $presents = $entrees;
        unset($presents[self::MANIFESTE]);

        $manquants = array_diff_key($attendus, $presents);
        if ($manquants !== []) {
            throw new SauvegardeException('Archive incomplète : ' . count($manquants) . ' fichier(s) manquant(s) (ex. ' . array_key_first($manquants) . ').');
        }
        $enTrop = array_diff_key($presents, $attendus);
        if ($enTrop !== []) {
            throw new SauvegardeException('L\'archive contient des fichiers non déclarés (ex. ' . array_key_first($enTrop) . ').');
        }

        foreach ($attendus as $entree => $somme) {
            $flux = $zip->getStream($entree);
            if ($flux === false) {
                throw new SauvegardeException('Lecture impossible dans l\'archive : ' . $entree);
            }
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $flux);
            fclose($flux);
            if (!hash_equals($somme, hash_final($ctx))) {
                throw new SauvegardeException('Archive corrompue : somme de contrôle incorrecte pour ' . $entree . '.');
            }
        }
    }

    // ==================================================================
    // RESTAURATION
    // ==================================================================

    /**
     * Restaure une archive : base ET fichiers remplacent l'existant.
     *
     * @param callable|null $migrer Appelé après une restauration réussie pour
     *                              appliquer les migrations manquantes
     *                              (archive d'une version antérieure) ;
     *                              retourne la liste des migrations jouées.
     * @return array{filet: string, manifeste: array<string, mixed>, migrations: string[]}
     * @throws SauvegardeException
     */
    public function restaurer(string $chemin, ?callable $migrer = null): array
    {
        $this->verrouiller();
        try {
            $manifeste = $this->analyser($chemin);

            // Filet de sécurité : état courant, AVANT toute modification.
            // (Rotation différée : l'archive à restaurer peut elle-même être
            // une ancienne sauvegarde « avant restauration ».)
            $filet = $this->creerSansVerrou(self::TYPE_AVANT_RESTAURATION, false);

            try {
                $this->appliquer($chemin);
            } catch (\Throwable $e) {
                error_log('[SauvegardeService] restauration échouée : ' . $e->getMessage());
                try {
                    $this->appliquer($filet);
                } catch (\Throwable $e2) {
                    error_log('[SauvegardeService] retour au filet échoué : ' . $e2->getMessage());
                    throw new SauvegardeException(sprintf(
                        'La restauration a échoué et l\'état précédent n\'a pas pu être rétabli automatiquement. '
                        . 'Restaurez la sauvegarde « %s » dès que possible.',
                        basename($filet)
                    ), 0, $e2);
                }
                throw new SauvegardeException(
                    'La restauration a échoué ; les données d\'avant la restauration ont été rétablies. '
                    . 'Consultez les journaux du serveur.',
                    0,
                    $e
                );
            }

            $this->rotation();

            $migrations = [];
            if ($migrer !== null) {
                try {
                    $migrations = (array) $migrer();
                } catch (\Throwable $e) {
                    error_log('[SauvegardeService] migrations après restauration : ' . $e->getMessage());
                    throw new SauvegardeException(
                        'Les données ont été restaurées, mais la mise à niveau de la base a échoué. '
                        . 'Consultez les journaux du serveur ; la sauvegarde « ' . basename($filet)
                        . ' » contient l\'état d\'avant la restauration.',
                        0,
                        $e
                    );
                }
            }

            return [
                'filet'      => basename($filet),
                'manifeste'  => $manifeste,
                'migrations' => $migrations,
            ];
        } finally {
            $this->deverrouiller();
        }
    }

    /**
     * Extrait l'archive puis remplace base et fichiers.
     */
    private function appliquer(string $chemin): void
    {
        $tmp = $this->dossierTemporaire();
        try {
            $zip = new ZipArchive();
            if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('Archive illisible : ' . $chemin);
            }
            // Les entrées ont été validées par analyser() (ou viennent d'un
            // filet créé à l'instant) ; on revalide tout de même.
            $entrees = array_keys($this->entreesValides($zip));
            $ok = $zip->extractTo($tmp, $entrees);
            $zip->close();
            if (!$ok) {
                throw new \RuntimeException('Extraction impossible (disque plein ?).');
            }

            $this->dumper->importer($tmp . '/' . self::FICHIER_SQL);

            foreach ($this->dossiers as $prefixe => $dossier) {
                $this->remplacerFichiers($tmp . '/' . self::PREFIXE_FICHIERS . $prefixe, $dossier);
            }
        } finally {
            $this->supprimerArborescence($tmp);
        }
    }

    /**
     * Remplace les fichiers des utilisateurs de $cible par ceux de $source.
     *
     * Seuls les fichiers « utilisateur » (extensions autorisées, hors
     * fichiers et dossiers cachés) sont supprimés : les .htaccess de
     * protection et tout fichier d'un autre type restent en place.
     */
    private function remplacerFichiers(string $source, string $cible): void
    {
        if (!is_dir($cible) && !@mkdir($cible, 0755, true)) {
            throw new \RuntimeException('Dossier impossible à créer : ' . $cible);
        }

        foreach ($this->fichiersUtilisateur($cible) as $absolu) {
            if (!@unlink($absolu)) {
                throw new \RuntimeException('Suppression impossible : ' . $absolu);
            }
        }

        if (!is_dir($source)) {
            return;
        }
        foreach ($this->fichiersUtilisateur($source) as $relatif => $absolu) {
            $destination = rtrim($cible, '/') . '/' . $relatif;
            $dossier = dirname($destination);
            if (!is_dir($dossier) && !@mkdir($dossier, 0755, true)) {
                throw new \RuntimeException('Dossier impossible à créer : ' . $dossier);
            }
            if (!@copy($absolu, $destination)) {
                throw new \RuntimeException('Copie impossible : ' . $destination);
            }
        }
    }

    // ==================================================================
    // OUTILS
    // ==================================================================

    /**
     * Fichiers « utilisateur » d'un dossier, récursivement.
     *
     * @return array<string, string> Chemin relatif (séparateur « / ») => chemin absolu.
     */
    private function fichiersUtilisateur(string $dossier): array
    {
        $dossier = rtrim($dossier, '/');
        if (!is_dir($dossier)) {
            return [];
        }
        $resultat = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
                // Ni fichiers/dossiers cachés (.htaccess, .DS_Store…), ni liens.
                static fn (\SplFileInfo $f) => !str_starts_with($f->getFilename(), '.') && !$f->isLink()
            )
        );
        foreach ($iterateur as $fichier) {
            /** @var \SplFileInfo $fichier */
            if (!$fichier->isFile() || !$this->extensionAutorisee($fichier->getFilename())) {
                continue;
            }
            $relatif = substr($fichier->getPathname(), strlen($dossier) + 1);
            $resultat[str_replace(DIRECTORY_SEPARATOR, '/', $relatif)] = $fichier->getPathname();
        }
        ksort($resultat, SORT_STRING);
        return $resultat;
    }

    private function extensionAutorisee(string $nom): bool
    {
        return in_array(strtolower(pathinfo($nom, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    private function typeDepuisNom(string $nom): string
    {
        if (str_starts_with($nom, 'televersee-')) {
            return self::TYPE_TELEVERSEE;
        }
        if (str_contains($nom, '-avant-restauration')) {
            return self::TYPE_AVANT_RESTAURATION;
        }
        return self::TYPE_MANUELLE;
    }

    /** Ne garde que les N dernières sauvegardes « avant restauration ». */
    private function rotation(): void
    {
        $avant = array_values(array_filter(
            $this->lister(),
            static fn ($a) => $a['type'] === self::TYPE_AVANT_RESTAURATION
        ));
        foreach (array_slice($avant, self::CONSERVER_AVANT_RESTAURATION) as $ancienne) {
            @unlink($this->dossierSauvegardes . '/' . $ancienne['nom']);
        }
    }

    private function dossierTemporaire(): string
    {
        $this->preparerDossier();
        $tmp = $this->dossierSauvegardes . '/.tmp-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700)) {
            throw new SauvegardeException('Dossier temporaire impossible à créer (droits d\'écriture sur var/ ?).');
        }
        return $tmp;
    }

    private function supprimerArborescence(string $dossier): void
    {
        if (!is_dir($dossier) || is_link($dossier)) {
            return;
        }
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterateur as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dossier);
    }

    private function verrouiller(): void
    {
        $this->preparerDossier();
        $f = @fopen($this->dossierSauvegardes . '/.verrou', 'c');
        if ($f === false || !flock($f, LOCK_EX | LOCK_NB)) {
            if ($f !== false) {
                fclose($f);
            }
            throw new SauvegardeException('Une sauvegarde ou une restauration est déjà en cours. Réessayez dans quelques minutes.');
        }
        $this->verrou = $f;
    }

    private function deverrouiller(): void
    {
        if ($this->verrou !== null) {
            flock($this->verrou, LOCK_UN);
            fclose($this->verrou);
            $this->verrou = null;
        }
    }
}
