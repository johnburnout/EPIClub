<?php

declare(strict_types=1);

namespace Epiclub\Engine;

use Epiclub\Exception\FactureUploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * [REFACTOR VAGUE 8] Contrat du service d'upload de factures.
 *
 * Introduit pour rendre FactureUploader (classe `final`) mockable
 * en test unitaire. Les handlers dépendent désormais de l'interface,
 * ce qui permet de simuler les cas d'erreur (BAD_MIME, TOO_LARGE,
 * IO_ERROR) sans dépendre du système de fichiers.
 *
 * L'implémentation concrète reste `final class FactureUploader`.
 */
interface FactureUploaderInterface
{
    /**
     * Upload un fichier dans {uploadsDir}/factures/.
     *
     * @return string|null  Chemin relatif ('factures/xxx.pdf') en cas de
     *                      succès, null si aucun fichier fourni.
     * @throws FactureUploadException en cas d'erreur (taille, mime, I/O).
     */
    public function upload(?UploadedFile $file): ?string;

    /**
     * Supprime un fichier relatif précédemment uploadé.
     * Best-effort : log en cas d'échec, ne throw pas.
     */
    public function delete(string $relativePath): bool;
}