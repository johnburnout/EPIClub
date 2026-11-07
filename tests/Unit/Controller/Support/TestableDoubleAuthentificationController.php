<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\DoubleAuthentificationController;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\DoubleAuthentificationManager;
use Epiclub\Domain\JournalActiviteManager;
use Epiclub\Domain\LoginThrottleManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AppUserAuthenticator;
use Epiclub\Engine\PasskeyService;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Domain\Support\InMemoryDoubleAuthentificationManager;
use Epiclub\Tests\Unit\Domain\Support\InMemoryLoginThrottleManager;
use Epiclub\Tests\Unit\Engine\Support\TestableAppUserAuthenticator;

/** [migration 020] Doublures : stockage en mémoire, horloge pilotable. */
final class TestableDoubleAuthentificationController extends DoubleAuthentificationController
{
    public int $horloge = 1_800_000_000;

    /** Simule une table de limitation inaccessible. */
    public bool $limiteurEnPanne = false;

    public function __construct(
        Session $session,
        public readonly InMemoryDoubleAuthentificationManager $manager,
        private readonly UtilisateurManager $utilisateurs,
        public readonly InMemoryLoginThrottleManager $limiteur = new InMemoryLoginThrottleManager(),
        private readonly string $rootUrl = 'https://epi.club.example',
        private readonly ?JournalActiviteManager $journal = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
    }

    protected function doubleAuthentification(): DoubleAuthentificationManager
    {
        return $this->manager;
    }

    protected function utilisateurManager(): UtilisateurManager
    {
        return $this->utilisateurs;
    }

    protected function authenticator(): AppUserAuthenticator
    {
        return new TestableAppUserAuthenticator($this->session, $this->utilisateurs);
    }

    protected function loginThrottle(): LoginThrottleManager
    {
        if ($this->limiteurEnPanne) {
            throw new \PDOException('table login_throttle inaccessible');
        }
        $this->limiteur->horloge = $this->horloge;
        return $this->limiteur;
    }

    protected function passkeyService(): PasskeyService
    {
        return new PasskeyService($this->rootUrl);
    }

    protected function maintenant(): int
    {
        return $this->horloge;
    }

    protected function journalActiviteManager(): ?JournalActiviteManager
    {
        return $this->journal;
    }

    protected function clubManagerModules(): ClubManager
    {
        throw new \RuntimeException('pas de base');
    }

    protected function qrCodeDataUri(string $donnees): string
    {
        return 'data:image/png;base64,' . base64_encode($donnees);
    }
}
