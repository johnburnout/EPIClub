<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\CategorieController;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;

final class TestableCategorieController extends CategorieController
{
    public function __construct(
        Session $session,
        private readonly CategorieManager $categorieManager,
        private readonly ?string $imagesDir = null,
    ) {
        parent::__construct($session);
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    /** [2026-10-25] Doublure du comptage des équipements ; sans elle, pas de comptage (pas de PDO). */
    public ?EquipementManager $equipementManagerOverride = null;

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManagerOverride ?? throw new \RuntimeException('Pas d\'équipements en test.');
    }
    
    /**
     * [audit 2026-10-19 Q2] Pas d'écriture last_activity en test Unit :
     * l'implémentation parente ouvre une vraie connexion MySQL.
     */
    protected function updateLastActivity()
    {
    }

    /**
     * [audit 2026-10-19 S1] Dossier temporaire en test : jamais public/images/.
     */
    protected function imagesDir(): string
    {
        return $this->imagesDir ?? sys_get_temp_dir() . '/epiclub_test_categories/';
    }

    public function sanitizeUploadedFilename(string $originalName): string
    {
        return parent::sanitizeUploadedFilename($originalName);
    }
}