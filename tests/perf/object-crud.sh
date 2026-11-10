#!/usr/bin/env bash
#
# Object CRUD performance harness — create / read / search / update / delete.
#
# `object-create.sh` measures the write path only. This covers the rest of the
# object API, because a read that is slower than a write is worth knowing about
# and nothing here had ever been measured.
#
# Every figure is reported BOTH as wall time and as wall minus the instance
# floor. Nextcloud boots every enabled app on every request; on the 2026-07-30
# development instance (92 apps) that floor was 172-213 ms, which is larger than
# most of the operations below. Judging any of them on wall time alone measures
# how many apps the machine has installed.
#
# ⚠️ Authenticate with an APP TOKEN. HTTP Basic with the account password makes
# Nextcloud bcrypt-verify it on every request — measured 1,058 ms median against
# 456 ms for a token on the same endpoint. That ~600 ms of hashing is not
# something a real client pays, and it buries everything measured here.
#
# ⚠️ Check host load before trusting a result. These numbers move by 3x under
# unrelated load; the script prints the load average so a bad sample is visible.
#
# Usage:
#   NC_AUTH="admin:<app-token>" tests/perf/object-crud.sh [-n RUNS] [-r REGISTER] [-s SCHEMA]
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

NC_URL="${NC_URL:-http://localhost:8080}"
NC_AUTH="${NC_AUTH:-admin:admin}"
RUNS="${RUNS:-10}"
REGISTER="${REGISTER:-larpingapp}"
SCHEMA="${SCHEMA:-character}"
PAYLOAD_EXTRA="${PAYLOAD_EXTRA:-}"

while getopts "n:r:s:p:h" opt; do
    case "$opt" in
        n) RUNS="$OPTARG" ;;
        r) REGISTER="$OPTARG" ;;
        s) SCHEMA="$OPTARG" ;;
        p) PAYLOAD_EXTRA="$OPTARG" ;;
        h) sed -n '2,30p' "$0"; exit 0 ;;
        *) exit 2 ;;
    esac
done

API="$NC_URL/index.php/apps/openregister/api/objects/$REGISTER/$SCHEMA"
HDR=(-H 'Content-Type: application/json' -H 'OCS-APIRequest: true')
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# One timed request. Echoes seconds; body lands in $WORK/body.
timed() {
    curl -s -m 120 -o "$WORK/body" -w '%{time_total} %{http_code}' -u "$NC_AUTH" "$@"
}

# min / median / p95 over a file of "seconds http" lines, in ms.
stats() {
    awk '{t[NR]=$1*1000; if ($2 !~ /^2/) bad++}
         END{
           n=NR; if(n==0){print "no samples"; exit}
           for(i=2;i<=n;i++){v=t[i];j=i-1;while(j>0&&t[j]>v){t[j+1]=t[j];j--}t[j+1]=v}
           p=int(n*0.95); if(p<1)p=1
           m=(n%2)?t[int(n/2)+1]:(t[n/2]+t[n/2+1])/2
           printf "%6.0f %6.0f %6.0f", t[1], m, t[p]
           if(bad) printf "  (%d non-2xx)", bad
         }' "$1"
}

echo "[crud] endpoint : $API"
echo "[crud] runs     : $RUNS per operation"
echo "[crud] load     : $(uptime | sed 's/.*load average: //')"
echo

# ---------------------------------------------------------------------------
# Instance floor, measured in this same run (it moves with load).
# ---------------------------------------------------------------------------
: > "$WORK/floor"
for _ in $(seq 1 "$RUNS"); do
    timed "$NC_URL/ocs/v2.php/cloud/capabilities?format=json" -H 'OCS-APIRequest: true' >> "$WORK/floor"
    echo >> "$WORK/floor"
done
FLOOR=$(awk '{t[NR]=$1*1000} END{for(i=2;i<=NR;i++){v=t[i];j=i-1;while(j>0&&t[j]>v){t[j+1]=t[j];j--}t[j+1]=v} print int(t[1])}' "$WORK/floor")

# ---------------------------------------------------------------------------
# Seed: one object per run, so update/delete each act on their own row and no
# operation is measured against a row another operation already touched.
# ---------------------------------------------------------------------------
: > "$WORK/create"
: > "$WORK/uuids"
for i in $(seq 1 "$RUNS"); do
    body="{\"name\":\"crud-$i-$RANDOM\"${PAYLOAD_EXTRA:+,$PAYLOAD_EXTRA}}"
    timed -X POST "${HDR[@]}" -d "$body" "$API" >> "$WORK/create"
    echo >> "$WORK/create"
    python3 -c "
import json,sys
try:
    d=json.load(open('$WORK/body'))
    print(d.get('id') or d.get('uuid') or (d.get('@self') or {}).get('id',''))
except Exception:
    print('')
" >> "$WORK/uuids"
done

# ---------------------------------------------------------------------------
# READ one, SEARCH, UPDATE, DELETE
# ---------------------------------------------------------------------------
: > "$WORK/read"; : > "$WORK/search"; : > "$WORK/update"; : > "$WORK/delete"

while read -r uuid; do
    [ -n "$uuid" ] || continue
    timed "$API/$uuid" "${HDR[@]}" >> "$WORK/read"; echo >> "$WORK/read"
done < "$WORK/uuids"

for _ in $(seq 1 "$RUNS"); do
    timed "$API?limit=20" "${HDR[@]}" >> "$WORK/search"; echo >> "$WORK/search"
done

while read -r uuid; do
    [ -n "$uuid" ] || continue
    timed -X PUT "${HDR[@]}" -d "{\"name\":\"crud-updated-$RANDOM\"${PAYLOAD_EXTRA:+,$PAYLOAD_EXTRA}}" \
        "$API/$uuid" >> "$WORK/update"; echo >> "$WORK/update"
done < "$WORK/uuids"

while read -r uuid; do
    [ -n "$uuid" ] || continue
    timed -X DELETE "${HDR[@]}" "$API/$uuid" >> "$WORK/delete"; echo >> "$WORK/delete"
done < "$WORK/uuids"

printf '%-12s %6s %6s %6s   %s\n' "operation" "min" "med" "p95" "minus floor (med)"
printf '%-12s %6s %6s %6s\n' "----------" "-----" "-----" "-----"
for op in create read search update delete; do
    line="$(stats "$WORK/$op")"
    med=$(echo "$line" | awk '{print $2}')
    delta=$(awk -v m="$med" -v f="$FLOOR" 'BEGIN{d=m-f; if(d<0)d=0; printf "%.0f", d}')
    printf '%-12s %s   %sms\n' "$op" "$line" "$delta"
done
echo
echo "[crud] instance floor (median): ${FLOOR}ms"
echo "[crud] 'minus floor' is the operation's OWN cost; wall includes app boot."
