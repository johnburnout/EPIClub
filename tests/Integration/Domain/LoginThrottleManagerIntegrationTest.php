<?php

declare(strict_types=1);

namespace Epiclub\Tests\Integration\Domain;

use Epiclub\Domain\LoginThrottleManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-10-19 S2] Stockage MySQL du limiteur
 * (table login_throttle, migration 010).
 *
 * ⚠️ Se skip si MySQL est indisponible ou si la migration 010 n'est pas
 * appliquée (lancer « php bin/migrate.php »).
 */
final class LoginThrottleManagerIntegrationTest extends TestCase
{
    private LoginThrottleManager $limiteur;
    private string $cle;

    protected function setUp(): void
    {
        try {
            $this->limiteur = new LoginThrottleManager();
            $this->limiteur->secondesRestantes('sonde');
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'Table login_throttle indisponible (php bin/migrate.php ?) : ' . $e->getMessage()
            );
        }
        $this->cle = 'test-integration:' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->limiteur, $this->cle)) {
            $this->limiteur->reinitialiser($this->cle);
        }
    }

    public function testCycleCompletEnBase(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(0, $this->limiteur->enregistrerEchec($this->cle, 5));
        }
        self::assertSame(0, $this->limiteur->secondesRestantes($this->cle));

        self::assertSame(60, $this->limiteur->enregistrerEchec($this->cle, 5));
        $restant = $this->limiteur->secondesRestantes($this->cle);
        self::assertGreaterThanOrEqual(58, $restant);
        self::assertLessThanOrEqual(60, $restant);

        self::assertSame(120, $this->limiteur->enregistrerEchec($this->cle, 5));

        $this->limiteur->reinitialiser($this->cle);
        self::assertSame(0, $this->limiteur->secondesRestantes($this->cle));
    }
}
