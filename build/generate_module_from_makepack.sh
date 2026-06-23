#!/bin/bash
#
# Script de génération du module basé sur makepack-multientity.conf
# Reproduit fidèlement la logique du workflow GitHub Actions
# Auteur: P'tite Tête
# Version: 0.1.0
#

set -e

echo "🔨 Génération du module MultiEntity basée sur makepack-multientity.conf"

# Lire les informations du module depuis le fichier conf
MODULE_VERSION=$(grep -E "^# Version:" build/makepack-multientity.conf | sed 's/# Version:[[:space:]]*//')
MODULE_NAME=$(grep -E "^# Project:" build/makepack-multientity.conf | sed 's/# Project:[[:space:]]*//')
MODULE_AUTHOR=$(grep -E "^# Author:" build/makepack-multientity.conf | sed 's/# Author:[[:space:]]*//')
MODULE_LICENSE=$(grep -E "^# Licence:" build/makepack-multientity.conf | sed 's/# Licence:[[:space:]]*//')

echo "📋 Informations du module:"
echo "  - Nom: ${MODULE_NAME}"
echo "  - Version: ${MODULE_VERSION}"
echo "  - Auteur: ${MODULE_AUTHOR}"
echo "  - Licence: ${MODULE_LICENSE}"

# Nettoyage
echo "🧹 Nettoyage des builds précédents..."
rm -rf temp_build
rm -rf dist/*

# Créer la structure temporaire
TEMP_DIR="temp_build"
MODULE_DIR="multientity"

echo "🏗️ Création de la structure du module..."
mkdir -p "${TEMP_DIR}/${MODULE_DIR}"

# Copier les fichiers selon makepack-multientity.conf
echo "📂 Copie des fichiers selon makepack-multientity.conf..."

while IFS= read -r line; do
    # Ignorer les commentaires et lignes vides
    [[ "$line" =~ ^#.*$ ]] && continue
    [[ -z "$line" ]] && continue

    # Format makepack officiel Dolibarr : chemins préfixés par le nom du module
    # (multientity/...). On retire le préfixe pour retrouver le chemin source
    # relatif à la racine du dépôt. Tolère aussi l'ancien format non préfixé.
    if [[ "$line" == multientity/* ]]; then
        relativePath="${line#multientity/}"
    else
        relativePath="$line"
    fi

    # Ignorer le fichier conf lui-même (un .info est généré à sa place)
    [[ "$relativePath" == build/makepack-multientity.conf ]] && continue

    # Exclusions (format officiel : multientity/!chemin ou .../dossier/!pattern).
    if [[ "$relativePath" == *"!"* ]]; then
        excludePath="${relativePath/!/}"
        if [[ "$excludePath" == *"*"* ]]; then
            rm -fr ${TEMP_DIR}/${MODULE_DIR}/${excludePath} 2>/dev/null || true
            echo "  🗑️  Exclusion (pattern): ${excludePath}"
        elif [[ -e "${TEMP_DIR}/${MODULE_DIR}/${excludePath}" ]]; then
            echo "  🗑️  Exclusion: ${excludePath}"
            rm -fr "${TEMP_DIR}/${MODULE_DIR}/${excludePath}"
        fi
        continue
    fi

    # Chemin source complet (relatif à la racine du dépôt)
    sourcePath="${relativePath}"

    if [[ -d "$sourcePath" ]]; then
        echo "  📁 Copie répertoire: ${relativePath}"
        mkdir -p "${TEMP_DIR}/${MODULE_DIR}/${relativePath}"
        if [[ -n "$(ls -A "$sourcePath" 2>/dev/null)" ]]; then
            cp -R "$sourcePath"/* "${TEMP_DIR}/${MODULE_DIR}/${relativePath}/" 2>/dev/null || true
        fi
    elif [[ -f "$sourcePath" ]]; then
        echo "  📄 Copie fichier: ${relativePath}"
        mkdir -p "${TEMP_DIR}/${MODULE_DIR}/$(dirname "$relativePath")"
        cp "$sourcePath" "${TEMP_DIR}/${MODULE_DIR}/${relativePath}"
    else
        echo "  ⚠️  Chemin non trouvé: ${sourcePath}"
    fi
done < build/makepack-multientity.conf

# Créer le répertoire build et le fichier info
mkdir -p "${TEMP_DIR}/${MODULE_DIR}/build"
cat > "${TEMP_DIR}/${MODULE_DIR}/build/makepack-multientity.info" << EOF
# Module information file
Name: $MODULE_NAME
Version: $MODULE_VERSION
Author: $MODULE_AUTHOR
License: $MODULE_LICENSE
Generated: $(date -u '+%Y-%m-%d %H:%M:%S UTC')
Generator: Local Script (makepack-based)
EOF

# Supprimer les répertoires de test s'ils existent
if [[ -d "${TEMP_DIR}/${MODULE_DIR}/test" ]]; then
    echo "🧹 Suppression répertoire test..."
    rm -rf "${TEMP_DIR}/${MODULE_DIR}/test"
fi

# Créer le package ZIP
mkdir -p dist
ZIP_NAME="module_multientity-${MODULE_VERSION}.zip"

echo "📦 Création du package: ${ZIP_NAME}"
cd ${TEMP_DIR}
zip -r "../dist/${ZIP_NAME}" . -q
cd ..

# Générer le checksum (convention makepack officiel Dolibarr : <zip>.md5)
echo "🔐 Génération du checksum..."
cd dist
if command -v md5sum >/dev/null 2>&1; then
    md5sum "${ZIP_NAME}" > "${ZIP_NAME}.md5"
else
    md5 -r "${ZIP_NAME}" > "${ZIP_NAME}.md5"
fi
cd ..

# Statistiques
PACKAGE_SIZE=$(du -h "dist/${ZIP_NAME}" | cut -f1)
PACKAGE_FILES=$(unzip -l "dist/${ZIP_NAME}" 2>/dev/null | tail -1 | awk '{print $2}')
PACKAGE_MD5=$(cut -d' ' -f1 "dist/${ZIP_NAME}.md5")

echo ""
echo "✅ Module généré avec succès:"
echo "  - Fichier: ${ZIP_NAME}"
echo "  - Taille: ${PACKAGE_SIZE}"
echo "  - Nombre de fichiers: ${PACKAGE_FILES}"
echo "  - MD5: ${PACKAGE_MD5}"
echo "  - Chemin: $(pwd)/dist/${ZIP_NAME}"

# Nettoyage final
rm -rf ${TEMP_DIR}

echo ""
echo "🚀 Package prêt selon makepack-multientity.conf!"
echo ""
echo "📋 Contenu du package (premiers fichiers):"
unzip -l "dist/${ZIP_NAME}" 2>/dev/null | head -30
