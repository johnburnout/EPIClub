<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Setup;

use Epiclub\Engine\EnvironmentFileParser;
use PHPUnit\Framework\TestCase;

/**
 * [#106 — Dette 23 soldée]
 *
 * `setup/includes/EnvironmentFileParser.php` était un doublon legacy de
 * `Epiclub\Engine\EnvironmentFileParser` (même namespace), chargé en
 * premier par step_1.php : c'était la version PRÉ-#49 qui gagnait, dont
 * `_dump()` écrivait les valeurs sans échappement. Un mot de passe BDD
 * contenant ' ou \ cassait (ou permettait d'injecter) le fichier
 * `.env.local.php`. Suppression du doublon + fige du round-trip sûr.
 */
final class SetupLegacyParserRemovedTest extends TestCase
{
    public function testLegacySetupParserFileIsRemoved(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/setup/includes/EnvironmentFileParser.php',
            'setup/includes/EnvironmentFileParser.php : doublon legacy pré-#49 supprimé (Dette 23, #106)'
        );
    }

    public function testEnvFileParserRoundTripsHostilePassword(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'epiclub-106-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, '<?php return [];');

        $hostilePassword = "p'@ss\\word\"x\ny;\$z";

        try {
            $parser = new EnvironmentFileParser($tmpFile);
            $parser->set('DB_PASS', $hostilePassword);

            // Le fichier généré doit être du PHP valide qui restitue
            // la valeur EXACTE (échappement var_export, #49).
            $config = require $tmpFile;
            self::assertIsArray($config);
            self::assertSame($hostilePassword, $config['DB_PASS']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
