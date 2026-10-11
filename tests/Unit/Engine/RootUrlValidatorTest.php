<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use Epiclub\Engine\RootUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootUrlValidatorTest extends TestCase
{
    #[DataProvider('validUrlsProvider')]
    public function testValidUrlIsNormalized(string $input, string $expected): void
    {
        self::assertSame($expected, RootUrlValidator::validate($input));
    }

    public static function validUrlsProvider(): array
    {
        return [
            'https avec slash final retiré'    => ['https://example.com/', 'https://example.com'],
            'https sans slash, inchangé'       => ['https://example.com', 'https://example.com'],
            'http localhost avec slash'        => ['http://localhost:8000/', 'http://localhost:8000'],
            'sous-répertoire, slash retiré'    => ['https://example.com/foo/bar/', 'https://example.com/foo/bar'],
            'espaces autour trimés'            => ['  https://example.com  ', 'https://example.com'],
        ];
    }

    #[DataProvider('invalidUrlsProvider')]
    public function testInvalidUrlIsRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RootUrlValidator::validate($input);
    }

    public static function invalidUrlsProvider(): array
    {
        return [
            'pas une URL'           => ['not-a-url'],
            'sans schéma'           => ['localhost:8000'],
            'schéma ftp'            => ['ftp://example.com'],
            'schéma file'           => ['file:///etc/passwd'],
            'query string'          => ['https://example.com?foo=bar'],
            'fragment'              => ['https://example.com#section'],
            'chaîne vide'           => [''],
            'que des espaces'       => ['   '],
            'http sans host'        => ['http://'],
        ];
    }
}