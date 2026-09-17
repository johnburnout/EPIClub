<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * Gestion centralisée des tokens CSRF.
 *
 * Un seul token par session, stocké sous la clé 'csrf_token'.
 * Toutes les couches (contrôleurs, Twig, formulaires) passent par cette classe.
 */
class CsrfToken
{
    private const SESSION_KEY = 'csrf_token';
    private const TOKEN_BYTES = 32;

    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Retourne le token courant, en le générant si nécessaire.
     * Idempotent : un seul token par session.
     */
    public function get(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Régénère un nouveau token (à utiliser après login/logout,
     * ou après un échec de vérification).
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }

    /**
     * Vérifie un token soumis.
     *
     * @param string|null $submitted
     * @return bool
     */
    public function validate(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}