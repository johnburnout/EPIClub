<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller\Support;

use Epiclub\Controller\EquipementController;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\ReferenceGenerator;
use Epiclub\Engine\ImageUploader;
use Epiclub\Engine\Session;

final class TestableEquipementController extends EquipementController
{
    public function __construct(
        Session $session,
        private readonly EquipementManager $equipementManager,
        private readonly CategorieManager $categorieManager,
        private readonly EmplacementManager $emplacementManager,
        private readonly AcquisitionManager $acquisitionManager,
        private readonly ?string $publicPathOverride = null,
    ) {
        parent::__construct($session);
    }

    /** [Photo groupée] Dossier public temporaire pour les photos. */
    protected function getPublicPath(string $subpath = ''): string
    {
        if ($this->publicPathOverride === null) {
            return parent::getPublicPath($subpath);
        }
        $subpath = ltrim($subpath, '/');
        return $subpath === '' ? $this->publicPathOverride : $this->publicPathOverride . '/' . $subpath;
    }

    /** Doublure optionnelle de l'enregistrement des photos. */
    public ?ImageUploader $photoUploaderOverride = null;

    protected function photoUploader(): ImageUploader
    {
        return $this->photoUploaderOverride ?? parent::photoUploader();
    }

    /** [2026-10-24] Doublure optionnelle du générateur de références. */
    public ?ReferenceGenerator $referenceGeneratorOverride = null;

    protected function referenceGenerator(): ReferenceGenerator
    {
        return $this->referenceGeneratorOverride ?? parent::referenceGenerator();
    }

    protected function equipementManager(): EquipementManager
    {
        return $this->equipementManager;
    }

    protected function categorieManager(): CategorieManager
    {
        return $this->categorieManager;
    }

    protected function emplacementManager(): EmplacementManager
    {
        return $this->emplacementManager;
    }

    protected function acquisitionManager(): AcquisitionManager
    {
        return $this->acquisitionManager;
    }

    /**
     * [audit 2026-10-19 S5/Q2] Pas de synchronisation de session en test
     * Unit : l'implémentation parente ouvre une vraie connexion MySQL.
     */
    protected function updateLastActivity()
    {
    }
}
