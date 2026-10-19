<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\IndexController;
use Epiclub\Engine\ConfigProvider;
use Epiclub\Engine\EnvironmentFileParser;
use Epiclub\Engine\MailerServiceInterface;
use Epiclub\Engine\Session;
use Symfony\Component\HttpFoundation\Request;

/**
 * [REFACTOR #74] Sous-classe de test pour IndexController.
 *
 * Pattern Vague 14 :
 *  - appelle le VRAI parent::__construct() ;
 *  - surcharge updateLastActivity() en no-op ;
 *  - surcharge validateCsrf() en no-op (CSRF testé isolément) ;
 *  - injecte un mock EnvironmentFileParser et/ou MailerServiceInterface
 *    via surcharge des factories.
 */
final class TestableIndexController extends IndexController
{
    public function __construct(
        Session $session,
        private ?EnvironmentFileParser $environmentFileParserMock = null,
        private ?MailerServiceInterface $mailerServiceMock = null,
        private ?ConfigProvider $configProviderMock = null,
        private bool $configThrows = false,
        private ?string $environmentFilePathOverride = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function environmentFileParser(string $path): EnvironmentFileParser
    {
        return $this->environmentFileParserMock ?? parent::environmentFileParser($path);
    }

    protected function environmentFilePath(): string
    {
        return $this->environmentFilePathOverride ?? parent::environmentFilePath();
    }

    protected function mailerService(ConfigProvider $config): MailerServiceInterface
    {
        return $this->mailerServiceMock ?? parent::mailerService($config);
    }

    protected function configProvider(): ConfigProvider
    {
        // [#101] Simule un fichier de config absent/illisible : le
        // contrôleur doit retomber sur des valeurs vides (iso-comportement
        // avec getenv/$_ENV ?? '').
        if ($this->configThrows) {
            throw new \RuntimeException('Configuration file not found (simulation test #101)');
        }
        return $this->configProviderMock ?? parent::configProvider();
    }

    protected function validateCsrf(Request $request): void
    {
        // no-op : CSRF testé isolément dans CsrfValidatorTest.
    }
}