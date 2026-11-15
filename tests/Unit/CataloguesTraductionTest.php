<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit;

use Epiclub\Engine\Langue;
use Epiclub\Engine\Traducteur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [Localisation] Garde-fou des catalogues : tout texte marqué pour la
 * traduction (|trans dans les gabarits, Traducteur::t() dans le code)
 * doit exister dans chaque langue, avec les mêmes paramètres {…}.
 */
final class CataloguesTraductionTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** @return array<string, array{0:string}> */
    public static function languesTraduites(): array
    {
        $langues = [];
        foreach (array_keys(Langue::DISPONIBLES) as $langue) {
            if ($langue !== Langue::DEFAUT) {
                $langues[$langue] = [$langue];
            }
        }
        return $langues;
    }

    /**
     * Textes marqués dans les gabarits et le code.
     *
     * @return array<string, string> texte => fichier où il apparaît
     */
    private static function textesMarques(): array
    {
        $textes = [];

        $gabarits = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/templates'));
        foreach ($gabarits as $fichier) {
            if (!$fichier->isFile() || $fichier->getExtension() !== 'twig') {
                continue;
            }
            $contenu = file_get_contents($fichier->getPathname());
            // 'texte'|trans ou "texte"|trans (guillemets échappés compris)
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\s*\|\s*trans\b/u", $contenu, $simples);
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*\|\s*trans\b/u', $contenu, $doubles);
            foreach ([...$simples[1], ...$doubles[1]] as $texte) {
                $textes[stripcslashes($texte)] = $fichier->getFilename();
            }
        }

        // app/ et l'installeur (setup/, fonctions setup_t / setup_html).
        $code = new \AppendIterator();
        $code->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath(self::RACINE . '/app'))));
        $code->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath(self::RACINE . '/setup'))));
        foreach ($code as $fichier) {
            if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }
            // Tokenizer : les exemples écrits en commentaire sont ignorés.
            $jetons = array_values(array_filter(
                token_get_all(file_get_contents($fichier->getPathname())),
                static fn ($j) => !is_array($j) || !in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($jetons as $i => $jeton) {
                if (
                    is_array($jeton) && in_array($jeton[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && str_ends_with($jeton[1], 'Traducteur')
                    && is_array($jetons[$i + 1] ?? null) && $jetons[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($jetons[$i + 2] ?? null) && $jetons[$i + 2][1] === 't'
                    && ($jetons[$i + 3] ?? null) === '('
                    && is_array($jetons[$i + 4] ?? null) && $jetons[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $litteral = $jetons[$i + 4][1];
                    $texte = substr($litteral, 1, -1);
                    $texte = $litteral[0] === "'"
                        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $texte)
                        : stripcslashes($texte);
                    $textes[$texte] = $fichier->getFilename();
                }
                if (
                    is_array($jeton) && $jeton[0] === T_STRING && in_array($jeton[1], ['setup_t', 'setup_html'], true)
                    && ($jetons[$i + 1] ?? null) === '('
                    && is_array($jetons[$i + 2] ?? null) && $jetons[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                    && !(is_array($jetons[$i - 1] ?? null) && $jetons[$i - 1][0] === T_FUNCTION)
                ) {
                    $litteral = $jetons[$i + 2][1];
                    $texte = substr($litteral, 1, -1);
                    $textes[$litteral[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $texte) : stripcslashes($texte)] = $fichier->getFilename();
                }
                // Traducteur::t(self::CONSTANTE) : classe déduite du chemin (PSR-4).
                if (
                    is_array($jeton) && $jeton[0] === T_STRING && $jeton[1] === 'Traducteur'
                    && is_array($jetons[$i + 2] ?? null) && $jetons[$i + 2][1] === 't'
                    && ($jetons[$i + 3] ?? null) === '('
                    && is_array($jetons[$i + 4] ?? null) && strtolower($jetons[$i + 4][1]) === 'self'
                    && is_array($jetons[$i + 6] ?? null) && $jetons[$i + 6][0] === T_STRING
                ) {
                    $relatif = substr($fichier->getPathname(), strlen(realpath(self::RACINE . '/app')) + 1, -4);
                    $classe = 'Epiclub\\' . str_replace('/', '\\', $relatif);
                    $textes[(new \ReflectionClassConstant($classe, $jetons[$i + 6][1]))->getValue()] = $fichier->getFilename();
                }
            }
        }

        return $textes;
    }

    /** @return list<string> */
    private static function parametres(string $texte): array
    {
        preg_match_all('/\{(\w+)\}/u', $texte, $m);
        $noms = array_unique($m[1]);
        sort($noms);
        return $noms;
    }

    #[DataProvider('languesTraduites')]
    public function testToutTexteMarqueEstTraduit(string $langue): void
    {
        $catalogue = Traducteur::catalogue($langue);
        $manquants = [];
        foreach (self::textesMarques() as $texte => $fichier) {
            if (!array_key_exists($texte, $catalogue)) {
                $manquants[] = "$fichier : $texte";
            }
        }
        self::assertSame([], $manquants, "Textes sans traduction ($langue)");
    }

    #[DataProvider('languesTraduites')]
    public function testTraductionsCoherentes(string $langue): void
    {
        $catalogue = Traducteur::catalogue($langue);
        self::assertNotSame([], $catalogue);
        foreach ($catalogue as $source => $traduction) {
            self::assertIsString($traduction, $source);
            self::assertNotSame('', trim($traduction), "Traduction vide ($langue) : $source");
            self::assertSame(
                self::parametres($source),
                self::parametres($traduction),
                "Paramètres différents ($langue) : $source"
            );
        }
    }

    public function testMemesClesDansToutesLesLangues(): void
    {
        $reference = array_keys(Traducteur::catalogue('en'));
        sort($reference);
        foreach (['es', 'it'] as $langue) {
            $cles = array_keys(Traducteur::catalogue($langue));
            sort($cles);
            self::assertSame([], array_values(array_diff($reference, $cles)), "Clés absentes ($langue)");
            self::assertSame([], array_values(array_diff($cles, $reference)), "Clés en trop ($langue)");
        }
    }
}
