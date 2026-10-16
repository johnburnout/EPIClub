<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\QrRedirectController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;

/**
 * [REFACTOR #73] Sous-classe de test pour QrRedirectController.
 *
 * Pattern Vague 14 (comme TestableJournalController) :
 *  - appelle le VRAI parent::__construct() (teste le constructeur réel) ;
 *  - surcharge updateLastActivity() en no-op (évite new UtilisateurManager()
 *    → PDO) ;
 *  - injecte des mocks des managers via surcharge des factories.
 *
 * QrCodeGenerator et QrCodeReader ne sont pas surchargés : ils ne
 * sont pas mockables (pas d'interface).
 *
 * [#94] La factory utilisateurManager() a disparu du contrôleur
 * (code mort) — le mock correspondant est retiré du constructeur.
 *
 * [#95 - Dette 13] Les actions retournent désormais des Response :
 * elles sont testables en Unit (plus de header()+exit).
 */
final class TestableQrRedirectController extends QrRedirectController
{
    public function __construct(
        Session $session,
        private ?EquipementManager $equipementManagerMock = null,
        private ?ControleManager $controleManagerMock = null,
        private ?ControleLigneManager $controleLigneManagerMock = null,
    ) {
        parent::__construct($session);
    }

    protected function updateLastActivity(): void
    {
        // no-op : évite new UtilisateurManager() → PDO
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManagerMock ?? parent::equipementManager();
    }

    protected function controleManager(): ControleManager
    {
        return $this->controleManagerMock ?? parent::controleManager();
    }

    protected function controleLigneManager(): ControleLigneManager
    {
        return $this->controleLigneManagerMock ?? parent::controleLigneManager();
    }
}
