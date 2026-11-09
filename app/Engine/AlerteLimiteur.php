<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Domain\JournalActiviteManager;

/**
 * [SÉCURITÉ — audit 2026-11-08 R4] Alerte « limiteur de tentatives
 * indisponible ».
 *
 * La connexion par mot de passe et la demande de réinitialisation restent
 * possibles quand la table login_throttle est inaccessible (choix
 * « fail-open », pour ne pas verrouiller tout le monde dehors après une
 * migration ratée). En contrepartie, l'incident ne doit pas rester caché
 * dans les journaux du serveur : chaque panne est consignée dans
 * var/alertes/limiteur.json, et le super-admin voit un bandeau sur toutes
 * les pages tant qu'il ne l'a pas acquittée. Le début d'une panne est
 * aussi inscrit au journal d'activité (type « Sécurité »).
 *
 * Un fichier plutôt qu'une table : la panne visée est justement celle de
 * la base. Aucune méthode ne lève d'exception : l'alerte ne doit jamais
 * empêcher la connexion de se dérouler.
 */
class AlerteLimiteur
{
    /**
     * @param string        $fichier Fichier JSON de l'alerte.
     * @param \Closure|null $journal fn(string $action): void, appelée au
     *                               début d'une panne (journal d'activité).
     */
    public function __construct(private string $fichier, private ?\Closure $journal = null)
    {
    }

    /**
     * Alerte de l'installation : {racine}/var/alertes/limiteur.json.
     * Seams de test : EPICLUB_ALERTES_DIR (dossier) et
     * EPICLUB_JOURNAL_ACTIVITE_DESACTIVE (pas d'écriture du journal).
     */
    public static function parDefaut(): self
    {
        $dossier = defined('EPICLUB_ALERTES_DIR') ? (string) constant('EPICLUB_ALERTES_DIR') : __DIR__ . '/../../var/alertes';

        $journal = defined('EPICLUB_JOURNAL_ACTIVITE_DESACTIVE') ? null : static function (string $action): void {
            (new JournalActiviteManager())->enregistrer(JournalActiviteManager::TYPE_SECURITE, $action);
        };

        return new self($dossier . '/limiteur.json', $journal);
    }

    /**
     * Consigne une panne du limiteur (et la journalise côté serveur).
     *
     * @param string $source Point d'entrée concerné (« connexion »…).
     */
    public function signaler(string $source, \Throwable $erreur): void
    {
        error_log(sprintf('[AlerteLimiteur] Limiteur de tentatives indisponible (%s) : %s', $source, $erreur->getMessage()));

        try {
            $nouvelle = $this->consigner($source, $erreur->getMessage());
        } catch (\Throwable $e) {
            error_log('[AlerteLimiteur] Alerte impossible à enregistrer : ' . $e->getMessage());
            return;
        }

        if ($nouvelle && $this->journal !== null) {
            try {
                ($this->journal)(sprintf(
                    'Limiteur de tentatives indisponible (%s) : protection contre la force brute inactive',
                    $source
                ));
            } catch (\Throwable $e) {
                error_log('[AlerteLimiteur] Journal d\'activité : ' . $e->getMessage());
            }
        }
    }

    /**
     * Alerte en cours, ou null s'il n'y en a pas (ou si elle est illisible).
     *
     * @return array{debut: string, dernier: string, nombre: int, sources: list<string>, message: string}|null
     */
    public function etat(): ?array
    {
        if (!is_file($this->fichier)) {
            return null;
        }
        $donnees = json_decode((string) @file_get_contents($this->fichier), true);
        if (!is_array($donnees) || !isset($donnees['debut'], $donnees['dernier'])) {
            return null;
        }

        return [
            'debut' => (string) $donnees['debut'],
            'dernier' => (string) $donnees['dernier'],
            'nombre' => (int) ($donnees['nombre'] ?? 1),
            'sources' => array_values(array_map('strval', (array) ($donnees['sources'] ?? []))),
            'message' => (string) ($donnees['message'] ?? ''),
        ];
    }

    /** Acquittement par le super-admin : l'alerte disparaît. */
    public function acquitter(): void
    {
        if (is_file($this->fichier) && !@unlink($this->fichier)) {
            error_log('[AlerteLimiteur] Acquittement impossible : ' . $this->fichier);
        }
    }

    /**
     * Crée ou met à jour le fichier sous verrou exclusif.
     *
     * @return bool true si l'alerte vient d'être créée (début de panne).
     */
    private function consigner(string $source, string $message): bool
    {
        $dossier = dirname($this->fichier);
        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier impossible à créer : ' . $dossier);
        }

        $flux = @fopen($this->fichier, 'c+');
        if ($flux === false) {
            throw new \RuntimeException('Fichier impossible à ouvrir : ' . $this->fichier);
        }

        try {
            if (!flock($flux, LOCK_EX)) {
                throw new \RuntimeException('Verrou impossible : ' . $this->fichier);
            }
            $contenu = stream_get_contents($flux);
            $donnees = $contenu !== false && $contenu !== '' ? json_decode($contenu, true) : null;
            $nouvelle = !is_array($donnees) || !isset($donnees['debut']);

            $maintenant = date('Y-m-d H:i:s');
            $sources = $nouvelle ? [] : array_map('strval', (array) ($donnees['sources'] ?? []));
            if (!in_array($source, $sources, true)) {
                $sources[] = $source;
            }

            $json = json_encode([
                'debut' => $nouvelle ? $maintenant : (string) $donnees['debut'],
                'dernier' => $maintenant,
                'nombre' => $nouvelle ? 1 : (int) ($donnees['nombre'] ?? 0) + 1,
                'sources' => $sources,
                // Message technique, affiché au seul super-admin.
                'message' => mb_substr($message, 0, 500),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            ftruncate($flux, 0);
            rewind($flux);
            fwrite($flux, (string) $json);
            fflush($flux);
            flock($flux, LOCK_UN);

            return $nouvelle;
        } finally {
            fclose($flux);
        }
    }
}
