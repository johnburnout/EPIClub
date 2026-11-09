<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\DoubleAuthentificationManager;
use Epiclub\Engine\MotDePasse;
use PHPUnit\Framework\TestCase;

/**
 * Codes de secours hachés par MotDePasse (point unique de hachage,
 * audit 2026-11-08 R5) : même algorithme et même coût que les mots de passe.
 */
final class DoubleAuthentificationManagerCodesSecoursTest extends TestCase
{
    private \PDO $pdo;
    private DoubleAuthentificationManager $manager;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE utilisateur (id INTEGER PRIMARY KEY, totp_secret TEXT, totp_active_le TEXT,
            totp_dernier_pas INTEGER, codes_secours TEXT)');
        $this->pdo->exec('INSERT INTO utilisateur (id) VALUES (1)');
        $this->manager = new DoubleAuthentificationManager($this->pdo);
    }

    public function testCodesHachesCommeLesMotsDePasse(): void
    {
        $codes = $this->manager->genererCodesSecours(1);
        $hachages = $this->manager->etat(1)['codes_secours'] ?? [];

        self::assertCount(DoubleAuthentificationManager::NOMBRE_CODES_SECOURS, $codes);
        self::assertCount(DoubleAuthentificationManager::NOMBRE_CODES_SECOURS, $hachages);
        foreach ($hachages as $hachage) {
            self::assertFalse(MotDePasse::doitEtreRehache($hachage), 'algorithme et coût de MotDePasse');
        }
    }

    public function testUnCodeNeSertQuUneFois(): void
    {
        $codes = $this->manager->genererCodesSecours(1);

        self::assertTrue($this->manager->consommerCodeSecours(1, strtoupper($codes[3])));
        self::assertFalse($this->manager->consommerCodeSecours(1, $codes[3]));
        self::assertCount(DoubleAuthentificationManager::NOMBRE_CODES_SECOURS - 1, $this->manager->etat(1)['codes_secours'] ?? []);
    }

    /** Les codes déjà émis (PASSWORD_DEFAULT) restent utilisables. */
    public function testAnciensHachagesToujoursAcceptes(): void
    {
        $this->pdo->exec("UPDATE utilisateur SET codes_secours = '"
            . json_encode([password_hash('abcde23456', PASSWORD_BCRYPT, ['cost' => 10])]) . "' WHERE id = 1");

        self::assertTrue($this->manager->consommerCodeSecours(1, 'abcde-23456'));
    }
}
