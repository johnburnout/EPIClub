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
                    try {
                        $rapport = $this->acquisitionImportService()->import(
                            $chemin,
                            (int) $this->session->get('user')['id'],
                            $mapping
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
}
