<?php

namespace Epiclub\Domain;

/**
 * Référencement automatique des équipements.
 *
 * Modèle global (club.ref_modele, défaut 'YYCATNNN') avec variables :
 *   - YY   : année sur 2 chiffres (ex. 26)       — YYYY : 4 chiffres
 *   - CAT  : code de la catégorie (ex. COR)
 *   - N…   : n° d'ordre — autant de N que de chiffres (NNN → 001)
 *
 * Le compteur du n° d'ordre est remis à zéro chaque année, par
 * catégorie (décision produit 2026-10-18) : il est calculé par MAX sur
 * les références existantes matchant le modèle avec l'année et le code
 * courants. Si le modèle ne contient ni YY ni YYYY, le compteur est
 * continu par catégorie (conséquence assumée du modèle choisi).
 *
 * Code catégorie : 3 premières lettres normalisées du libellé
 * (majuscules, accents retirés) ; en cas de collision, 2 premières
 * lettres + n° d'ordre (COR, puis CO1, CO2…). Une catégorie sans code
 * se le voit attribuer et persister à la première utilisation
 * (self-healing — la migration 006 ne backfill pas).
 */
class ReferenceGenerator
{
    public const DEFAULT_MODELE = 'YYCATNNN';

    /** Longueur max de la colonne club_equipement.reference (varchar). */
    private const REFERENCE_MAX = 32;

    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
    // ==================================================================

    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    // ==================================================================
    // Code catégorie
    // ==================================================================

    /**
     * Normalise un libellé en base de code : majuscules ASCII, accents
     * retirés, tout caractère non [A-Z0-9] supprimé.
     * (« Cordes à double » → 'CORDESADOUBLE')
     */
    public function normalize(string $libelle): string
    {
        $s = strtr($libelle, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
        $s = strtoupper($s);
        return (string) preg_replace('/[^A-Z0-9]/', '', $s);
    }

    /**
     * Suggère un code catégorie disponible.
     *
     * @param string   $libelle        Libellé de la catégorie
     * @param string[] $codesExistants Codes déjà pris (codes d'AUTRES catégories)
     */
    public function suggestCode(string $libelle, array $codesExistants): string
    {
        $normalise = $this->normalize($libelle);

        $base3 = mb_substr($normalise, 0, 3);
        if ($base3 === '') {
            $base3 = 'CAT';
        }
        if (!in_array($base3, $codesExistants, true)) {
            return $base3;
        }

        // Collision : 2 premières lettres + n° d'ordre (CO1, CO2…)
        $base2 = mb_substr($normalise, 0, 2);
        if ($base2 === '') {
            $base2 = 'CA';
        }
        for ($i = 1; $i <= 99; $i++) {
            $candidat = $base2 . $i;
            if (!in_array($candidat, $codesExistants, true)) {
                return $candidat;
            }
        }

        throw new \RuntimeException("Impossible de suggérer un code pour « $libelle ».");
    }

    /**
     * Code de la catégorie ; attribué et persisté à la volée si absent.
     *
     * @param array $categorie Ligne BDD de la catégorie (avec 'id')
     */
    public function codeForCategorie(array $categorie): string
    {
        if (!empty($categorie['code'])) {
            return $categorie['code'];
        }

        $manager = $this->categorieManager();
        $existants = array_values(array_filter(
            array_column($manager->findAll(), 'code'),
            static fn($code) => is_string($code) && $code !== ''
        ));
        // Ne pas se compter soi-même en cas de ré-attribution
        if (isset($categorie['id'])) {
            $self = $manager->findId((int) $categorie['id']);
            if ($self && !empty($self['code'])) {
                $existants = array_values(array_diff($existants, [$self['code']]));
            }
        }

        $code = $this->suggestCode((string) ($categorie['libelle'] ?? ''), $existants);
        $categorie['code'] = $code;
        $manager->save($categorie);

        return $code;
    }

    // ==================================================================
    // Modèle
    // ==================================================================

    /**
     * Modèle de référence configuré (club.ref_modele), défaut si absent.
     */
    public function getModele(): string
    {
        $club = $this->clubManager()->findParameters();
        $modele = $club['ref_modele'] ?? null;
        return (is_string($modele) && trim($modele) !== '')
            ? trim($modele)
            : self::DEFAULT_MODELE;
    }

    /**
     * Valide un modèle. Retourne la liste des erreurs (vide = valide).
     *
     * Règles : CAT obligatoire ; exactement un bloc de N consécutifs
     * (le compteur) ; longueur raisonnable pour la colonne varchar(32).
     *
     * @return string[]
     */
    public function validateModele(string $modele): array
    {
        $erreurs = [];

        if (trim($modele) === '') {
            $erreurs[] = 'Le modèle est obligatoire.';
            return $erreurs;
        }
        if (!str_contains($modele, 'CAT')) {
            $erreurs[] = 'Le modèle doit contenir la variable CAT (code catégorie).';
        }
        if (!preg_match('/N+/', $modele)) {
            $erreurs[] = 'Le modèle doit contenir le n° d\'ordre (autant de N que de chiffres, ex. NNN → 001).';
        } elseif (preg_match('/N+.*N+/s', preg_replace('/N+/', '', $modele, 1))) {
            // Deux blocs de N séparés : ambigu pour le compteur
            $erreurs[] = 'Le modèle ne doit contenir qu\'un seul bloc de N (le compteur).';
        }
        if (strlen($modele) > self::REFERENCE_MAX) {
            $erreurs[] = 'Le modèle est trop long (références limitées à 32 caractères).';
        }
        if (preg_match('/Y(?!Y)/', str_replace(['YYYY', 'YY'], '', $modele))) {
            $erreurs[] = 'Variable d\'année invalide : utilisez YY ou YYYY.';
        }

        return $erreurs;
    }

    /**
     * Aperçu d'une référence pour un code catégorie donné (compteur = 1,
     * année courante). Utilisé par l'écran d'administration.
     */
    public function preview(string $modele, string $codeCategorie): string
    {
        return $this->render($modele, $codeCategorie, (int) date('Y'), 1);
    }

    // ==================================================================
    // Génération
    // ==================================================================

    /**
     * Génère la prochaine référence disponible pour une catégorie.
     *
     * Compteur remis à zéro chaque année (par catégorie) : MAX des
     * suffixes numériques des références existantes matchant le modèle
     * substitué (année + code), + 1.
     *
     * @param int      $categorieId ID de la catégorie
     * @param int|null $annee       Année de référence (défaut : courante)
     */
    public function generate(int $categorieId, ?int $annee = null): string
    {
        $categorie = $this->categorieManager()->findId($categorieId);
        if (!$categorie) {
            throw new \RuntimeException("Catégorie introuvable (id=$categorieId).");
        }

        $code = $this->codeForCategorie($categorie);
        $annee ??= (int) date('Y');
        $modele = $this->getModele();
        $pad = $this->padLength($modele);

        $regexp = $this->buildRegexp($modele, $code, $annee);
        $existantes = $this->equipementManager()->findReferencesMatching($regexp);

        $max = 0;
        foreach ($existantes as $reference) {
            if (preg_match('/' . $regexp . '/', $reference, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $this->render($modele, $code, $annee, $max + 1);
    }

    // ==================================================================
    // Internes
    // ==================================================================

    /**
     * Longueur du compteur = nombre de N consécutifs du modèle.
     */
    private function padLength(string $modele): int
    {
        return preg_match('/N+/', $modele, $m) ? strlen($m[0]) : 3;
    }

    /**
     * Rend le modèle en remplaçant les variables par leurs valeurs.
     * YYYY est remplacé AVANT YY (ordre significatif).
     */
    private function render(string $modele, string $code, int $annee, int $compteur): string
    {
        $pad = $this->padLength($modele);
        $out = str_replace('YYYY', (string) $annee, $modele);
        $out = str_replace('YY', substr((string) $annee, -2), $out);
        $out = str_replace('CAT', $code, $out);
        return (string) preg_replace(
            '/N+/',
            str_pad((string) $compteur, $pad, '0', STR_PAD_LEFT),
            $out,
            1
        );
    }

    /**
     * Construit le REGEXP MySQL matchant les références du modèle pour
     * (année, code) donnés, avec le compteur en groupe capturant 1.
     *
     * Ex. modèle 'YYCATNNN', code COR, 2026 → '^26COR([0-9]{3})$'
     */
    private function buildRegexp(string $modele, string $code, int $annee): string
    {
        $pad = $this->padLength($modele);

        // Tokeniser : YYYY, YY, CAT, bloc N, littéraux échappés
        $tokens = preg_split('/(YYYY|YY|CAT|N+)/', $modele, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $parts = [];
        foreach ($tokens as $token) {
            if ($token === 'YYYY') {
                $parts[] = (string) $annee;
            } elseif ($token === 'YY') {
                $parts[] = substr((string) $annee, -2);
            } elseif ($token === 'CAT') {
                $parts[] = $code;
            } elseif (preg_match('/^N+$/', $token)) {
                $parts[] = '([0-9]{' . $pad . '})';
            } else {
                $parts[] = $token;
            }
        }

        $regexp = '';
        foreach ($parts as $part) {
            // Les seules parties non littérales sont le groupe du compteur
            if (str_starts_with($part, '([0-9]')) {
                $regexp .= $part;
            } else {
                $regexp .= preg_quote($part, '/');
            }
        }

        return '^' . $regexp . '$';
    }
}
