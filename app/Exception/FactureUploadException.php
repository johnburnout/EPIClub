<?php

declare(strict_types=1);

namespace Epiclub\Exception;

use RuntimeException;

/**
 * Erreur d'upload d'une facture (taille, mime, I/O).
 *
 * Le code permet au contrôleur (ou à un futur handler) de distinguer
 * les cas sans parser le message :
 *   - TOO_LARGE  : fichier au-dessus de la limite calculée
 *   - BAD_MIME   : type MIME non autorisé
 *   - IO_ERROR   : mkdir, is_writable, move, etc.
 *   - INVALID    : UploadedFile::isValid() a échoué (upload PHP)
 *
 * Le message est destiné à être affiché à l'utilisateur (pas de fuite
 * d'information sensible : on n'inclut pas le chemin réel, ni l'erreur
 * système brute).
 */
final class FactureUploadException extends RuntimeException
{
    public const TOO_LARGE = 'TOO_LARGE';
    public const BAD_MIME  = 'BAD_MIME';
    public const IO_ERROR  = 'IO_ERROR';
    public const INVALID   = 'INVALID';

    public function __construct(string $message, string $code, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        // On surcharge le code numérique par un code string via une propriété dédiée.
        // getCode() reste 0 (contrat \Exception : int), mais getErrorCode() porte le sens.
        $this->errorCode = $code;
    }

    private string $errorCode;

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}