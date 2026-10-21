<?php

declare(strict_types=1);

namespace Epiclub\Domain;

use Epiclub\Enum\EquipementStatut;
use Epiclub\Enum\PretStatut;

/**
 * Règles métier du prêt de matériel (migration 016) — fonctions pures,
 * sans accès à la base (testées en Unit).
 */
final class PretRegles
{
    /** Taille maximale de l'image de signature décodée (octets). */
    public const SIGNATURE_TAILLE_MAX = 300 * 1024;

    private const PREFIXE_SIGNATURE = 'data:image/png;base64,';

    /**
     * Motif pour lequel un équipement ne peut pas être ajouté à un prêt,
     * ou null s'il est prêtable.
     *
     * @param array<string, mixed>      $equipement
     * @param array<string, mixed>|null $autrePret  Prêt non terminé contenant déjà l'équipement
     * @param string                    $aujourdhui Y-m-d
     */
    public static function motifIneligibilite(array $equipement, ?array $autrePret, string $aujourdhui): ?string
    {
        $ref = (string) ($equipement['reference'] ?? '#' . ($equipement['id'] ?? '?'));

        if (!empty($equipement['deleted_at'])) {
            return "{$ref} : équipement supprimé.";
        }
        if ($autrePret !== null) {
            return sprintf('%s : déjà inscrit sur le prêt n° %d (%s %s).',
                $ref,
                (int) $autrePret['id'],
                $autrePret['emprunteur_prenom'] ?? '',
                $autrePret['emprunteur_nom'] ?? ''
            );
        }
        if (!empty($equipement['controle_en_cours'])) {
            return "{$ref} : équipement en cours de contrôle.";
        }
        $statut = EquipementStatut::tryFrom((int) ($equipement['statut'] ?? -1));
        if ($statut !== EquipementStatut::DISPONIBLE) {
            return sprintf('%s : statut « %s », seul un matériel disponible peut être prêté.',
                $ref,
                $statut?->label() ?? 'inconnu'
            );
        }
        $fin = (string) ($equipement['date_fin_utilisation'] ?? '');
        if ($fin !== '' && substr($fin, 0, 10) <= $aujourdhui) {
            return "{$ref} : date limite d'utilisation atteinte.";
        }

        return null;
    }

    /**
     * Lecture et validation des informations de l'emprunteur selon le
     * statut de la fiche :
     *   - saisie   : tous les champs ;
     *   - en_cours : téléphone seulement ;
     *   - termine  : rien.
     *
     * @param array<string, mixed> $saisie Données POST
     * @return array{0: array<string, mixed>, 1: array<string, string>} [champs à enregistrer, erreurs]
     */
    public static function lireEmprunteur(array $saisie, PretStatut $statut): array
    {
        $data = [];
        $erreurs = [];

        if ($statut === PretStatut::TERMINE) {
            return [$data, $erreurs];
        }

        $texte = static fn(string $cle): string => trim((string) ($saisie[$cle] ?? ''));

        // Téléphone : modifiable en saisie ET pendant le prêt.
        $tel = $texte('emprunteur_telephone');
        if (mb_strlen($tel) > 20) {
            $erreurs['emprunteur_telephone'] = 'Le téléphone ne doit pas dépasser 20 caractères.';
        } elseif ($tel !== '' && !preg_match('/^[0-9+().\s-]+$/', $tel)) {
            $erreurs['emprunteur_telephone'] = 'Téléphone invalide (chiffres, espaces, + . - ( ) uniquement).';
        } elseif ($tel === '' && $statut === PretStatut::EN_COURS) {
            $erreurs['emprunteur_telephone'] = 'Le téléphone est obligatoire.';
        }
        $data['emprunteur_telephone'] = $tel;

        if ($statut === PretStatut::EN_COURS) {
            return [$data, $erreurs];
        }

        foreach (['emprunteur_nom' => 'Le nom', 'emprunteur_prenom' => 'Le prénom'] as $cle => $libelle) {
            $v = $texte($cle);
            if ($v === '') {
                $erreurs[$cle] = "{$libelle} est obligatoire.";
            } elseif (mb_strlen($v) > 64) {
                $erreurs[$cle] = "{$libelle} ne doit pas dépasser 64 caractères.";
            }
            $data[$cle] = $v;
        }

        $licence = $texte('emprunteur_licence');
        if (mb_strlen($licence) > 32) {
            $erreurs['emprunteur_licence'] = 'Le numéro de licence ne doit pas dépasser 32 caractères.';
        }
        $data['emprunteur_licence'] = $licence;

        foreach (['date_pret' => 'La date du prêt', 'date_retour_prevue' => 'La date de retour prévue'] as $cle => $libelle) {
            $v = $texte($cle);
            if (!self::dateValide($v)) {
                $erreurs[$cle] = "{$libelle} est obligatoire (date valide).";
            }
            $data[$cle] = $v;
        }
        if (self::dateValide($data['date_pret']) && self::dateValide($data['date_retour_prevue'])
            && $data['date_retour_prevue'] < $data['date_pret']
        ) {
            $erreurs['date_retour_prevue'] = 'La date de retour prévue doit être postérieure ou égale à la date du prêt.';
        }

        $remarques = $texte('remarques');
        $data['remarques'] = $remarques === '' ? null : mb_substr($remarques, 0, 2000);

        return [$data, $erreurs];
    }

    /**
     * Conditions pour passer un prêt « en saisie » à « en cours ».
     *
     * @param array<string, mixed>       $pret
     * @param list<array<string, mixed>> $lignes
     * @return list<string> Erreurs (vide = validable)
     */
    public static function erreursValidation(array $pret, array $lignes): array
    {
        $erreurs = [];
        if (($pret['statut'] ?? null) !== PretStatut::SAISIE->value) {
            $erreurs[] = "Seul un prêt en saisie peut être validé.";
        }
        $libelles = [
            'emprunteur_nom' => 'le nom',
            'emprunteur_prenom' => 'le prénom',
            'emprunteur_telephone' => 'le téléphone',
            'emprunteur_licence' => 'le numéro de licence',
        ];
        $manquants = [];
        foreach ($libelles as $cle => $libelle) {
            if (trim((string) ($pret[$cle] ?? '')) === '') {
                $manquants[] = $libelle;
            }
        }
        if ($manquants !== []) {
            $erreurs[] = "Renseignez " . implode(', ', $manquants) . " de l'emprunteur.";
        }
        if (!self::dateValide((string) ($pret['date_pret'] ?? ''))
            || !self::dateValide((string) ($pret['date_retour_prevue'] ?? ''))
        ) {
            $erreurs[] = 'Renseignez la date du prêt et la date de retour prévue.';
        }
        if ($lignes === []) {
            $erreurs[] = 'Ajoutez au moins un matériel au prêt.';
        }
        return $erreurs;
    }

    /**
     * Valide une signature dessinée (data URL PNG produite par le canvas).
     *
     * @return array{0: ?string, 1: ?string} [signature normalisée (null si vide), erreur]
     */
    public static function lireSignature(mixed $brut): array
    {
        if (!is_string($brut) || trim($brut) === '') {
            return [null, null];
        }
        $brut = trim($brut);
        if (!str_starts_with($brut, self::PREFIXE_SIGNATURE)) {
            return [null, 'Signature invalide.'];
        }
        $binaire = base64_decode(substr($brut, strlen(self::PREFIXE_SIGNATURE)), true);
        if ($binaire === false || !str_starts_with($binaire, "\x89PNG\r\n\x1a\n")) {
            return [null, 'Signature invalide.'];
        }
        if (strlen($binaire) > self::SIGNATURE_TAILLE_MAX) {
            return [null, 'Signature trop volumineuse.'];
        }
        return [self::PREFIXE_SIGNATURE . base64_encode($binaire), null];
    }

    /**
     * @param array<string, mixed> $pret
     */
    public static function estEnRetard(array $pret, string $aujourdhui): bool
    {
        return ($pret['statut'] ?? null) === PretStatut::EN_COURS->value
            && !empty($pret['date_retour_prevue'])
            && substr((string) $pret['date_retour_prevue'], 0, 10) < $aujourdhui;
    }

    public static function dateValide(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
