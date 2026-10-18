<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Tests\Unit\Domain\Support\TestableReferenceGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceGeneratorTest extends TestCase
{
    private TestableReferenceGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TestableReferenceGenerator();
    }

    // ---------------------------------------------------------------
    // Bloc A — normalize()
    // ---------------------------------------------------------------

    public static function normalizeProvider(): array
    {
        return [
            'simple'             => ['Cordes', 'CORDES'],
            'accents'            => ['Élingues à chaîne', 'ELINGUESACHAINE'],
            'ponctuation/espace' => ['Cordes à double !', 'CORDESADOUBLE'],
            'chiffres conservés' => ['Corde 10mm', 'CORDE10MM'],
            'vide'               => ['', ''],
            'que des symboles'   => ['à é è', 'AEE'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $libelle, string $expected): void
    {
        self::assertSame($expected, $this->generator->normalize($libelle));
    }

    // ---------------------------------------------------------------
    // Bloc B — suggestCode()
    // ---------------------------------------------------------------

    public function testSuggestCodeTroisPremieresLettres(): void
    {
        self::assertSame('COR', $this->generator->suggestCode('Cordes', []));
    }

    public function testSuggestCodeCollisionDonneDeuxLettresPlusNumero(): void
    {
        self::assertSame('CO1', $this->generator->suggestCode('Cordes', ['COR']));
        self::assertSame('CO2', $this->generator->suggestCode('Cordes', ['COR', 'CO1']));
    }

    public function testSuggestCodeLibelleVideDonneCAT(): void
    {
        self::assertSame('CAT', $this->generator->suggestCode('', []));
    }

    public function testSuggestCodeLibelleVideEnCollisionDonneCA1(): void
    {
        self::assertSame('CA1', $this->generator->suggestCode('', ['CAT']));
    }

    public function testSuggestCodeLibelleCourt(): void
    {
        self::assertSame('PI', $this->generator->suggestCode('Pi', []));
    }

    // ---------------------------------------------------------------
    // Bloc C — codeForCategorie()
    // ---------------------------------------------------------------

    public function testCodeForCategorieRetourneLeCodeExistantSansPersister(): void
    {
        $manager = $this->createMock(CategorieManager::class);
        $manager->expects(self::never())->method('save');
        $this->generator->categorieManagerMock = $manager;

        self::assertSame(
            'COR',
            $this->generator->codeForCategorie(['id' => 7, 'libelle' => 'Cordes', 'code' => 'COR'])
        );
    }

    public function testCodeForCategorieAttribueEtPersisteSiAbsent(): void
    {
        $manager = $this->createMock(CategorieManager::class);
        $manager->method('findAll')->willReturn([
            ['id' => 1, 'libelle' => 'Cordes', 'code' => 'COR'],
            ['id' => 2, 'libelle' => 'Cordages', 'code' => null],
        ]);
        $manager->method('findId')->willReturn(['id' => 3, 'libelle' => 'Cordettes', 'code' => null]);
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(fn(array $c) => ($c['code'] ?? null) === 'CO1'));
        $this->generator->categorieManagerMock = $manager;

        // COR déjà pris par une autre catégorie → CO1
        self::assertSame(
            'CO1',
            $this->generator->codeForCategorie(['id' => 3, 'libelle' => 'Cordettes', 'code' => null])
        );
    }

    // ---------------------------------------------------------------
    // Bloc D — getModele() / validateModele()
    // ---------------------------------------------------------------

    public function testGetModeleRetourneLeModeleConfigure(): void
    {
        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['ref_modele' => 'YYYY-CAT-NNNN']);
        $this->generator->clubManagerMock = $club;

        self::assertSame('YYYY-CAT-NNNN', $this->generator->getModele());
    }

    public function testGetModeleFallbackDefautSiAbsentOuVide(): void
    {
        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['ref_modele' => '  ']);
        $this->generator->clubManagerMock = $club;

        self::assertSame(ReferenceGenerator::DEFAULT_MODELE, $this->generator->getModele());
    }

    public static function validateModeleProvider(): array
    {
        return [
            'défaut valide'          => ['YYCATNNN', 0],
            'YYYY + séparateurs'     => ['YYYY-CAT-NNNN', 0],
            'sans année (continu)'   => ['CAT-NNN', 0],
            'vide'                   => ['', 1],
            'sans CAT'               => ['YYNNN', 1],
            'sans N'                 => ['YYCAT', 1],
            'deux blocs de N'        => ['YYCNNCATNN', 1],
            'Y isolé'                => ['YCATNNN', 1],
            'trop long (> 32)'       => ['YYYY-CAT-NNNN-ABCDEFGHIJKLMNOPQRST', 1],
        ];
    }

    #[DataProvider('validateModeleProvider')]
    public function testValidateModele(string $modele, int $nbErreursAttendu): void
    {
        self::assertCount($nbErreursAttendu, $this->generator->validateModele($modele));
    }

    // ---------------------------------------------------------------
    // Bloc E — generate()
    // ---------------------------------------------------------------

    private function injecterMocksGenerate(string $modele, array $references, ?string &$regexpCapture = null): void
    {
        $categorie = $this->createMock(CategorieManager::class);
        $categorie->method('findId')->willReturn(['id' => 5, 'libelle' => 'Cordes', 'code' => 'COR']);
        $this->generator->categorieManagerMock = $categorie;

        $club = $this->createMock(ClubManager::class);
        $club->method('findParameters')->willReturn(['ref_modele' => $modele]);
        $this->generator->clubManagerMock = $club;

        $equipement = $this->createMock(EquipementManager::class);
        $equipement->method('findReferencesMatching')
            ->willReturnCallback(function (string $regexp) use ($references, &$regexpCapture) {
                $regexpCapture = $regexp;
                // Simule le filtre REGEXP MySQL côté mock
                return array_values(array_filter(
                    $references,
                    static fn(string $r) => preg_match('/' . $regexp . '/', $r) === 1
                ));
            });
        $this->generator->equipementManagerMock = $equipement;
    }

    public function testGeneratePremierEquipement(): void
    {
        $regexp = null;
        $this->injecterMocksGenerate('YYCATNNN', [], $regexp);

        self::assertSame('26COR001', $this->generator->generate(5, 2026));
        self::assertSame('^26COR([0-9]{3})$', $regexp);
    }

    public function testGenerateIncrementeLeMax(): void
    {
        $this->injecterMocksGenerate('YYCATNNN', ['26COR001', '26COR005', '26COR003']);

        self::assertSame('26COR006', $this->generator->generate(5, 2026));
    }

    public function testGenerateIgnoreAutresAnneesEtCategories(): void
    {
        // Le REGEXP exclut 25COR042, 26MOU009 et les refs hors modèle
        $this->injecterMocksGenerate('YYCATNNN', ['25COR042', '26MOU009', '26COR002', 'LIBRE']);

        self::assertSame('26COR003', $this->generator->generate(5, 2026));
    }

    public function testGenerateRemiseAZeroChaqueAnnee(): void
    {
        $regexp = null;
        $this->injecterMocksGenerate('YYCATNNN', ['26COR042'], $regexp);

        self::assertSame('27COR001', $this->generator->generate(5, 2027));
        self::assertSame('^27COR([0-9]{3})$', $regexp);
    }

    public function testGeneratePaddingSelonNombreDeN(): void
    {
        $this->injecterMocksGenerate('YYCATNN', ['26COR09']);

        self::assertSame('26COR10', $this->generator->generate(5, 2026));
    }

    public function testGenerateModeleAvecSeparateurs(): void
    {
        $this->injecterMocksGenerate('YYYY-CAT-NNNN', ['2026-COR-0007']);

        self::assertSame('2026-COR-0008', $this->generator->generate(5, 2026));
    }

    public function testGenerateCategorieIntrouvableLeveException(): void
    {
        $categorie = $this->createMock(CategorieManager::class);
        $categorie->method('findId')->willReturn(null);
        $this->generator->categorieManagerMock = $categorie;

        $this->expectException(\RuntimeException::class);
        $this->generator->generate(999, 2026);
    }

    // ---------------------------------------------------------------
    // Bloc F — preview()
    // ---------------------------------------------------------------

    public function testPreview(): void
    {
        $yy = substr((string) date('Y'), -2);
        self::assertSame($yy . 'COR001', $this->generator->preview('YYCATNNN', 'COR'));
        self::assertSame(date('Y') . '-COR-0001', $this->generator->preview('YYYY-CAT-NNNN', 'COR'));
    }
}
