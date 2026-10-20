<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\JournalManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableJournalController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-21] voir() / pdf() : id de route validé par getValidId()
 * (audit des contrôleurs) — un id non numérique ne doit jamais
 * atteindre le manager.
 */
final class JournalControllerIdTest extends TestCase
{
    #[DataProvider('idsInvalides')]
    public function testIdInvalideRedirigeSansInterrogerLaBase(string $action, string $id): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => 'ROLE_USER']);

        $manager = $this->createMock(JournalManager::class);
        $manager->expects(self::never())->method('getControleCloture');

        $request = Request::create('/journaux/' . $id);
        $request->attributes->set('id', $id);

        $response = (new TestableJournalController($session, $manager))->$action($request);

        self::assertSame('/journaux', $response->headers->get('Location'));
    }

    public static function idsInvalides(): array
    {
        return [
            'voir texte' => ['voir', 'abc'],
            'voir zéro' => ['voir', '0'],
            'pdf texte' => ['pdf', '1abc'],
            'pdf négatif' => ['pdf', '-3'],
        ];
    }
}
