<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\AppUpdateController;
use Epiclub\Engine\GitHubReleaseProviderInterface;
use Epiclub\Engine\Session;

/**
 * [REFACTOR VAGUE 10] Sous-classe de test d'AppUpdateController.
 *
 * Permet d'injecter un GitHubReleaseProviderInterface mocké pour
 * tester index() et perform() sans dépendre du réseau.
 */
final class TestableAppUpdateController extends AppUpdateController
{
    public function __construct(
        Session $session,
        private readonly GitHubReleaseProviderInterface $provider,
    ) {
        parent::__construct($session);
    }

    protected function githubReleaseProvider(): GitHubReleaseProviderInterface
    {
        return $this->provider;
    }
}