<?php

declare(strict_types=1);

namespace Epiclub\Exception;

/**
 * Le verrou de l'instance (var/epiclub.lock) est tenu par une autre
 * opération : installation, sauvegarde, restauration ou mise à jour.
 * Le message est destiné à l'utilisateur.
 */
class VerrouOccupeException extends \RuntimeException
{
}
