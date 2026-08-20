#!/usr/bin/env bash
#
# Object-create performance harness — the measurement half of
# openspec/changes/object-write-sub-500ms.
#
# Reports wall time (min / median / p95) plus the three counters that bound the
# implementation, so a regression is attributable rather than merely visible:
#
#   seq scans of oc_openregister_schemas    bound: <= 5 per write
#   seq scans of oc_openregister_registers
#   xact_commit                             bound: 1 per write
#
# A single timing is not evidence: the pre-change baseline varied from 13.6s to
# 99.1s on identical payloads, so this always reports a distribution.
#
# SAFETY — read before editing:
#   Every psql invocation writes to a FILE, never to a pipe that can close under
#   it. A backgrounded `docker exec psql` whose stdout pipe closed on a timeout
#   SIGPIPE'd a backend during this investigation and took the whole cluster
#   into recovery ("server process was terminated by signal 13: Broken pipe" ->
#   "all server processes terminated; reinitializing"). Keep it that way.
#
# Usage:
#   tests/perf/object-create.sh [-n RUNS] [-r REGISTER] [-s SCHEMA] [-p PAYLOAD_JSON]
#
# Environment:
#   NC_URL      default http://localhost:8080
#   NC_AUTH     default admin:admin
#
#     ⚠️ USE AN APP TOKEN, NOT THE ACCOUNT PASSWORD.
#     Nextcloud bcrypt-verifies a Basic-auth password on EVERY request. Measured
#     2026-07-29 on the same endpoint: account password median 1,058ms, app
#     token median 456ms — ~600ms of pure hashing per request. No real client
#     authenticates that way (browsers carry a session cookie, integrations use
#     app tokens), so benchmarking with the password measures bcrypt, not the
#     write path, and buries every improvement you are trying to see.
#
#       TOKEN=$(docker exec -u www-data -e OC_PASS=<pw> nextcloud \
#                 php occ user:add-app-password admin --password-from-env \
#                 | grep -oE '[A-Za-z0-9]{29,}')
#       NC_AUTH="admin:$TOKEN" tests/perf/object-create.sh
#   PG_CONTAINER default conduction-postgres
#   PG_USER     default oc_admin
#   PG_DB       default nextcloud
#   BUDGET_MS   default 500   (p95 budget; exit 1 when exceeded)
#   MAX_SCHEMA_SCANS default 5
#   MAX_COMMITS default 1
#
# Exit codes: 0 within budget, 1 over budget or over a counter bound, 2 setup error.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

NC_URL="${NC_URL:-http://localhost:8080}"
NC_AUTH="${NC_AUTH:-admin:admin}"
PG_CONTAINER="${PG_CONTAINER:-conduction-postgres}"
PG_USER="${PG_USER:-oc_admin}"
PG_DB="${PG_DB:-nextcloud}"
BUDGET_MS="${BUDGET_MS:-500}"
MAX_SCHEMA_SCANS="${MAX_SCHEMA_SCANS:-5}"
MAX_COMMITS="${MAX_COMMITS:-1}"

RUNS=10
REGISTER="larpingapp"
SCHEMA="character"
PAYLOAD=""

while getopts "n:r:s:p:h" opt; do
    case "$opt" in
        n) RUNS="$OPTARG" ;;
        r) REGISTER="$OPTARG" ;;
        s) SCHEMA="$OPTARG" ;;
        p) PAYLOAD="$OPTARG" ;;
        h) sed -n '2,40p' "$0"; exit 0 ;;
        *) exit 2 ;;
    esac
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------------------
# psql helper. Output ALWAYS goes to a file (see SAFETY above).
# ---------------------------------------------------------------------------
psql_to() {
    local out="$1"; shift
    docker exec "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" -tAc "$*" > "$out" 2>"$out.err" || {
        echo "[perf] psql failed: $(cat "$out.err")" >&2
        return 1
    }
}

COUNTER_SQL="SELECT
  coalesce((SELECT seq_scan FROM pg_stat_user_tables WHERE relname='oc_openregister_schemas'),0)
  || ' ' ||
  coalesce((SELECT seq_scan FROM pg_stat_user_tables WHERE relname='oc_openregister_registers'),0)
  || ' ' ||
  (SELECT xact_commit FROM pg_stat_database WHERE datname='$PG_DB');"

if ! psql_to "$WORK/probe" "SELECT 1"; then
    echo "[perf] cannot reach postgres in container '$PG_CONTAINER'" >&2
    exit 2
fi

if [ -z "$PAYLOAD" ]; then
    PAYLOAD='{"name":"__PROBE__"}'
fi

ENDPOINT="$NC_URL/index.php/apps/openregister/api/objects/$REGISTER/$SCHEMA"

# ---------------------------------------------------------------------------
# One timed create. Prints "<seconds> <http> <schema_scans> <register_scans> <commits>".
# ---------------------------------------------------------------------------
one_run() {
    local tag="$1"
    local body="${PAYLOAD//__PROBE__/perf-$tag-$RANDOM}"

    psql_to "$WORK/before" "$COUNTER_SQL" || return 1

    curl -s -m 400 -o "$WORK/resp" -w '%{time_total} %{http_code}' \
        -u "$NC_AUTH" -X POST \
        -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
        -d "$body" "$ENDPOINT" > "$WORK/timing" 2>"$WORK/curlerr" || true

    psql_to "$WORK/after" "$COUNTER_SQL" || return 1

    awk -v b="$(cat "$WORK/before")" -v a="$(cat "$WORK/after")" \
        '{split(b,B," "); split(a,A," ");
          printf "%s %s %d %d %d\n", $1, $2, A[1]-B[1], A[2]-B[2], A[3]-B[3]}' "$WORK/timing"
}

# ---------------------------------------------------------------------------
# Instance floor: the cost of an authenticated request that does NO object
# work. Nextcloud boots every enabled app on every request (autoload + DI +
# listener registration), and that cost belongs to the instance, not to the
# write path. Without subtracting it the budget measures how many apps the
# machine has installed. On the 2026-07-29 dev instance (92 enabled apps) the
# floor was ~950ms — nearly double the entire 500ms budget.
measure_floor() {
    local total=0 n=5 t
    for _ in $(seq 1 $n); do
        t="$(curl -s -m 60 -o /dev/null -w '%{time_total}' -u "$NC_AUTH" \
            -H 'OCS-APIRequest: true' \
            "$NC_URL/ocs/v2.php/cloud/capabilities?format=json" 2>/dev/null || echo 0)"
        total="$(awk -v a="$total" -v b="$t" 'BEGIN{print a+b}')"
    done
    awk -v t="$total" -v n="$n" 'BEGIN{printf "%.0f", (t/n)*1000}'
}

# A password-shaped credential is almost certainly the account password, which
# makes every sample ~600ms of bcrypt. Warn rather than refuse — the caller may
# genuinely want to measure that path.
case "${NC_AUTH#*:}" in
    *[!A-Za-z0-9]*|"") ;;
    *) [ "${#NC_AUTH}" -lt 40 ] && echo "[perf] WARNING: NC_AUTH looks like an account password, not an app token." \
        && echo "[perf]          Expect ~600ms/request of bcrypt on top of everything measured here." ;;
esac

echo "[perf] endpoint : $ENDPOINT"
echo "[perf] runs     : $RUNS (plus 2 warm-up, discarded)"
echo "[perf] budget   : p95 < ${BUDGET_MS}ms, schema scans <= ${MAX_SCHEMA_SCANS}, commits <= ${MAX_COMMITS}"
echo

# Warm-up: a cold PHP worker repays opcache + every lazy cache, and this
# harness measures the WARM path. Discarded, but reported so a pathological
# cold cost is still visible.
for i in 1 2; do
    r="$(one_run "warm$i")" || exit 2
    echo "[perf] warm-up $i: $(echo "$r" | awk '{printf "%.3fs http=%s schema_scans=%d commits=%d", $1, $2, $3, $5}')"
done
echo

: > "$WORK/results"
for i in $(seq 1 "$RUNS"); do
    r="$(one_run "r$i")" || exit 2
    echo "$r" >> "$WORK/results"
    echo "[perf] run $i: $(echo "$r" | awk '{printf "%.3fs http=%s schema_scans=%d register_scans=%d commits=%d", $1, $2, $3, $4, $5}')"
done

echo
FLOOR_MS="$(measure_floor)"
echo "[perf] instance floor (app-boot, no object work): ${FLOOR_MS}ms"
echo

awk -v budget="$BUDGET_MS" -v maxs="$MAX_SCHEMA_SCANS" -v maxc="$MAX_COMMITS" -v floor="$FLOOR_MS" '
{
    t[NR] = $1 * 1000
    if ($2 !~ /^2/) bad++
    if ($3 > worst_schema) worst_schema = $3
    if ($5 > worst_commits) worst_commits = $5
    sum_schema += $3; sum_commits += $5
}
END {
    n = NR
    if (n == 0) { print "[perf] no runs"; exit 2 }
    # insertion sort — n is small and this avoids depending on asort()
    for (i = 2; i <= n; i++) { v = t[i]; j = i - 1; while (j > 0 && t[j] > v) { t[j+1] = t[j]; j-- } t[j+1] = v }
    p95i = int(n * 0.95); if (p95i < 1) p95i = 1
    med = (n % 2) ? t[int(n/2)+1] : (t[n/2] + t[n/2+1]) / 2

    printf "[perf] wall           min=%.0fms  median=%.0fms  p95=%.0fms  max=%.0fms\n", t[1], med, t[p95i], t[n]
    printf "[perf] minus floor    min=%.0fms  median=%.0fms  p95=%.0fms   (floor %.0fms)\n", \
        (t[1]-floor < 0 ? 0 : t[1]-floor), (med-floor < 0 ? 0 : med-floor), \
        (t[p95i]-floor < 0 ? 0 : t[p95i]-floor), floor
    printf "[perf] schema seq scans  avg=%.1f  worst=%d  (bound %d)\n", sum_schema/n, worst_schema, maxs
    printf "[perf] commits           avg=%.1f  worst=%d  (bound %d)\n", sum_commits/n, worst_commits, maxc

    fail = 0
    if (bad)                { printf "[perf] FAIL %d run(s) did not return 2xx\n", bad; fail = 1 }
    if (t[p95i] > budget) {
        printf "[perf] FAIL p95 %.0fms exceeds %sms\n", t[p95i], budget
        if (t[p95i] - floor <= budget) {
            printf "[perf]      (write path itself is %.0fms — within budget; the overage is the %.0fms instance floor)\n", t[p95i]-floor, floor
        }
        fail = 1
    }
    if (worst_schema > maxs){ printf "[perf] FAIL %d schema seq scans exceeds %d\n", worst_schema, maxs; fail = 1 }
    if (worst_commits > maxc){printf "[perf] FAIL %d commits exceeds %d\n", worst_commits, maxc; fail = 1 }
    if (!fail) print "[perf] PASS"
    exit fail
}' "$WORK/results"
