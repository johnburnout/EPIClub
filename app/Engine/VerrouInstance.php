<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\VerrouOccupeException;

/**
 * Verrou unique d'une instance EPIClub (var/epiclub.lock).
 *
 * Partagé par toutes les opérations qui ne doivent pas se chevaucher :
 * installation (bin/installer.php), sauvegarde et restauration (page
 * Sauvegarde, bin/sauvegarder.php), mise à jour (page Mise à jour).
 * Une sauvegarde planifiée ne tourne donc jamais pendant qu'un
 * administrateur met à jour son instance, et inversement.
 *
 * flock() non bloquant : la seconde opération échoue tout de suite
 * (VerrouOccupeException) au lieu d'attendre. Le verrou est libéré par
 * le système si le processus meurt. Le fichier garde, pour le
 * diagnostic, l'opération en cours, son PID et son heure de début.
 */
final class VerrouInstance
{
    /** @var resource|null */
    private $flux = null;

    public function __construct(private string $fichier)
    {
    }

    /**
     * Verrou par défaut d'une installation : {racine}/var/epiclub.lock.
     */
    public static function pourRacine(string $racine): self
    {
        return new self(rtrim($racine, '/') . '/var/epiclub.lock');
    }

    public function fichier(): string
    {
        return $this->fichier;
    }

    /**
     * @throws VerrouOccupeException si une autre opération tient le verrou.
     * @throws \RuntimeException     si le fichier ne peut pas être créé.
     */
    public function acquerir(string $operation): void
    {
        if ($this->flux !== null) {
            throw new \LogicException('Verrou déjà acquis par ce processus.');
        }

        $dossier = dirname($this->fichier);
        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier du verrou impossible à créer : ' . $dossier);
        }

        $flux = @fopen($this->fichier, 'c+');
        if ($flux === false) {
            throw new \RuntimeException('Fichier de verrou impossible à ouvrir : ' . $this->fichier);
        }

        if (!flock($flux, LOCK_EX | LOCK_NB)) {
            $detenteur = trim((string) stream_get_contents($flux));
            fclose($flux);
            throw new VerrouOccupeException(self::message($detenteur));
        }

        ftruncate($flux, 0);
        fwrite($flux, $operation . ' pid=' . getmypid() . ' depuis=' . date('c') . "\n");
        fflush($flux);
        $this->flux = $flux;
    }

    public function liberer(): void
    {
        if ($this->flux === null) {
            return;
        }
        ftruncate($this->flux, 0);
        flock($this->flux, LOCK_UN);
        fclose($this->flux);
        $this->flux = null;
    }

    public function estAcquis(): bool
    {
        return $this->flux !== null;
    }

    /**
     * Exécute $action sous le verrou, puis le libère quoi qu'il arrive.
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    public function executer(string $operation, callable $action): mixed
    {
        $this->acquerir($operation);
        try {
            return $action();
        } finally {
            $this->liberer();
        }
    }

    private static function message(string $detenteur): string
    {
        $operation = strtok($detenteur, ' ') ?: '';
        $libelles = [
            'installation' => 'une installation',
            'sauvegarde'   => 'une sauvegarde',
            'restauration' => 'une restauration',
            'mise-a-jour'  => 'une mise à jour',
        ];
        $libelle = $libelles[$operation] ?? 'une autre opération';

        return 'Opération impossible : ' . $libelle . ' est déjà en cours. Réessayez dans quelques minutes.';
    }
}
