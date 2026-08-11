#!/bin/bash
##
# Flow ENGINE coverage — the collection that actually executes flows.
#
# Eight real flows are created, RUN, and checked for what they changed:
# an API sync, an object change plus a notification, an AI mailbox summary, a
# scheduled quality sweep, an enrichment, approval routing, a retention sweep,
# and a batched export.
#
# Every run goes through the endpoint's `sync: true` mode, which executes the
# flow inline and answers with the finished run. That is what makes this
# runnable anywhere: asynchronously a run is queued for a background worker,
# and this dev stack sets `backgroundjobs_mode=cron` with nothing calling
# cron.php — so a queued run sits untouched forever and every assertion times
# out against a perfectly healthy engine.
#
# A separate entry point from run-all.sh only because of the leak check below;
# the collection itself needs nothing but a reachable instance.
#
# Usage:
#   bash tests/newman/run-flow-engine.sh
#
# Env:
#   BASE_URL        — API base (default http://localhost:8080)
#   ADMIN_USER      — admin user (default admin)
#   ADMIN_PASSWORD  — admin password (default admin)
#   DB_CONTAINER    — postgres container, for the leak check (default conduction-postgres)
#   AGENT_MODEL     — ollama model for the AI case (default qwen2.5:3b)
#   SKIP_LEAK_CHECK — 1 to skip the before/after row comparison
##

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

BASE_URL=${BASE_URL:-"http://localhost:8080"}
ADMIN_USER=${ADMIN_USER:-"admin"}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-"admin"}
DB_CONTAINER=${DB_CONTAINER:-"conduction-postgres"}
AGENT_MODEL=${AGENT_MODEL:-"qwen2.5:3b"}
SKIP_LEAK_CHECK=${SKIP_LEAK_CHECK:-0}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION="$SCRIPT_DIR/openregister-flow-engine.postman_collection.json"

echo -e "${BLUE}━━━ Flow engine coverage ━━━${NC}"
echo -e "  Base URL: ${YELLOW}$BASE_URL${NC}"

##
# What the collection must leave behind: nothing. Captured BEFORE the run so
# the comparison is a DELTA — the dev instance legitimately has flows and runs
# of its own, so an absolute count would prove nothing either way.
##
count_state() {
    docker exec "$DB_CONTAINER" psql -U oc_admin -d nextcloud -tAc \
        "select (select count(*) from oc_openregister_flows)||'/'||(select count(*) from oc_openregister_flow_runs)||'/'||(select count(*) from oc_openregister_flow_steps);" 2>/dev/null | tr -d ' '
}

BEFORE=""
if [ "$SKIP_LEAK_CHECK" != "1" ]; then
    BEFORE=$(count_state)
    echo -e "  State before (flows/runs/steps): ${YELLOW}${BEFORE:-unavailable}${NC}"
fi
echo ""

rc=0
newman run "$COLLECTION" \
    --env-var baseUrl="$BASE_URL" \
    --env-var base_url="$BASE_URL" \
    --env-var username="$ADMIN_USER" \
    --env-var password="$ADMIN_PASSWORD" \
    --env-var agentModel="$AGENT_MODEL" \
    --reporters cli \
    --color on || rc=$?

##
# The collection tears down after itself. VERIFY that rather than trusting it:
# a teardown request that 404s still reports "collection passed", and the
# residue only surfaces later as a dev database nobody can read. Before the
# delete cascade landed this instance had 493 orphaned runs across 80 dead
# flows, accumulated exactly that way.
##
if [ -n "$BEFORE" ]; then
    AFTER=$(count_state)
    echo ""
    echo -e "${BLUE}━━━ Leak check ━━━${NC}"
    echo -e "  before: ${YELLOW}${BEFORE}${NC}   after: ${YELLOW}${AFTER}${NC}  (flows/runs/steps)"

    if [ "$BEFORE" != "$AFTER" ]; then
        echo -e "${RED}✗ the collection left rows behind — teardown is incomplete${NC}"
        rc=1
    else
        echo -e "${GREEN}✓ no rows left behind${NC}"
    fi

    ORPHANS=$(docker exec "$DB_CONTAINER" psql -U oc_admin -d nextcloud -tAc \
        "select count(*) from oc_openregister_flow_runs r where not exists (select 1 from oc_openregister_flows f where f.uuid=r.flow_id);" 2>/dev/null | tr -d ' ')
    if [ -n "$ORPHANS" ] && [ "$ORPHANS" != "0" ]; then
        echo -e "${RED}✗ $ORPHANS orphaned run(s) — the delete cascade did not fire${NC}"
        rc=1
    else
        echo -e "${GREEN}✓ no orphaned runs${NC}"
    fi
fi

if [ "$rc" -eq 0 ]; then
    echo -e "${GREEN}✓ flow-engine passed${NC}"
else
    echo -e "${RED}✗ flow-engine failed (rc=$rc)${NC}"
fi

exit $rc
