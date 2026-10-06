<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [REFACTOR VAGUE 10] Contrat du fournisseur de releases GitHub.
 *
 * Extrait d'AppUpdateController (issue #45) pour rendre les appels
 * réseau (API GitHub + téléchargement du zip) testables en Unit.
 *
 * L'implémentation concrète `GitHubReleaseProvider` gère :
 *  - l'appel à `https://api.github.com/repos/{owner}/{repo}/releases/latest`
 *  - le cache TTL (1h par défaut) sur fichier JSON
 *  - le téléchargement via `file_get_contents` avec fallback cURL
 *
 * ⚠️ Contrairement à l'implémentation historique d'AppUpdateController,
 *    cette version accepte les Releases dont le champ `body` est `null`
 *    (Release GitHub sans description). C'est le fix de l'issue #45.
 */
interface GitHubReleaseProviderInterface
{
    /**
     * Retourne la dernière release disponible, ou null en cas d'échec.
     *
     * @return array{tag:string,zip_url:string,body:string}|null
     *         - tag     : ex. "v0.17.18"
     *         - zip_url : URL du zipball GitHub
     *         - body    : description de la Release (chaîne vide si null)
     */
    public function getLatestRelease(): ?array;

    /**
     * Télécharge le contenu d'une URL et le retourne sous forme de string.
     *
     * Tente d'abord `file_get_contents()`, puis fallback cURL.
     *
     * @throws \RuntimeException en cas d'erreur HTTP, réseau ou config
     */
    public function downloadUrl(string $url): string;
}