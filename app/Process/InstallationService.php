<?php

declare(strict_types=1);

namespace Epiclub\Process;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\EnvironmentFileParser;
use Epiclub\Engine\MigrationManager;
use Epiclub\Exception\InstallationException;

/**
 * Installation d'une instance EPIClub, partagée par l'assistant web
 * (setup/steps/*) et la ligne de commande (bin/installer.php).
 *
 * Les étapes de setup/ n'en appellent que des morceaux (validation,
 * base, compte, club, catégories) ; installer() enchaîne tout, pour la
 * ligne de commande. Les messages d'erreur sont ceux de l'assistant.
 *
 * Règles reprises de l'assistant :
 *  - une base qui contient déjà des tables est refusée (aucune
 *    suppression automatique) ;
 *  - le premier compte est SUPER_ADMIN et protégé ; il n'est jamais créé
 *    s'il existe déjà un utilisateur ;
 *  - SECRET_KEY n'est jamais régénérée si elle existe (les données
 *    chiffrées deviendraient illisibles) ;
 *  - .env.local.php est créé en 0600 (il contient DB_PASS et SECRET_KEY).
 */
final class InstallationService
{
    public const ACTIVITES = ['Alpinisme', 'Escalade', 'Spéléologie'];

    /** Activité utilisée quand aucune liste de catégories ne correspond. */
    public const ACTIVITE_PAR_DEFAUT = 'Escalade';

    public const LONGUEUR_MIN_MOT_DE_PASSE = 12;

    public function __construct(private string $racine)
    {
        $this->racine = rtrim($this->racine, '/');
    }

    // ==================================================================
    // PRÉREQUIS
    // ==================================================================

    /**
     * Prérequis de ressources/prerequis.php.
     *
     * @param array<string, mixed>|null           $exigences   Par défaut, ressources/prerequis.php.
     * @param (callable(string): bool)|null        $extension   Par défaut, extension_loaded().
     * @param (callable(string): (string|false))|null $ini     Par défaut, ini_get().
     * @return array{erreurs: string[], avertissements: string[]}
     *         Erreurs bloquantes, avertissements non bloquants.
     */
    public function verifierPrerequis(
        ?array $exigences = null,
        string $versionPhp = PHP_VERSION,
        ?callable $extension = null,
        ?callable $ini = null,
    ): array {
        $exigences ??= require $this->racine . '/ressources/prerequis.php';
        $extension ??= 'extension_loaded';
        $ini ??= 'ini_get';

        $erreurs = [];
        $avertissements = [];

        $phpRequis = ltrim((string) $exigences['php_version'], '>=');
        if (version_compare($versionPhp, $phpRequis, '<')) {
            $erreurs[] = sprintf(
                "PHP %s ou supérieur est requis (version actuelle : %s).",
                $phpRequis,
                $versionPhp
            );
        }

        foreach ((array) ($exigences['php_extensions'] ?? []) as $ext) {
            if (!$extension($ext)) {
                $erreurs[] = sprintf(
                    "L'extension PHP « %s » est requise mais non installée. "
                    . "Sur Debian/Ubuntu : sudo apt install php-%s",
                    $ext,
                    str_replace('_', '-', $ext)
                );
            }
        }

        foreach ((array) ($exigences['upload_limits'] ?? []) as $cle => $requis) {
            $actuel = (string) $ini($cle);
            if (self::tailleIni($actuel) < self::tailleIni((string) $requis)) {
                $avertissements[] = sprintf(
                    "La limite PHP « %s » est à %s alors que %s est recommandé. "
                    . "Les uploads de factures jusqu'à 10 Mo pourraient échouer. "
                    . "Ajustez-la dans php.ini, .user.ini, ou depuis votre panneau d'hébergement.",
                    $cle,
                    $actuel !== '' ? $actuel : 'non définie',
                    $requis
                );
            }
        }

        return ['erreurs' => $erreurs, 'avertissements' => $avertissements];
    }

    /**
     * Valeur php.ini (« 12M », « 1G », « 512K ») en octets ; 0 si vide.
     */
    public static function tailleIni(string $valeur): int
    {
        $valeur = trim($valeur);
        if ($valeur === '') {
            return 0;
        }
        $nombre = (int) $valeur;

        return match (strtolower($valeur[strlen($valeur) - 1])) {
            'g' => $nombre * 1024 * 1024 * 1024,
            'm' => $nombre * 1024 * 1024,
            'k' => $nombre * 1024,
            default => $nombre,
        };
    }

    // ==================================================================
    // VALIDATION (sans effet de bord)
    // ==================================================================

    /** @return string[] */
    public static function validerSite(string $nom, string $url): array
    {
        $erreurs = [];
        if (trim($nom) === '') {
            $erreurs[] = "Le nom du site est requis.";
        }
        if (trim($url) === '') {
            $erreurs[] = "L'URL racine du site est requise.";
        } elseif (!filter_var(trim($url), FILTER_VALIDATE_URL)) {
            $erreurs[] = "L'URL racine doit être une URL valide (ex: https://mon-site.com).";
        }
        return $erreurs;
    }

    /** @return string[] */
    public static function validerBase(string $hote, string $base, string $utilisateur): array
    {
        $erreurs = [];
        if ($hote === '') {
            $erreurs[] = "L'adresse du serveur est requise.";
        } elseif (!preg_match('/^[a-zA-Z0-9\.\:\-\_]+$/', $hote)) {
            $erreurs[] = "L'adresse du serveur contient des caractères invalides.";
        }

        if ($base === '') {
            $erreurs[] = "Le nom de la base est requis.";
        } elseif (!preg_match('/^[a-zA-Z0-9\_]+$/', $base)) {
            $erreurs[] = "Le nom de la base ne peut contenir que des lettres, chiffres et underscores.";
        } elseif (strlen($base) > 64) {
            $erreurs[] = "Le nom de la base est trop long (64 caractères max).";
        }

        if ($utilisateur === '') {
            $erreurs[] = "Le nom d'utilisateur est requis.";
        } elseif (!preg_match('/^[a-zA-Z0-9\.\-\_\@]+$/', $utilisateur)) {
            $erreurs[] = "Le nom d'utilisateur contient des caractères invalides.";
        }
        return $erreurs;
    }

    /**
     * @param array{nom?: string, prenom?: string, username?: string, email?: string, password?: string} $admin
     * @param string|null $confirmation Confirmation saisie (assistant web) ;
     *                                  null : pas de confirmation (CLI).
     * @return string[]
     */
    public static function validerAdmin(array $admin, ?string $confirmation = null): array
    {
        $erreurs = [];
        if (empty($admin['nom'])) {
            $erreurs[] = 'Le nom est requis.';
        }
        if (empty($admin['prenom'])) {
            $erreurs[] = 'Le prénom est requis.';
        }
        if (empty($admin['username'])) {
            $erreurs[] = 'Le nom d\'utilisateur est requis.';
        } elseif (strlen((string) $admin['username']) > 32) {
            $erreurs[] = 'Le nom d\'utilisateur est trop long (32 caractères max).';
        }
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'Une adresse email valide est requise.';
        }
        if (empty($admin['password']) || strlen((string) $admin['password']) < self::LONGUEUR_MIN_MOT_DE_PASSE) {
            $erreurs[] = 'Le mot de passe doit contenir au moins 12 caractères.';
        }
        if ($confirmation !== null && ($admin['password'] ?? '') !== $confirmation) {
            $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
        }
        return $erreurs;
    }

    /**
     * @param array{nom?: string, email?: string, activite?: string} $club
     * @return string[]
     */
    public static function validerClub(array $club): array
    {
        $erreurs = [];
        if (empty($club['nom'])) {
            $erreurs[] = 'Le nom du club est requis.';
        }
        if (empty($club['email']) || !filter_var($club['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse email du club est requise et doit être valide.';
        }
        if (isset($club['activite']) && $club['activite'] !== '' && !in_array($club['activite'], self::ACTIVITES, true)) {
            $erreurs[] = 'Activité inconnue : ' . $club['activite'] . ' (attendu : ' . implode(', ', self::ACTIVITES) . ').';
        }
        return $erreurs;
    }

    /**
     * SMTP facultatif : sans hôte, aucune vérification.
     *
     * @return string[]
     */
    public static function validerSmtp(string $hote, string $port, string $utilisateur): array
    {
        if ($hote === '') {
            return [];
        }
        $erreurs = [];
        if ($port === '' || !ctype_digit($port)) {
            $erreurs[] = 'Le port SMTP doit être un nombre.';
        }
        if ($utilisateur === '') {
            $erreurs[] = 'Le nom d\'utilisateur SMTP est requis.';
        }
        return $erreurs;
    }

    /**
     * Clés MAILER_* de .env.local.php (hôte vide : envoi désactivé).
     *
     * @return array<string, string>
     */
    public static function parametresSmtp(string $hote, string $port, string $utilisateur, string $motDePasse): array
    {
        if ($hote === '') {
            return ['MAILER_DSN' => '', 'MAILER_ENABLED' => 'false'];
        }
        return [
            'MAILER_DSN'     => 'smtp://' . urlencode($utilisateur) . ':' . urlencode($motDePasse) . '@' . $hote . ':' . $port,
            'MAILER_FROM'    => 'admin@' . $hote,
            'MAILER_NAME'    => 'EPIClub',
            'MAILER_ENABLED' => 'true',
        ];
    }

    // ==================================================================
    // BASE DE DONNÉES
    // ==================================================================

    /**
     * Connexion au serveur MySQL (DB_HOST peut porter un port : « hote:3307 »).
     */
    public static function connexion(string $hote, string $utilisateur, string $motDePasse, ?string $base = null): \PDO
    {
        $port = null;
        if (str_contains($hote, ':')) {
            [$hote, $port] = explode(':', $hote, 2);
        }
        $dsn = 'mysql:host=' . $hote . ';charset=utf8mb4';
        if ($port !== null && $port !== '') {
            $dsn .= ';port=' . $port;
        }
        if ($base !== null) {
            $dsn .= ';dbname=' . $base;
        }

        return new \PDO($dsn, $utilisateur, $motDePasse, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Connexion à la base décrite par une configuration (.env.local.php).
     *
     * @param array<string, mixed> $config
     */
    public static function connexionDepuisConfig(array $config): \PDO
    {
        return self::connexion(
            (string) ($config['DB_HOST'] ?? ''),
            (string) ($config['DB_USER'] ?? ''),
            (string) ($config['DB_PASS'] ?? ''),
            (string) ($config['DB_NAME'] ?? '')
        );
    }

    /**
     * Sélectionne la base (créée si elle n'existe pas), vérifie qu'elle
     * est vide, puis applique toutes les migrations.
     *
     * Une base existante n'est pas recréée : sous un hébergeur (Virtualmin),
     * l'utilisateur MySQL du domaine n'a souvent pas le droit CREATE.
     *
     * @param (callable(string): void)|null $journal
     * @return string[] Migrations appliquées.
     * @throws InstallationException DEJA_INSTALLE si la base contient des tables.
     */
    public function installerBase(\PDO $serveur, string $base, ?callable $journal = null): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $base)) {
            throw new InstallationException('Nom de base invalide.', InstallationException::USAGE);
        }

        try {
            $serveur->exec("USE `{$base}`");
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1049) { // 1049 : base inconnue
                throw $e;
            }
            $serveur->exec("CREATE DATABASE `{$base}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $serveur->exec("USE `{$base}`");
            $journal && $journal('Base « ' . $base . ' » créée.');
        }

        $tables = $serveur->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            throw new InstallationException(sprintf(
                "La base « %s » contient déjà %d table(s). " .
                "L'installation ne peut pas continuer. " .
                "Pour réinstaller, supprimez la base manuellement " .
                "et relancez l'installateur.",
                $base,
                count($tables)
            ), InstallationException::DEJA_INSTALLE);
        }

        $dossier = $this->racine . '/migrations';
        if (!is_dir($dossier)) {
            throw new \RuntimeException("Le dossier des migrations est introuvable : $dossier");
        }

        $appliquees = (new MigrationManager($serveur, $dossier))->migrate(
            static function (string $version) use ($journal): void {
                $journal && $journal('Migration appliquée : ' . $version);
            }
        );
        if ($appliquees === []) {
            throw new \RuntimeException(
                "Aucune migration n'a été appliquée. Vérifiez le contenu du dossier migrations/."
            );
        }
        return $appliquees;
    }

    // ==================================================================
    // CONFIGURATION (.env.local.php)
    // ==================================================================

    /**
     * Crée le fichier de configuration vide, en 0600, s'il n'existe pas.
     */
    public static function preparerFichierConfig(string $fichier): void
    {
        if (file_exists($fichier)) {
            return;
        }
        if (@file_put_contents($fichier, '<?php return [];') === false) {
            throw new InstallationException(
                'Impossible de créer ' . basename($fichier) . ' (droits d\'écriture sur le dossier d\'EPIClub ?).',
                InstallationException::PREREQUIS
            );
        }
        chmod($fichier, 0600);
    }

    /**
     * @return array<string, mixed> Configuration existante (vide si absente ou invalide).
     */
    public static function lireConfig(string $fichier): array
    {
        if (!is_file($fichier)) {
            return [];
        }
        $config = include $fichier;
        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $valeurs
     */
    public static function ecrireConfig(string $fichier, array $valeurs): void
    {
        self::preparerFichierConfig($fichier);
        (new EnvironmentFileParser($fichier))->setAll($valeurs);
    }

    /**
     * Clé de chiffrement à ajouter : vide si SECRET_KEY existe déjà
     * (ne JAMAIS régénérer une clé existante).
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function cleDeChiffrement(array $config): array
    {
        if (!empty($config['SECRET_KEY'])) {
            return [];
        }
        return [
            'SECRET_KEY'    => bin2hex(random_bytes(32)),
            'CIPHER_METHOD' => 'AES-256-CBC',
        ];
    }

    public static function marquerTerminee(string $fichier): void
    {
        self::ecrireConfig($fichier, ['SETUP_COMPLETE' => true]);
    }

    // ==================================================================
    // DONNÉES INITIALES
    // ==================================================================

    /**
     * Crée le compte SUPER_ADMIN protégé, seul compte de l'installation.
     *
     * @param array{nom: string, prenom: string, username: string, email: string, password: string} $admin
     *        password en clair (haché ici).
     * @throws InstallationException DEJA_INSTALLE s'il existe déjà un compte.
     */
    public function creerSuperAdmin(\PDO $pdo, array $admin): int
    {
        $manager = new UtilisateurManager($pdo);
        if (!empty($manager->findAll())) {
            throw new InstallationException(
                'Un compte administrateur existe déjà. '
                . 'Pour modifier le compte principal, connectez-vous à l\'application.',
                InstallationException::DEJA_INSTALLE
            );
        }

        return (int) $manager->save([
            'nom'                  => $admin['nom'],
            'prenom'               => $admin['prenom'],
            'username'             => $admin['username'],
            'email'                => $admin['email'],
            'password'             => password_hash($admin['password'], PASSWORD_DEFAULT),
            // Le tout premier compte de l'installation est SUPER_ADMIN :
            // seul accès aux réglages club, SMTP, mise à jour, sauvegarde.
            'role'                 => 'ROLE_SUPER_ADMIN',
            // [2026-10-20] Compte protégé : ni suppression, ni rétrogradation.
            'est_protege'          => 1,
            'date_creation'        => (new \DateTime())->format('Y-m-d H:i:s'),
            'derniere_connexion'   => null,
            'last_activity'        => null,
            'reset_token'          => null,
            'reset_token_expires'  => null,
            'reset_email_sent_at'  => null,
            'controle_en_cours_id' => null,
        ]);
    }

    /**
     * Fiche club (singleton id = 1), créée ou mise à jour.
     *
     * @param array{nom: string, activite?: string, description?: string, email: string, phone?: string} $club
     */
    public function enregistrerClub(\PDO $pdo, array $club): void
    {
        $donnees = [
            'nom'         => $club['nom'],
            'activite'    => $club['activite'] ?? '',
            'description' => $club['description'] ?? '',
            'email'       => $club['email'],
            'phone'       => $club['phone'] ?? '',
        ];
        $manager = new ClubManager($pdo);
        $existant = $manager->findParameters();
        if ($existant && isset($existant['id'])) {
            $donnees['id'] = $existant['id'];
        }
        $manager->save($donnees);
    }

    /**
     * Liste préparée de catégories pour une activité (Escalade par défaut).
     *
     * @return list<array{libelle: string, code: string, est_epi: int, duree_max_utilisation: int}>
     */
    public function categoriesProposees(string $activite): array
    {
        $listes = require $this->racine . '/ressources/categories_presets.php';
        return $listes[$activite] ?? $listes[self::ACTIVITE_PAR_DEFAUT];
    }

    /**
     * Crée les catégories proposées (toutes, ou les indices choisis).
     * Idempotent : une catégorie de même libellé n'est pas recréée.
     *
     * @param int[]|null $indices Indices dans categoriesProposees() ; null = toutes.
     * @return int Nombre de catégories créées.
     */
    public function creerCategories(\PDO $pdo, string $activite, ?array $indices = null): int
    {
        $categories = $this->categoriesProposees($activite);
        $indices ??= array_keys($categories);

        $manager = new CategorieManager($pdo);
        $creees = 0;
        foreach ($indices as $index) {
            if (!isset($categories[$index])) {
                continue; // index forgé → ignoré
            }
            if ($manager->findOneByCriteria(['libelle' => $categories[$index]['libelle']])) {
                continue;
            }
            $manager->save($categories[$index]);
            $creees++;
        }
        return $creees;
    }

    // ==================================================================
    // INSTALLATION COMPLÈTE (ligne de commande)
    // ==================================================================

    /**
     * Installe une instance d'un seul tenant.
     *
     * @param array{
     *     site_name: string, root_url: string,
     *     db_host: string, db_name: string, db_user: string, db_pass: string,
     *     admin: array{nom: string, prenom: string, username: string, email: string, password: ?string},
     *     club: array{nom: string, email: string, activite?: string, description?: string, phone?: string},
     *     categories?: bool,
     *     smtp?: array{hote: string, port: string, utilisateur: string, mot_de_passe: string}|null,
     * } $p
     * @param (callable(string): void)|null $journal Une ligne par étape (sans secret).
     * @return array{migrations: string[], admin_id: int, mot_de_passe_genere: ?string, categories: int}
     * @throws InstallationException avec le code de sortie attendu.
     */
    public function installer(array $p, string $fichierConfig, ?callable $journal = null): array
    {
        $journal ??= static function (string $ligne): void {
        };

        // 1. Validation complète avant toute modification.
        $motDePasseGenere = null;
        $admin = $p['admin'];
        if ($admin['password'] === null || $admin['password'] === '') {
            $motDePasseGenere = bin2hex(random_bytes(12));
            $admin['password'] = $motDePasseGenere;
        }
        $club = $p['club'];
        $club['activite'] = $club['activite'] ?? '';
        $smtp = $p['smtp'] ?? null;

        // Préfixe par rubrique : sans formulaire, « Le nom d'utilisateur est
        // requis » doit dire s'il s'agit de la base ou du compte.
        $rubrique = static fn (string $nom, array $messages): array => array_map(
            static fn (string $m): string => $nom . ' : ' . $m,
            $messages
        );
        $erreurs = array_merge(
            $rubrique('Site', self::validerSite($p['site_name'], $p['root_url'])),
            $rubrique('Base', self::validerBase($p['db_host'], $p['db_name'], $p['db_user'])),
            $rubrique('Super-administrateur', self::validerAdmin($admin)),
            $rubrique('Club', self::validerClub($club)),
            $smtp !== null ? $rubrique('SMTP', self::validerSmtp($smtp['hote'], $smtp['port'], $smtp['utilisateur'])) : [],
        );
        if ($erreurs !== []) {
            throw new InstallationException('Paramètres invalides.', InstallationException::USAGE, $erreurs);
        }

        // 2. Prérequis.
        $prerequis = $this->verifierPrerequis();
        foreach ($prerequis['avertissements'] as $avertissement) {
            $journal('Avertissement : ' . $avertissement);
        }
        if ($prerequis['erreurs'] !== []) {
            throw new InstallationException('Prérequis non satisfaits.', InstallationException::PREREQUIS, $prerequis['erreurs']);
        }
        if (!is_writable(dirname($fichierConfig)) && !is_writable($fichierConfig)) {
            throw new InstallationException(
                'Le dossier d\'EPIClub n\'est pas inscriptible : ' . basename($fichierConfig) . ' ne peut pas être écrit.',
                InstallationException::PREREQUIS
            );
        }

        // 3. Installation déjà faite ?
        $config = self::lireConfig($fichierConfig);
        if (!empty($config['SETUP_COMPLETE'])) {
            throw new InstallationException('EPIClub est déjà installé (SETUP_COMPLETE).', InstallationException::DEJA_INSTALLE);
        }

        // 4. Base : connexion, base vide, migrations.
        try {
            $pdo = self::connexion($p['db_host'], $p['db_user'], $p['db_pass']);
        } catch (\PDOException $e) {
            error_log('[InstallationService] connexion : ' . $e->getMessage());
            throw new InstallationException(
                "Connexion impossible à la base de données. Vérifiez l'hôte, le nom d'utilisateur et le mot de passe.",
                InstallationException::PREREQUIS,
                [],
                $e
            );
        }
        $migrations = $this->installerBase($pdo, $p['db_name'], $journal);
        $journal(count($migrations) . ' migration(s) appliquée(s).');

        // 5. À partir d'ici la base est modifiée : tout échec est une
        //    installation interrompue (à reprendre à la main).
        try {
            self::ecrireConfig($fichierConfig, array_merge(
                [
                    'SITE_NAME' => trim($p['site_name']),
                    'ROOT_URL'  => trim($p['root_url']),
                    'DB_HOST'   => $p['db_host'],
                    'DB_NAME'   => $p['db_name'],
                    'DB_USER'   => $p['db_user'],
                    'DB_PASS'   => $p['db_pass'],
                ],
                $smtp !== null
                    ? self::parametresSmtp($smtp['hote'], $smtp['port'], $smtp['utilisateur'], $smtp['mot_de_passe'])
                    : self::parametresSmtp('', '', '', ''),
                self::cleDeChiffrement($config),
            ));
            $journal('Configuration écrite : ' . basename($fichierConfig) . '.');

            $adminId = $this->creerSuperAdmin($pdo, $admin);
            $journal('Super-administrateur protégé créé : ' . $admin['username'] . '.');

            $this->enregistrerClub($pdo, $club);
            $journal('Club enregistré : ' . $club['nom'] . '.');

            $categories = 0;
            if ($p['categories'] ?? true) {
                $categories = $this->creerCategories($pdo, $club['activite']);
                $journal($categories . ' catégorie(s) créée(s).');
            }

            self::marquerTerminee($fichierConfig);
            $journal('Installation terminée.');
        } catch (\Throwable $e) {
            error_log('[InstallationService] installation interrompue : ' . $e->getMessage());
            throw new InstallationException(
                'Installation interrompue après la création des tables : ' . $e->getMessage()
                . ' Vérifiez la base « ' . $p['db_name'] . ' » et ' . basename($fichierConfig) . ' avant de relancer.',
                InstallationException::INTERROMPUE,
                [],
                $e
            );
        }

        return [
            'migrations'          => $migrations,
            'admin_id'            => $adminId,
            'mot_de_passe_genere' => $motDePasseGenere,
            'categories'          => $categories,
        ];
    }
}
