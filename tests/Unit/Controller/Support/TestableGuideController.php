<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\GuideController;
use Epiclub\Engine\Session;

final class TestableGuideController extends GuideController
{
    private ?string $guideFilePath = null;

    public function __construct(Session $session)
    {
        parent::__construct($session);
    }

    public function setGuideFilePath(string $path): void
    {
        $this->guideFilePath = $path;
    }

    protected function guideFilePath(): string
    {
        return $this->guideFilePath ?? parent::guideFilePath();
    }

    protected function updateLastActivity(): void
    {
        // no-op (évite tout accès BDD en test Unit)
    }
}
