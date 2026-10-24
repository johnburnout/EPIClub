<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Domain\AcquisitionLigneManager;
use Epiclub\Domain\AcquisitionManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\FournisseurManager;
use Epiclub\Engine\Session;
use Epiclub\Exception\AccessDeniedException;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionController;
use Epiclub\Tests\Unit\Controller\Support\TestableAcquisitionControllerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [2026-10-24] Correction de la référence et de la date de facture d'une
 * acquisition par un super-administrateur, depuis sa fiche : unicité de la
 * référence, mots REFERENCE et DATE, dates des équipements réalignées et
 * dates manuelles réinterrogées.
 */
final class AcquisitionControllerCorrectionTest extends TestCase
{
    private Session $session;
    private ?array $acquisitionSauvee = null;
    /** @var array<int, array> */
    private array $equipementsSauves = [];

    private const ACQUISITION = [
        'id' => 9, 'fournisseur_id' => 1, 'facture_reference' => 'FAC-001', 'facture_date' => '2024-05-14',
        'facture_document' => 'f.pdf', 'saisie_par' => 1, 'est_validee' => 1,
    ];

    private function controller(string $role): TestableAcquisitionController
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('user', ['id' => 1, 'username' => 'x', 'role' => $role]);
        $this->session->set('csrf_token', 'csrf');

        $acquisitions = $this->createMock(AcquisitionManager::class);
        $acquisitions->method('findId')->willReturnCallback(fn (int $id) => $id === 9 ? self::ACQUISITION : null);
        $acquisitions->method('findOneByCriteria')->willReturnCallback(fn (array $c) => match ($c['facture_reference'] ?? null) {
            'FAC-001' => self::ACQUISITION,
            'FAC-PRISE' => ['id' => 3, 'facture_reference' => 'FAC-PRISE'],
            default => null,
        });
        $acquisitions->method('save')->willReturnCallback(function (array $a) {
            $this->acquisitionSauvee = $a;
            return true;
        });
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findByAcquisition')->willReturn([
            ['id' => 1, 'reference' => '24CAS001', 'libelle' => 'Casque', 'date_mise_en_service' => '2024-05-14', 'date_fin_utilisation' => '2034-05-31'],
            ['id' => 2, 'reference' => '24COR001', 'libelle' => 'Corde', 'date_mise_en_service' => '2024-06-01', 'date_fin_utilisation' => null],
        ]);
        $equipements->method('save')->willReturnCallback(function (array $e) {
            $this->equipementsSauves[$e['id']] = $e;
            return true;
        });
        $lignes = $this->createMock(AcquisitionLigneManager::class);
        $lignes->method('findByAcquisition')->willReturn([]);
        $fournisseurs = $this->createMock(FournisseurManager::class);
        $fournisseurs->method('findAll')->willReturn([]);

        return (new TestableAcquisitionControllerBuilder($this))
            ->withSession($this->session)
            ->withAcquisitionManager($acquisitions)
            ->withEquipementManager($equipements)
            ->withAcquisitionLigneManager($lignes)
            ->withFournisseurManager($fournisseurs)
            ->build();
    }

    private function corriger(array $post, string $role = 'ROLE_SUPER_ADMIN', string $csrf = 'csrf'): Response
    {
        $request = Request::create('/admin/acquisitions/acquisition_corriger-9', 'POST', $post + ['csrf_token' => $csrf]);
        $request->attributes->set('id', '9');
        return $this->controller($role)->corriger($request);
    }

    private function flash(string $type): string
    {
        return implode(' | ', $this->session->getFlashBag()->peek($type));
    }

    private function fiche(string $role): string
    {
        $request = Request::create('/admin/acquisitions/acquisition-9');
        $request->attributes->set('id', '9');
        return (string) $this->controller($role)->show($request)->getContent();
    }

    // ---------------- Fiche ----------------

    public function testRoueDenteeEtDatesManuellesSurLaFicheDuSuperAdmin(): void
    {
        $html = $this->fiche('ROLE_SUPER_ADMIN');

        self::assertStringContainsString('id="afficher-correction"', $html);
        self::assertStringContainsString('class="card mt-2 border-warning d-none" id="carte-correction"', $html);
        self::assertStringContainsString('<code>REFERENCE</code>', $html);
        self::assertStringContainsString('<code>DATE</code>', $html);
        // Seule la corde a une date saisie à la main.
        self::assertStringContainsString('name="date_mise_en_service[2]" value="2024-06-01"', $html);
        self::assertStringNotContainsString('date_mise_en_service[1]', $html);
    }

    public function testPasDeCorrectionPourUnAdmin(): void
    {
        self::assertStringNotContainsString('id="carte-correction"', $this->fiche('ROLE_ADMIN'));
    }

    // ---------------- Correction ----------------

    public function testReferenceSeule(): void
    {
        $response = $this->corriger([
            'facture_reference' => ' FAC-002 ', 'confirmation_reference' => 'REFERENCE', 'facture_date' => '2024-05-14',
        ]);

        self::assertSame('/admin/acquisitions/acquisition-9', $response->headers->get('Location'));
        self::assertSame(['id' => 9, 'facture_reference' => 'FAC-002', 'facture_date' => '2024-05-14'], $this->acquisitionSauvee);
        self::assertSame([], $this->equipementsSauves);
        self::assertStringContainsString('« FAC-001 » → « FAC-002 »', $this->flash('success'));
    }

    public function testDateAvecDatesReallignees(): void
    {
        $this->corriger([
            'facture_reference' => 'FAC-001', 'facture_date' => '2024-04-02', 'confirmation_date' => 'DATE',
            'date_mise_en_service' => ['2' => '2024-06-01'],
        ]);

        self::assertSame('2024-04-02', $this->acquisitionSauvee['facture_date']);
        self::assertSame(
            ['id' => 1, 'date_mise_en_service' => '2024-04-02', 'date_fin_utilisation' => '2034-04-30'],
            $this->equipementsSauves[1]
        );
        self::assertArrayNotHasKey(2, $this->equipementsSauves); // date manuelle confirmée telle quelle
        self::assertStringContainsString('1 équipement(s)', $this->flash('success'));
    }

    public function testRefus(): void
    {
        $cas = [
            'référence prise' => [['facture_reference' => 'FAC-PRISE', 'confirmation_reference' => 'REFERENCE'], 'déjà utilisée'],
            'référence vide' => [['facture_reference' => '', 'confirmation_reference' => 'REFERENCE'], 'obligatoire'],
            'mot REFERENCE' => [['facture_reference' => 'FAC-002', 'confirmation_reference' => 'reference'], 'saisissez le mot REFERENCE'],
            'mot DATE' => [['facture_date' => '2024-04-02', 'confirmation_date' => '', 'date_mise_en_service' => ['2' => '2024-06-01']], 'saisissez le mot DATE'],
            'date invalide' => [['facture_date' => '2024-02-30', 'confirmation_date' => 'DATE'], 'invalide'],
            'date manuelle non confirmée' => [['facture_date' => '2024-04-02', 'confirmation_date' => 'DATE'], 'à confirmer'],
            'mise en service trop tôt' => [['facture_date' => '2024-07-01', 'confirmation_date' => 'DATE', 'date_mise_en_service' => ['2' => '2024-06-01']], 'ne peut pas précéder'],
        ];
        foreach ($cas as $nom => [$post, $message]) {
            $this->acquisitionSauvee = null;
            $this->equipementsSauves = [];
            $response = $this->corriger($post + ['facture_reference' => 'FAC-001', 'facture_date' => '2024-05-14']);
            self::assertNull($this->acquisitionSauvee, $nom);
            self::assertSame([], $this->equipementsSauves, $nom);
            self::assertSame('/admin/acquisitions/acquisition-9', $response->headers->get('Location'), $nom);
            self::assertStringContainsString($message, $this->flash('error'), $nom);
        }
    }

    public function testRienAModifier(): void
    {
        $this->corriger(['facture_reference' => 'FAC-001', 'facture_date' => '2024-05-14']);

        self::assertNull($this->acquisitionSauvee);
        self::assertStringContainsString('Aucune modification', $this->flash('note'));
    }

    public function testReserveeAuSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->corriger(['facture_reference' => 'FAC-002', 'confirmation_reference' => 'REFERENCE'], 'ROLE_ADMIN');
    }

    public function testCsrfObligatoire(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->corriger(['facture_reference' => 'FAC-002'], 'ROLE_SUPER_ADMIN', 'faux');
    }

    // ---------------- Vérification à la volée ----------------

    private function disponible(string $reference, string $role = 'ROLE_SUPER_ADMIN'): array
    {
        $response = $this->controller($role)->referenceFactureDisponible(
            Request::create('/admin/acquisitions/reference-facture-disponible', 'GET', ['id' => '9', 'reference' => $reference])
        );
        return json_decode((string) $response->getContent(), true);
    }

    public function testVerificationALaVolee(): void
    {
        self::assertTrue($this->disponible('FAC-LIBRE')['disponible']);
        self::assertFalse($this->disponible('FAC-PRISE')['disponible']);
        self::assertSame("C'est la référence actuelle.", $this->disponible('FAC-001')['message']);
        self::assertFalse($this->disponible('')['disponible']);
    }

    public function testVerificationReserveeAuSuperAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->disponible('FAC-LIBRE', 'ROLE_ADMIN');
    }
}
