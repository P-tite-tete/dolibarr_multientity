#!/bin/bash
# =============================================================================
# update_version.sh - Met à jour la version du module MultiEntity partout
# =============================================================================
# Usage: ./update_version.sh X.Y.Z
#
# Met à jour :
# 1. lib/version.lib.php (source unique — constante MULTIENTITY_MODULE_VERSION)
# 2. build/makepack-multientity.conf (commentaire # Version:)
# 3. ChangeLog.md (ajoute une entrée squelette si la version n'est pas déjà présente)
# 4. @version PHPDoc dans tous les fichiers PHP du module
#
# @author      P'tite Tête
# @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
# @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
# @version     0.1.0
# @since       0.1.0
# =============================================================================

set -euo pipefail

# Detect OS for sed -i compatibility (macOS BSD vs GNU/Linux)
sed_i() { if [[ "$OSTYPE" == "darwin"* ]]; then sed -i '' "$@"; else sed -i "$@"; fi; }

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

# Validation argument
if [ $# -ne 1 ]; then
    echo -e "${RED}Usage: $0 <version>${NC}"
    echo "  Exemple: $0 0.2.0"
    exit 1
fi

NEW_VERSION="$1"

# Validation format semver
if ! echo "$NEW_VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo -e "${RED}Erreur: version invalide '$NEW_VERSION' — format attendu: X.Y.Z${NC}"
    exit 1
fi

# Vérifier que l'on est bien à la racine du module
if [ ! -f "lib/version.lib.php" ]; then
    echo -e "${RED}Erreur: lib/version.lib.php introuvable. Executez depuis la racine du module.${NC}"
    exit 1
fi

# Lire la version actuelle
CURRENT_VERSION=$(grep "define('MULTIENTITY_MODULE_VERSION'" lib/version.lib.php | sed "s/.*'\([0-9]*\.[0-9]*\.[0-9]*\)'.*/\1/")

if [ -z "$CURRENT_VERSION" ]; then
    echo -e "${RED}Erreur: impossible de lire la version actuelle depuis lib/version.lib.php${NC}"
    exit 1
fi

if [ "$CURRENT_VERSION" = "$NEW_VERSION" ]; then
    echo -e "${YELLOW}La version est deja $NEW_VERSION — rien a faire.${NC}"
    exit 0
fi

# Échapper les points pour les motifs regex sed/grep
CURRENT_VERSION_RE=$(printf '%s' "$CURRENT_VERSION" | sed 's/\./\\./g')

echo -e "${BOLD}==============================================================================${NC}"
echo -e "${BOLD}  Mise a jour version MultiEntity : $CURRENT_VERSION → $NEW_VERSION${NC}"
echo -e "${BOLD}==============================================================================${NC}"
echo ""

UPDATED=0

# -----------------------------------------------
# 1. lib/version.lib.php (SOURCE UNIQUE)
# -----------------------------------------------
echo -e "${BLUE}[1/4] lib/version.lib.php${NC}"
sed_i "s/define('MULTIENTITY_MODULE_VERSION', '$CURRENT_VERSION_RE')/define('MULTIENTITY_MODULE_VERSION', '$NEW_VERSION')/" lib/version.lib.php
# Mettre à jour aussi le @version PHPDoc dans ce fichier
sed_i "s/@version[[:space:]]*$CURRENT_VERSION_RE/@version     $NEW_VERSION/" lib/version.lib.php
echo -e "  ${GREEN}OK${NC} MULTIENTITY_MODULE_VERSION: $CURRENT_VERSION → $NEW_VERSION"
UPDATED=$((UPDATED + 1))

# -----------------------------------------------
# 2. build/makepack-multientity.conf
# -----------------------------------------------
echo -e "${BLUE}[2/4] build/makepack-multientity.conf${NC}"
if [ -f "build/makepack-multientity.conf" ]; then
    sed_i "s/# Version:[[:space:]]*$CURRENT_VERSION_RE/# Version: $NEW_VERSION/" build/makepack-multientity.conf
    echo -e "  ${GREEN}OK${NC} Version commentaire: $CURRENT_VERSION → $NEW_VERSION"
    UPDATED=$((UPDATED + 1))
else
    echo -e "  ${YELLOW}SKIP${NC} Fichier introuvable"
fi

# -----------------------------------------------
# 3. ChangeLog.md (entrée squelette si absente)
# -----------------------------------------------
echo -e "${BLUE}[3/4] ChangeLog.md${NC}"
if [ -f "ChangeLog.md" ]; then
    TODAY=$(date +%Y-%m-%d)
    if grep -q "\[$NEW_VERSION\]" ChangeLog.md; then
        echo -e "  ${YELLOW}SKIP${NC} Entrée $NEW_VERSION déjà présente dans ChangeLog.md"
    else
        TMPFILE=$(mktemp)
        {
            printf '## [%s] — %s\n\n### Modifié\n\n- (à compléter)\n\n' "$NEW_VERSION" "$TODAY"
            cat ChangeLog.md
        } > "$TMPFILE"
        mv "$TMPFILE" ChangeLog.md
        echo -e "  ${GREEN}OK${NC} Squelette v$NEW_VERSION ajouté en tête de ChangeLog.md"
        UPDATED=$((UPDATED + 1))
    fi
else
    echo -e "  ${YELLOW}SKIP${NC} ChangeLog.md introuvable"
fi

# -----------------------------------------------
# 4. @version PHPDoc dans tous les fichiers PHP du module
# -----------------------------------------------
echo -e "${BLUE}[4/4] @version PHPDoc (fichiers PHP du module)${NC}"
PHP_COUNT=0

for dir in admin ajax class core css lib js sql test; do
    if [ -d "$dir" ]; then
        while IFS= read -r file; do
            if grep -q "@version.*$CURRENT_VERSION_RE" "$file"; then
                sed_i "s/@version[[:space:]]*$CURRENT_VERSION_RE/@version     $NEW_VERSION/" "$file"
                PHP_COUNT=$((PHP_COUNT + 1))
            fi
        done < <(find "$dir" -name "*.php" -type f)
    fi
done

for file in *.php; do
    if [ -f "$file" ] && grep -q "@version.*$CURRENT_VERSION_RE" "$file"; then
        sed_i "s/@version[[:space:]]*$CURRENT_VERSION_RE/@version     $NEW_VERSION/" "$file"
        PHP_COUNT=$((PHP_COUNT + 1))
    fi
done

echo -e "  ${GREEN}OK${NC} $PHP_COUNT fichier(s) PHP mis a jour"
UPDATED=$((UPDATED + PHP_COUNT))

# -----------------------------------------------
# Résumé
# -----------------------------------------------
echo ""
echo -e "${BOLD}==============================================================================${NC}"
echo -e "  ${GREEN}${BOLD}TERMINE${NC} — $UPDATED fichier(s) mis a jour de $CURRENT_VERSION vers $NEW_VERSION"
echo -e "${BOLD}==============================================================================${NC}"
echo ""

# -----------------------------------------------
# Alerte : versions hardcodées résiduelles (ancienne version oubliée)
# -----------------------------------------------
echo -e "${BLUE}Recherche d'occurrences résiduelles de l'ancienne version (${CURRENT_VERSION})...${NC}"
RESIDUAL=$(grep -rn "$CURRENT_VERSION_RE" \
    --include="*.php" \
    --include="*.sh" \
    --include="*.conf" \
    --exclude-dir=_bmad \
    --exclude-dir=docs \
    --exclude-dir=design-artifacts \
    . \
    | grep -v "^./lib/version.lib.php:" \
    | grep -v "^./update_version.sh:" \
    | grep -v "@since" \
    || true)

if [ -n "$RESIDUAL" ]; then
    echo -e "${YELLOW}  Attention — l'ancienne version $CURRENT_VERSION subsiste (hors @since) :${NC}"
    echo "$RESIDUAL" | while IFS= read -r line; do
        echo "    $line"
    done
    echo ""
    echo -e "${YELLOW}  Vérifiez si ces occurrences doivent être mises à jour vers $NEW_VERSION.${NC}"
else
    echo -e "  ${GREEN}Aucun oubli détecté (hors @since légitimes).${NC}"
fi

echo ""
echo -e "  ${BLUE}Prochaines étapes :${NC}"
echo "  1. Vérifier les changements  : git diff"
echo "  2. Compléter ChangeLog.md    : notes de version"
echo "  3. Commit + push             : git commit -m \"chore: bump version $NEW_VERSION\""
echo "  4. Build paquet              : bash build/generate_module_from_makepack.sh"
