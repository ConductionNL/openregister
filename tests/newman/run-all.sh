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

BASE_URL=${BASE_URL:-"http://localhost:8080"}
ADMIN_USER=${ADMIN_USER:-"admin"}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-"admin"}
##
# Default to the dev container name shipped with the OR docker-dev
# bundle (`nextcloud`). The historical default (master-nextcloud-1) is
# from a different upstream stack and silently fails network discovery
# on this repo.
##
CONTAINER_NAME=${CONTAINER_NAME:-"nextcloud"}
FAIL_FAST=${FAIL_FAST:-0}
##
# Newman runner mode:
#   sidecar (default) — pull the upstream postman/newman:alpine image
#                       and run via `docker run` against the dev network.
#                       No Node needed in the NC container.
#   exec               — run via `docker exec $CONTAINER_NAME` using
#                       npx (assumes the NC container ships Node — the
#                       upstream nextcloud-dev image does NOT).
#   host               — run `newman` from the host PATH (devs who
#                       installed it via npm install -g newman).
##
NEWMAN_RUNNER=${NEWMAN_RUNNER:-"sidecar"}
NEWMAN_IMAGE=${NEWMAN_IMAGE:-"postman/newman:alpine"}
##
# When NEWMAN_RUNNER=sidecar, the sidecar container needs to reach
# the OR API. Default to the docker network the NC container is on.
##
NEWMAN_NETWORK=${NEWMAN_NETWORK:-""}
if [ -z "$NEWMAN_NETWORK" ] && command -v docker >/dev/null 2>&1; then
    NEWMAN_NETWORK=$(docker inspect "$CONTAINER_NAME" \
        --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' 2>/dev/null | head -1)
fi
##
# When NEWMAN_RUNNER=sidecar and the test sidecar runs on the same
# docker network as the NC container, the sidecar's `localhost` is
# itself, not NC. Rewrite localhost in BASE_URL to the container name.
##
NEWMAN_BASE_URL="$BASE_URL"
if [ "$NEWMAN_RUNNER" = "sidecar" ]; then
    case "$BASE_URL" in
        http://localhost*|http://127.0.0.1*)
            # Inside the docker network, the NC container exposes
            # apache on port 80 — the host's :8080 mapping is not
            # reachable. Strip any host port the user passed in.
            NEWMAN_BASE_URL=$(echo "$BASE_URL" | sed -E "s#http://(localhost|127\.0\.0\.1)(:[0-9]+)?#http://${CONTAINER_NAME}#")
            ;;
    esac
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

##
# Domain → collection-file map (run order matters: bootstrap, then crud,
# then everything that depends on objects existing).
#
# `agent-cms` and `federation` require external infrastructure (Ollama
# LLM, two-instance federation pair) that the default dev container
# does not provide; they are excluded from the default order and only
# run when explicitly requested via COLLECTIONS=...
##
DOMAIN_ORDER=(
    "crud"
    "graphql"
    "relations"
    "platform-annotations"
    "auth-matrix"
    "error-matrix"
    "files"
    "referential-integrity"
)

declare -A DOMAIN_COLLECTIONS=(
    [crud]="$REPO_ROOT/tests/integration/openregister-crud.postman_collection.json"
    [referential-integrity]="$REPO_ROOT/tests/integration/openregister-referential-integrity.postman_collection.json"
    [graphql]="$REPO_ROOT/tests/postman/openregister-graphql-tests.postman_collection.json"
    [relations]="$REPO_ROOT/tests/postman/openregister-relations-tests.postman_collection.json"
    [platform-annotations]="$REPO_ROOT/tests/postman/openregister-platform-annotations.postman_collection.json"
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
    # Collections in this repo split between two URL-variable
    # conventions: legacy ones use `{{base_url}}` (snake_case), newer
    # ones use `{{baseUrl}}` (camelCase). Set both so every collection
    # resolves the URL regardless of which convention it follows.
    case "$NEWMAN_RUNNER" in
        sidecar)
            # Run newman from the upstream postman image. Mount the
            # collection in via a stdin pipe + temp path under /etc.
            docker run --rm -i \
                ${NEWMAN_NETWORK:+--network "$NEWMAN_NETWORK"} \
                "$NEWMAN_IMAGE" \
                run /dev/stdin \
                --env-var baseUrl="$NEWMAN_BASE_URL" \
                --env-var base_url="$NEWMAN_BASE_URL" \
                --env-var username="$ADMIN_USER" \
                --env-var password="$ADMIN_PASSWORD" \
                --reporters cli \
                --color on \
                < "$collection" || rc=$?
            ;;
        exec)
            docker exec -i "$CONTAINER_NAME" sh -c "
                cd /tmp && \
                cat > collection.json && \
                npx --yes newman run collection.json \
                    --env-var baseUrl=$BASE_URL \
                    --env-var base_url=$BASE_URL \
                    --env-var username=$ADMIN_USER \
                    --env-var password=$ADMIN_PASSWORD \
                    --reporters cli \
                    --color on
            " < "$collection" || rc=$?
            ;;
        host)
            newman run "$collection" \
                --env-var baseUrl="$BASE_URL" \
                --env-var base_url="$BASE_URL" \
                --env-var username="$ADMIN_USER" \
                --env-var password="$ADMIN_PASSWORD" \
                --reporters cli \
                --color on || rc=$?
            ;;
        *)
            echo -e "${RED}✗ unknown NEWMAN_RUNNER: $NEWMAN_RUNNER (use: sidecar | exec | host)${NC}"
            exit 2
            ;;
    esac

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
