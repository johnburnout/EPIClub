<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Domain;

use Epiclub\Domain\PretRegles;
use Epiclub\Enum\PretStatut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [Prêt de matériel — migration 016] Règles métier pures.
 */
final class PretReglesTest extends TestCase
{
    private const JOUR = '2026-10-21';

    /** @return array<string, array{array<string, mixed>, ?array<string, mixed>, ?string}> */
    public static function eligibilite(): array
    {
        $ok = ['id' => 7, 'reference' => 'CAS007', 'statut' => 0, 'controle_en_cours' => 0, 'date_fin_utilisation' => '2030-01-31'];
        return [
            'disponible'             => [$ok, null, null],
            'sans date limite'       => [['date_fin_utilisation' => null] + $ok, null, null],
            'en maintenance'         => [['statut' => 1] + $ok, null, 'statut « En maintenance »'],
            'hors service'           => [['statut' => 2] + $ok, null, 'statut « Hors service »'],
            'déjà prêté'             => [['statut' => 3] + $ok, null, 'statut « Prêté »'],
            'égaré'                  => [['statut' => 4] + $ok, null, 'statut « Égaré »'],
            'en contrôle'            => [['controle_en_cours' => 1] + $ok, null, 'en cours de contrôle'],
            'date limite atteinte'   => [['date_fin_utilisation' => self::JOUR] + $ok, null, "date limite d'utilisation"],
            'sur un autre prêt'      => [$ok, ['id' => 12, 'emprunteur_prenom' => 'Ana', 'emprunteur_nom' => 'Roc'], 'prêt n° 12 (Ana Roc)'],
            'supprimé'               => [['deleted_at' => '2026-01-01 10:00:00'] + $ok, null, 'supprimé'],
        ];
    }

    /**
     * @param array<string, mixed>      $equipement
     * @param array<string, mixed>|null $autre
     */
    #[DataProvider('eligibilite')]
    public function testMotifIneligibilite(array $equipement, ?array $autre, ?string $attendu): void
    {
        $motif = PretRegles::motifIneligibilite($equipement, $autre, self::JOUR);
        if ($attendu === null) {
            self::assertNull($motif);
        } else {
            self::assertNotNull($motif);
            self::assertStringContainsString($attendu, $motif);
            self::assertStringStartsWith('CAS007', $motif);
        }
    }

    private function saisieComplete(): array
    {
        return [
            'emprunteur_nom' => ' Dupont ',
            'emprunteur_prenom' => 'Marie',
            'emprunteur_telephone' => '06 12 34 56 78',
            'emprunteur_licence' => 'FFME-123',
            'date_pret' => '2026-10-21',
            'date_retour_prevue' => '2026-10-28',
            'remarques' => '',
        ];
    }

    public function testLireEmprunteurEnSaisieLitTousLesChamps(): void
    {
        [$data, $erreurs] = PretRegles::lireEmprunteur($this->saisieComplete(), PretStatut::SAISIE);

        self::assertSame([], $erreurs);
        self::assertSame('Dupont', $data['emprunteur_nom']);
        self::assertSame('06 12 34 56 78', $data['emprunteur_telephone']);
        self::assertSame('2026-10-28', $data['date_retour_prevue']);
        self::assertNull($data['remarques']);
    }

    public function testLireEmprunteurEnSaisieExigeNomPrenomEtDates(): void
    {
        [, $erreurs] = PretRegles::lireEmprunteur(
            ['emprunteur_nom' => '', 'emprunteur_prenom' => '', 'date_pret' => '2026-02-30', 'date_retour_prevue' => ''],
            PretStatut::SAISIE
        );

        self::assertArrayHasKey('emprunteur_nom', $erreurs);
        self::assertArrayHasKey('emprunteur_prenom', $erreurs);
        self::assertArrayHasKey('date_pret', $erreurs);
        self::assertArrayHasKey('date_retour_prevue', $erreurs);
        // Téléphone et licence : facultatifs en saisie (exigés à la validation).
        self::assertArrayNotHasKey('emprunteur_telephone', $erreurs);
        self::assertArrayNotHasKey('emprunteur_licence', $erreurs);
    }

    public function testRetourPrevuAvantLePretRefuse(): void
    {
        [, $erreurs] = PretRegles::lireEmprunteur(
            ['date_retour_prevue' => '2026-10-20'] + $this->saisieComplete(),
            PretStatut::SAISIE
        );

        self::assertArrayHasKey('date_retour_prevue', $erreurs);
    }

    public function testTelephoneInvalideRefuse(): void
    {
        [, $erreurs] = PretRegles::lireEmprunteur(
            ['emprunteur_telephone' => '06<script>'] + $this->saisieComplete(),
            PretStatut::SAISIE
        );

        self::assertArrayHasKey('emprunteur_telephone', $erreurs);
    }

    public function testPretEnCoursSeulLeTelephoneEstLu(): void
    {
        [$data, $erreurs] = PretRegles::lireEmprunteur(
            ['emprunteur_nom' => 'Pirate', 'emprunteur_telephone' => '+33 6 00 00 00 00'] + $this->saisieComplete(),
            PretStatut::EN_COURS
        );

        self::assertSame([], $erreurs);
        self::assertSame(['emprunteur_telephone' => '+33 6 00 00 00 00'], $data);
    }

    public function testPretEnCoursTelephoneObligatoire(): void
    {
        [, $erreurs] = PretRegles::lireEmprunteur(['emprunteur_telephone' => ' '], PretStatut::EN_COURS);

        self::assertArrayHasKey('emprunteur_telephone', $erreurs);
    }

    public function testPretTermineRienNEstLu(): void
    {
        self::assertSame([[], []], PretRegles::lireEmprunteur($this->saisieComplete(), PretStatut::TERMINE));
    }

    public function testErreursValidation(): void
    {
        $pret = ['statut' => 'saisie'] + $this->saisieComplete();

        self::assertSame([], PretRegles::erreursValidation($pret, [['id' => 1]]));

        $erreurs = PretRegles::erreursValidation(['emprunteur_licence' => '', 'emprunteur_telephone' => ''] + $pret, []);
        self::assertCount(2, $erreurs);
        self::assertStringContainsString('le téléphone, le numéro de licence', $erreurs[0]);
        self::assertStringContainsString('au moins un matériel', $erreurs[1]);

        self::assertNotSame([], PretRegles::erreursValidation(['statut' => 'en_cours'] + $pret, [['id' => 1]]));
    }

    public function testSignaturePngAcceptee(): void
    {
        $image = imagecreatetruecolor(10, 5);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $dataUrl = 'data:image/png;base64,' . base64_encode($png);

        self::assertSame([$dataUrl, null], PretRegles::lireSignature($dataUrl));
    }

    public function testSignatureVideFacultative(): void
    {
        self::assertSame([null, null], PretRegles::lireSignature(''));
        self::assertSame([null, null], PretRegles::lireSignature(null));
    }

    /** @return array<string, array{mixed}> */
    public static function signaturesInvalides(): array
    {
        return [
            'autre type'     => ['data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>')],
            'pas un png'     => ['data:image/png;base64,' . base64_encode('GIF89a...')],
            'base64 invalide' => ['data:image/png;base64,@@@'],
            'trop lourde'    => ['data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('A', PretRegles::SIGNATURE_TAILLE_MAX))],
        ];
    }

    #[DataProvider('signaturesInvalides')]
    public function testSignatureInvalideRefusee(mixed $brut): void
    {
        [$signature, $erreur] = PretRegles::lireSignature($brut);

        self::assertNull($signature);
        self::assertNotNull($erreur);
    }

    public function testEnRetard(): void
    {
        self::assertTrue(PretRegles::estEnRetard(['statut' => 'en_cours', 'date_retour_prevue' => '2026-10-20'], self::JOUR));
        self::assertFalse(PretRegles::estEnRetard(['statut' => 'en_cours', 'date_retour_prevue' => self::JOUR], self::JOUR));
        self::assertFalse(PretRegles::estEnRetard(['statut' => 'termine', 'date_retour_prevue' => '2026-10-01'], self::JOUR));
        self::assertFalse(PretRegles::estEnRetard(['statut' => 'saisie', 'date_retour_prevue' => '2026-10-01'], self::JOUR));
    }
}
