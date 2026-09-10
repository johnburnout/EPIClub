<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Engine\Exception\MailDeliveryException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * MailerService uses an SMTP server to send emails.
 *
 * ex: MAILER_DSN=smtp://user:pass@smtp.example.com:25
 * doc: https://symfony.com/doc/current/mailer.html
 */
final class MailerService
{
    private ?MailerInterface $mailer = null;

    /**
     * @throws MailDeliveryException
     */
    public function sendEmail(Email $email): void
    {
        try {
            $this->getMailer()->send($email);
        } catch (TransportExceptionInterface $e) {
            // On loggue le détail côté serveur — JAMAIS exposé au client.
            error_log(sprintf(
                '[MailerService] Transport failure: %s',
                $e->getMessage()
            ));

            throw MailDeliveryException::fromTransport($e);
        }
    }

    private function getMailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $dsn = $_ENV['MAILER_DSN'] ?? '';
            if ($dsn === '') {
                throw MailDeliveryException::fromTransport(
                    new \RuntimeException('MAILER_DSN is not configured.')
                );
            }
            $this->mailer = new Mailer(Transport::fromDsn($dsn));
        }

        return $this->mailer;
    }
}