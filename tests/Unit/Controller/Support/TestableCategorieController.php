<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\CategorieController;
use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;

final class TestableCategorieController extends CategorieController
{
    public function __construct(
        Session $session,
        private readonly CategorieManager $categorieManager,
    ) {
        parent::__construct($session);
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }
    
    public function sanitizeUploadedFilename(string $originalName): string
    {
        return parent::sanitizeUploadedFilename($originalName);
    }
}