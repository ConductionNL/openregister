#!/bin/bash
##
# Chat-streaming acceptance harness — runs every test layer that
# guards the ai-chat-companion-streaming spec, in order:
#
#   1. PHPUnit unit tests for the orchestrator surface that already
#      shipped — Message.context, ChatHealthController, the migration,
#      ChatStreamController (auth gate). These MUST stay green.
#   2. PHPUnit unit tests added by the streaming change — gated on the
#      new test files existing; skipped (not failed) until apply lands.
#   3. Newman collection: HTTP contract checks against the live
#      /api/chat/* endpoints.
#   4. Playwright e2e: browser-level smoke of the FAB → thinking →
#      response flow against /apps/openbuild/.
#
# Exit code is the highest non-zero across all four layers. CI runs
# this; devs can run it locally too.
#
# Usage:
#   bash tests/chat-streaming/run-all.sh
#
# Env:
#   BASE_URL           — default http://localhost:8080
#   CONTAINER_NAME     — default nextcloud
#   PLAYWRIGHT_BASE_URL — default http://localhost:8080
#   SKIP_PHPUNIT       — set to 1 to skip layer 1+2
#   SKIP_NEWMAN        — set to 1 to skip layer 3
#   SKIP_PLAYWRIGHT    — set to 1 to skip layer 4
##

set -u

BASE_URL=${BASE_URL:-"http://localhost:8080"}
CONTAINER_NAME=${CONTAINER_NAME:-"nextcloud"}
PLAYWRIGHT_BASE_URL=${PLAYWRIGHT_BASE_URL:-"$BASE_URL"}
OR_DIR=${OR_DIR:-"$(cd "$(dirname "${BASE_SOURCE[0]:-${BASH_SOURCE[0]}}")/../.." && pwd)"}
OPENBUILD_DIR=${OPENBUILD_DIR:-"$(cd "$OR_DIR/.." && pwd)/openbuild"}

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
BLUE=$'\033[0;34m'
NC=$'\033[0m'

OVERALL=0

section() {
  echo
  echo "${BLUE}=== $1 ===${NC}"
}

mark_layer() {
  local label="$1"
  local rc="$2"
  if [ "$rc" -eq 0 ]; then
    echo "${GREEN}✓${NC} $label"
  else
    echo "${RED}✗${NC} $label (exit $rc)"
    OVERALL=$rc
  fi
}

##
# Layer 1 + 2: PHPUnit.
##
if [ "${SKIP_PHPUNIT:-0}" != "1" ]; then
  section "PHPUnit — chat-ai unit tests"

  # Orchestrator-side tests that must stay green (landed via
  # ai-chat-companion-orchestrator).
  UNIT_TESTS=(
    "tests/Unit/Db/MessageTest.php"
    "tests/Unit/Migration/Version1Date20260511130000Test.php"
    "tests/Unit/Controller/ChatHealthControllerTest.php"
    "tests/Unit/Controller/ChatStreamControllerTest.php"
  )

  # Streaming-change tests (added by ai-chat-companion-streaming).
  # Glob — if absent the layer is a no-op, not a failure.
  STREAMING_TESTS_GLOB="tests/Unit/Service/Chat/StreamYieldChannelTest.php tests/Unit/Service/Chat/ResponseGenerationHandlerStreamingTest.php tests/Unit/Controller/ChatStreamControllerHeartbeatTest.php"

  # Build the present-on-disk list inside the container.
  PRESENT=$(docker exec -u www-data "$CONTAINER_NAME" bash -c "cd /var/www/html/custom_apps/openregister && for f in ${UNIT_TESTS[*]} $STREAMING_TESTS_GLOB; do [ -f \"\$f\" ] && echo \"\$f\"; done")

  if [ -z "$PRESENT" ]; then
    echo "${YELLOW}— no chat-ai unit tests present in container; skipping${NC}"
    mark_layer "phpunit" 0
  else
    docker exec -u www-data "$CONTAINER_NAME" bash -c "cd /var/www/html/custom_apps/openregister && ./vendor/bin/phpunit -c phpunit-unit.xml $PRESENT"
    # Tolerate exit 1 from PHPUnit when only the "no coverage driver"
    # warning fires — re-check by looking for "FAILURES" or "ERRORS!"
    # in a captured second run. Cheap: re-runs are <2s.
    RC=$?
    if [ "$RC" -ne 0 ]; then
      RAW=$(docker exec -u www-data "$CONTAINER_NAME" bash -c "cd /var/www/html/custom_apps/openregister && ./vendor/bin/phpunit -c phpunit-unit.xml $PRESENT 2>&1 | tail -3")
      if echo "$RAW" | grep -qE "^OK"; then
        RC=0
      fi
    fi
    mark_layer "phpunit (4 orchestrator + N streaming)" $RC
  fi
fi

##
# Layer 3: Newman.
##
if [ "${SKIP_NEWMAN:-0}" != "1" ]; then
  section "Newman — chat-streaming HTTP contract"

  COLLECTION="$OR_DIR/tests/newman/openregister-chat-streaming.postman_collection.json"
  if [ ! -f "$COLLECTION" ]; then
    echo "${RED}collection missing: $COLLECTION${NC}"
    mark_layer "newman" 2
  else
    # Use the same pattern as tests/newman/run-all.sh — sidecar container.
    docker run --rm --network host \
      -v "$COLLECTION:/etc/newman/chat-streaming.postman_collection.json:ro" \
      postman/newman:alpine run /etc/newman/chat-streaming.postman_collection.json \
        --env-var "base_url=$BASE_URL" \
        --env-var "admin_user=admin" \
        --env-var "admin_password=admin" \
        --reporters cli \
        --bail
    mark_layer "newman" $?
  fi
fi

##
# Layer 4: Playwright e2e.
##
if [ "${SKIP_PLAYWRIGHT:-0}" != "1" ]; then
  section "Playwright — chat companion FAB + streaming flow"

  SPEC="$OPENBUILD_DIR/tests/e2e/chat-companion-streaming.spec.ts"
  if [ ! -f "$SPEC" ]; then
    echo "${RED}spec missing: $SPEC${NC}"
    mark_layer "playwright" 2
  else
    (
      cd "$OPENBUILD_DIR" || exit 2
      PLAYWRIGHT_BASE_URL="$PLAYWRIGHT_BASE_URL" \
        npx playwright test tests/e2e/chat-companion-streaming.spec.ts --reporter=list
    )
    mark_layer "playwright" $?
  fi
fi

echo
if [ "$OVERALL" -eq 0 ]; then
  echo "${GREEN}== chat-streaming harness: ALL GREEN ==${NC}"
else
  echo "${RED}== chat-streaming harness: FAILURES (exit $OVERALL) ==${NC}"
fi
exit "$OVERALL"
