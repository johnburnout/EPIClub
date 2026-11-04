<?php

declare(strict_types=1);

namespace Epiclub\Engine\Cli;

/**
 * Résumé, sans secret, de la configuration SMTP de l'instance
 * (.env.local.php : MAILER_DSN, MAILER_FROM, MAILER_NAME), pour
 * bin/infos.php et bin/tester-smtp.php.
 *
 * Le mot de passe n'est jamais repris : seuls le serveur, le port,
 * l'identifiant et l'expéditeur le sont.
 */
final class ResumeSmtp
{
    /**
     * @param array<string, mixed> $config Contenu de .env.local.php.
     * @return array{hote: string, port: ?int, protocole: string, utilisateur: ?string, expediteur: ?string, nom_expediteur: ?string}|null
     *         null si aucun SMTP n'est configuré (MAILER_DSN vide ou illisible).
     */
    public static function depuisConfig(array $config): ?array
    {
        $dsn = $config['MAILER_DSN'] ?? '';
        if (!is_string($dsn) || trim($dsn) === '') {
            return null;
        }
        $parties = parse_url(trim($dsn));
        if (!is_array($parties) || !isset($parties['scheme'], $parties['host']) || $parties['host'] === '') {
            return null;
        }
        $texte = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'hote'           => $parties['host'],
            'port'           => $parties['port'] ?? null,
            'protocole'      => strtolower($parties['scheme']),
            'utilisateur'    => isset($parties['user']) ? $texte(urldecode($parties['user'])) : null,
            'expediteur'     => $texte($config['MAILER_FROM'] ?? null),
            'nom_expediteur' => $texte($config['MAILER_NAME'] ?? null),
        ];
    }
}
