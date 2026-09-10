<?php

declare(strict_types=1);

namespace Epiclub\Engine\Exception;

final class MailDeliveryException extends \RuntimeException
{
    public static function fromTransport(\Throwable $previous): self
    {
        return new self('Unable to deliver email.', 0, $previous);
    }
}