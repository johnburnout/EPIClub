<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\MailDeliveryException;
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
 *
 * [VAGUE 13 - #50c] Le DSN est lu via ConfigProvider (Vague 12), injecté
 * au constructeur, au lieu de `$_ENV['MAILER_DSN']`. La lecture reste
 * **lazy** : le transport est instancié au premier sendEmail(), pas au
 * constructeur — iso-comportement avec l'implémentation pré-#50c.
 *
 * Politique stricte : DSN absent ou vide → MailDeliveryException. Le
 * constructeur ne throw jamais (lazy), c'est sendEmail() qui remonte
 * l'erreur si la config est incomplète.
 *
 * @see MailerServiceInterface
 */
final class MailerService implements MailerServiceInterface
{
    private ?MailerInterface $mailer = null;

    public function __construct(private ConfigProvider $config)
    {
    }

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
            $dsn = $this->config->get('MAILER_DSN', '');
            if (!is_string($dsn) || $dsn === '') {
                throw MailDeliveryException::fromTransport(
                    new \RuntimeException('MAILER_DSN is not configured.')
                );
            }
            try {
                $this->mailer = new Mailer(Transport::fromDsn($dsn));
            } catch (\Throwable $e) {
                // DSN malformé → Transport::fromDsn() throw InvalidArgumentException,
                // qu'on normalise en MailDeliveryException pour que l'appelant
                // n'ait qu'une seule exception à gérer.
                error_log(sprintf(
                    '[MailerService] DSN parsing failure: %s',
                    $e->getMessage()
                ));
                throw MailDeliveryException::fromTransport($e);
            }
        }
        
        return $this->mailer;
    }
}