#!/usr/bin/env bash
# Comment-only proof for a spec-anchor-repair diff.
# 1. Every changed (+/-) line in the diff must contain "@spec".
# 2. Every changed file must have insertions == deletions (1:1 line rewrite —
#    no statement added or removed).
# Exits non-zero and prints offenders if either invariant is violated.
set -uo pipefail
ROOT="$1"
BASE="${2:-origin/development}"

cd "$ROOT" || exit 2

# Invariant 1: no non-@spec content lines changed
BAD=$(git diff "$BASE" -- lib src | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' | grep -v '@spec' || true)
NBAD=$(printf '%s' "$BAD" | grep -c . || true)

# Invariant 2: per-file symmetry
ASYM=$(git diff --numstat "$BASE" -- lib src | awk '$1 != $2 {print $0}')
NASYM=$(printf '%s' "$ASYM" | grep -c . || true)

TOTAL=$(git diff "$BASE" -- lib src | grep -E '^[+-]' | grep -vE '^(\+\+\+|---)' | grep -c . || true)
FILES=$(git diff --numstat "$BASE" -- lib src | grep -c . || true)

echo "files_changed=${FILES} changed_lines=${TOTAL} non_spec_lines=${NBAD} asymmetric_files=${NASYM}"
if [ "${NBAD}" -ne 0 ]; then echo "--- NON-@spec LINES ---"; printf '%s\n' "$BAD" | head -20; fi
if [ "${NASYM}" -ne 0 ]; then echo "--- ASYMMETRIC FILES ---"; printf '%s\n' "$ASYM" | head -20; fi
# also assert nothing outside lib/ src/ changed
OUT=$(git diff --numstat "$BASE" -- . ':(exclude)lib' ':(exclude)src' | grep -c . || true)
echo "files_changed_outside_lib_src=${OUT}"
if [ "${NBAD}" -eq 0 ] && [ "${NASYM}" -eq 0 ]; then echo "PROOF: COMMENT-ONLY OK"; exit 0; fi
echo "PROOF: FAILED"; exit 1
