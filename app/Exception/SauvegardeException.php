<?php

declare(strict_types=1);

namespace Epiclub\Exception;

/**
 * Erreur de sauvegarde ou de restauration.
 *
 * Le message est destiné à l'utilisateur (super-administrateur) : il ne
 * contient ni chemin serveur ni détail SQL. Le détail technique est
 * journalisé séparément (error_log) par l'appelant.
 */
class SauvegardeException extends \RuntimeException
{
}
