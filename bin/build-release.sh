#!/usr/bin/env bash
# =====================================================================
# [audit 2026-10-19 F1] Construit l'archive COMPLÈTE d'une release :
#   releases/epiclub-<version>.zip         (vendor/ inclus, sans tests)
#   releases/epiclub-<version>.zip.sha256  (somme vérifiée par l'updater)
#
# Usage : bin/build-release.sh v1.0.0-beta.5
#
# L'archive contient un unique dossier racine « epiclub-<version>/ »
# (format attendu par AppUpdateController::perform()) et s'installe
# telle quelle : dézipper, pointer le serveur sur public/, ouvrir l'URL.
# Utilisé par la CI (.github/workflows/release.yml) et en local.
# =====================================================================
set -euo pipefail

VERSION="${1:?Usage : $0 <version, ex. v1.0.0-beta.5>}"
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
SORTIE="$RACINE/releases"
NOM="epiclub-${VERSION}"

cd "$RACINE"

# 1. Cohérence version.txt ↔ tag (évite une release qui se croit ancienne)
VERSION_FICHIER="$(tr -d '[:space:]' < version.txt)"
if [ "${VERSION#v}" != "${VERSION_FICHIER#v}" ]; then
    echo "❌ version.txt contient « $VERSION_FICHIER », attendu « ${VERSION#v} »." >&2
    exit 1
fi

# 2. Export propre du commit courant (aucun fichier non versionné)
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT
git archive --format=tar --prefix="${NOM}/" HEAD | tar -x -C "$TRAVAIL"

# 3. Dépendances de production uniquement
# COMPOSER_ROOT_VERSION : évite l'avertissement « could not detect the root package version »
( cd "$TRAVAIL/$NOM" \
  && COMPOSER_ROOT_VERSION="${VERSION#v}" composer install --no-dev --optimize-autoloader --classmap-authoritative \
       --no-interaction --no-progress --no-scripts )

# 4. Rien qui ne serve en production
( cd "$TRAVAIL/$NOM" && rm -rf tests phpunit.xml .phpunit.cache .github releases \
    arborescence.txt build-release.sh composer.phar )

# 5. Garde-fous : l'archive doit être installable telle quelle
for f in vendor/autoload.php public/index.php public/.htaccess .htaccess version.txt; do
    if [ ! -e "$TRAVAIL/$NOM/$f" ]; then
        echo "❌ Fichier manquant dans l'archive : $f" >&2
        exit 1
    fi
done

# 6. Archive + somme de contrôle
mkdir -p "$SORTIE"
rm -f "$SORTIE/${NOM}.zip" "$SORTIE/${NOM}.zip.sha256"
( cd "$TRAVAIL" && zip -qr -X "$SORTIE/${NOM}.zip" "$NOM" )
( cd "$SORTIE" && { sha256sum "${NOM}.zip" 2>/dev/null || shasum -a 256 "${NOM}.zip"; } > "${NOM}.zip.sha256" )

echo "✅ $SORTIE/${NOM}.zip"
cat "$SORTIE/${NOM}.zip.sha256"
