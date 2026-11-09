#!/usr/bin/env bash
# Pre-checks single cloud PRs against current main so the integrator's trees come out green.
# Holds no lease and never merges. A red PR is parked (the integrator skips parked heads);
# a green one is recorded in queue/prechecked.txt.
#   precheck.sh next <k> <K>  pick the next open candidate with number % K == k that has no result
#                             at its head, build its one-PR tree on main and start the suite.
#                             Exit 0 = started, 1 = nothing left for this lane, other = trouble.
#   precheck.sh finish        wait up to 170 s; exit 3 while the suite still runs, else record the
#                             result and print GREEN, RED, PARKED or NORESULT.
set -o pipefail
BIN=$(dirname "$(readlink -f "$0")")
I=$BIN/integrate.sh
export SIG_WORK=${SIG_WORK:-$HOME/sigint} CAP=1 NO_LEASE=1
W=$SIG_WORK
export ST=$W/state ST_ME_FILE=$W/.id SIG_ROLE=precheck
source "$BIN/state.sh"

case "$1" in
next)
	k=${2:?lane index}; K=${3:?lane count}
	bash "$I" select >/dev/null 2>&1; rc=$?
	[ "$rc" = 1 ] && { echo "NONE no candidates"; exit 1; }
	[ "$rc" = 0 ] || { echo "SELECT-FAILED rc=$rc"; exit 6; }
	cp "$W/cand.tsv" "$W/all.tsv"
	while IFS=$'\t' read -r n head ref review; do
		[ $((n % K)) = "$k" ] || continue
		grep -qE "^$n $head " "$ST/queue/prechecked.txt" 2>/dev/null && continue
		grep -qE "^openregister#$n $head( |$)" "$ST/parked.txt" 2>/dev/null && continue
		grep -qE "^$n $head$" "$W/skipped.txt" 2>/dev/null && continue
		printf '%s\t%s\t%s\t%s\n' "$n" "$head" "$ref" "$review" > "$W/cand.tsv"
		if ! out=$(bash "$I" build 2>&1); then
			echo "$n $head" >> "$W/skipped.txt"; echo "SKIP #$n: $(tail -1 <<<"$out")"; continue
		fi
		bash "$I" test >/dev/null || { echo "TEST-START-FAILED #$n"; exit 6; }
		echo "STARTED #$n ${head:0:8}"; exit 0
	done < "$W/all.tsv"
	echo "NONE nothing left for lane $k"; exit 1 ;;
finish)
	bash "$I" wait 170 >/dev/null || exit 3
	n=$(cut -f1 "$W/tree.tsv"); head=$(cut -f2 "$W/tree.tsv")
	out=$(bash "$I" verdict 2>&1); vrc=$?
	case "$vrc" in
		0) st_append queue/prechecked.txt "$n $head GREEN $(date -u +%FT%TZ)"; echo "GREEN #$n ${head:0:8}" ;;
		5) st_append queue/prechecked.txt "$n $head RED $(date -u +%FT%TZ)"; echo "PARKED #$n ${head:0:8} (auto-park)" ;;
		6) if grep -q '^RED' <<<"$out"; then
			st_append parked.txt "$(echo "openregister#$n $head precheck-red alone on main: $(head -3 "$W/run/failures.txt" 2>/dev/null | paste -sd';')" | cut -c1-300)"
			st_append queue/prechecked.txt "$n $head RED $(date -u +%FT%TZ)"; echo "RED #$n ${head:0:8} parked"
		   else echo "NORESULT #$n: $(tail -1 <<<"$out")"; fi ;;
		*) echo "NORESULT #$n rc=$vrc: $(tail -1 <<<"$out")" ;;
	esac ;;
*) echo "usage: precheck.sh next <k> <K> | finish"; exit 2 ;;
esac
