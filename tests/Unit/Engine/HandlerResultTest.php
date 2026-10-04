<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\HandlerResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests unitaires de HandlerResult (Vague 7).
 *
 * Vérifie le contrat du DTO retourné par les handlers :
 *  - constructeur (toutes les valeurs fournies)
 *  - valeurs par défaut (arrays vides)
 *  - isRedirect() (true si response non-null, false sinon)
 *  - immutabilité (readonly)
 *
 * Aucune dépendance externe : le DTO est pur, testable en isolation.
 */
final class HandlerResultTest extends TestCase
{
    // ==================================================================
    // Constructeur
    // ==================================================================

    public function testConstructorStoresAllProvidedValues(): void
    {
        $response = new RedirectResponse('/admin/acquisitions');
        $acquisition = ['id' => 7, 'facture_reference' => 'REF-001'];
        $formErrors = ['facture_reference' => 'obligatoire'];
        $ligneData = ['reference' => 'LIGNE-001', 'nombre' => 3];

        $result = new HandlerResult($response, $acquisition, $formErrors, $ligneData);

        self::assertSame($response, $result->response);
        self::assertSame($acquisition, $result->acquisition);
        self::assertSame($formErrors, $result->formErrors);
        self::assertSame($ligneData, $result->ligneData);
    }

    public function testConstructorDefaultsToEmptyArrays(): void
    {
        $result = new HandlerResult(null);

        self::assertNull($result->response);
        self::assertSame([], $result->acquisition);
        self::assertSame([], $result->formErrors);
        self::assertSame([], $result->ligneData);
    }

    public function testConstructorAcceptsOnlyResponse(): void
    {
        $response = new Response('OK');

        $result = new HandlerResult($response);

        self::assertSame($response, $result->response);
        self::assertSame([], $result->acquisition);
        self::assertSame([], $result->formErrors);
        self::assertSame([], $result->ligneData);
    }

    // ==================================================================
    // isRedirect()
    // ==================================================================

    public function testIsRedirectReturnsTrueWhenResponseProvided(): void
    {
        $result = new HandlerResult(new RedirectResponse('/admin/acquisitions'));

        self::assertTrue($result->isRedirect());
    }

    public function testIsRedirectReturnsTrueForPlainResponse(): void
    {
        // Le DTO ne se limite pas aux RedirectResponse : toute Response
        // non-null déclenche isRedirect() === true (le nom est un peu
        // trompeur mais c'est le contrat : "response présente").
        $result = new HandlerResult(new Response('OK'));

        self::assertTrue($result->isRedirect());
    }

    public function testIsRedirectReturnsFalseWhenResponseNull(): void
    {
        $result = new HandlerResult(null);

        self::assertFalse($result->isRedirect());
    }

    public function testIsRedirectReturnsFalseWithOnlyAcquisition(): void
    {
        // Cas typique d'erreur de formulaire : pas de Response, mais
        // une acquisition à réafficher.
        $result = new HandlerResult(
            null,
            ['fournisseur_nom' => 'Test'],
            ['facture_reference' => 'obligatoire'],
        );

        self::assertFalse($result->isRedirect());
    }

    // ==================================================================
    // Immutabilité (readonly)
    // ==================================================================

    public function testResponseIsReadonly(): void
    {
        $result = new HandlerResult(null);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $result->response = new Response('hack');
    }

    public function testAcquisitionIsReadonly(): void
    {
        $result = new HandlerResult(null, ['id' => 1]);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $result->acquisition = ['id' => 2];
    }

    public function testFormErrorsIsReadonly(): void
    {
        $result = new HandlerResult(null);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $result->formErrors = ['x' => 'y'];
    }

    public function testLigneDataIsReadonly(): void
    {
        $result = new HandlerResult(null);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $result->ligneData = ['x' => 'y'];
    }
}