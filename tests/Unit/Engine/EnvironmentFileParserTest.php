<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\EnvironmentFileParser;
use PHPUnit\Framework\TestCase;

/**
 * [#98] Sous-classe de test : compte les appels à _dump() pour
 * vérifier que setAll() n'écrit le fichier qu'une fois.
 */
final class CountingEnvironmentFileParser extends EnvironmentFileParser
{
    public int $dumpCount = 0;

    protected function _dump()
    {
        $this->dumpCount++;
        parent::_dump();
    }
}

/**
 * Tests unitaires de EnvironmentFileParser (issue #49).
 *
 * ⚠️ Utilise un fichier temporaire réel (sys_get_temp_dir()) pour
 *    valider les écritures de _dump(). Chaque test repart d'un
 *    fichier inexistant : le parser démarre avec un env vide.
 */
final class EnvironmentFileParserTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/epiclub_env_test_' . bin2hex(random_bytes(6)) . '.php';
        // ⚠️ On ne crée PAS le fichier : file_exists() retourne false
        //    → $this->env reste vide au constructeur.
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // ==================================================================
    // Échappement des valeurs (issue #49)
    // ==================================================================

    public function testDumpEscapesSingleQuote(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('DB_PASS', "pass'word");

        $content = file_get_contents($this->tmpFile);

        // La valeur doit être échappée par var_export : 'pass\'word'
        self::assertStringContainsString("'pass\\'word'", $content);
        // Le fichier doit être du PHP syntaxiquement valide
        self::assertTrue($this->isValidPhpSyntax($content));
    }

    public function testDumpEscapesBackslash(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('PATH_KEY', 'C:\\Windows\\System32');

        $content = file_get_contents($this->tmpFile);

        self::assertTrue($this->isValidPhpSyntax($content));
    }

    public function testDumpEscapesDollar(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('TEMPLATE', 'Hello $user, welcome');

        $content = file_get_contents($this->tmpFile);

        self::assertTrue($this->isValidPhpSyntax($content));
    }

    public function testDumpEscapesDoubleQuote(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('GREETING', 'Say "hi"');

        $content = file_get_contents($this->tmpFile);

        self::assertTrue($this->isValidPhpSyntax($content));
    }

    public function testDumpMakesPhpTagInert(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('EVIL', '<?php system($_GET["x"]); ?>');

        $content = file_get_contents($this->tmpFile);

        // Le code PHP doit être dans une string, pas exécuté.
        self::assertTrue($this->isValidPhpSyntax($content));

        // Vérification d'inertie : le fichier inclus retourne un tableau
        // contenant la valeur littérale, pas le résultat de l'exécution.
        $included = require($this->tmpFile);
        self::assertSame('<?php system($_GET["x"]); ?>', $included['EVIL']);
    }

    public function testDumpMakesDoubleQuoteInjectionInert(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        // Tentative d'injection : fermer la string et ouvrir une nouvelle clé
        $parser->set('EVIL', "foo', 'INJECTED' => 'bar");

        $content = file_get_contents($this->tmpFile);

        self::assertTrue($this->isValidPhpSyntax($content));

        $included = require($this->tmpFile);
        self::assertSame("foo', 'INJECTED' => 'bar", $included['EVIL']);
        self::assertArrayNotHasKey('INJECTED', $included);
    }

    public function testDumpPreservesNormalValues(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('APP_ENV', 'production');
        $parser->set('APP_DEBUG', '0');

        $content = file_get_contents($this->tmpFile);

        $included = require($this->tmpFile);
        self::assertSame('production', $included['APP_ENV']);
        self::assertSame('0', $included['APP_DEBUG']);
    }

    public function testDumpUppercasesKeys(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('lower_key', 'value');

        $content = file_get_contents($this->tmpFile);
        $included = require($this->tmpFile);

        self::assertArrayHasKey('LOWER_KEY', $included);
        self::assertArrayNotHasKey('lower_key', $included);
    }

    public function testDumpPreservesExistingEntries(): void
    {
        // Prépare un fichier avec une entrée existante
        file_put_contents($this->tmpFile, "<?php\nreturn [\n    'FIRST' => 'one',\n];\n");

        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->set('SECOND', 'two');

        $included = require($this->tmpFile);
        self::assertSame('one', $included['FIRST']);
        self::assertSame('two', $included['SECOND']);
    }

    // ==================================================================
    // setAll() — Dette 2 (#98) : une seule réécriture du fichier
    // ==================================================================

    public function testSetAllWritesAllKeysUppercased(): void
    {
        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->setAll([
            'mailer_dsn'  => 'smtp://u:p@host:587',
            'MAILER_FROM' => 'from@test.fr',
        ]);

        $included = require($this->tmpFile);

        self::assertSame('smtp://u:p@host:587', $included['MAILER_DSN']);
        self::assertSame('from@test.fr', $included['MAILER_FROM']);
        self::assertArrayNotHasKey('mailer_dsn', $included);
    }

    public function testSetAllPreservesExistingEntries(): void
    {
        file_put_contents($this->tmpFile, "<?php\nreturn [\n    'FIRST' => 'one',\n];\n");

        $parser = new EnvironmentFileParser($this->tmpFile);
        $parser->setAll(['SECOND' => 'two', 'THIRD' => 'three']);

        $included = require($this->tmpFile);
        self::assertSame('one', $included['FIRST']);
        self::assertSame('two', $included['SECOND']);
        self::assertSame('three', $included['THIRD']);
    }

    public function testSetAllDumpsOnceForMultipleKeys(): void
    {
        $parser = new CountingEnvironmentFileParser($this->tmpFile);
        $parser->setAll(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);

        self::assertSame(1, $parser->dumpCount, 'setAll() ne doit réécrire le fichier qu\'une fois.');

        $parser->set('E', 5);
        $parser->set('F', 6);
        self::assertSame(3, $parser->dumpCount, 'set() réécrit à chaque appel (contrat inchangé).');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Vérifie que le contenu est du PHP syntaxiquement valide en
     * l'écrivant dans un fichier temporaire et en lançant php -l.
     */
    private function isValidPhpSyntax(string $content): bool
    {
        $checkFile = tempnam(sys_get_temp_dir(), 'epiclub_syntax_');
        file_put_contents($checkFile, $content);

        $output = shell_exec('php -l ' . escapeshellarg($checkFile) . ' 2>&1');
        unlink($checkFile);

        return is_string($output) && str_contains($output, 'No syntax errors detected');
    }
}