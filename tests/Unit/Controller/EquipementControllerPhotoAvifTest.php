<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\EmplacementManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\ImageUploader;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableEquipementController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-21] Photo AVIF sur un serveur qui ne la lit pas : la fiche
 * affiche le message explicite d'ImageUploader (et non l'erreur
 * générique), et rien n'est enregistré.
 */
final class EquipementControllerPhotoAvifTest extends TestCase
{
    public function testMessageAvifAfficheDansLaFiche(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 1, 'username' => 'u', 'role' => 'ROLE_ADMIN']);
        $session->set('csrf_token', 'csrf');

        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn([
            'id' => 7, 'reference' => 'R7', 'libelle' => 'Casque', 'categorie_id' => null,
            'statut' => 0, 'controle_en_cours' => 0, 'photo' => null, 'remarques' => null, 'acquisition_id' => null,
        ]);
        $equipements->expects(self::never())->method('save');
        $categories = $this->createMock(CategorieManager::class);
        $categories->method('findAll')->willReturn([]);
        $emplacements = $this->createMock(EmplacementManager::class);
        $emplacements->method('findAll')->willReturn([]);

        $dir = sys_get_temp_dir() . '/epiclub_avif_ctrl_' . bin2hex(random_bytes(4)) . '/';
        $controleur = new TestableEquipementController(
            $session, $equipements, $categories, $emplacements, $this->createMock(AcquisitionManager::class)
        );
        $controleur->photoUploaderOverride = new ImageUploader($dir, 1000, false);

        $source = tempnam(sys_get_temp_dir(), 'avif');
        copy(__DIR__ . '/../../Fixtures/images/rouge-64x48.avif', $source);
        $requete = Request::create('/equipements/equipement_modification-7', 'POST', [
            'csrf_token' => 'csrf', 'statut' => '0', 'emplacement_id' => '', 'remarques' => '',
        ], [], ['photo' => new UploadedFile($source, 'photo.avif', null, null, true)]);
        $requete->attributes->set('id', '7');

        $html = (string) $controleur->edit($requete)->getContent();
        @unlink($source);

        self::assertStringContainsString(
            htmlspecialchars(ImageUploader::MESSAGE_AVIF_NON_PRIS_EN_CHARGE, ENT_QUOTES),
            $html
        );
        self::assertDirectoryDoesNotExist($dir);
    }
}
