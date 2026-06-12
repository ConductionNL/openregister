#!/usr/bin/env bash
#
# coverage-ratchet.sh — fleet-wide CI coverage ratchet for Conduction NC apps.
#
# A PR that adds untested code must FAIL (or at least visibly drop the number).
# This script re-measures LINE coverage for one suite, compares it to the
# committed baseline in tests/.coverage-baseline.json, and:
#   • FAILS (exit 1) if coverage dropped below  baseline - TOLERANCE.
#   • On an INCREASE, ratchets the baseline UP (rewrites the file) so the bar
#     can only ever rise — gated by --update (CI passes it; the bump lands as a
#     follow-up commit / is asserted clean).
#
# It is suite-agnostic: it does NOT run the test suite itself. The CI step runs
# the suite WITH coverage first (producing the report below), then calls this.
# That keeps the ratchet independent of each app's bootstrap quirks.
#
# Usage:
#   tests/coverage-ratchet.sh phpunit <clover.xml>            [--update] [--tolerance N]
#   tests/coverage-ratchet.sh vitest  <coverage-summary.json> [--update] [--tolerance N]
#
# Reports consumed:
#   • phpunit → PHPUnit clover XML  (statements == lines under pcov/xdebug).
#                 project <metrics statements="" coveredstatements=""/>.
#   • vitest  → @vitest/coverage-v8 json-summary  (total.lines.pct).
#
# Baseline file (tests/.coverage-baseline.json), committed per app:
#   { "phpunit": 52.25, "vitest": 2.13, "tolerance": 0.5 }
# A null/absent suite value means "not yet measured" — the ratchet SEEDS it on
# first green run (with --update) instead of failing.
#
# SPDX-FileCopyrightText: 2026 Conduction
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

SUITE="${1:-}"
REPORT="${2:-}"
shift 2 2>/dev/null || true

UPDATE=0
TOLERANCE=""   # default comes from baseline file (fallback 0.5)
while [ $# -gt 0 ]; do
	case "$1" in
		--update) UPDATE=1 ;;
		--tolerance) TOLERANCE="$2"; shift ;;
		*) echo "coverage-ratchet: unknown arg '$1'" >&2; exit 2 ;;
	esac
	shift
done

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASELINE_FILE="${COVERAGE_BASELINE_FILE:-$SCRIPT_DIR/.coverage-baseline.json}"

if [ -z "$SUITE" ] || [ -z "$REPORT" ]; then
	echo "usage: coverage-ratchet.sh {phpunit|vitest} <report> [--update] [--tolerance N]" >&2
	exit 2
fi
if [ ! -f "$REPORT" ]; then
	echo "coverage-ratchet: report '$REPORT' not found (did the suite run with coverage?)" >&2
	exit 2
fi

# ---------------------------------------------------------------------------
# Measure: extract LINE coverage % from the report.
# ---------------------------------------------------------------------------
measure_phpunit() {
	# Last project-level <metrics .../> carries the totals. statements maps to
	# lines under pcov/xdebug. Pure awk so it runs in a bare php/node container.
	awk '
		/<metrics / {
			s=""; cs="";
			if (match($0, /[ ]statements="[0-9]+"/))        { t=substr($0,RSTART,RLENGTH); gsub(/[^0-9]/,"",t); s=t }
			if (match($0, /coveredstatements="[0-9]+"/))     { t=substr($0,RSTART,RLENGTH); gsub(/[^0-9]/,"",t); cs=t }
			if (s!="") { last_s=s; last_cs=cs }
		}
		END {
			if (last_s=="" || last_s+0==0) { print "ERR"; exit }
			printf "%.2f", (last_cs/last_s)*100
		}
	' "$REPORT"
}

measure_vitest() {
	# total.lines.pct from the v8 json-summary. node is present in the node job.
	if command -v node >/dev/null 2>&1; then
		node -e '
			const fs = require("fs");
			const s = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
			const p = s && s.total && s.total.lines && s.total.lines.pct;
			if (typeof p !== "number") { console.log("ERR"); process.exit(0); }
			console.log(p.toFixed(2));
		' "$REPORT"
	else
		# Fallback: grep the pct out of total.lines without a JSON parser.
		awk 'BEGIN{RS="}"} /"lines"/ { if (match($0,/"pct":[ ]*[0-9.]+/)) { t=substr($0,RSTART,RLENGTH); gsub(/[^0-9.]/,"",t); print t; exit } }' "$REPORT"
	fi
}

case "$SUITE" in
	phpunit) CURRENT="$(measure_phpunit)" ;;
	vitest)  CURRENT="$(measure_vitest)" ;;
	*) echo "coverage-ratchet: suite must be 'phpunit' or 'vitest'" >&2; exit 2 ;;
esac

if [ "$CURRENT" = "ERR" ] || [ -z "$CURRENT" ]; then
	echo "coverage-ratchet: could not parse $SUITE line coverage from $REPORT" >&2
	exit 2
fi

# ---------------------------------------------------------------------------
# Read baseline (json) without jq — use whichever of php/node is around.
# ---------------------------------------------------------------------------
read_json_field() { # $1=field
	[ -f "$BASELINE_FILE" ] || { echo "null"; return; }
	if command -v node >/dev/null 2>&1; then
		node -e 'const fs=require("fs"); const b=JSON.parse(fs.readFileSync(process.argv[1],"utf8")); const v=b[process.argv[2]]; console.log(v===undefined||v===null?"null":v)' "$BASELINE_FILE" "$1"
	elif command -v php >/dev/null 2>&1; then
		php -r '$b=json_decode(file_get_contents($argv[1]),true)?:[]; $v=$b[$argv[2]]??null; echo $v===null?"null":$v;' "$BASELINE_FILE" "$1"
	else
		awk -v k="\"$1\"" 'BEGIN{FS="[:,}]"} $0 ~ k { for(i=1;i<=NF;i++) if($i ~ k){gsub(/[^0-9.]/,"",$(i+1)); print $(i+1); exit} }' "$BASELINE_FILE"
	fi
}

BASELINE="$(read_json_field "$SUITE")"
[ -z "$BASELINE" ] && BASELINE="null"
if [ -z "$TOLERANCE" ]; then
	TOLERANCE="$(read_json_field tolerance)"
	[ "$TOLERANCE" = "null" ] || [ -z "$TOLERANCE" ] && TOLERANCE="0.5"
fi

echo "coverage-ratchet [$SUITE]: current=${CURRENT}%  baseline=${BASELINE}  tolerance=${TOLERANCE}%"

# ---------------------------------------------------------------------------
# Compare with awk float math (no bc dependency).
# ---------------------------------------------------------------------------
write_baseline() { # $1 = new value for the active suite
	local new="$1"
	if command -v node >/dev/null 2>&1; then
		node -e '
			const fs=require("fs"); const f=process.argv[1];
			let b={}; try { b=JSON.parse(fs.readFileSync(f,"utf8")); } catch(e){ b={}; }
			b[process.argv[2]] = Number(process.argv[3]);
			if (b.tolerance===undefined) b.tolerance = Number(process.argv[4]);
			fs.writeFileSync(f, JSON.stringify(b,null,2)+"\n");
		' "$BASELINE_FILE" "$SUITE" "$new" "$TOLERANCE"
	elif command -v php >/dev/null 2>&1; then
		php -r '
			$f=$argv[1]; $b=is_file($f)?(json_decode(file_get_contents($f),true)?:[]):[];
			$b[$argv[2]]=(float)$argv[3]; if(!isset($b["tolerance"]))$b["tolerance"]=(float)$argv[4];
			file_put_contents($f, json_encode($b, JSON_PRETTY_PRINT)."\n");
		' "$BASELINE_FILE" "$SUITE" "$new" "$TOLERANCE"
	fi
	echo "coverage-ratchet [$SUITE]: baseline written → ${new}%  ($BASELINE_FILE)"
}

if [ "$BASELINE" = "null" ]; then
	# First measurement — seed it (only when allowed to write).
	if [ "$UPDATE" = "1" ]; then
		write_baseline "$CURRENT"
		exit 0
	fi
	echo "coverage-ratchet [$SUITE]: no baseline yet; run with --update to seed it." >&2
	exit 0
fi

# floor = baseline - tolerance ; ratchet up if current > baseline
RESULT="$(awk -v c="$CURRENT" -v b="$BASELINE" -v t="$TOLERANCE" 'BEGIN{
	floor=b-t;
	if (c+0 < floor+0) { print "FAIL"; }
	else if (c+0 > b+0) { print "UP"; }
	else { print "OK"; }
}')"

case "$RESULT" in
	FAIL)
		echo "❌ coverage-ratchet [$SUITE]: ${CURRENT}% is below floor ($(awk -v b="$BASELINE" -v t="$TOLERANCE" 'BEGIN{printf "%.2f", b-t}')%). Add tests for new code." >&2
		exit 1
		;;
	UP)
		echo "⬆️  coverage-ratchet [$SUITE]: ${CURRENT}% > baseline ${BASELINE}% — ratcheting up."
		[ "$UPDATE" = "1" ] && write_baseline "$CURRENT"
		exit 0
		;;
	OK)
		echo "✅ coverage-ratchet [$SUITE]: ${CURRENT}% holds at/above floor."
		exit 0
		;;
esac
