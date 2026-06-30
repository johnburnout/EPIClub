<?php

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Session\Session as BaseSession;

class Session extends BaseSession
{
    const SESSION_LIFETIME = 900;

    public function isAuthenticated(): bool
    {
        return $this->get('user') ? true : false;
    }

    public function isGranted(string $role): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $userRole = $this->get('user')['role'];

        // Hiérarchie des rôles
        $levels = [
            'ROLE_USER' => 1,
            'ROLE_CONTROLLEUR' => 2,
            'ROLE_ADMIN' => 3,
        ];

        if (!isset($levels[$userRole]) || !isset($levels[$role])) {
            return false;
        }

        return $levels[$userRole] >= $levels[$role];
    }
}