#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_errexit_discipline.sh — the runner must never enable errexit, and a
# crashing checker must not be able to kill the rest of the suite.
#
# WHAT THIS GUARDS (.github#243)
# ------------------------------
# run-hydra-gates.sh runs under `set -u` only. errexit is deliberately OFF,
# because a gate returning non-zero is how a gate reports findings.
#
# Twenty-six blocks wrapped a helper call in `set +e … set -e` and read the
# trailing `set -e` as "restore". It is an unconditional ENABLE: errexit was
# never on. The first offender sat in gate-19, so gates 20–64 ran under an
# errexit that the code around them does not expect.
#
# Measured before the fix, with a `python3` that exits 127: gate-39's unguarded
# `python3 - "$vue" <<'PYBN'` tripped errexit and the run DIED there —
# 37 of 64 gates emitted a verdict, 27 never executed.
#
# Two arms, because a static check and a behavioural check fail for different
# reasons and a fix that satisfies only one is not a fix:
#
#   ARM 1  no bare `set -e` line exists in run-hydra-gates.sh
#   ARM 2  a full run with a deliberately non-zero helper still reports EVERY
#          gate the runner declares, and prints no abort banner
#
# ARM 2 is the one that matters, and it is deliberately driven by the same
# mechanism that produced the original outage rather than by a mock.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
# Overridable so the fix can be MUTATION-CHECKED against a known-bad copy of the
# runner without editing the shipped file:
#
#   HYDRA_GATES_RUNNER_UNDER_TEST=/path/to/pre-fix/run-hydra-gates.sh \
#       bash scripts/lib/test_gate_errexit_discipline.sh
#
# Against the pre-fix runner both arms must go RED. A single-site mutation is
# NOT sufficient to red ARM 2 — the remaining `set +e` sites switch errexit back
# off a few gates later, which is exactly why the leak was survivable often
# enough to stay unnoticed. The honest mutant is the whole pre-fix file.
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()   { echo "  ok   — $1"; }
_bad()  { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_errexit_discipline.sh"

# ---------------------------------------------------------------------------
# ARM 1 — static: nothing re-enables errexit.
# ---------------------------------------------------------------------------
_bare=$(grep -n '^[[:space:]]*set -e$' "${_runner}" 2>/dev/null || true)
if [ -z "${_bare}" ]; then
    _ok "no bare 'set -e' in run-hydra-gates.sh"
else
    _bad "run-hydra-gates.sh re-enables errexit — a restore site must say 'set +e':"
    printf '%s\n' "${_bare}" | sed 's/^/         /'
fi

# The backstop must stay in place: every gate ends at one of these three, so
# re-asserting `set +e` there caps the blast radius of any future leak.
for _fn in _pass _fail _skip; do
    if awk -v fn="${_fn}" '
        $0 ~ "^"fn"\\(\\)" { inside = 1 }
        inside && /set \+e/ { found = 1 }
        inside && /^}/      { inside = 0 }
        END { exit(found ? 0 : 1) }
    ' "${_runner}"; then
        _ok "${_fn}() re-asserts 'set +e' (leak backstop)"
    else
        _bad "${_fn}() no longer re-asserts 'set +e' — a leaked errexit would survive past a gate verdict"
    fi
done

# ---------------------------------------------------------------------------
# ARM 2 — behavioural: a crashing checker must not truncate the suite.
#
# A `python3` shim that exits 127 stands in for "the checker had a bad day":
# not installed, OOM-killed, argv too long, syntax error in a helper. Every one
# of those reaches the runner as a non-zero exit from a bare command.
# ---------------------------------------------------------------------------
_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-errexit-test.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_app="${_tmp}/app"
mkdir -p "${_app}/src/views" "${_app}/openspec/specs" "${_app}/appinfo" "${_app}/lib"
printf '<template>\n  <div><button><span class="icon-x" /></button></div>\n</template>\n' \
    > "${_app}/src/views/Thing.vue"
printf '{"name":"fx"}\n' > "${_app}/src/manifest.json"
printf '<?php\nreturn [];\n' > "${_app}/appinfo/routes.php"
printf '## Purpose\n\n#### Scenario: a thing happens\n- WHEN x\n- THEN y\n' \
    > "${_app}/openspec/specs/spec.md"
(
    cd "${_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_shim="${_tmp}/shim"
mkdir -p "${_shim}"
printf '#!/bin/sh\necho "python3: simulated crash" >&2\nexit 127\n' > "${_shim}/python3"
chmod +x "${_shim}/python3"

_logs="${_tmp}/logs"
mkdir -p "${_logs}"
_out="${_tmp}/run.txt"
(
    cd "${_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_logs}" PATH="${_shim}:${PATH}" \
        bash "${_runner}" . > "${_out}" 2>&1
)

# The runner declares its own inventory; compare against that rather than a
# hardcoded 64, so adding a gate cannot quietly shrink this assertion.
_declared=$(grep -cE '^# Gate [0-9]+' "${_runner}" 2>/dev/null || echo 0)
_reported=$(grep -cE '^\[gate-[0-9]+\]' "${_out}" 2>/dev/null || echo 0)

if grep -q 'ABORTED before the summary' "${_out}"; then
    _bad "the run ABORTED — a crashing checker still kills the suite"
    grep -E '^\[gate-[0-9]+\]' "${_out}" | tail -1 | sed 's/^/         last verdict: /'
else
    _ok "no abort banner — the run survived a crashing checker"
fi

if [ "${_reported}" -ge "${_declared}" ] && [ "${_declared}" -gt 0 ]; then
    _ok "every declared gate reported (${_reported} verdicts for ${_declared} declared gates)"
else
    _bad "only ${_reported} of ${_declared} declared gates reported — the suite was truncated"
fi

# The summary must actually be reached; its absence is how a truncated run used
# to read as green.
if grep -q '^\[hydra-gates\] COVERAGE:' "${_out}"; then
    _ok "the coverage summary was reached"
else
    _bad "the coverage summary never printed"
fi

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_errexit_discipline.sh: ALL PASS"
    exit 0
fi
echo "test_gate_errexit_discipline.sh: ${_failures} FAILURE(S)"
exit 1
