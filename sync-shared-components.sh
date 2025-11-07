#!/bin/bash

###############################################################################
# Shared Components Sync Script
# 
# This script synchronizes shared Vue components from OpenRegister to other
# Conduction Nextcloud apps (OpenConnector, OpenCatalogi, SoftwareCatalog).
#
# Author: Conduction Development Team <info@conduction.nl>
# License: EUPL-1.2
# 
# Usage:
#   ./sync-shared-components.sh            # Sync to all apps
#   ./sync-shared-components.sh connector  # Sync only to OpenConnector
#   ./sync-shared-components.sh --dry-run  # Preview changes without copying
###############################################################################

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Source and target apps
SOURCE_DIR="openregister/src/components/shared"
APPS=("openconnector" "opencatalogi" "softwarecatalog")

# Parse arguments
DRY_RUN=false
TARGET_APP=""

for arg in "$@"; do
  case $arg in
    --dry-run)
      DRY_RUN=true
      shift
      ;;
    connector)
      TARGET_APP="openconnector"
      shift
      ;;
    catalogi)
      TARGET_APP="opencatalogi"
      shift
      ;;
    catalog)
      TARGET_APP="softwarecatalog"
      shift
      ;;
    --help|-h)
      echo "Usage: $0 [OPTIONS] [APP]"
      echo ""
      echo "Options:"
      echo "  --dry-run    Preview changes without copying"
      echo "  --help, -h   Show this help message"
      echo ""
      echo "Apps:"
      echo "  connector    Sync only to OpenConnector"
      echo "  catalogi     Sync only to OpenCatalogi"
      echo "  catalog      Sync only to SoftwareCatalog"
      echo ""
      echo "Examples:"
      echo "  $0                    # Sync to all apps"
      echo "  $0 connector          # Sync only to OpenConnector"
      echo "  $0 --dry-run          # Preview without copying"
      exit 0
      ;;
    *)
      echo -e "${RED}Unknown argument: $arg${NC}"
      echo "Use --help for usage information"
      exit 1
      ;;
  esac
done

# Determine which apps to sync
if [ -n "$TARGET_APP" ]; then
  APPS=("$TARGET_APP")
fi

# Check if source directory exists
if [ ! -d "$SOURCE_DIR" ]; then
  echo -e "${RED}Error: Source directory not found: $SOURCE_DIR${NC}"
  echo "Please run this script from the apps-extra directory"
  exit 1
fi

# Print header
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║         Shared Components Sync Script                     ║${NC}"
echo -e "${BLUE}║         Conduction B.V. © 2024                            ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$DRY_RUN" = true ]; then
  echo -e "${YELLOW}🔍 DRY RUN MODE - No files will be copied${NC}"
  echo ""
fi

echo -e "${BLUE}Source:${NC} $SOURCE_DIR"
echo -e "${BLUE}Target apps:${NC} ${APPS[*]}"
echo ""

# Count files in source
FILE_COUNT=$(find "$SOURCE_DIR" -type f | wc -l)
echo -e "${BLUE}Files to sync:${NC} $FILE_COUNT"
echo ""

# Sync to each app
SUCCESS_COUNT=0
FAIL_COUNT=0

for app in "${APPS[@]}"; do
  TARGET_DIR="$app/src/components/shared"
  
  echo -e "${BLUE}────────────────────────────────────────${NC}"
  echo -e "📦 Syncing to: ${YELLOW}$app${NC}"
  
  # Check if app directory exists
  if [ ! -d "$app" ]; then
    echo -e "${RED}   ✗ App directory not found: $app${NC}"
    ((FAIL_COUNT++))
    continue
  fi
  
  # Check if components directory exists
  if [ ! -d "$app/src/components" ]; then
    echo -e "${YELLOW}   ⚠ Creating components directory${NC}"
    if [ "$DRY_RUN" = false ]; then
      mkdir -p "$app/src/components"
    fi
  fi
  
  # Show what will be copied
  if [ -d "$TARGET_DIR" ]; then
    echo -e "${YELLOW}   ℹ Target directory exists (will be updated)${NC}"
  else
    echo -e "${YELLOW}   ℹ Target directory will be created${NC}"
  fi
  
  # Copy files
  if [ "$DRY_RUN" = false ]; then
    if cp -r "$SOURCE_DIR" "$app/src/components/"; then
      echo -e "${GREEN}   ✓ Successfully synced $FILE_COUNT files${NC}"
      ((SUCCESS_COUNT++))
    else
      echo -e "${RED}   ✗ Failed to sync files${NC}"
      ((FAIL_COUNT++))
    fi
  else
    echo -e "${YELLOW}   → Would copy $FILE_COUNT files${NC}"
    ((SUCCESS_COUNT++))
  fi
done

# Print summary
echo -e "${BLUE}────────────────────────────────────────${NC}"
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                      Summary                               ║${NC}"
echo -e "${BLUE}╠════════════════════════════════════════════════════════════╣${NC}"

if [ "$DRY_RUN" = true ]; then
  echo -e "${BLUE}║${NC} Mode:           ${YELLOW}DRY RUN (no changes made)${NC}"
else
  echo -e "${BLUE}║${NC} Mode:           ${GREEN}LIVE${NC}"
fi

echo -e "${BLUE}║${NC} Total apps:     ${#APPS[@]}"
echo -e "${BLUE}║${NC} Successful:     ${GREEN}$SUCCESS_COUNT${NC}"
echo -e "${BLUE}║${NC} Failed:         ${RED}$FAIL_COUNT${NC}"
echo -e "${BLUE}║${NC} Files synced:   $FILE_COUNT"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$DRY_RUN" = false ] && [ $SUCCESS_COUNT -gt 0 ]; then
  echo -e "${GREEN}✓ Sync completed successfully!${NC}"
  echo ""
  echo -e "${YELLOW}Next steps:${NC}"
  echo "  1. Test each app: npm run dev"
  echo "  2. Run linters: npm run lint"
  echo "  3. Check for import errors in browser console"
  echo ""
elif [ "$DRY_RUN" = true ]; then
  echo -e "${YELLOW}ℹ This was a dry run. Run without --dry-run to actually copy files.${NC}"
  echo ""
fi

if [ $FAIL_COUNT -gt 0 ]; then
  echo -e "${RED}⚠ Some apps failed to sync. Check errors above.${NC}"
  exit 1
fi

exit 0

