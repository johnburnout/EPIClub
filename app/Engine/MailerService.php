<?php

namespace Epiclub\Engine;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * MailerService uses an SMTP server to send emails
 * 
 * ex: MAILER_DSN=smtp://user:pass@smtp.example.com:25
 * 
 * doc: https://symfony.com/doc/current/mailer.html
 */
class MailerService
{
    public function sendEmail(Email $email)
    {
        // $dsn = 'smtp://localhost:1025';
        $dsn = $_ENV['MAILER_DSN'];
        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Email failure' . $e->getMessage(), 1);
        }

        return $mailer->send($email);
    }
}
