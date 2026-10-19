<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\CategorieManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableCategorieController;
use Epiclub\Tests\Unit\Controller\Support\TestableCategorieControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires de CategorieController::edit() — issue #48.
 *
 * ⚠️ Périmètre : validation libelle + troncature description + sanitize
 * filename. Les scénarios nominaux (upload réel, suppression ancienne
 * image) restent couverts par la suite Integration.
 */
final class CategorieControllerHandlersTest extends TestCase
{
    // ==================================================================
    // edit() — validation libelle (commit 1)
    // ==================================================================

    public function testEditRejectsEmptyLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // ⚠️ save() NE DOIT PAS être appelé si libelle vide
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '',  // ← vide
            'description' => 'Une description',
        ]);

        $response = $controller->edit($request);
        
        // Pas de redirection : on reste sur le formulaire avec l'erreur.
        // L'assertion sur le contenu HTML est volontairement omise ici :
        // elle dépendrait du rendu Twig complet, ce qui n'est pas du
        // ressort d'un test Unit. La présence effective du message est
        // couverte par la suite Integration.
    self::assertNotInstanceOf(RedirectResponse::class, $response);
    self::assertSame(200, $response->getStatusCode());
    }

    public function testEditRejectsWhitespaceOnlyLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '   ',  // ← uniquement des espaces
            'description' => '',
        ]);

        $response = $controller->edit($request);
        
        // Pas de redirection : on reste sur le formulaire avec l'erreur.
        // L'assertion sur le contenu HTML est volontairement omise ici :
        // elle dépendrait du rendu Twig complet, ce qui n'est pas du
        // ressort d'un test Unit. La présence effective du message est
        // couverte par la suite Integration.
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditTruncatesLongLibelle(): void
    {
        $longLibelle = str_repeat('a', 50); // 50 > 32

        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return mb_strlen($c['libelle']) === 32;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => $longLibelle,
            'description' => 'desc',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }

    public function testEditTruncatesLongDescription(): void
    {
        $longDescription = str_repeat('x', 3000); // 3000 > 2000

        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return mb_strlen($c['description']) === 2000;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => 'Valide',
            'description' => $longDescription,
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }

    // ==================================================================
    // edit() — cas nominal (non-régression)
    // ==================================================================

    public function testEditAcceptsValidLibelle(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (array $c) {
                return $c['libelle'] === 'Équipement'
                    && $c['description'] === 'Court'
                    && (int) $c['est_epi'] === 1;
            }))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $request = $this->makePostRequest([
            'libelle'     => '  Équipement  ',  // ← trim attendu
            'description' => 'Court',
            'est_epi'     => '1',
        ]);

        $response = $controller->edit($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/categories', $response->headers->get('Location'));
    }
    
    // ==================================================================
    // sanitizeUploadedFilename — nettoyage du nom de fichier (commit 2)
    // ==================================================================
    
    /**
    * @return array<string, array{0:string, 1:string}>
    */
    public static function filenameSanitizeProvider(): array
    {
        return [
            'nom simple'                 => ['photo.jpg',              'photo'],
            'chemin traversant'          => ['../../evil.jpg',         'evil'],
            'chemin traversant profond'  => ['/etc/passwd/secret.jpg', 'secret'],
            // .htaccess : pathinfo('.htaccess', PATHINFO_FILENAME) = '' → fallback 'image'
            'fichier caché'              => ['.htaccess',              'image'],
            'nom vide'                   => ['',                       'image'],
            'nom uniquement ponctuation' => ['...',                    'image'],
            'caractères spéciaux'        => ['a<b>c|d.jpg',            'abcd'],
            'unicode'                    => ['中文.jpg',                'image'],
            'espaces'                    => ['   photo   .jpg',        'photo'],
            'accents'                    => ['éphémère.jpg',           'phmre'],
            'tirets et underscores'      => ['my_file-2024.jpg',       'my_file-2024'],
            'nom très long'              => [str_repeat('a', 200) . '.jpg', str_repeat('a', 100)],
            'chemin + nom très long'     => ['../../' . str_repeat('b', 300) . '.jpg', str_repeat('b', 100)],
        ];
    }
    
    #[DataProvider('filenameSanitizeProvider')]
    public function testSanitizeUploadedFilename(string $input, string $expected): void
    {
        $controller = $this->makeControllerWith();
        
    self::assertSame($expected, $controller->sanitizeUploadedFilename($input));
    }

    // ==================================================================
    // edit() — code catégorie (référencement automatique)
    // ==================================================================

    public function testEditAutoSuggestsCodeWhenEmpty(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->method('findAll')->willReturn([]);
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn(array $c) => ($c['code'] ?? null) === 'COR'))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $response = $controller->edit($this->makePostRequest([
            'libelle' => 'Cordes',
            // pas de champ 'code' → suggestion automatique
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditAutoSuggestsCodeAlternativeOnCollision(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // COR déjà pris par une AUTRE catégorie
        $manager->method('findAll')->willReturn([
            ['id' => 9, 'libelle' => 'Cordes', 'code' => 'COR'],
        ]);
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn(array $c) => ($c['code'] ?? null) === 'CO1'))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $response = $controller->edit($this->makePostRequest([
            'libelle' => 'Cordages',
            'code'    => '',
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditNormalizesManualCode(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->method('findAll')->willReturn([]);
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn(array $c) => ($c['code'] ?? null) === 'COR'))
            ->willReturn(1);

        $controller = $this->makeControllerWith($manager);

        $response = $controller->edit($this->makePostRequest([
            'libelle' => 'Cordes',
            'code'    => ' cor ',  // trim + majuscules attendus
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditRejectsInvalidCode(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $response = $controller->edit($this->makePostRequest([
            'libelle' => 'Cordes',
            'code'    => 'C',  // 1 caractère < min 2
        ]));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditRejectsDuplicateCode(): void
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        // COR déjà pris par une AUTRE catégorie (id=9)
        $manager->method('findAll')->willReturn([
            ['id' => 9, 'libelle' => 'Cordes', 'code' => 'COR'],
        ]);
        $manager->expects(self::never())->method('save');

        $controller = $this->makeControllerWith($manager);

        $response = $controller->edit($this->makePostRequest([
            'libelle' => 'Cordages',
            'code'    => 'COR',
        ]));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // [SÉCURITÉ — audit 2026-10-19 S1] Upload de l'image de catégorie
    //
    // Reproduction de la faille : avant le correctif, un fichier HTML
    // nommé « photo.jpg » était déplacé tel quel dans public/images/
    // sous le nom « photo-<uniqid>.html » (XSS stockée, même domaine).
    // ==================================================================

    public function testEditRefuseUnFichierHtmlDeguiseEnImage(): void
    {
        $dir = $this->dossierImagesTemporaire();
        $manager = $this->managerSansCategorieExistante();
        $manager->expects(self::never())->method('save');

        $controller = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withCategorieManager($manager)
            ->withImagesDir($dir)
            ->build();

        $response = $controller->edit($this->makePostRequest(
            ['libelle' => 'Baudriers', 'code' => 'BAU'],
            ['image' => $this->fichierUploade('photo.jpg', '<html><script>alert(document.cookie)</script></html>')],
        ));

        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->contenuDossier($dir), 'Aucun fichier ne doit être déposé dans le dossier public.');
        $this->supprimerDossier($dir);
    }

    public function testEditRefuseUnSvgAvecScript(): void
    {
        $dir = $this->dossierImagesTemporaire();
        $manager = $this->managerSansCategorieExistante();
        $manager->expects(self::never())->method('save');

        $controller = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withCategorieManager($manager)
            ->withImagesDir($dir)
            ->build();

        $controller->edit($this->makePostRequest(
            ['libelle' => 'Casques', 'code' => 'CAS'],
            ['image' => $this->fichierUploade('logo.png',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        ));

        self::assertSame([], $this->contenuDossier($dir));
        $this->supprimerDossier($dir);
    }

    public function testEditReencodeUneImageValide(): void
    {
        $dir = $this->dossierImagesTemporaire();
        $manager = $this->managerSansCategorieExistante();
        $manager->expects(self::once())
            ->method('save')
            ->with(self::callback(function (array $categorie): bool {
                return (bool) preg_match('/^photo-[0-9a-f]{16}\.(webp|jpg)$/', $categorie['image'] ?? '');
            }));

        $controller = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withCategorieManager($manager)
            ->withImagesDir($dir)
            ->build();

        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $response = $controller->edit($this->makePostRequest(
            ['libelle' => 'Mousquetons', 'code' => 'MOU'],
            ['image' => $this->fichierUploade('photo.png', $png)],
        ));

        self::assertInstanceOf(RedirectResponse::class, $response);
        $fichiers = $this->contenuDossier($dir);
        self::assertCount(1, $fichiers);
        self::assertMatchesRegularExpression('/\.(webp|jpg)$/', $fichiers[0]);
        $this->supprimerDossier($dir);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeControllerWith(?CategorieManager $manager = null): TestableCategorieController
    {
        $manager ??= $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $builder = (new TestableCategorieControllerBuilder($this))
            ->withSession($this->makeSession())
            ->withCategorieManager($manager);

        return $builder->build();
    }

    private function makeSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        // ⚠️ edit() exige ROLE_ADMIN
        $session->set('user', [
            'id'       => 42,
            'username' => 'admin',
            'role'     => 'ROLE_ADMIN',
        ]);
        $session->set('csrf_token', 'test-csrf-token');
        return $session;
    }

    /**
     * @param array<string,mixed> $post
     */
    private function makePostRequest(array $post, array $files = []): Request
    {
        $post['csrf_token'] = $post['csrf_token'] ?? 'test-csrf-token';

        return Request::create(
            '/admin/categories/edit',
            'POST',
            $post,
            [],
            $files,
        );
    }

    private function managerSansCategorieExistante(): CategorieManager
    {
        $manager = $this->getMockBuilder(CategorieManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $manager->method('findAll')->willReturn([]);
        return $manager;
    }

    private function dossierImagesTemporaire(): string
    {
        $dir = sys_get_temp_dir() . '/epiclub_cat_' . bin2hex(random_bytes(4)) . '/';
        mkdir($dir, 0700, true);
        return $dir;
    }

    private function fichierUploade(string $nomClient, string $contenu): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'epiclub_up_');
        file_put_contents($chemin, $contenu);
        return new UploadedFile($chemin, $nomClient, null, null, true);
    }

    /** @return string[] */
    private function contenuDossier(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private function supprimerDossier(string $dir): void
    {
        foreach ($this->contenuDossier($dir) as $f) {
            @unlink($dir . $f);
        }
        @rmdir($dir);
    }
}