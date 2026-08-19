#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# run-helper-suites.sh — run EVERY scripts/lib/test_* suite in this package.
#
# WHY THIS EXISTS
# ---------------
# The package ships ~19 helper self-tests under scripts/lib/. Until 2026-08-04
# CI named four of them by hand and ran those four. The other fifteen existed,
# looked like coverage, and were executed by nothing. Two of them had been
# pointing at fixture directories that never existed:
#
#   test_check_manifest.sh            — GREEN its whole life. A missing manifest
#                                       path makes the validator print "Tier 0,
#                                       skipping" and exit 0, which is exactly
#                                       what 2 of its 3 assertions expected.
#   test_check_manifest_crossref.js   — RED its whole life (13 of 21 assertions),
#                                       which nobody saw, because no job ran it.
#
# A hand-maintained list of test names is the same failure mode as a
# hand-maintained paths filter: adding a file does not add it to the list, and
# the omission is invisible. So this runner DISCOVERS the suites instead. A new
# scripts/lib/test_* file is executed by CI the moment it lands, with no
# workflow edit and nothing to forget.
#
# QUARANTINE
# ----------
# A suite may be quarantined only with a reason, and the quarantine cannot rot:
#   * a quarantined suite that no longer exists      -> hard failure (stale)
#   * a quarantined suite that now PASSES            -> hard failure (un-quarantine it)
# So the list can only shrink, and it tells you why each entry is on it.
#
# Run: bash hydra-gates/tests/run-helper-suites.sh   (exit 0 = all green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/.." && pwd)"
LIB="${PKG_ROOT}/scripts/lib"

# --- quarantine: "<basename>|<reason>" --------------------------------------
# These two fail for the SAME reason the crossref suite did — they reference
# fixture directories that have never existed in this repository:
#   scripts/test-fixtures/register-handler-resolution-{pass,fail}/
#   scripts/test-fixtures/orphaned-write-capability-{pass,fail}/
#   scripts/test-fixtures/orphaned-write-crossapp/
#   (+ the glob-recursion fixture tree)
# test_gate_orphaned_capability_fixtures.sh is the worse of the two: its `cd`
# into the missing directory fails, the gate then finds nothing, and its
# "pass fixture: 0 finding(s)" assertions report PASS on that empty result —
# green because the input is absent. Tracked, not silently unrun.
QUARANTINE=(
	"test_gate_glob_recursion_fixtures.sh|fixtures absent — 7 assertions red; needs the nested lib/ + src/ fixture tree authored (follow-up to the gate-30 fixture work)"
	"test_gate_orphaned_capability_fixtures.sh|fixtures absent — 3 assertions red AND 2 green-because-empty; needs register-handler-resolution-{pass,fail}, orphaned-write-capability-{pass,fail}, orphaned-write-crossapp authored"
)

is_quarantined() { # <basename> -> 0 if quarantined; echoes reason
	local name="$1" entry
	for entry in "${QUARANTINE[@]}"; do
		if [ "${entry%%|*}" = "${name}" ]; then
			printf '%s' "${entry#*|}"
			return 0
		fi
	done
	return 1
}

runner_for() { # <basename> -> interpreter
	case "$1" in
		*.py) echo python3 ;;
		*.js) echo node ;;
		*.sh) echo bash ;;
		*) echo "" ;;
	esac
}

# Per-invocation log directory, for the same reason the gate runner has one:
# two CI jobs running this harness on one host used to write
# /tmp/_helper_<name>.log over each other, so the failure output printed for a
# red suite could belong to a different run entirely.
LOGDIR="$(mktemp -d "${TMPDIR:-/tmp}/hydra-helper-suites.XXXXXXXX")" || {
	echo "FAIL — could not create a private log directory; refusing to run."
	exit 1
}

if [ ! -d "${LIB}" ]; then
	echo "FAIL — ${LIB} does not exist; this runner cannot discover anything"
	exit 1
fi

mapfile -t SUITES < <(find "${LIB}" -maxdepth 1 -type f -name 'test_*' -printf '%f\n' | sort)

# Guard the guard: discovering nothing must not look like "everything passed".
if [ "${#SUITES[@]}" -eq 0 ]; then
	echo "FAIL — discovered ZERO test suites under ${LIB}."
	echo "       An empty run is not a green run. Either the layout changed or"
	echo "       the discovery glob broke."
	exit 1
fi

echo "== discovered ${#SUITES[@]} helper suite(s) under scripts/lib/ =="
echo

_failed=()
_passed=0
_skipped=()

for name in "${SUITES[@]}"; do
	if reason="$(is_quarantined "${name}")"; then
		_skipped+=("${name}")
		echo "QUARANTINED — ${name}"
		echo "              ${reason}"
		continue
	fi
	cmd="$(runner_for "${name}")"
	if [ -z "${cmd}" ]; then
		echo "FAIL — ${name}: no interpreter for this extension"
		_failed+=("${name}")
		continue
	fi
	if ( cd "${PKG_ROOT}" && "${cmd}" "scripts/lib/${name}" ) > "${LOGDIR}/helper_${name}.log" 2>&1; then
		echo "PASS — ${name}"
		_passed=$((_passed + 1))
	else
		echo "FAIL — ${name} (exit $?)"
		sed 's/^/      /' "${LOGDIR}/helper_${name}.log" | tail -40
		_failed+=("${name}")
	fi
done

# --- the quarantine must not rot --------------------------------------------
echo
echo "== quarantine integrity =="
_rot=0
for entry in "${QUARANTINE[@]}"; do
	name="${entry%%|*}"
	if [ ! -f "${LIB}/${name}" ]; then
		echo "FAIL — quarantined suite '${name}' no longer exists. Remove it from QUARANTINE."
		_rot=$((_rot + 1))
		continue
	fi
	cmd="$(runner_for "${name}")"
	if ( cd "${PKG_ROOT}" && "${cmd}" "scripts/lib/${name}" ) > "${LOGDIR}/quar_${name}.log" 2>&1; then
		echo "FAIL — quarantined suite '${name}' now PASSES. Un-quarantine it: delete its"
		echo "       QUARANTINE entry so CI enforces it from here on."
		_rot=$((_rot + 1))
	else
		echo "still failing as documented — ${name}"
	fi
done

echo
echo "== summary =="
echo "   passed:      ${_passed}"
echo "   quarantined: ${#_skipped[@]}"
echo "   failed:      ${#_failed[@]}"

if [ "${#_failed[@]}" -eq 0 ] && [ "${_rot}" -eq 0 ]; then
	echo
	echo "ALL discovered helper suites PASSED (${#_skipped[@]} quarantined, each with a reason)"
	exit 0
fi

echo
if [ "${#_failed[@]}" -gt 0 ]; then
	echo "FAILED SUITES: ${_failed[*]}"
fi
if [ "${_rot}" -gt 0 ]; then
	echo "QUARANTINE ROT: ${_rot} entry/entries need attention"
fi
exit 1
