<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Domain\UtilisateurManager;
use Epiclub\Tests\Unit\Engine\Support\TestableUtilisateurManager;
use PHPUnit\Framework\TestCase;

/**
 * [SÉCURITÉ — audit 2026-11-07] Le jeton de réinitialisation n'est jamais
 * stocké en clair : une fuite de la base ou d'une sauvegarde ne permet pas
 * de réinitialiser un mot de passe.
 */
final class UtilisateurManagerResetTokenTest extends TestCase
{
    private \PDO $pdo;
    private UtilisateurManager $manager;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE utilisateur (id INTEGER PRIMARY KEY, username TEXT, reset_token TEXT, reset_token_expires TEXT, reset_email_sent_at TEXT)');
        $this->pdo->exec("INSERT INTO utilisateur (id, username) VALUES (42, 'jean'), (43, 'paul')");
        $this->manager = new TestableUtilisateurManager($this->pdo);
    }

    public function testSeuleLEmpreinteEstEnregistree(): void
    {
        $jeton = bin2hex(random_bytes(32));
        $this->manager->setResetToken(42, $jeton, '2030-01-01 00:00:00', '2026-11-07 12:00:00');

        $enBase = (string) $this->pdo->query('SELECT reset_token FROM utilisateur WHERE id = 42')->fetchColumn();
        self::assertNotSame($jeton, $enBase);
        self::assertStringNotContainsString($jeton, $enBase);
        self::assertSame(UtilisateurManager::empreinteJetonReset($jeton), $enBase);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $enBase);
    }

    public function testLeJetonDuLienRetrouveLeCompte(): void
    {
        $jeton = bin2hex(random_bytes(32));
        $this->manager->setResetToken(42, $jeton, '2030-01-01 00:00:00', '2026-11-07 12:00:00');

        self::assertSame(42, (int) ($this->manager->findByResetToken($jeton)['id'] ?? 0));
    }

    public function testLEmpreinteVoleeNeSertPasDeJeton(): void
    {
        $jeton = bin2hex(random_bytes(32));
        $this->manager->setResetToken(42, $jeton, '2030-01-01 00:00:00', '2026-11-07 12:00:00');
        $vole = (string) $this->pdo->query('SELECT reset_token FROM utilisateur WHERE id = 42')->fetchColumn();

        self::assertNull($this->manager->findByResetToken($vole), 'la valeur lue en base ne vaut pas jeton');
        self::assertNull($this->manager->findByResetToken(''));
        self::assertNull($this->manager->findByResetToken('autre'));
    }

    /** Ancien jeton stocké en clair (avant la mise à jour) : inutilisable. */
    public function testUnAncienJetonEnClairNEstPlusAccepte(): void
    {
        $this->pdo->exec("UPDATE utilisateur SET reset_token = 'ancien-jeton-en-clair' WHERE id = 43");

        self::assertNull($this->manager->findByResetToken('ancien-jeton-en-clair'));
    }
}
