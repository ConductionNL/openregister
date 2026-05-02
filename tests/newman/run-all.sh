#!/bin/bash
##
# OpenRegister API Test Coverage — orchestrator.
#
# Runs every Newman / Postman collection registered under tests/newman/
# and tests/integration/, in dependency order, against a live OR
# instance. One canonical entry point so CI + local devs see the same
# end-to-end pass/fail surface.
#
# Each collection is responsible for its own setUp / tearDown so the
# orchestrator does not need to track inter-collection state — the
# only ordering constraint is "domain bootstraps before domains that
# depend on it" (see DOMAIN_ORDER below).
#
# Usage:
#   bash tests/newman/run-all.sh
#
# Env:
#   BASE_URL        — base URL for the API (default http://localhost)
#   ADMIN_USER      — admin user (default admin)
#   ADMIN_PASSWORD  — admin password (default admin)
#   CONTAINER_NAME  — NC container (default master-nextcloud-1)
#   FAIL_FAST       — stop on first failing collection (default 0)
#   COLLECTIONS     — space-separated subset to run; empty = all
##

set -e

# Colors.
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

BASE_URL=${BASE_URL:-"http://localhost"}
ADMIN_USER=${ADMIN_USER:-"admin"}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-"admin"}
CONTAINER_NAME=${CONTAINER_NAME:-"master-nextcloud-1"}
FAIL_FAST=${FAIL_FAST:-0}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

##
# Domain → collection-file map (run order matters: bootstrap, then crud,
# then everything that depends on objects existing).
##
DOMAIN_ORDER=(
    "crud"
    "graphql"
    "relations"
    "platform-annotations"
    "auth-matrix"
    "error-matrix"
    "files"
    "agent-cms"
    "referential-integrity"
    "federation"
)

declare -A DOMAIN_COLLECTIONS=(
    [crud]="$REPO_ROOT/tests/integration/openregister-crud.postman_collection.json"
    [referential-integrity]="$REPO_ROOT/tests/integration/openregister-referential-integrity.postman_collection.json"
    [graphql]="$REPO_ROOT/tests/newman/openregister-graphql-tests.postman_collection.json"
    [relations]="$REPO_ROOT/tests/newman/openregister-relations-tests.postman_collection.json"
    [platform-annotations]="$REPO_ROOT/tests/newman/openregister-platform-annotations.postman_collection.json"
    [auth-matrix]="$REPO_ROOT/tests/newman/openregister-auth-matrix.postman_collection.json"
    [error-matrix]="$REPO_ROOT/tests/newman/openregister-error-matrix.postman_collection.json"
    [files]="$REPO_ROOT/tests/newman/openregister-files-domain.postman_collection.json"
    [agent-cms]="$REPO_ROOT/tests/newman/agent-cms-testing.postman_collection.json"
    [federation]="$REPO_ROOT/tests/federation/federation-tests.postman_collection.json"
)

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  OpenRegister API Test Coverage Orchestrator${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Base URL:       ${YELLOW}$BASE_URL${NC}"
echo -e "  Container:      ${YELLOW}$CONTAINER_NAME${NC}"
echo -e "  Fail fast:      ${YELLOW}$FAIL_FAST${NC}"
echo ""

##
# Accept an explicit subset via COLLECTIONS env, else run every domain.
##
if [ -n "$COLLECTIONS" ]; then
    DOMAINS_TO_RUN=($COLLECTIONS)
else
    DOMAINS_TO_RUN=("${DOMAIN_ORDER[@]}")
fi

OVERALL_RC=0
PASSED=0
FAILED=0
SKIPPED=0
FAILED_DOMAINS=()

for domain in "${DOMAINS_TO_RUN[@]}"; do
    collection="${DOMAIN_COLLECTIONS[$domain]}"
    if [ -z "$collection" ] || [ ! -f "$collection" ]; then
        echo -e "${YELLOW}⊘ $domain — collection missing, skipping ($collection)${NC}"
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    echo -e "${BLUE}━━━ Running domain: ${domain} ━━━${NC}"

    rc=0
    docker exec -i "$CONTAINER_NAME" sh -c "
        cd /tmp && \
        cat > collection.json && \
        npx --yes newman run collection.json \
            --env-var baseUrl=$BASE_URL \
            --env-var username=$ADMIN_USER \
            --env-var password=$ADMIN_PASSWORD \
            --reporters cli \
            --color on
    " < "$collection" || rc=$?

    if [ "$rc" -eq 0 ]; then
        echo -e "${GREEN}✓ $domain passed${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}✗ $domain failed (rc=$rc)${NC}"
        FAILED=$((FAILED + 1))
        FAILED_DOMAINS+=("$domain")
        OVERALL_RC=1
        if [ "$FAIL_FAST" = "1" ]; then
            break
        fi
    fi
    echo ""
done

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Summary${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "  ${GREEN}Passed:  $PASSED${NC}"
echo -e "  ${RED}Failed:  $FAILED${NC}"
echo -e "  ${YELLOW}Skipped: $SKIPPED${NC}"
if [ "$FAILED" -gt 0 ]; then
    echo ""
    echo -e "${RED}Failed domains:${NC}"
    for d in "${FAILED_DOMAINS[@]}"; do
        echo -e "  - $d"
    done
fi

exit $OVERALL_RC
