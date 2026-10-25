<?php

declare(strict_types=1);

namespace Epiclub\Exception;

/**
 * Échec de l'installation (assistant web ou bin/installer.php).
 *
 * Le message est destiné à la personne qui installe. Le code de
 * l'exception est le code de sortie de bin/installer.php (constantes
 * ci-dessous, voir docs/virtualmin.md § 6.1).
 */
class InstallationException extends \RuntimeException
{
    /** Paramètres manquants ou invalides. */
    public const USAGE = 2;

    /** Prérequis absents : PHP, extensions, droits d'écriture. */
    public const PREREQUIS = 3;

    /** Installation interrompue après modification (base ou configuration). */
    public const INTERROMPUE = 8;

    /** Déjà installé : SETUP_COMPLETE posé, base non vide ou compte existant. */
    public const DEJA_INSTALLE = 9;

    /** @var string[] */
    private array $details;

    /**
     * @param string[] $details Liste d'erreurs détaillées (validation).
     */
    public function __construct(string $message, int $code, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    /** @return string[] */
    public function details(): array
    {
        return $this->details;
    }
}
