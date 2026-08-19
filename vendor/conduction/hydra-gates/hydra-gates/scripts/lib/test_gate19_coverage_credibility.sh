#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate19_coverage_credibility.sh — a tag is not a test.
#
# WHY THIS EXISTS
# ---------------
# gate-19's number is the one most often quoted in fleet reporting, and four
# separate defects make it directional rather than exact. Three of them share a
# single shape: **something that is not a running test is credited as coverage.**
#
#   .github#356  a requirement-level `@e2e exclude` silently exempts every
#                SIBLING scenario. openbuild: 471 of 509 exclusions (92.5%)
#                share a reason, across only 42 distinct reasons, one covering
#                32 scenarios.
#   .github#343  a FILE-level `@e2e` tag is credited without checking the test
#                body. A file can be credited for scenarios nothing in it
#                exercises.
#   .github#345  an `@e2e exclude` is read as POSITIVE coverage — excluding a
#                scenario and covering it are indistinguishable to the gate.
#   (decidesk)   a `test.skip(true, …)` guard, whose condition can never be
#                false, still carries `@e2e` and still credits coverage. ~10
#                such tests, plus 4 named for tabs that only assert that
#                `#app-root` mounted.
#
# MEASURED HERE, on the canonical package:
#
#   req-inherit/     ONE exclude line, THREE scenarios beneath it
#                    -> PASS — 0 reference(s) in e2e suite
#   file-level-tag/  a file whose only test is `test.skip(true)`
#                    -> PASS — 2 reference(s) in e2e suite
#   honest/          two scenarios, two tests that really run and really assert
#                    -> PASS — 2 reference(s) in e2e suite
#
# Read those last two again: **the dishonest arm and the honest arm produce
# byte-identical verdicts.** That is the finding. A number you cannot distinguish
# from its own counterfeit is not a measurement.
#
# WHY FOUR ARMS AND NOT TWO
# -------------------------
# `scenario-level/` currently behaves CORRECTLY — an exclusion on one scenario
# does not leak to its sibling. It is fixtured anyway, because the obvious fix
# for #356 (stop inheriting exclusions) can easily over-correct into "no
# exclusion ever applies", and a suite with only a planted arm would score that
# as a repair. Every arm below differs from its neighbour by exactly one thing.
#
# Run: bash scripts/lib/test_gate19_coverage_credibility.sh
set -uo pipefail

GF_PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
export GF_PKG_ROOT
# shellcheck source=./gate_fixture_support.sh
. "${GF_PKG_ROOT}/scripts/lib/gate_fixture_support.sh"

FIX="${GF_PKG_ROOT}/scripts/test-fixtures/e2e-credibility"
CHECKER="${GF_PKG_ROOT}/scripts/lib/check_e2e_coverage.py"

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

for _arm in req-inherit scenario-level file-level-tag honest; do
    [ -d "${FIX}/${_arm}" ] || { echo "FAIL — fixture arm ${_arm} missing at ${FIX}"; exit 1; }
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/hydra-e2ec.XXXXXXXX")"
trap 'rm -rf "${WORK}"' EXIT

# Materialise each arm as a real, COMMITTED repository. Committed, not merely
# written to disk: gate-16's changed-method set comes from committed history
# while its annotations are read from disk, and an uncommitted plant there
# yields a false PASS. Same discipline everywhere so no suite in this package
# can be fooled by working-tree state.
_arm_out() {  # <arm> -> checker stdout
    # Two statements, not one: under `set -u`, `local _a="$1" _r="${WORK}/${_a}"`
    # declares both names before assigning, so the second initialiser reads _a
    # while it is still unset and aborts the function with "unbound variable".
    # The function then returns nothing, which reaches the caller as an empty
    # verdict — and an empty verdict grades exactly like a failing one.
    local _a="$1"
    local _r="${WORK}/${_a}"
    if [ ! -d "${_r}" ]; then
        mkdir -p "${_r}"; cp -r "${FIX}/${_a}/." "${_r}/"
        ( cd "${_r}" && git init -q . && git symbolic-ref HEAD refs/heads/development \
            && git config user.email fixture@example.invalid && git config user.name F \
            && git config commit.gpgsign false \
            && git add -f . >/dev/null && git commit -qm "fixture ${_a}" >/dev/null )
    fi
    ( cd "${_r}" && python3 "${CHECKER}" . 2>&1 )
}

# ===========================================================================
echo "== positive control: the honest arm really does pass =="
# ===========================================================================
# Without this, every "PASS" below is ambiguous between "correctly covered" and
# "the checker cannot see this tree at all".
_honest="$(_arm_out honest)"
if printf '%s' "${_honest}" | grep -q 'PASS — 2 reference'; then
    _ok "honest/ passes with 2 genuine references — the gate CAN pass legitimately"
else
    echo "FAIL — the positive control did not pass. Either the fixture stopped being"
    echo "       honest or the checker cannot read it. Refusing to grade the rest."
    printf '%s\n' "${_honest}" | sed 's/^/       /'
    exit 1
fi

# ===========================================================================
echo
echo "== control arm: a SCENARIO-level exclusion must not leak to its sibling =="
# ===========================================================================
_scen="$(_arm_out scenario-level)"
if printf '%s' "${_scen}" | grep -q 'uncovered-sibling'; then
    _ok "scenario-level/ still reports the un-excluded sibling by name"
else
    _bad "scenario-level/ no longer names 'uncovered-sibling'. If this broke while fixing #356, the fix over-corrected: an exclusion is now leaking to siblings, or every exclusion has been made inert. Got: $(printf '%s' "${_scen}" | tail -1)"
fi

# ===========================================================================
echo
echo "== .github#356 — a REQUIREMENT-level exclude exempts every sibling =="
# ===========================================================================
_req="$(_arm_out req-inherit)"
echo "   verdict: $(printf '%s' "${_req}" | tail -1)"
_still=1
printf '%s' "${_req}" | grep -q 'PASS' && _still=0
_known_defect ".github#356" \
    "ONE '@e2e exclude' in a requirement body exempted all THREE scenarios beneath it; the gate printed 'PASS — 0 reference(s) in e2e suite' — announcing that the e2e suite references nothing, and calling that a pass. This is the openbuild shape: 471 of 509 exclusions share a reason, one covering 32 scenarios." \
    "${_still}"

# The reporting half of #356: even if inheritance stays, the OUTPUT must let a
# reader tell a wholly-excluded spec from a covered one.
_states_counts=1
if printf '%s' "${_req}" | grep -qiE '[0-9]+ (scenario|of them)[^.]*exclud|exclud[^.]*: *[0-9]+'; then
    _states_counts=0
fi
if [ "${_states_counts}" -eq 0 ]; then
    _ok "the output states how many scenarios were excluded"
else
    _defect_n=$((_defect_n + 1))
    printf 'KNOWN DEFECT (still live, %s) — %s\n' ".github#356 (reporting)" \
        "the verdict states neither the number of EXEMPTED scenarios nor the number of DISTINCT reasons. A spec whose every scenario is excluded by one line is typographically identical to a fully covered one, which is why 92.5 percent duplication survived unnoticed in openbuild."
fi

# ===========================================================================
echo
echo "== .github#343 + the never-false test.skip guard =="
# ===========================================================================
_tagged="$(_arm_out file-level-tag)"
echo "   verdict: $(printf '%s' "${_tagged}" | tail -1)"
_still=1
printf '%s' "${_tagged}" | grep -q 'PASS' && _still=0
_known_defect ".github#343 + skip-guard" \
    "a FILE-level '@e2e' docblock credited 2 scenarios in a file whose ONLY test is 'test.skip(true, …)' — a condition that can never be false — and whose single assertion is merely that #app-root is visible. Nothing ran; both scenarios were credited." \
    "${_still}"

# ===========================================================================
echo
echo "== the load-bearing one: honest and counterfeit must be DISTINGUISHABLE =="
# ===========================================================================
# This is the assertion that actually matters. Fixing #343 or #356 without making
# this true would leave the number just as unreadable as it is now.
_h="$(printf '%s' "${_honest}" | tail -1)"
_t="$(printf '%s' "${_tagged}" | tail -1)"
if [ "${_h}" = "${_t}" ]; then
    _defect_n=$((_defect_n + 1))
    printf 'KNOWN DEFECT (still live, %s) — %s\n' "gate-19 legibility" \
        "the honest arm and the counterfeit arm emit BYTE-IDENTICAL verdicts: '${_h}'. Two scenarios covered by two real tests, and two scenarios credited to a permanently-skipped file, are the same sentence. A number you cannot distinguish from its counterfeit is not a measurement."
else
    _ok "honest and counterfeit arms are distinguishable ('${_h}' vs '${_t}')"
fi

# ===========================================================================
echo
echo "== a bare '@e2e exclude' with no reason must remain non-compliant =="
# ===========================================================================
# An exemption's reason is a testable claim; a bare exclusion is unfalsifiable.
_bare_dir="${WORK}/bare"
mkdir -p "${_bare_dir}/openspec/specs/bare" "${_bare_dir}/appinfo"
cp "${FIX}/honest/appinfo/info.xml" "${_bare_dir}/appinfo/"
cat > "${_bare_dir}/openspec/specs/bare/spec.md" <<'SPEC'
# Bare exclusion spec

### Requirement: Bare

#### Scenario: bare exclusion

@e2e exclude

- WHEN a THEN b
SPEC
( cd "${_bare_dir}" && git init -q . && git symbolic-ref HEAD refs/heads/development \
    && git config user.email f@example.invalid && git config user.name F \
    && git add -f . >/dev/null && git commit -qm bare >/dev/null )
_bare_out="$( cd "${_bare_dir}" && python3 "${CHECKER}" . 2>&1 )"
if printf '%s' "${_bare_out}" | grep -qiE 'FAIL|bare|no reason|reason required'; then
    _ok "a bare '@e2e exclude' is rejected — $(printf '%s' "${_bare_out}" | tail -1 | cut -c1-80)"
else
    _bad "a bare '@e2e exclude' (no reason) was ACCEPTED: $(printf '%s' "${_bare_out}" | tail -1)"
fi

# ===========================================================================
echo
echo "== end-to-end through the real wrapper, not just the checker =="
# ===========================================================================
# Everything above drives the checker directly, which is fast and precise but
# cannot see wrapper-level defects (that is how gate-61 hid). Run one arm the
# way CI does.
_wrap_repo="${WORK}/req-inherit"
_wout="$(gf_run_wrapper "${_wrap_repo}" "${WORK}/log-wrap" --full)"
_wv="$(gf_verdict "${_wout}" 19)"
if [ -z "${_wv}" ]; then
    _bad "gate-19 emitted no verdict through the wrapper on the req-inherit arm"
else
    _ok "the wrapper reaches gate-19 on this fixture — ${_wv:0:90}"
fi

echo
echo "== summary =="
echo "   passed:        ${_pass_n}"
echo "   failed:        ${_fail_n}"
echo "   known defects: ${_defect_n} (live, each named above)"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL gate-19 credibility controls PASSED (${_defect_n} known defect(s) still live)"
exit 0
