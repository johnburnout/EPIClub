<?php

namespace Epiclub\Engine;

use Symfony\Component\HttpFoundation\Session\Session as BaseSession;

class Session extends BaseSession
{
    const SESSION_LIFETIME = 900;

    /**
     * Hiérarchie des rôles (du plus bas au plus élevé).
     * Utilisée par isGranted() pour déterminer si un utilisateur
     * possède au moins le niveau requis.
     */
    private const ROLE_LEVELS = [
        'ROLE_USER' => 1,
        'ROLE_CONTROLLEUR' => 2,
        'ROLE_ADMIN' => 3,
        'ROLE_SUPER_ADMIN' => 4,
    ];

    public function isAuthenticated(): bool
    {
        return $this->get('user') ? true : false;
    }

    public function isGranted(string $role): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $userRole = $this->get('user')['role'] ?? null;

        if (!isset(self::ROLE_LEVELS[$userRole]) || !isset(self::ROLE_LEVELS[$role])) {
            return false;
        }

        return self::ROLE_LEVELS[$userRole] >= self::ROLE_LEVELS[$role];
    }
}