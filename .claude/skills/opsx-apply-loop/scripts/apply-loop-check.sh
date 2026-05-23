#!/bin/bash
# apply-loop-check.sh — single-poll status check for the apply-loop container
# Run once per monitoring cycle. Claude re-runs it in a loop, printing a brief status
# update to the user after each invocation (~1 min per cycle).
#
# Usage: apply-loop-check.sh <container-name> <work-dir>
#
# Exit codes:
#   0 = container still running — re-run to continue monitoring
#   1 = container stopped
#   2 = stuck detected (no file changes or output for STUCK_THRESHOLD seconds)
#   3 = usage error

CONTAINER="$1"
WORK_DIR="$2"
LIVE_LOG="$3"   # optional — if provided, all output is also appended to this file

if [ -z "$CONTAINER" ] || [ -z "$WORK_DIR" ]; then
  echo "Usage: $0 <container-name> <work-dir> [live-log-path]"
  exit 3
fi

# Tee all output to the live log if provided
if [ -n "$LIVE_LOG" ]; then
  exec > >(tee -a "$LIVE_LOG") 2>&1
fi

CHECK_INTERVAL=60
STUCK_THRESHOLD=300  # 5 minutes

# Persistent state files — survive across invocations for the same container run
ACTIVITY_REF="/tmp/apply-loop-activity-${CONTAINER}"
LAST_ACTIVITY_FILE="/tmp/apply-loop-last-activity-${CONTAINER}"

# Initialize on first invocation
[ -f "$ACTIVITY_REF" ] || touch "$ACTIVITY_REF"
[ -f "$LAST_ACTIVITY_FILE" ] || date +%s > "$LAST_ACTIVITY_FILE"
LAST_ACTIVITY_TIME=$(cat "$LAST_ACTIVITY_FILE")

# Quick-poll every 5s within the interval to catch early container exit
ELAPSED=0
while [ $ELAPSED -lt $CHECK_INTERVAL ]; do
  STATUS=$(docker inspect "$CONTAINER" --format '{{.State.Status}}' 2>/dev/null || echo "gone")
  if [ "$STATUS" != "running" ]; then
    EXIT_CODE=$(docker inspect "$CONTAINER" --format '{{.State.ExitCode}}' 2>/dev/null || echo "n/a")
    echo "CONTAINER_STOPPED status=$STATUS exit_code=$EXIT_CODE"
    DOCKER_FINAL=$(docker logs "$CONTAINER" --tail 10 2>&1)
    [ -n "$DOCKER_FINAL" ] && echo "--- final docker logs (last 10) ---" && echo "$DOCKER_FINAL"
    rm -f "$ACTIVITY_REF" "$LAST_ACTIVITY_FILE"
    exit 1
  fi
  sleep 5
  ELAPSED=$((ELAPSED + 5))
done

# Gather evidence after the interval
NOW=$(date +%s)
DOCKER_RECENT=$(docker logs "$CONTAINER" --since 65s 2>&1 | tail -5)
DOCKER_STATS=$(docker stats "$CONTAINER" --no-stream --format "CPU: {{.CPUPerc}}  MEM: {{.MemUsage}}" 2>/dev/null)
CHANGED_FILES=$(find "$WORK_DIR" -newer "$ACTIVITY_REF" \
  -not -path '*/.git/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -type f 2>/dev/null | head -10)

HAS_ACTIVITY=false
[ -n "$DOCKER_RECENT" ] && HAS_ACTIVITY=true
[ -n "$CHANGED_FILES" ] && HAS_ACTIVITY=true

if [ "$HAS_ACTIVITY" = true ]; then
  echo "$(date '+%H:%M:%S') — container active"
  [ -n "$DOCKER_STATS" ] && echo "  $DOCKER_STATS"
  [ -n "$CHANGED_FILES" ] && echo "  Files changed:" && echo "$CHANGED_FILES" | sed "s|${WORK_DIR}/||" | sed 's/^/    /'
  # shellcheck disable=SC2001  # sed is fine for this — multi-line indent prefixing
  [ -n "$DOCKER_RECENT" ] && echo "  Recent output:" && echo "$DOCKER_RECENT" | sed 's/^/    /'
  echo "$NOW" > "$LAST_ACTIVITY_FILE"
  touch "$ACTIVITY_REF"
else
  SILENT=$((NOW - LAST_ACTIVITY_TIME))
  SILENT_MIN=$((SILENT / 60))
  SILENT_SEC=$((SILENT % 60))
  echo "$(date '+%H:%M:%S') — idle for ${SILENT_MIN}m${SILENT_SEC}s (no file changes, no new output)"
  [ -n "$DOCKER_STATS" ] && echo "  $DOCKER_STATS"
  if [ $SILENT -ge $STUCK_THRESHOLD ]; then
    echo "STUCK_DETECTED no activity for ${SILENT}s"
    rm -f "$ACTIVITY_REF" "$LAST_ACTIVITY_FILE"
    exit 2
  fi
fi

exit 0
