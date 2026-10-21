<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Controller;

use Epiclub\Controller\ControleController;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\ControleManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Engine\Session;
use Epiclub\Tests\Unit\Controller\Support\TestableControleController;
use Epiclub\Tests\Unit\Controller\Support\TestableControleControllerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [MÉTIER 2026-10-21] Élément textile remplaçable : un équipement en
 * maintenance dont le contrôleur signale le remplacement du textile, avec
 * un contrôle OK, repart à la clôture pour 10 ans (date du contrôle
 * + 10 ans, fin de mois).
 */
final class ControleControllerTextileTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function eligibilite(): array
    {
        return [
            'textile + maintenance'      => [['textile_remplacable' => 1, 'statut' => 1], true],
            'textile + disponible'       => [['textile_remplacable' => 1, 'statut' => 0], false],
            'textile + hors service'     => [['textile_remplacable' => 1, 'statut' => 2], false],
            'sans textile, maintenance'  => [['textile_remplacable' => 0, 'statut' => 1], false],
            'colonne absente'            => [['statut' => 1], false],
        ];
    }

    /** @param array<string, mixed> $equipement */
    #[DataProvider('eligibilite')]
    public function testRemplacementPossible(array $equipement, bool $attendu): void
    {
        self::assertSame($attendu, ControleController::textileRemplacementPossible($equipement));
    }

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function nouvellesDates(): array
    {
        $ok = ['textile_remplace' => 1, 'statut' => 'controle_ok', 'date_controle' => '2026-10-21 10:00:00'];
        return [
            'remplacé, OK'                  => [$ok, '2036-10-31'],
            'date invalide (29/02/2026)'  => [['date_controle' => '2026-02-29T09:30'] + $ok, null],
            'saisie datetime-local valide'  => [['date_controle' => '2028-02-29T09:30'] + $ok, '2038-02-28'],
            'remplacé mais KO'              => [['statut' => 'controle_ko'] + $ok, null],
            'non signalé'                   => [['textile_remplace' => 0] + $ok, null],
        ];
    }

    /** @param array<string, mixed> $ligne */
    #[DataProvider('nouvellesDates')]
    public function testDateLimiteApresRemplacement(array $ligne, ?string $attendu): void
    {
        $equipement = ['textile_remplacable' => 1, 'statut' => 1];
        self::assertSame($attendu, ControleController::dateLimiteApresRemplacementTextile($ligne, $equipement));
    }

    public function testDateLimiteSansDateDeControleUtiliseLaDateDuJour(): void
    {
        $ligne = ['textile_remplace' => 1, 'statut' => 'controle_ok', 'date_controle' => null];
        self::assertSame(
            date('Y-m-t', strtotime('first day of this month +10 years')),
            ControleController::dateLimiteApresRemplacementTextile($ligne, ['textile_remplacable' => 1, 'statut' => 1])
        );
    }

    public function testEquipementPlusEnMaintenancePasDeReport(): void
    {
        $ligne = ['textile_remplace' => 1, 'statut' => 'controle_ok', 'date_controle' => '2026-10-21'];
        self::assertNull(ControleController::dateLimiteApresRemplacementTextile($ligne, ['textile_remplacable' => 1, 'statut' => 0]));
    }

    // ------------------------------------------------------------------
    // Saisie de la ligne
    // ------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string, bool, int}> */
    public static function saisies(): array
    {
        $eligible = ['textile_remplacable' => 1, 'statut' => 1];
        return [
            'éligible, OK, coché'      => [$eligible, 'controle_ok', true, 1],
            'éligible, OK, non coché'  => [$eligible, 'controle_ok', false, 0],
            'éligible, KO, coché'      => [$eligible, 'controle_ko', true, 0],
            'non éligible, OK, coché'  => [['textile_remplacable' => 0, 'statut' => 1], 'controle_ok', true, 0],
        ];
    }

    /** @param array<string, mixed> $equipement */
    #[DataProvider('saisies')]
    public function testSaisieDeLaLigne(array $equipement, string $statut, bool $coche, int $attendu): void
    {
        $lignes = $this->createMock(ControleLigneManager::class);
        $lignes->method('findId')->willReturn(['id' => 10, 'controle_id' => 5, 'equipement_id' => 42, 'statut' => 'a_controler']);
        $sauvee = null;
        $lignes->method('save')->willReturnCallback(function (array $l) use (&$sauvee) {
            $sauvee = $l;
            return true;
        });

        $post = ['statut' => $statut, 'remarque' => '', 'date_controle' => '2026-10-21 10:00:00'];
        if ($coche) {
            $post['textile_remplace'] = '1';
        }
        $this->controleur($lignes, $this->equipements(['id' => 42, 'controle_en_cours' => 0] + $equipement))
            ->updateLigne($this->requete('POST', $post));

        self::assertSame($attendu, $sauvee['textile_remplace']);
    }

    public function testLaCaseNApparaitQuePourUnEquipementEligible(): void
    {
        $lignes = $this->createMock(ControleLigneManager::class);
        $lignes->method('findId')->willReturn(['id' => 10, 'controle_id' => 5, 'equipement_id' => 42, 'statut' => 'a_controler', 'date_controle' => null, 'remarque' => '']);

        $avec = (string) $this->controleur($lignes, $this->equipements(['id' => 42, 'reference' => 'R', 'textile_remplacable' => 1, 'statut' => 1]))
            ->updateLigne($this->requete('GET'))->getContent();
        $sans = (string) $this->controleur($lignes, $this->equipements(['id' => 42, 'reference' => 'R', 'textile_remplacable' => 1, 'statut' => 0]))
            ->updateLigne($this->requete('GET'))->getContent();

        self::assertStringContainsString('name="textile_remplace"', $avec);
        self::assertStringNotContainsString('name="textile_remplace"', $sans);
    }

    // ------------------------------------------------------------------
    // Clôture
    // ------------------------------------------------------------------

    /** @return array<string, array{int, ?string}> */
    public static function clotures(): array
    {
        return [
            'textile remplacé → +10 ans'  => [1, '2036-10-31'],
            'non signalé → date conservée' => [0, '2027-01-31'],
        ];
    }

    #[DataProvider('clotures')]
    public function testClotureReporteLaDateLimite(int $textileRemplace, string $attendue): void
    {
        $lignes = $this->createMock(ControleLigneManager::class);
        $lignes->method('findByControle')->willReturn([[
            'id' => 10, 'controle_id' => 5, 'equipement_id' => 42, 'statut' => 'controle_ok',
            'remarque' => '', 'date_controle' => '2026-10-21 10:00:00', 'textile_remplace' => $textileRemplace,
        ]]);
        $equipements = $this->createMock(EquipementManager::class);
        $equipements->method('findId')->willReturn([
            'id' => 42, 'statut' => 1, 'controle_en_cours' => 1,
            'textile_remplacable' => 1, 'date_fin_utilisation' => '2027-01-31',
        ]);
        $sauve = null;
        $equipements->method('save')->willReturnCallback(function (array $e) use (&$sauve) {
            $sauve = $e;
            return true;
        });

        $this->controleur($lignes, $equipements)->cloturer($this->requete('POST', ['id' => '5']));

        self::assertSame($attendue, $sauve['date_fin_utilisation']);
        self::assertSame(0, $sauve['statut'], 'Contrôle OK → disponible');
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $equipement */
    private function equipements(array $equipement): EquipementManager
    {
        $m = $this->createMock(EquipementManager::class);
        $m->method('findId')->willReturn($equipement);
        return $m;
    }

    private function controleur(ControleLigneManager $lignes, EquipementManager $equipements): TestableControleController
    {
        $controles = $this->createMock(ControleManager::class);
        $controles->method('findId')->willReturn([
            'id' => 5, 'statut' => 'ouvert', 'controleur_id' => 42,
            'date_debut' => date('Y-m-d H:i:s'), 'hash_remarques' => null,
        ]);

        $session = new Session(new MockArraySessionStorage());
        $session->set('user', ['id' => 42, 'username' => 'c', 'role' => 'ROLE_CONTROLLEUR', 'controle_en_cours_id' => null]);
        $session->set('csrf_token', 'jeton');

        return (new TestableControleControllerBuilder($this))
            ->withSession($session)
            ->withControleManager($controles)
            ->withControleLigneManager($lignes)
            ->withEquipementManager($equipements)
            ->build();
    }

    /** @param array<string, string> $post */
    private function requete(string $methode, array $post = []): Request
    {
        $r = Request::create('/admin/controles/update-ligne/10', $methode, $methode === 'POST' ? $post + ['csrf_token' => 'jeton'] : []);
        $r->attributes->set('id', $methode === 'POST' && isset($post['id']) ? $post['id'] : '10');
        return $r;
    }
}
