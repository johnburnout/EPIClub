<?php

declare(strict_types=1);

namespace Epiclub\Exception;

use RuntimeException;

class AccessDeniedException extends RuntimeException
{
    public function __construct(string $message = 'Access Denied', int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}