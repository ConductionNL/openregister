#!/bin/bash
##
# OpenRegister RBAC / IDOR Newman Runner
#
# Runs the multi-user authorization suite (rbac.postman_collection.json) against
# a live Nextcloud + OpenRegister instance. Follows the established integration
# pattern: host-split base_url, --ignore-redirects, Accept: application/json +
# OCS-APIRequest headers (set per-request inside the collection).
#
# The suite needs a NON-admin user `e2euser` with a password that satisfies
# Nextcloud's default password_policy (minLength 10). This script creates it
# idempotently (create-if-absent) via occ before running. The user is NOT
# deleted afterwards — it is a reusable fixture. Test objects/schemas/register
# are cleaned up by the collection's Teardown folder.
#
# NOTE: the collection ALSO self-provisions e2euser via the OCS user
# provisioning API in its own "S0. Provision e2euser" setup request, so it is
# runnable standalone (e.g. in CI, which does not run this script). The occ
# step here is a local-run convenience/fallback and is safe to double up with
# the collection's self-provisioning (both are create-if-absent).
#
# Usage:
#   bash run-newman.sh
#
# Environment variables:
#   BASE_URL       - Base URL reachable FROM INSIDE the container (default: http://localhost)
#   ADMIN_USER     - Admin username   (default: admin)
#   ADMIN_PASSWORD - Admin password   (default: admin)
#   E2E_USER       - Non-admin user   (default: e2euser)
#   E2E_PASSWORD   - Non-admin pass   (default: E2epass-1234; must satisfy password_policy minLength 10)
#   CONTAINER_NAME - Docker container (default: nextcloud)
##

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; YELLOW='\033[1;33m'; NC='\033[0m'

# Default BASE_URL depends on WHERE newman runs:
#  - host newman  -> http://localhost:8080 (the published container port)
#  - container newman -> http://localhost (port 80 inside the container)
# We auto-detect below; BASE_URL env always wins if set explicitly.
ADMIN_USER=${ADMIN_USER:-"admin"}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-"admin"}
E2E_USER=${E2E_USER:-"e2euser"}
E2E_PASSWORD=${E2E_PASSWORD:-"E2epass-1234"}
CONTAINER_NAME=${CONTAINER_NAME:-"nextcloud"}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION_HOST="$SCRIPT_DIR/rbac.postman_collection.json"
# Path to the collection as seen from inside the container. The app is served
# from custom_apps; fall back to apps-extra if needed.
COLLECTION_IN_CONTAINER="/var/www/html/custom_apps/openregister/tests/integration/rbac.postman_collection.json"

echo -e "${BLUE}=== OpenRegister RBAC / IDOR Integration Tests ===${NC}"
echo -e "  Base URL : ${YELLOW}${BASE_URL:-auto}${NC}"
echo -e "  Admin    : ${YELLOW}$ADMIN_USER${NC}"
echo -e "  Non-admin: ${YELLOW}$E2E_USER${NC}"
echo -e "  Container: ${YELLOW}$CONTAINER_NAME${NC}"
echo ""

# --- Env setup: create the non-admin user idempotently (create-if-absent). ---
echo -e "${BLUE}--> Ensuring non-admin user '$E2E_USER' exists${NC}"
if docker exec -u 33 "$CONTAINER_NAME" php occ user:info "$E2E_USER" >/dev/null 2>&1; then
    echo -e "${GREEN}    user already present (reused)${NC}"
else
    docker exec -u 33 -e OC_PASS="$E2E_PASSWORD" "$CONTAINER_NAME" \
        php occ user:add --password-from-env --display-name="E2E Test User" "$E2E_USER" \
        2>&1 | grep -vE "collation|DETAIL|HINT|WARNING" || true
    echo -e "${GREEN}    user created${NC}"
fi
echo ""

echo -e "${BLUE}--> Running Newman${NC}"
echo ""

run_newman () {
    local bin="$1"; local collection="$2"; local url="$3"
    "$bin" run "$collection" \
        --ignore-redirects \
        --env-var "base_url=$url" \
        --env-var "admin_user=$ADMIN_USER" \
        --env-var "admin_password=$ADMIN_PASSWORD" \
        --env-var "e2e_user=$E2E_USER" \
        --env-var "e2e_password=$E2E_PASSWORD" \
        --reporters cli \
        --color on \
        --disable-unicode
}

# Prefer host newman (reaches the published port localhost:8080); fall back to
# container newman (port 80 inside the container).
if command -v newman >/dev/null 2>&1; then
    URL=${BASE_URL:-"http://localhost:8080"}
    echo -e "${GREEN}    using host newman against $URL${NC}"; echo ""
    run_newman "$(command -v newman)" "$COLLECTION_HOST" "$URL"
elif docker exec "$CONTAINER_NAME" sh -c 'command -v newman' >/dev/null 2>&1; then
    URL=${BASE_URL:-"http://localhost"}
    echo -e "${GREEN}    using container newman against $URL${NC}"; echo ""
    docker exec -u 33 "$CONTAINER_NAME" newman run "$COLLECTION_IN_CONTAINER" \
        --ignore-redirects \
        --env-var "base_url=$URL" \
        --env-var "admin_user=$ADMIN_USER" \
        --env-var "admin_password=$ADMIN_PASSWORD" \
        --env-var "e2e_user=$E2E_USER" \
        --env-var "e2e_password=$E2E_PASSWORD" \
        --reporters cli --color on --disable-unicode
else
    echo -e "${RED}    newman not found on host or in container.${NC}"
    echo -e "${RED}    Install with: npm install -g newman${NC}"
    exit 1
fi
