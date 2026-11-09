<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Support;

use Epiclub\Engine\SignatureMinisign;

/**
 * [audit 2026-11-08 R1] Signataire minisign logiciel pour les tests :
 * produit une clé publique et des fichiers .minisig au format exact de
 * l'outil `minisign` (Ed25519, préhachage BLAKE2b-512 par défaut).
 */
final class SignataireMinisign
{
    public readonly string $idCle;
    private string $secrete;
    private string $publique;

    public function __construct()
    {
        $paire = sodium_crypto_sign_keypair();
        $this->secrete = sodium_crypto_sign_secretkey($paire);
        $this->publique = sodium_crypto_sign_publickey($paire);
        $this->idCle = random_bytes(8);
    }

    /** Ligne 2 du fichier .pub de minisign. */
    public function clePublique(): string
    {
        return base64_encode('Ed' . $this->idCle . $this->publique);
    }

    public function verificateur(): SignatureMinisign
    {
        return new SignatureMinisign([$this->clePublique()]);
    }

    public function signer(string $contenu, string $commentaire, bool $prehache = true): string
    {
        $message = $prehache
            ? sodium_crypto_generichash($contenu, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MAX)
            : $contenu;
        $signature = sodium_crypto_sign_detached($message, $this->secrete);
        $globale = sodium_crypto_sign_detached($signature . $commentaire, $this->secrete);

        return "untrusted comment: signature from minisign secret key\n"
            . base64_encode(($prehache ? 'ED' : 'Ed') . $this->idCle . $signature) . "\n"
            . 'trusted comment: ' . $commentaire . "\n"
            . base64_encode($globale) . "\n";
    }
}
