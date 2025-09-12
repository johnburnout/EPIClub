<?php

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Session\Session as BaseSession;

class Session extends BaseSession
{
    const SESSION_LIFETIME = 900;
    const ROLES = [
        'ROLE_USER' => 1,
        'ROLE_CONTROLLEUR' => 1,
        'ROLE_ADMIN' => 1
    ];

    public function isAuthenticated(): bool
    {
        return $this->get('user') ? true : false;
    }

    public function isGranted(string $role): bool
    {
        # if ($this->isAuthenticated() && $this->get('user')['role'] >= $level) {
        if ($this->isAuthenticated() && $this->get('user')['role'] >= self::ROLES[$role]) {
            return true;
        }

        return false;
    }
}
