<?php

namespace Epiclub\Controller;

use Epiclub\Process\AcquisitionImportService;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Import d'acquisitions depuis un fichier Excel (.xlsx/.xls) ou CSV,
 * en deux étapes (décision produit 2026-10-19) :
 *
 *  1. POST avec fichier → analyse : correspondance colonnes ↔ champs
 *     proposée (modifiable), aperçu, lignes non conformes signalées
 *     « seront ignorées ». Le fichier est conservé côté serveur
 *     (chemin en session) en attendant la confirmation.
 *  2. POST avec confirmation + mapping → import effectif, rapport.
 *     Seules les lignes cochées sont importées ; les catégories à créer
 *     reçoivent le nom et le code choisis, et chaque ligne peut être
 *     affectée à une autre catégorie (décision produit 2026-10-20).
 */
class AcquisitionImportController extends AbstractController
{
    private const EXTENSIONS_ACCEPTEES = ['xlsx', 'xls', 'csv'];
    private const SESSION_CLE = 'import_acquisitions_fichier';

    // ==================================================================
    // Factory — mockable en test Unit (pattern Vague 11)
    // ==================================================================

    protected function acquisitionImportService(): AcquisitionImportService
    {
        return new AcquisitionImportService();
    }

    public function import(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');

        $analyse = null;
        $rapport = null;
        $erreur = null;

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement (upload inclus)
            $this->validateCsrf($request);

            if ($request->request->get('confirmation') === '1') {
                // ---------- Étape 2 : import avec le mapping validé ----------
                $chemin = $this->session->get(self::SESSION_CLE);
                $this->session->remove(self::SESSION_CLE);

                if (!is_string($chemin) || !file_exists($chemin)) {
                    $erreur = "Le fichier analysé n'est plus disponible. Merci de le téléverser à nouveau.";
                } else {
                    $mapping = $this->nettoyerMapping($request->request->all('mapping'));
                    $choixCategories = $this->nettoyerChoixCategories($request->request->all('categories'));
                    // Sélection des lignes : absente (anciens formulaires,
                    // appels directs) → toutes les lignes.
                    $selection = $request->request->get('selection_active') === '1'
                        ? $this->nettoyerSelection($request->request->all('lignes'))
                        : null;
                    $categoriesParLigne = $this->nettoyerCategoriesParLigne(
                        $request->request->all('ligne_categorie')
                    );
                    $nombresParLigne = $this->nettoyerNombresParLigne(
                        $request->request->all('ligne_nombre'),
                        $request->request->all('ligne_nombre_actuel')
                    );
                    $datesParLigne = $this->nettoyerDatesParLigne(
                        $request->request->all('ligne_date_achat'),
                        $request->request->all('ligne_date_peremption')
                    );
                    try {
                        $rapport = $this->acquisitionImportService()->import(
                            $chemin,
                            (int) $this->session->get('user')['id'],
                            $mapping,
                            $choixCategories,
                            $selection,
                            $categoriesParLigne,
                            $nombresParLigne,
                            $datesParLigne
                        );
                        if ($rapport['erreur_globale'] !== null) {
                            $erreur = $rapport['erreur_globale'];
                            $rapport = null;
                        }
                    } catch (\Throwable $e) {
                        error_log('[AcquisitionImportController] Import failed: ' . $e->getMessage());
                        $erreur = "Erreur lors de l'import (détail en journal serveur).";
                    } finally {
                        @unlink($chemin);
                    }
                }
            } else {
                // ---------- Étape 1 : upload + analyse ----------
                $fichier = $request->files->get('fichier');

                if (!$fichier || !$fichier->isValid()) {
                    $erreur = "Aucun fichier valide n'a été téléversé.";
                } else {
                    $extension = strtolower((string) $fichier->getClientOriginalExtension());
                    if (!in_array($extension, self::EXTENSIONS_ACCEPTEES, true)) {
                        $erreur = 'Format non accepté : ' . implode(', ', self::EXTENSIONS_ACCEPTEES) . ' uniquement.';
                    } else {
                        // Le service choisit le reader selon l'extension →
                        // on la conserve dans le nom temporaire.
                        $chemin = sys_get_temp_dir()
                            . '/epiclub_import_' . bin2hex(random_bytes(8)) . '.' . $extension;
                        try {
                            $fichier->move(dirname($chemin), basename($chemin));
                            $analyse = $this->acquisitionImportService()->analyser($chemin);
                            if ($analyse['erreur_globale'] !== null) {
                                $erreur = $analyse['erreur_globale'];
                                $analyse = null;
                                @unlink($chemin);
                            } else {
                                // Rattachement des catégories du fichier
                                // (choix proposé à l'analyse — 2026-10-19)
                                $enrichissement = $this->acquisitionImportService()
                                    ->enrichirCategories($analyse['categories_fichier']);
                                $analyse['categories'] = $enrichissement['categories'];
                                $analyse['categories_existantes'] = $enrichissement['existantes'];
                                // EPI à durée de vie limitée : date d'achat
                                // obligatoire, fin de vie par défaut (2026-10-20)
                                $analyse = $this->acquisitionImportService()
                                    ->marquerDureeDeVieLimitee($analyse);
                                // Le fichier reste disponible pour l'étape 2
                                $this->session->set(self::SESSION_CLE, $chemin);
                            }
                        } catch (\Throwable $e) {
                            error_log('[AcquisitionImportController] Analyse failed: ' . $e->getMessage());
                            $erreur = "Erreur lors de l'analyse du fichier (détail en journal serveur).";
                            if (file_exists($chemin)) {
                                @unlink($chemin);
                            }
                        }
                    }
                }
            }
        }

        return $this->render('acquisition_import.twig', [
            'analyse' => $analyse,
            'rapport' => $rapport,
            'erreur' => $erreur,
            'champs' => AcquisitionImportService::CHAMPS,
        ]);
    }

    /**
     * Nettoie la correspondance reçue : index entiers, champs limités
     * aux valeurs connues (sinon 'ignorer').
     *
     * @return array<int, string>
     */
    private function nettoyerMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $index => $champ) {
            $out[(int) $index] = array_key_exists((string) $champ, AcquisitionImportService::CHAMPS)
                ? (string) $champ
                : 'ignorer';
        }
        return $out;
    }

    /**
     * Nettoie les choix des catégories du fichier (décisions
     * 2026-10-19/20) : valeur du fichier => {cible, libelle, code}.
     * cible : 'nouvelle', id numérique, ou 'v:<autre valeur>' (même
     * catégorie que cette autre valeur du fichier). Une simple chaîne
     * (ancien formulaire) vaut cible.
     *
     * @return array<string, array{cible:string, libelle:string, code:string}>
     */
    private function nettoyerChoixCategories(array $choix): array
    {
        $out = [];
        foreach ($choix as $valeur => $c) {
            $valeur = trim((string) $valeur);
            if ($valeur === '') {
                continue;
            }
            if (!is_array($c)) {
                $c = ['cible' => $c];
            }
            $cible = isset($c['cible']) && is_scalar($c['cible']) ? trim((string) $c['cible']) : '';
            if (!$this->cibleCategorieValide($cible, true)) {
                continue;
            }
            $out[$valeur] = [
                'cible' => $cible,
                'libelle' => isset($c['libelle']) && is_scalar($c['libelle'])
                    ? mb_substr(trim((string) $c['libelle']), 0, 32) : '',
                'code' => isset($c['code']) && is_scalar($c['code'])
                    ? mb_substr(trim((string) $c['code']), 0, 8) : '',
                // Durée de vie limitée (case cochée — 2026-10-20)
                'duree_max' => !empty($c['duree_max']) ? 1 : 0,
            ];
        }
        return $out;
    }

    /**
     * Numéros (1-based) des lignes cochées pour l'import.
     *
     * @return int[]
     */
    private function nettoyerSelection(array $lignes): array
    {
        $out = [];
        foreach ($lignes as $numero) {
            if (is_scalar($numero) && ctype_digit((string) $numero) && (int) $numero > 0) {
                $out[] = (int) $numero;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Catégorie imposée par ligne : numéro => id existant ou
     * 'v:<valeur du fichier>' ; valeur vide = selon le fichier.
     *
     * @return array<int, string>
     */
    private function nettoyerCategoriesParLigne(array $choix): array
    {
        $out = [];
        foreach ($choix as $numero => $cible) {
            if (!ctype_digit((string) $numero) || (int) $numero < 1 || !is_scalar($cible)) {
                continue;
            }
            $cible = trim((string) $cible);
            if ($cible !== '' && $this->cibleCategorieValide($cible, false)) {
                $out[(int) $numero] = $cible;
            }
        }
        return $out;
    }

    /**
     * Stock max / stock actuel saisis par ligne (2026-10-20) :
     * numéro => {nombre?, nombre_actuel?} (chaînes, validées par le
     * service ; 10 caractères max).
     *
     * @return array<int, array{nombre?:string, nombre_actuel?:string}>
     */
    private function nettoyerNombresParLigne(array $nombres, array $actuels): array
    {
        $out = [];
        foreach (['nombre' => $nombres, 'nombre_actuel' => $actuels] as $champ => $valeurs) {
            foreach ($valeurs as $numero => $valeur) {
                if (!ctype_digit((string) $numero) || (int) $numero < 1 || !is_scalar($valeur)) {
                    continue;
                }
                $out[(int) $numero][$champ] = mb_substr(trim((string) $valeur), 0, 10);
            }
        }
        return $out;
    }

    /**
     * Dates saisies par ligne (2026-10-20) : numéro => {date_achat?,
     * date_peremption?} au format AAAA-MM-JJ, ou chaîne vide (date
     * effacée) ; toute autre valeur est ignorée.
     *
     * @return array<int, array{date_achat?:string, date_peremption?:string}>
     */
    private function nettoyerDatesParLigne(array $achats, array $peremptions): array
    {
        $out = [];
        foreach (['date_achat' => $achats, 'date_peremption' => $peremptions] as $champ => $valeurs) {
            foreach ($valeurs as $numero => $valeur) {
                if (!ctype_digit((string) $numero) || (int) $numero < 1 || !is_scalar($valeur)) {
                    continue;
                }
                $valeur = trim((string) $valeur);
                if ($valeur === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1) {
                    $out[(int) $numero][$champ] = $valeur;
                }
            }
        }
        return $out;
    }

    private function cibleCategorieValide(string $cible, bool $nouvelleAutorisee): bool
    {
        return ($nouvelleAutorisee && $cible === 'nouvelle')
            || (ctype_digit($cible) && (int) $cible > 0)
            || (str_starts_with($cible, 'v:') && trim(substr($cible, 2)) !== '');
    }
}
