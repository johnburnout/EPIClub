<?php

declare(strict_types=1);

namespace Epiclub\Exception;

use RuntimeException;

/**
* Exception métier levée lorsqu'une ressource est introuvable.
* Rendue en HTTP 404 par public/index.php.
*/
    
class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Not Found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}