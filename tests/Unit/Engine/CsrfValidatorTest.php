<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\CsrfValidator;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

final class CsrfValidatorTest extends TestCase
{
    /**
     * Construit un mock de Session avec un jeton CSRF donné et une FlashBag
     * fonctionnelle (add() sans erreur).
     */
    /**
    * Construit un mock de Session avec un jeton CSRF donné et une FlashBag
    * fonctionnelle (add() sans erreur).
    */
    private function makeSession(?string $token): Session
    {
        // FlashBagInterface::add() a un type de retour void.
        // PHPUnit 11 refuse willReturn() sur void → on laisse le mock
        // par défaut (qui ne fait rien) ou on utilise willReturnCallback().
        $flashBag = $this->createMock(FlashBagInterface::class);
        
        $session = $this->createMock(Session::class);
        $session->method('get')
        ->with('csrf_token')
        ->willReturn($token);
        $session->method('getFlashBag')
        ->willReturn($flashBag);
        
        return $session;
    }

    // ==================================================================
    // Branches « pas d'exception »
    // ==================================================================

    public function testGetNeLevePasDException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'GET');
        
        $validator->validate($request);
        
        $this->addToAssertionCount(1); // pas d'exception = succès
    }
    
    public function testPostAvecTokenValideDansLeCorpsNeLevePasDException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST', ['csrf_token' => 'secret']);
        
        $validator->validate($request);
        
        $this->addToAssertionCount(1);
    }
    
    public function testPostAvecTokenValideViaHeaderNeLevePasDException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST');
        $request->headers->set('X-CSRF-Token', 'secret');
        
        $validator->validate($request);
        
        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // Branches « AccessDeniedException »
    // ==================================================================

    public function testPostSansTokenLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Jeton CSRF invalide.');

        $validator->validate($request);
    }

    public function testPostAvecTokenInvalideLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST', ['csrf_token' => 'mauvais']);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Jeton CSRF invalide.');

        $validator->validate($request);
    }

    public function testPostAvecTokenSessionVideLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession(''));
        $request = Request::create('/foo', 'POST', ['csrf_token' => 'peu importe']);

        $this->expectException(AccessDeniedException::class);

        $validator->validate($request);
    }

    public function testPostAvecTokenSessionNullLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession(null));
        $request = Request::create('/foo', 'POST', ['csrf_token' => 'peu importe']);

        $this->expectException(AccessDeniedException::class);

        $validator->validate($request);
    }

    public function testPostAvecTokenSoumisVideLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST', ['csrf_token' => '']);

        $this->expectException(AccessDeniedException::class);

        $validator->validate($request);
    }

    public function testPostAvecSeulementEspaceCommeTokenLeveAccessDeniedException(): void
    {
        $validator = new CsrfValidator($this->makeSession('secret'));
        $request = Request::create('/foo', 'POST', ['csrf_token' => '   ']);

        $this->expectException(AccessDeniedException::class);

        $validator->validate($request);
    }
}