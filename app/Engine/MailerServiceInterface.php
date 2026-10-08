<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\MailDeliveryException;
use Symfony\Component\Mime\Email;

/**
 * [VAGUE 8] Interface pour MailerService.
 *
 * Permet de mocker l'envoi d'emails en test Unit sans dépendre
 * de Symfony Mailer / SMTP.
 *
 * @see MailerService implémentation concrète.
 */
interface MailerServiceInterface
{
    /**
     * Envoie un email via le transport configuré.
     *
     * @throws MailDeliveryException Si le transport échoue.
     */
    public function sendEmail(Email $email): void;
}