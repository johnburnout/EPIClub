<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\GuideController;
use Epiclub\Engine\Session;

final class TestableGuideController extends GuideController
{
    public function __construct(Session $session)
    {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op (évite tout accès BDD en test Unit)
    }
}
