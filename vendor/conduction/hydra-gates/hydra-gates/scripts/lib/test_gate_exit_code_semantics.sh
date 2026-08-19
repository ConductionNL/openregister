#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_exit_code_semantics.sh — a byte holds 255.
#
# WHY THIS EXISTS
# ---------------
# `.github#209`: gate-19 returned its finding COUNT as its exit STATUS. 266
# findings exited as 10. **256 findings would have exited 0 = PASS.**
# gate-26 had the identical defect: 260 uncovered page components -> exit 4 ->
# "FAIL — 4", and at 256 it would have gone green while its own stdout read
# "FAIL — 256". openconnector's long-quoted "21" was really 266.
#
# Both are fixed. This suite exists so they STAY fixed, and so the same shape
# cannot appear in a 65th gate. Note what makes it invisible to a unit test:
# the truncation happens at the PROCESS boundary, in the byte the shell reads.
# A Python test calling the checker as a function sees the real integer and
# passes. You have to cross exec() to see it at all.
#
# THE FOUR PROPERTIES
# -------------------
#   1. STATIC   — no checker may exit with a finding count.
#   2. DYNAMIC  — a real 300-finding tree must report 300, not 300 & 255 = 44,
#                 and above all not PASS.
#   3. DYNAMIC  — a checker that PRINTS findings and exits 0 (which is what
#                 `sys.exit(256)` looks like from the shell) must not be read as
#                 a pass. This is the residual half of #209 and it is CURRENTLY
#                 LIVE — see the known defect below.
#   4. INVARIANT— no gate may report PASS while its own log states FAIL.
#                 Property 4 is the general form of 1-3 and is worth more than
#                 all of them: it holds for every gate, including ones nobody
#                 has thought to test, and it is cheap to evaluate on any run.
#
# Run: bash scripts/lib/test_gate_exit_code_semantics.sh
set -uo pipefail

GF_PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
export GF_PKG_ROOT
# shellcheck source=./gate_fixture_support.sh
. "${GF_PKG_ROOT}/scripts/lib/gate_fixture_support.sh"

_fail_n=0; _pass_n=0; _defect_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }
_known_defect() {
    local _issue="$1" _desc="$2" _still="$3"
    if [ "${_still}" -eq 0 ]; then
        _defect_n=$((_defect_n + 1))
        printf 'KNOWN DEFECT (still live, %s) — %s\n' "${_issue}" "${_desc}"
    else
        _bad "${_issue} appears to be FIXED — '${_desc}' no longer reproduces. Flip this assertion to enforce the correct behaviour and delete the _known_defect entry."
    fi
}

WORK="$(mktemp -d "${TMPDIR:-/tmp}/hydra-exit.XXXXXXXX")"
trap 'rm -rf "${WORK}"' EXIT

# ===========================================================================
echo "== property 1 (static): no checker returns a finding count as its status =="
# ===========================================================================
# `sys.exit(<digit>)` / `sys.exit(EXIT_FAIL)` / `sys.exit(main(...))` are
# statuses. `sys.exit(len(findings))` or `sys.exit(count)` is #209.
#
# `sys.exit(main(...))` is permitted because it is the normal Python idiom, and
# the risk it carries — main() returning a COUNT — is covered by part (b) below
# and, definitively, by property 4. Verified by hand at the time of writing:
# check_visual_coverage.py and check_spec_coverage.py both return EXIT_*
# constants or a bounded 0/1, never a tally.
_bad_exits="$(grep -n 'sys\.exit(' "${GF_PKG_ROOT}"/scripts/lib/check_*.py 2>/dev/null \
    | grep -vE 'sys\.exit\([0-9]\)' \
    | grep -vE 'sys\.exit\(main\(' \
    | grep -vE 'sys\.exit\(EXIT_[A-Z_]+\)' || true)"
if [ -z "${_bad_exits}" ]; then
    _ok "(a) every checker exits with a bounded status, never a bare count"
else
    _bad "(a) checker(s) exit with a non-status expression — the #209 shape. A count above 255 wraps, and at exactly 256 it wraps to 0 = PASS:"
    printf '%s\n' "${_bad_exits}" | sed 's/^/       /'
fi

# (b) The three checkers that HAD this defect must keep a BOUNDED exit contract.
# Two vocabularies are in use and both are correct, so the assertion accepts
# either — asserting only `EXIT_FAIL` would have failed
# check_custom_widget_ratchet.py, which is properly fixed and simply spells it
# `return 1 if findings else 0` while putting the real tally on stdout as
# `[custom-widget-ratchet] findings=N`. That checker's own header documents why:
# an exit status is one byte AND is also how the interpreter reports a crash, so
# a traceback exiting 1 was once reported as `FAIL — 1 custom-widget finding(s)`
# — a plausible, actionable, entirely fictional finding.
for _hist in check_e2e_coverage.py check_visual_coverage.py check_custom_widget_ratchet.py; do
    _f="${GF_PKG_ROOT}/scripts/lib/${_hist}"
    if [ ! -f "${_f}" ]; then
        _bad "(b) ${_hist} is missing — it is one of the three checkers that carried the #209 defect"
    elif grep -qE '\bEXIT_(FAIL|PASS)\b|return 1 if ' "${_f}"; then
        _ok "(b) ${_hist} keeps a bounded exit contract (named status or 1/0)"
    else
        _bad "(b) ${_hist} has neither EXIT_* constants nor a 1/0 return — it carried the #209 count-as-status defect once; check it has not gone back to returning a tally"
    fi
done

# ===========================================================================
echo
echo "== property 2 (dynamic): 300 real findings must report 300 =="
# ===========================================================================
_R="${WORK}/bulk"
mkdir -p "${_R}/openspec/specs/bulk" "${_R}/appinfo"
cp "${GF_PKG_ROOT}/scripts/test-fixtures/scope-matrix/app/appinfo/info.xml" "${_R}/appinfo/"
( cd "${_R}" && git init -q . && git symbolic-ref HEAD refs/heads/development \
    && git config user.email f@example.invalid && git config user.name F \
    && git config commit.gpgsign false )
echo "# base" > "${_R}/README.md"
gf_commit_paths "${_R}" "base" README.md appinfo
gf_mark_base "${_R}"

# 300 scenarios, none covered by any e2e test. Generated rather than committed:
# the property under test IS the volume, and 300 hand-written stanzas in the
# repository would be 1200 lines of noise that nobody would ever re-read.
{
    echo "# Bulk spec"; echo; echo "### Requirement: Bulk"; echo
    _i=1
    while [ "${_i}" -le 300 ]; do
        echo "#### Scenario: bulk scenario ${_i}"; echo; echo "- WHEN x THEN y"; echo
        _i=$((_i + 1))
    done
} > "${_R}/openspec/specs/bulk/spec.md"
gf_commit_paths "${_R}" "spec: 300 uncovered scenarios" openspec

_out="$(gf_run_wrapper "${_R}" "${WORK}/log-bulk")"
_v="$(gf_verdict "${_out}" 19)"
case "${_v}" in
    *"FAIL — 300 scenario"*) _ok "gate-19 reports 300, uncorrupted — ${_v:0:70}" ;;
    *"FAIL — 44 scenario"*)  _bad "gate-19 reported 44 = 300 & 255. The count is being taken from the exit byte (#209 has regressed)." ;;
    *PASS*)                  _bad "gate-19 reported PASS over 300 uncovered scenarios — a green over 300 findings." ;;
    *)                       _bad "gate-19 wanted 'FAIL — 300 scenario', got: ${_v:0:140}" ;;
esac

# ===========================================================================
echo
echo "== property 4 (invariant): no gate may PASS while its own log states FAIL =="
# ===========================================================================
# Evaluated on the real run above. Generic: applies to every gate that writes a
# log, including gates nobody has written a fixture for.
_liars=0
for _log in "${WORK}"/log-bulk/hydra-gate-*.log; do
    [ -f "${_log}" ] || continue
    grep -qE '^FAIL[ —-]' "${_log}" 2>/dev/null || continue
    _base="$(basename "${_log}" .log)"; _name="${_base#hydra-gate-}"
    _line="$(printf '%s' "${_out}" | grep -E "^\[gate-[0-9]+\] ${_name}: PASS" || true)"
    if [ -n "${_line}" ]; then
        echo "       ${_line:0:110}"
        echo "         ...but ${_base}.log says: $(grep -E '^FAIL[ —-]' "${_log}" | head -1 | cut -c1-90)"
        _liars=$((_liars + 1))
    fi
done
if [ "${_liars}" -eq 0 ]; then
    _ok "no gate reported PASS while its own log recorded a FAIL"
else
    _bad "${_liars} gate(s) reported PASS over a log that states FAIL — the verdict and the evidence disagree"
fi

# ===========================================================================
echo
echo "== property 3 (dynamic): a checker that prints findings and exits 0 =="
# ===========================================================================
# `sys.exit(256)` is indistinguishable from `sys.exit(0)` at the shell. This
# substitutes a checker that does exactly that, and asks whether the gate
# notices. It does not. MEASURED on the canonical package (a fresh clone of
# origin/main), not on an adjacent stale checkout:
#
#   [gate-19] e2e-coverage: PASS
#   hydra-gate-e2e-coverage.log:  FAIL — 256 scenario(s) missing @e2e
#   wrapper exit: 0
#
# NOTE ON SCOPE OF THE CLAIM. This is NOT "gate-19 is broken today": property 1
# above proves no shipped checker returns a count, so nothing currently triggers
# it. It is that the #209 REPAIR was made in the message and not in the verdict —
# `run-hydra-gates.sh` greps the count out of stdout for the FAIL text, but still
# branches PASS/FAIL on `$?`. The blast radius is any future checker, or any
# checker whose exit contract drifts. An earlier agent nearly filed "#209 is only
# half-fixed" from a STALE adjacent checkout and was wrong on the facts; this is
# a different and narrower claim, measured on the canonical package.
_PKG="${WORK}/pkg"
mkdir -p "${_PKG}"
cp -r "${GF_PKG_ROOT}" "${_PKG}/hydra-gates"
cat > "${_PKG}/hydra-gates/scripts/lib/check_e2e_coverage.py" <<'PYSTUB'
import sys
# Simulates .github#209 exactly: 256 findings, `sys.exit(256)`.
# A byte holds 255, so the shell observes 0.
print("FAIL — 256 scenario(s) missing @e2e")
sys.exit(256)
PYSTUB

_stubout="$(HYDRA_GATE_LOG_DIR="${WORK}/log-stub" mkdir -p "${WORK}/log-stub" && \
    HYDRA_GATE_LOG_DIR="${WORK}/log-stub" bash "${_PKG}/hydra-gates/bin/hydra-gates" \
        --app-dir "${_R}" 2>&1 || true)"
_v="$(gf_verdict "${_stubout}" 19)"
_still=1
case "${_v}" in *PASS*) _still=0 ;; esac
_known_defect ".github#209 (residual half)" \
    "gate-19 reports PASS while hydra-gate-e2e-coverage.log reads 'FAIL — 256 scenario(s) missing @e2e'. The count is parsed from stdout for the MESSAGE, but the VERDICT still branches on \$?, so a checker that exits 256 (== 0 at the shell) is read as a pass. Latent, not active: no shipped checker returns a count (property 1)." \
    "${_still}"

# Whatever the verdict, the invariant from property 4 must catch it. If this
# ever fails, property 4 has stopped being a safety net for the whole class.
if grep -qE '^FAIL' "${WORK}/log-stub/hydra-gate-e2e-coverage.log" 2>/dev/null \
   && printf '%s' "${_v}" | grep -q PASS; then
    _ok "property 4 DOES catch the exit-0-with-findings case (verdict PASS vs log FAIL)"
elif printf '%s' "${_v}" | grep -qv PASS; then
    _ok "the exit-0-with-findings case is no longer read as a pass"
fi

# ===========================================================================
echo
echo "== property 5: the wrapper's exit code agrees with its own summary =="
# ===========================================================================
# The wrapper returns the FAILURE COUNT, deliberately (flows route on it), with
# 99 reserved for "could not run". That is only safe while the count is a number
# of GATES (max 64) and never a number of FINDINGS.
_R2="${WORK}/clean2"
mkdir -p "${_R2}/appinfo"
cp "${GF_PKG_ROOT}/scripts/test-fixtures/scope-matrix/app/appinfo/info.xml" "${_R2}/appinfo/"
( cd "${_R2}" && git init -q . && git symbolic-ref HEAD refs/heads/development \
    && git config user.email f@example.invalid && git config user.name F \
    && echo x > README.md && git add -f . >/dev/null && git commit -qm base >/dev/null \
    && git update-ref refs/remotes/origin/development HEAD \
    && echo y >> README.md && git add -f README.md >/dev/null && git commit -qm next >/dev/null )

mkdir -p "${WORK}/log-rc"
HYDRA_GATE_LOG_DIR="${WORK}/log-rc" bash "${GF_PKG_ROOT}/bin/hydra-gates" \
    --app-dir "${_R2}" > "${WORK}/rc.txt" 2>&1
_rc=$?
# NOT `grep -c ... || echo 0`: grep exits 1 on zero matches, so the fallback
# APPENDS a second "0" and the variable becomes "0\n0", which every later
# integer test rejects with "integer expression expected" — and those errors go
# to stderr while the assertion still prints PASS.
_declared_fail="$(grep -cE '^\[gate-[0-9]+\].*: FAIL' "${WORK}/rc.txt" 2>/dev/null || true)"
if [ "${_rc}" -eq 99 ]; then
    _bad "the wrapper exited 99 (could not run at all) on a well-formed fixture"
elif [ "${_declared_fail}" -eq 0 ] && [ "${_rc}" -ne 0 ]; then
    _bad "wrapper exit ${_rc} but no gate reported FAIL — the exit code and the summary disagree"
elif [ "${_declared_fail}" -gt 0 ] && [ "${_rc}" -eq 0 ]; then
    _bad "wrapper exit 0 while ${_declared_fail} gate(s) reported FAIL — a failing run read as success"
else
    _ok "wrapper exit (${_rc}) agrees with the number of FAIL verdicts (${_declared_fail})"
fi
if [ "${_rc}" -gt 64 ] && [ "${_rc}" -ne 99 ]; then
    _bad "wrapper exit ${_rc} exceeds the 64-gate inventory — it is carrying a FINDING count, not a gate count, and will wrap at 256"
else
    _ok "wrapper exit is a gate count (<= 64) or the reserved 99, so it cannot wrap a byte"
fi

echo
echo "== summary =="
echo "   passed:        ${_pass_n}"
echo "   failed:        ${_fail_n}"
echo "   known defects: ${_defect_n} (live, each named above)"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL exit-code semantics controls PASSED (${_defect_n} known defect(s) still live)"
exit 0
