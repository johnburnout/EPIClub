<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\EtatInstallation;
use PHPUnit\Framework\TestCase;

/**
 * Le bootstrap sert-il l'assistant d'installation ?
 *
 * Bogue corrigé : dès la création du compte admin, le repli « un compte
 * existe → installé » renvoyait vers la connexion, et les étapes SMTP,
 * Club et Catégories n'étaient jamais atteintes.
 */
final class EtatInstallationTest extends TestCase
{
    private const BASE = ['DB_HOST' => 'localhost', 'DB_NAME' => 'epiclub'];

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requis(array $config, int|\Throwable $comptes, bool $sessionInstallateur): bool
    {
        return EtatInstallation::installeurRequis(
            $config,
            static function () use ($comptes): int {
                if ($comptes instanceof \Throwable) {
                    throw $comptes;
                }
                return $comptes;
            },
            static fn (): bool => $sessionInstallateur
        );
    }

    public function testSetupCompleteFermeToujoursLAssistant(): void
    {
        self::assertFalse($this->requis(self::BASE + ['SETUP_COMPLETE' => '1', 'SETUP_EN_COURS' => '1'], 1, true));
    }

    public function testSansBaseConfigureeLAssistantEstServi(): void
    {
        self::assertTrue($this->requis([], 0, false));
        self::assertTrue($this->requis(['DB_HOST' => 'localhost'], 0, false));
    }

    public function testBaseInjoignableOuSansCompte(): void
    {
        self::assertTrue($this->requis(self::BASE, new \PDOException('table absente'), false));
        self::assertTrue($this->requis(self::BASE, 0, false));
    }

    public function testInstallationAncienneSansDrapeauEstConsidereeTerminee(): void
    {
        // Repli historique : un compte existe, ni SETUP_COMPLETE ni assistant en cours.
        self::assertFalse($this->requis(self::BASE, 1, true));
    }

    public function testLInstallateurTermineLAssistantApresLaCreationDuCompte(): void
    {
        self::assertTrue($this->requis(self::BASE + ['SETUP_EN_COURS' => '1'], 1, true));
    }

    public function testUnAutreVisiteurNePeutPasReprendreLAssistant(): void
    {
        // Pendant que l'installateur termine, un tiers voit l'instance
        // installée : il ne peut ni changer le SMTP ni la base.
        self::assertFalse($this->requis(self::BASE + ['SETUP_EN_COURS' => '1'], 1, false));
    }

    public function testLaSessionNEstConsulteeQuSiNecessaire(): void
    {
        $consultee = false;
        EtatInstallation::installeurRequis(
            self::BASE,
            static fn (): int => 1,
            static function () use (&$consultee): bool {
                $consultee = true;
                return true;
            }
        );
        self::assertFalse($consultee, 'Sans assistant en cours, aucune session n\'est ouverte');
    }
}
