<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\ClubController;
use Epiclub\Domain\ClubManager;
use Epiclub\Engine\Session;

final class TestableClubController extends ClubController
{
    public function __construct(
        Session $session,
        private readonly ClubManager $clubManager,
    ) {
        parent::__construct($session);
    }

    protected function clubManager(): ClubManager
    {
        return $this->clubManager;
    }

    protected function updateLastActivity(): void
    {
        // no-op
    }
}