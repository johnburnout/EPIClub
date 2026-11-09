#!/usr/bin/env bash
# =====================================================================
# [audit 2026-11-08 R1] Signe HORS LIGNE l'archive d'une release publiée.
#
# Usage : bin/signer-release.sh v2.12.0
#
# À lancer sur la machine du mainteneur (jamais en CI), une fois que
# release.yml a attaché epiclub-<tag>.zip et son .sha256 :
#   1. télécharge l'archive et sa somme depuis la release GitHub ;
#   2. contrôle la somme et le version.txt de l'archive ;
#   3. signe l'archive avec minisign (mot de passe de la clé demandé) ;
#      le commentaire de confiance « file:epiclub-<tag>.zip » lie la
#      signature à CETTE version (pas de retour en arrière possible) ;
#   4. vérifie la signature avec la clé publique livrée dans
#      l'application (app/Engine/SignatureMinisign.php) ;
#   5. attache epiclub-<tag>.zip.minisig à la release.
#
# Tant que le .minisig n'est pas attaché, les instances voient la version
# « en préparation » et ne l'installent pas.
#
# Clé privée : $EPICLUB_MINISIGN_KEY, par défaut ~/.minisign/epiclub.key
# (créée une fois : minisign -G -p ~/.minisign/epiclub.pub -s ~/.minisign/epiclub.key).
# =====================================================================
set -euo pipefail

TAG="${1:?Usage : $0 <tag, ex. v2.12.0>}"
CLE="${EPICLUB_MINISIGN_KEY:-$HOME/.minisign/epiclub.key}"
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
NOM="epiclub-${TAG}.zip"
DEPOT="${EPICLUB_DEPOT:-johnburnout/EPIClub}"

for outil in gh minisign php unzip; do
    command -v "$outil" >/dev/null || { echo "❌ Outil manquant : $outil" >&2; exit 1; }
done
[ -f "$CLE" ] || { echo "❌ Clé privée introuvable : $CLE" >&2; exit 1; }

TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT

# 1. Archive et somme publiées par la CI
gh release download "$TAG" --repo "$DEPOT" --dir "$TRAVAIL" --pattern "$NOM" --pattern "$NOM.sha256"
cd "$TRAVAIL"

# 2. Contrôles avant de signer
{ sha256sum -c "$NOM.sha256" 2>/dev/null || shasum -a 256 -c "$NOM.sha256"; } >/dev/null \
    || { echo "❌ Somme SHA-256 différente : archive non signée." >&2; exit 1; }
VERSION_ZIP="$(unzip -p "$NOM" "epiclub-${TAG}/version.txt" | tr -d '[:space:]')"
if [ "${VERSION_ZIP#v}" != "${TAG#v}" ]; then
    echo "❌ version.txt de l'archive : « $VERSION_ZIP », attendu « ${TAG#v} »." >&2
    exit 1
fi
echo "Archive $NOM : somme et version.txt conformes."

# 3. Signature (prehash BLAKE2b, format par défaut de minisign ≥ 0.11)
minisign -S -s "$CLE" -m "$NOM" -x "$NOM.minisig" -t "file:$NOM"

# 4. Vérification par le code même de l'updater
php -r '
    require $argv[1] . "/vendor/autoload.php";
    try {
        (new Epiclub\Engine\SignatureMinisign())->verifier(
            file_get_contents($argv[2]), file_get_contents($argv[2] . ".minisig"), basename($argv[2])
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "❌ " . $e->getMessage() . "\n");
        exit(1);
    }
' "$RACINE" "$TRAVAIL/$NOM" \
    || { echo "❌ Signature refusée par la clé publique de l'application : rien n'est attaché." >&2; exit 1; }
echo "Signature vérifiée avec la clé publique de l'application."

# 5. Publication
gh release upload "$TAG" "$NOM.minisig" --repo "$DEPOT"
echo "✅ $NOM.minisig attaché à la release $TAG."
