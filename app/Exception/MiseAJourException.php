<?php

declare(strict_types=1);

namespace Epiclub\Exception;

/**
 * Échec d'une mise à jour (page « Mise à jour » ou bin/mettre-a-jour.php).
 *
 * Le message est destiné au super-administrateur ou à l'hébergeur. Le code
 * est le code de sortie de bin/mettre-a-jour.php (docs/virtualmin.md § 6.1) :
 * il dit aussi si l'instance a été modifiée (INTERROMPUE) ou non.
 */
class MiseAJourException extends \RuntimeException
{
    /** Erreur sans modification : réseau, archive illisible… */
    public const ERREUR = 1;

    /** Version distante introuvable, droits d'écriture absents. */
    public const PREREQUIS = 3;

    /** Archive rejetée (somme de contrôle, zip slip, archive incomplète). */
    public const ARCHIVE_REJETEE = 5;

    /** Sauvegarde de la base impossible : rien n'a été modifié. */
    public const SAUVEGARDE_IMPOSSIBLE = 7;

    /** Interrompue APRÈS modification (fichiers copiés) : il faut intervenir. */
    public const INTERROMPUE = 8;

    public function modifie(): bool
    {
        return $this->getCode() === self::INTERROMPUE;
    }
}
