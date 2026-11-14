#!/usr/bin/env bash
#
# Tests for tests/perf/lib/summarise.awk — the verdict half of object-create.sh.
# Runs without a Nextcloud instance: it feeds fixed run lines and checks the
# exit code and the verdict line.
#
# Usage: tests/perf/summarise.test.sh
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -uo pipefail

AWK_FILE="$(dirname "$0")/lib/summarise.awk"
fails=0

# 20 runs: 19 at the given ms, one slow outlier at the given ms.
runs() {
    local fast="$1" slow="$2" http="${3:-201}" scans="${4:-3}" commits="${5:-1}"
    for _ in $(seq 1 19); do
        awk -v m="$fast" -v h="$http" -v s="$scans" -v c="$commits" 'BEGIN{printf "%.3f %s %d 0 %d\n", m/1000, h, s, c}'
    done
    awk -v m="$slow" 'BEGIN{printf "%.3f 201 3 0 1\n", m/1000}'
}

# check <name> <expected exit> <expected output fragment> <floor> <budget> -- <runs args>
check() {
    local name="$1" want_rc="$2" want_text="$3" floor="$4" budget="$5"; shift 5
    local out rc
    out="$(runs "$@" | awk -v floor="$floor" -v floorbudget=50 -v budget="$budget" \
        -v maxs=5 -v maxc=1 -f "$AWK_FILE")"
    rc=$?
    if [ "$rc" != "$want_rc" ] || ! grep -qF -- "$want_text" <<< "$out"; then
        echo "FAIL $name: exit $rc (want $want_rc), output:"; echo "$out" | sed 's/^/    /'
        fails=$((fails + 1))
    else
        echo "ok   $name"
    fi
}

# 20 runs, nearest-rank p95 is the 19th: the single outlier is the max, not the p95.
check "under the floor budget passes"          0 "[perf] PASS"                                   200 "" 240 900
check "p95 exactly 50ms over the floor fails"  1 "p95 minus floor 50ms is not under 50ms"        200 "" 250 250
check "p95 49ms over the floor passes"         0 "[perf] PASS"                                   200 "" 249 249
check "slow wall on a slow floor still passes" 0 "minus floor    min=40ms"                       900 "" 940 940
check "absolute budget only gates when set"    1 "exceeds the absolute 500ms budget"             900 500 940 940
check "a non-2xx run fails"                    1 "did not return 2xx"                            200 "" 210 210 500
check "too many schema scans fail"             1 "6 schema seq scans exceeds 5"                  200 "" 210 210 201 6
check "more than one commit fails"             1 "2 commits exceeds 1"                           200 "" 210 210 201 3 2
check "wall figure is still reported"          0 "[perf] wall           min=240ms  median=240ms  p95=240ms  max=900ms" 200 "" 240 900

out="$(: | awk -v floor=200 -v floorbudget=50 -v maxs=5 -v maxc=1 -f "$AWK_FILE")"; rc=$?
if [ "$rc" = 2 ] && [ "$out" = "[perf] no runs" ]; then echo "ok   no runs is a setup error"; else echo "FAIL no runs: exit $rc"; fails=$((fails + 1)); fi

[ "$fails" = 0 ] && echo "all passed" || { echo "$fails failed"; exit 1; }
