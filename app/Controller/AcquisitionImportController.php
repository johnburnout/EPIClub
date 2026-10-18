<?php

namespace Epiclub\Controller;

use Epiclub\Process\AcquisitionImportService;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Import d'acquisitions depuis un fichier Excel (.xlsx/.xls) ou CSV.
 *
 * Les lignes partageant la même facture_reference sont groupées dans
 * une même acquisition, automatiquement validée (équipements générés,
 * références automatiques ou forcées), avec facture_document =
 * « facture à importer » (mise à jour ultérieure possible).
 */
class AcquisitionImportController extends AbstractController
{
    private const EXTENSIONS_ACCEPTEES = ['xlsx', 'xls', 'csv'];

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

        $rapport = null;
        $erreur = null;

        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement (upload inclus)
            $this->validateCsrf($request);

            $fichier = $request->files->get('fichier');

            if (!$fichier || !$fichier->isValid()) {
                $erreur = "Aucun fichier valide n'a été téléversé.";
            } else {
                $extension = strtolower((string) $fichier->getClientOriginalExtension());
                if (!in_array($extension, self::EXTENSIONS_ACCEPTEES, true)) {
                    $erreur = 'Format non accepté : ' . implode(', ', self::EXTENSIONS_ACCEPTEES) . ' uniquement.';
                } else {
                    // Le service s'appuie sur l'extension pour choisir le
                    // reader PhpSpreadsheet → on conserve l'extension
                    // d'origine dans le nom temporaire.
                    $tempPath = tempnam(sys_get_temp_dir(), 'import_') . '.' . $extension;
                    try {
                        $fichier->move(dirname($tempPath), basename($tempPath));
                        $rapport = $this->acquisitionImportService()->import(
                            $tempPath,
                            (int) $this->session->get('user')['id']
                        );
                        if ($rapport['erreur_globale'] !== null) {
                            $erreur = $rapport['erreur_globale'];
                            $rapport = null;
                        }
                    } catch (\Throwable $e) {
                        error_log('[AcquisitionImportController] Import failed: ' . $e->getMessage());
                        $erreur = "Erreur lors de l'import (détail en journal serveur).";
                    } finally {
                        if (file_exists($tempPath)) {
                            @unlink($tempPath);
                        }
                    }
                }
            }
        }

        return $this->render('acquisition_import.twig', [
            'rapport' => $rapport,
            'erreur' => $erreur,
        ]);
    }
}
