<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine;

use Epiclub\Engine\SignatureMinisign;
use Epiclub\Tests\Unit\Support\SignataireMinisign;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [audit 2026-11-08 R1] Vérification des signatures minisign des releases.
 */
final class SignatureMinisignTest extends TestCase
{
    private const NOM = 'epiclub-v2.12.0.zip';
    private const COMMENTAIRE = 'timestamp:1762675200	file:epiclub-v2.12.0.zip	hashed';

    private SignataireMinisign $signataire;

    protected function setUp(): void
    {
        $this->signataire = new SignataireMinisign();
    }

    public function testSignaturePrehacheeAcceptee(): void
    {
        $this->signataire->verificateur()->verifier('archive', $this->signataire->signer('archive', self::COMMENTAIRE), self::NOM);
        $this->addToAssertionCount(1);
    }

    public function testSignatureLegacyAcceptee(): void
    {
        $sig = $this->signataire->signer('archive', 'file:' . self::NOM, false);
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
        $this->addToAssertionCount(1);
    }

    public function testFinsDeLigneWindowsAcceptees(): void
    {
        $sig = str_replace("\n", "\r\n", $this->signataire->signer('archive', self::COMMENTAIRE));
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
        $this->addToAssertionCount(1);
    }

    /**
     * Vecteur produit par l'outil minisign 0.12 (clé jetable sans mot de passe,
     * `minisign -S -m test -t …` sur le fichier « test\\n »),
     * pour valider le format réel et pas seulement celui du signataire de test.
     */
    public function testCompatibiliteAvecLOutilMinisign(): void
    {
        $verificateur = new SignatureMinisign(['RWS8O0HtJTWZlz3ZG13ptH4X4VQs9Ga91X6Qrsz3Wv0BDW1BtjTSNvDg']);
        $sig = "untrusted comment: signature from minisign secret key\n"
            . "RUS8O0HtJTWZl+QYuXcL7BBvyrxqRgPEfXsOS39IeOY/czhWMwdkA0Av7JLuTxcGmq1/+ggpZFq5eNzW5kbbDOAOwhy1RTxtvQ4=\n"
            . "trusted comment: timestamp:1555779966\tfile:test\n"
            . "q4S3rgriKfSw/scI1v1AKRPN9xRICYIHuRbsxElv+XPzXv4vR8H4DfMvBcgquTQNw/62h6L6jghq4rpc/ZueDA==\n";
        $verificateur->verifier("test\n", $sig, 'test');
        $this->addToAssertionCount(1);
    }

    public function testContenuModifieRefuse(): void
    {
        $this->expectExceptionMessage('signature invalide');
        $this->signataire->verificateur()->verifier('archive piégée', $this->signataire->signer('archive', self::COMMENTAIRE), self::NOM);
    }

    public function testCleInconnueRefusee(): void
    {
        $autre = new SignataireMinisign();
        $this->expectExceptionMessage('clé inconnue');
        $this->signataire->verificateur()->verifier('archive', $autre->signer('archive', self::COMMENTAIRE), self::NOM);
    }

    public function testCleDeMemeIdentifiantMaisAutreSecretRefusee(): void
    {
        // Un attaquant recopie l'identifiant de la vraie clé : la signature reste fausse.
        $autre = new SignataireMinisign();
        $sig = $autre->signer('archive', self::COMMENTAIRE);
        $lignes = explode("\n", $sig);
        $brut = base64_decode($lignes[1]);
        $lignes[1] = base64_encode(substr($brut, 0, 2) . $this->signataire->idCle . substr($brut, 10));
        $this->expectExceptionMessage('signature invalide');
        $this->signataire->verificateur()->verifier('archive', implode("\n", $lignes), self::NOM);
    }

    public function testCommentaireDeConfianceAltereRefuse(): void
    {
        $sig = $this->signataire->signer('archive', 'file:epiclub-v2.11.0.zip');
        $sig = str_replace('file:epiclub-v2.11.0.zip', 'file:' . self::NOM, $sig);
        $this->expectExceptionMessage('commentaire de confiance altéré');
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
    }

    /** Une ancienne archive, correctement signée, resservie sous un tag plus récent. */
    public function testArchiveDUneAutreVersionRefusee(): void
    {
        $sig = $this->signataire->signer('archive', 'timestamp:1	file:epiclub-v2.1.0.zip	hashed');
        $this->expectExceptionMessage('autre fichier');
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
    }

    public function testNomPrefixeNeSuffitPas(): void
    {
        $sig = $this->signataire->signer('archive', 'file:' . self::NOM . '.old');
        $this->expectExceptionMessage('autre fichier');
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
    }

    public function testSansCleConfigureeToutEstRefuse(): void
    {
        $this->expectExceptionMessage('aucune clé publique');
        (new SignatureMinisign([]))->verifier('archive', $this->signataire->signer('archive', self::COMMENTAIRE), self::NOM);
    }

    #[DataProvider('signaturesIllisibles')]
    public function testSignatureIllisibleRefusee(string $sig): void
    {
        $this->expectExceptionMessage('illisible');
        $this->signataire->verificateur()->verifier('archive', $sig, self::NOM);
    }

    /** @return iterable<string, array{string}> */
    public static function signaturesIllisibles(): iterable
    {
        yield 'vide' => [''];
        yield 'somme sha256' => [str_repeat('a', 64) . '  epiclub-v2.12.0.zip'];
        yield 'base64 invalide' => ["untrusted comment: x\n!!!\ntrusted comment: file:a\n!!!\n"];
        yield 'signature tronquée' => ["untrusted comment: x\n" . base64_encode('EDabc') . "\ntrusted comment: file:a\n" . base64_encode(str_repeat('x', 64)) . "\n"];
    }

    public function testCleDeLApplicationValide(): void
    {
        // La clé livrée doit être décodable (sinon l'updater planterait).
        new SignatureMinisign();
        $this->addToAssertionCount(1);
    }

    public function testClePubliqueMalformeeRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureMinisign(['abc']);
    }
}
