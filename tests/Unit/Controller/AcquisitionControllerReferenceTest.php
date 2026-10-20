<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * [2026-10-20] Proposition de référence (AJAX) à la saisie d'une ligne.
 */
final class AcquisitionControllerReferenceTest extends TestCase
{
    public function testSuggestionUtiliseLAnneeDeLaFacture(): void
    {
        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturn(['id' => 9, 'facture_date' => '2025-12-30']);

        $controller = (new TestableAcquisitionControllerBuilder($this))
            ->withAcquisitionManager($acquisitions)
            ->build();

        $generateur = $this->createMock(ReferenceGenerator::class);
        $generateur->expects(self::once())->method('suggest')
            ->with('Cordes', 2025)->willReturn('25COR004');
        $controller->referenceGeneratorMock = $generateur;

        $response = $controller->suggestionReference(
            Request::create('/admin/acquisitions/reference-suggestion?categorie=Cordes&acquisition=9')
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['reference' => '25COR004'], json_decode((string) $response->getContent(), true));
    }

    public function testSansCategoriePasDeProposition(): void
    {
        $controller = (new TestableAcquisitionControllerBuilder($this))->build();

        $response = $controller->suggestionReference(Request::create('/admin/acquisitions/reference-suggestion?categorie='));

        self::assertSame(['reference' => null], json_decode((string) $response->getContent(), true));
    }
}
