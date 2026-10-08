<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\Session;

/**
 * [REFACTOR #50a] Sous-classe de test d'AbstractController.
 *
 * Expose getBaseUrl() en public et permet d'injecter un ConfigProvider
 * pré-configuré via la factory configProvider().
 */
final class TestableAbstractController extends AbstractController
{
    private ?ConfigProvider $configProvider = null;

    public function withConfigProvider(ConfigProvider $configProvider): self
    {
        $this->configProvider = $configProvider;
        return $this;
    }

    protected function configProvider(): ConfigProvider
    {
        if ($this->configProvider !== null) {
            return $this->configProvider;
        }
        return parent::configProvider();
    }

    /**
     * Expose getBaseUrl() en public pour les tests Unit.
     */
    public function exposeGetBaseUrl(): string
    {
        return $this->getBaseUrl();
    }
}