#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_29_gitignore_negation.sh — gate-29 must read a `!` line as a
# NEGATION, not as an ignore rule (.github#293).
#
# WHAT WENT WRONG
# ---------------
# gate-29 built its lookup prefix with
#
#     _prefix=$(echo "${_pat}" | sed -E 's|^/||; s|/$||; s|^\!||')
#                                                    ^^^^^^^^^^
#
# — it STRIPPED the `!` and then looked the remainder up in `git ls-files`. A
# `!` line does the OPPOSITE of an ignore rule: it un-ignores. So a negation
# that matches a tracked file is the CORRECT, INTENDED state, and gate-29
# reported it as an oversight.
#
# Measured on ConductionNL/openbuild@development:
#
#     ignore_pattern=!tests/vitest/setup.js      tracked_file=tests/vitest/setup.js
#     ignore_pattern=!tests/e2e/global-setup.ts  tracked_file=tests/e2e/global-setup.ts
#
# Those two lines exist to rescue real source from a broader `**/setup*` rule:
#
#     **/setup*
#     !tests/vitest/setup.js
#     !tests/e2e/global-setup.ts
#
# WHY IT IS WORSE THAN NOISE
# --------------------------
# The finding pushes the author toward DELETING the negations — which would
# ignore two real test files. The gate as written can talk someone into causing
# the exact defect it exists to detect, and it is unclosable: openbuild fixed
# its 5 genuine findings (openbuild#161) and gate-29 still reported 2.
#
# NOT `git check-ignore`
# ----------------------
# `git check-ignore -q` EXITS 0 WHEN A NEGATION MATCHES, so an exit-code probe
# reports "ignored" for a file that is explicitly un-ignored. The gate's own
# docblock already refuses that command for a different reason (it answers from
# the working tree and calls a tracked file "not ignored"). This fix therefore
# does not reach for it: a `!` line is simply not an ignore rule, which is a
# question about the pattern, not about the filesystem.
#
# Run: bash scripts/lib/test_gate_29_gitignore_negation.sh
set -uo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
RUNNER="${LIB_DIR}/../run-hydra-gates.sh"

_fail_count=0
_pass_count=0

_ok()   { echo "  PASS — $1"; _pass_count=$((_pass_count + 1)); }
_bad()  { echo "  FAIL — $1"; _fail_count=$((_fail_count + 1)); }

if [ ! -f "${RUNNER}" ]; then
	echo "FAIL — runner not found at ${RUNNER}"
	exit 1
fi

# ---------------------------------------------------------------------------
# Build a throwaway app repo with a BASE commit and a HEAD commit whose diff
# adds .gitignore lines. gate-29 is intrinsically diff-relative, so the fixture
# has to be a real two-commit git history — this is the gate's actual contract,
# not a stand-in for it.
#
# Args: <gitignore-lines-added-at-HEAD> <tracked-file-1> [<tracked-file-2>...]
# Echoes the gate-29 findings (log lines), one per line.
# ---------------------------------------------------------------------------
_run_gate29() {
	local added="$1"; shift
	local root
	root="$(mktemp -d "${TMPDIR:-/tmp}/g29fixture.XXXXXX")" || return 1
	(
		cd "${root}" || exit 1
		git init -q .
		git config user.email t@example.com
		git config user.name t
		git config commit.gpgsign false
		# BASE: the tracked files exist, .gitignore is empty.
		local f
		for f in "$@"; do
			mkdir -p "$(dirname "${f}")"
			echo "// real source" > "${f}"
		done
		printf '# base\n' > .gitignore
		git add -A >/dev/null 2>&1
		git commit -qm base >/dev/null 2>&1
		git branch -f base HEAD >/dev/null 2>&1
		# HEAD: the diff ADDS the ignore lines under test.
		printf '%s\n' "${added}" >> .gitignore
		git add -A >/dev/null 2>&1
		git commit -qm head >/dev/null 2>&1
	) >/dev/null 2>&1 || { rm -rf "${root}"; return 1; }

	local logdir out
	logdir="$(mktemp -d "${TMPDIR:-/tmp}/g29logs.XXXXXX")"
	# The runner's exit status is DELIBERATELY not captured: it aggregates 60+
	# gates and says nothing about gate-29. The verdict is the log.
	out="$(cd "${root}" && HYDRA_GATE_LOG_DIR="${logdir}" \
		bash "${RUNNER}" --scope-to-diff --base base 2>&1)"
	if [ -f "${logdir}/hydra-gate-gitignore-then-commit.log" ]; then
		cat "${logdir}/hydra-gate-gitignore-then-commit.log"
	fi
	# Surface the verdict line on stderr so a human debugging this suite can
	# see whether the gate ran at all.
	printf '%s\n' "${out}" | grep -E '\[(hydra-gates\] )?gate-29' >&2
	rm -rf "${root}" "${logdir}"
	return 0
}

echo "== gate-29: a '!' line is a NEGATION, not an ignore rule (.github#293) =="
echo

# ---------------------------------------------------------------------------
# ARM 1 — the false positive must be GONE.
# openbuild's exact shape: a broad rule plus the negations that rescue real
# source from it, with both rescued files tracked.
# ---------------------------------------------------------------------------
_out="$(_run_gate29 '**/setup*
!tests/vitest/setup.js
!tests/e2e/global-setup.ts' tests/vitest/setup.js tests/e2e/global-setup.ts 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 0 ]; then
	_ok "arm 1: negations over tracked files report 0 (openbuild's shape)"
else
	_bad "arm 1: expected 0 findings, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ---------------------------------------------------------------------------
# ARM 2 — THE TRUE POSITIVE MUST STILL FIRE.
# A plain ignore rule over a tracked path is the defect gate-29 exists for
# (opencatalogi#539: 116 .phpunit.cache/ files shipped alongside the new rule).
# If arm 1's fix ever degrades into "skip anything with a bang in it", or into
# skipping the whole loop, this goes quiet.
# ---------------------------------------------------------------------------
_out="$(_run_gate29 'docs/build/' docs/build/index.html 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -ge 1 ] && printf '%s' "${_out}" | grep -q 'tracked_file=docs/build/index.html'; then
	_ok "arm 2: a plain ignore rule over a tracked file still FAILS (${_n} finding(s))"
else
	_bad "arm 2: expected >=1 finding naming docs/build/index.html, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ---------------------------------------------------------------------------
# ARM 3 — THE ABUSE CONTROL. A diff that adds BOTH a real ignore rule and a
# negation must still report the real one. This is the arm that proves the
# skip stays narrow: it would fail if the fix short-circuited the whole
# pattern loop on seeing a negation, or skipped the rest of the file.
# ---------------------------------------------------------------------------
_out="$(_run_gate29 '!tests/vitest/setup.js
docs/build/' tests/vitest/setup.js docs/build/index.html 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 1 ] && printf '%s' "${_out}" | grep -q 'tracked_file=docs/build/index.html'; then
	_ok "arm 3: negation skipped, the real rule beside it still reported"
elif printf '%s' "${_out}" | grep -q 'ignore_pattern=!'; then
	_bad "arm 3: a '!' pattern was reported as an ignore rule:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
else
	_bad "arm 3: expected exactly 1 finding (docs/build/index.html), got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ---------------------------------------------------------------------------
# ARM 4 — a negation whose path is NOT tracked is still not a finding, and the
# gate must not crash or invert on it.
# ---------------------------------------------------------------------------
_out="$(_run_gate29 '!tests/vitest/setup.js' src/main.js 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 0 ]; then
	_ok "arm 4: a negation over an untracked path reports 0"
else
	_bad "arm 4: expected 0 findings, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

echo
echo "== summary: ${_pass_count} passed, ${_fail_count} failed =="
[ "${_fail_count}" -eq 0 ] || exit 1
exit 0
