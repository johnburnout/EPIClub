<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Tests\Unit\Domain\Support\InMemoryLoginThrottleManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-10-19 S2] Politique de blocage progressif.
 */
final class LoginThrottleManagerTest extends TestCase
{
    private InMemoryLoginThrottleManager $limiteur;

    protected function setUp(): void
    {
        $this->limiteur = new InMemoryLoginThrottleManager();
    }

    public function testAucunBlocageSousLeSeuil(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(0, $this->limiteur->enregistrerEchec('login-user:jean', 5));
        }
        self::assertSame(0, $this->limiteur->secondesRestantes('login-user:jean'));
    }

    public function testBlocageProgressifPuisPlafonne(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->limiteur->enregistrerEchec('k', 5);
        }
        $durees = [];
        for ($i = 0; $i < 9; $i++) {
            $durees[] = $this->limiteur->enregistrerEchec('k', 5);
        }
        self::assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600, 3600], $durees);
        self::assertSame(3600, $this->limiteur->secondesRestantes('k'));
    }

    public function testLeBlocageExpireAvecLeTemps(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->limiteur->enregistrerEchec('k', 5);
        }
        self::assertSame(60, $this->limiteur->secondesRestantes('k'));

        $this->limiteur->horloge += 59;
        self::assertSame(1, $this->limiteur->secondesRestantes('k'));

        $this->limiteur->horloge += 1;
        self::assertSame(0, $this->limiteur->secondesRestantes('k'));
    }

    public function testLesEchecsAnciensSontOubliesApres24h(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->limiteur->enregistrerEchec('k', 5);
        }
        $this->limiteur->horloge += 86401;

        // Le compteur repart de 1 : pas de blocage.
        self::assertSame(0, $this->limiteur->enregistrerEchec('k', 5));
    }

    public function testReinitialiserLeveLeBlocage(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->limiteur->enregistrerEchec('k', 5);
        }
        $this->limiteur->reinitialiser('k');

        self::assertSame(0, $this->limiteur->secondesRestantes('k'));
        self::assertSame(0, $this->limiteur->enregistrerEchec('k', 5));
    }

    public function testLesClesSontIndependantes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->limiteur->enregistrerEchec('login-user:jean', 5);
        }
        self::assertGreaterThan(0, $this->limiteur->secondesRestantes('login-user:jean'));
        self::assertSame(0, $this->limiteur->secondesRestantes('login-user:marie'));
    }

    public function testAucuneDonneeEnClairNestStockee(): void
    {
        $this->limiteur->enregistrerEchec('login-user:jean.dupont', 5);
        $this->limiteur->enregistrerEchec('login-ip:203.0.113.7', 30);

        foreach (array_keys($this->limiteur->lignes) as $cle) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cle);
            self::assertStringNotContainsString('jean', $cle);
            self::assertStringNotContainsString('203.0.113', $cle);
        }
    }
}
