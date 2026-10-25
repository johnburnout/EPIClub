<?php

declare(strict_types=1);

namespace Epiclub\Engine\Cli;

/**
 * Socle commun des commandes bin/installer.php et bin/sauvegarder.php
 * (contrat : docs/virtualmin.md § 6.1).
 *
 *  - options « --cle=valeur » et drapeaux « --drapeau », rien d'interactif ;
 *  - sortie texte (stdout), erreurs sur stderr, --quiet (rien si tout va
 *    bien), --json (un seul objet sur stdout, pour la console
 *    d'hébergement) ;
 *  - journal horodaté dans var/log/epiclub-cli.log (0640, sans secret) ;
 *  - refus de s'exécuter en root sauf --autoriser-root : les fichiers
 *    créés doivent appartenir à l'utilisateur du domaine.
 */
final class Commande
{
    public const SUCCES = 0;
    public const ERREUR = 1;
    public const USAGE = 2;
    public const PREREQUIS = 3;
    public const VERROU_OCCUPE = 4;
    public const SAUVEGARDE_IMPOSSIBLE = 7;
    public const INTERROMPUE = 8;
    public const DEJA_INSTALLE = 9;

    /** @var array<string, string|true> */
    private array $options = [];

    private bool $quiet;
    private bool $json;

    /** @var list<string> */
    private array $journal = [];

    /** @var resource|null */
    private $sortie;

    /** @var resource|null */
    private $erreurs;

    /**
     * @param list<string> $argv         Arguments ($argv sans le nom du script).
     * @param list<string> $optionsValeur Options attendant une valeur (--cle=valeur).
     * @param list<string> $drapeaux     Options sans valeur.
     * @param resource|null $sortie      Flux de sortie (stdout par défaut).
     * @param resource|null $erreurs     Flux d'erreur (stderr par défaut).
     */
    public function __construct(
        private string $nom,
        array $argv,
        array $optionsValeur,
        array $drapeaux,
        private ?string $fichierJournal = null,
        $sortie = null,
        $erreurs = null,
    ) {
        $this->sortie = $sortie ?? STDOUT;
        $this->erreurs = $erreurs ?? STDERR;

        $drapeaux = array_merge($drapeaux, ['quiet', 'json', 'autoriser-root', 'aide']);
        $inconnues = [];
        foreach ($argv as $argument) {
            if (!preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $argument, $m)) {
                $inconnues[] = $argument;
                continue;
            }
            $cle = $m[1];
            if (in_array($cle, $optionsValeur, true) && isset($m[2])) {
                $this->options[$cle] = $m[2];
            } elseif (in_array($cle, $drapeaux, true) && !isset($m[2])) {
                $this->options[$cle] = true;
            } else {
                $inconnues[] = $argument;
            }
        }
        $this->quiet = isset($this->options['quiet']);
        $this->json = isset($this->options['json']);

        if ($inconnues !== []) {
            throw new UsageException('Option inconnue ou mal formée : ' . implode(', ', $inconnues));
        }
    }

    /**
     * Échec de lecture des arguments, avant que la commande existe : même
     * format de sortie (--json repéré directement dans $argv).
     *
     * @param list<string> $argv Arguments sans le nom du script.
     * @param resource|null $sortie
     * @param resource|null $erreurs
     */
    public static function echecUsage(string $nom, array $argv, string $message, $sortie = null, $erreurs = null): int
    {
        if (in_array('--json', $argv, true)) {
            fwrite($sortie ?? STDOUT, json_encode([
                'commande' => $nom,
                'succes'   => false,
                'code'     => self::USAGE,
                'message'  => $message,
                'details'  => [],
                'etapes'   => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            fwrite($erreurs ?? STDERR, 'Erreur : ' . $message . "\nAide : php bin/" . $nom . ".php --aide\n");
        }
        return self::USAGE;
    }

    /**
     * Gardes d'exécution : CLI uniquement, pas en root (sauf --autoriser-root).
     */
    public static function verifierContexte(string $sapi, ?int $uid, bool $autoriserRoot): ?string
    {
        if ($sapi !== 'cli') {
            return 'Cette commande ne s\'exécute qu\'en ligne de commande.';
        }
        if ($uid === 0 && !$autoriserRoot) {
            return 'Refus de s\'exécuter en root : les fichiers créés appartiendraient à root. '
                . 'Lancez la commande sous l\'utilisateur du domaine (su, sudo -u), '
                . 'ou ajoutez --autoriser-root en connaissance de cause.';
        }
        return null;
    }

    public function option(string $cle, ?string $defaut = null): ?string
    {
        $valeur = $this->options[$cle] ?? null;
        return is_string($valeur) ? $valeur : $defaut;
    }

    public function drapeau(string $cle): bool
    {
        return ($this->options[$cle] ?? null) === true;
    }

    public function enJson(): bool
    {
        return $this->json;
    }

    /**
     * Étape en cours : affichée (sauf --quiet/--json) et journalisée.
     */
    public function etape(string $message): void
    {
        $this->journal[] = $message;
        $this->journaliser('INFO', $message);
        if (!$this->quiet && !$this->json) {
            fwrite($this->sortie, $message . "\n");
        }
    }

    /**
     * Fin de commande : écrit le résultat et renvoie le code de sortie.
     *
     * @param array<string, mixed> $donnees Données supplémentaires (--json).
     * @param string[]             $details Erreurs détaillées.
     */
    public function terminer(int $code, string $message, array $donnees = [], array $details = []): int
    {
        $this->journaliser($code === self::SUCCES ? 'OK' : 'ERREUR', $message . ($details !== [] ? ' — ' . implode(' ; ', $details) : '') . ' (code ' . $code . ')');

        if ($this->json) {
            fwrite($this->sortie, json_encode(array_merge([
                'commande' => $this->nom,
                'succes'   => $code === self::SUCCES,
                'code'     => $code,
                'message'  => $message,
                'details'  => $details,
                'etapes'   => $this->journal,
            ], $donnees), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return $code;
        }

        if ($code === self::SUCCES) {
            if (!$this->quiet) {
                fwrite($this->sortie, $message . "\n");
            }
            return $code;
        }

        fwrite($this->erreurs, 'Erreur : ' . $message . "\n");
        foreach ($details as $detail) {
            fwrite($this->erreurs, '  - ' . $detail . "\n");
        }
        return $code;
    }

    private function journaliser(string $niveau, string $message): void
    {
        if ($this->fichierJournal === null) {
            return;
        }
        $dossier = dirname($this->fichierJournal);
        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            return;
        }
        $nouveau = !file_exists($this->fichierJournal);
        $ligne = sprintf("%s [%s] %s pid=%d : %s\n", date('c'), $this->nom, $niveau, getmypid(), str_replace("\n", ' ', $message));
        if (@file_put_contents($this->fichierJournal, $ligne, FILE_APPEND | LOCK_EX) !== false && $nouveau) {
            @chmod($this->fichierJournal, 0640);
        }
    }
}
