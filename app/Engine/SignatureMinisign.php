<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [audit 2026-11-08 R1] Vérification de la signature minisign (Ed25519)
 * d'une archive de release, en PHP pur (extension sodium).
 *
 * La somme SHA-256 attachée à la release ne protège que de la corruption :
 * elle vient du même endroit que l'archive. La signature, elle, est faite
 * HORS LIGNE avec une clé privée qui ne quitte pas la machine du
 * mainteneur (bin/signer-release.sh) ; seule la clé publique est livrée
 * avec l'application (CLES_PUBLIQUES). Un compte GitHub ou une CI
 * compromis ne peut donc pas publier une archive acceptée par l'updater.
 *
 * Format minisign (https://jedisct1.github.io/minisign/) :
 *  - clé publique : base64( "Ed" | id de clé (8 o) | clé Ed25519 (32 o) )
 *  - fichier .minisig, 4 lignes :
 *      untrusted comment: …
 *      base64( "ED" | id de clé (8 o) | signature (64 o) )   — "ED" : BLAKE2b-512
 *                                                             du fichier signé ;
 *                                                             "Ed" (ancien) : fichier brut
 *      trusted comment: <texte>
 *      base64( signature globale (64 o) de  signature | texte )
 *
 * Le commentaire de confiance (signé) doit nommer le fichier attendu
 * (`file:epiclub-<tag>.zip`, cf. bin/signer-release.sh) : une archive
 * ancienne, signée elle aussi, ne peut pas être resservie sous un tag
 * plus récent (retour en arrière vers une version vulnérable).
 */
final class SignatureMinisign
{
    /**
     * Clés publiques de confiance (ligne 2 du fichier .pub de minisign).
     * Rotation : ajouter la nouvelle clé, publier une release signée par
     * l'ancienne, puis retirer l'ancienne.
     */
    public const CLES_PUBLIQUES = [
        // [R1] Clé de signature des releases EPIClub (id 85679766495520BB, 2026-11-09).
        'RWS7IFVJZpdnhR9xY+Q9awMczhU106RdwsMWLKVAv27NlQD1947YNsX4',
    ];

    /** @var array<string, string> id de clé (8 o) => clé publique Ed25519 (32 o) */
    private readonly array $cles;

    /**
     * @param list<string>|null $clesPubliques null = CLES_PUBLIQUES
     */
    public function __construct(?array $clesPubliques = null)
    {
        $cles = [];
        foreach ($clesPubliques ?? self::CLES_PUBLIQUES as $cle) {
            $brut = base64_decode(trim($cle), true);
            if ($brut === false || strlen($brut) !== 42 || substr($brut, 0, 2) !== 'Ed') {
                throw new \InvalidArgumentException('Clé publique minisign invalide.');
            }
            $cles[substr($brut, 2, 8)] = substr($brut, 10, 32);
        }
        $this->cles = $cles;
    }

    /**
     * @param string $contenu       Fichier signé (archive zip).
     * @param string $minisig       Contenu du fichier .minisig.
     * @param string $nomAttendu    Nom du fichier que le commentaire de confiance
     *                              doit désigner (ex. epiclub-v2.12.0.zip).
     * @throws \RuntimeException    Signature absente, illisible, inconnue ou fausse.
     */
    public function verifier(string $contenu, string $minisig, string $nomAttendu): void
    {
        if ($this->cles === []) {
            throw new \RuntimeException('aucune clé publique de signature configurée');
        }

        $lignes = preg_split('/\r?\n/', trim($minisig)) ?: [];
        if (count($lignes) < 4
            || !str_starts_with($lignes[0], 'untrusted comment:')
            || !str_starts_with($lignes[2], 'trusted comment: ')
        ) {
            throw new \RuntimeException('fichier de signature illisible');
        }

        $sig = base64_decode(trim($lignes[1]), true);
        $globale = base64_decode(trim($lignes[3]), true);
        $commentaire = substr($lignes[2], strlen('trusted comment: '));
        if ($sig === false || strlen($sig) !== 74 || $globale === false || strlen($globale) !== 64) {
            throw new \RuntimeException('fichier de signature illisible');
        }

        $algo = substr($sig, 0, 2);
        $cle = $this->cles[substr($sig, 2, 8)] ?? null;
        $signature = substr($sig, 10, 64);

        if ($cle === null) {
            throw new \RuntimeException('signature faite avec une clé inconnue');
        }

        $message = match ($algo) {
            'ED'    => sodium_crypto_generichash($contenu, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MAX),
            'Ed'    => $contenu,
            default => throw new \RuntimeException('algorithme de signature inconnu'),
        };

        if (!sodium_crypto_sign_verify_detached($signature, $message, $cle)) {
            throw new \RuntimeException('signature invalide');
        }
        if (!sodium_crypto_sign_verify_detached($globale, $signature . $commentaire, $cle)) {
            throw new \RuntimeException('commentaire de confiance altéré');
        }
        if (!preg_match('/(?:^|\s)file:' . preg_quote($nomAttendu, '/') . '(?:\s|$)/', $commentaire)) {
            throw new \RuntimeException('la signature porte sur un autre fichier que ' . $nomAttendu);
        }
    }
}
