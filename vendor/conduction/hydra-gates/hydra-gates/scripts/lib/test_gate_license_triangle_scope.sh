#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_license_triangle_scope.sh — control-pair suite for gate-28's
# THREE-WAY distinction between "diff-scoped out", "a real gap", and "clean".
#
# WHY THIS EXISTS
# ---------------
# ConductionNL/.github#172 fixed a real defect: gate-28 reported PASS on repos
# where it had opened zero files, so a green said nothing. The fix made an
# empty read report `structural` instead — a claim that the REPOSITORY is
# missing licence declarations.
#
# But there are two ways to read zero files, and #172 gave them the same word:
#
#   (a) lib/**/*.php files exist and ARE in this diff, and none carries a tag
#       — a genuine gap in the repository, correctly `structural`;
#   (b) no lib/**/*.php file is in this diff AT ALL — diff-scoping working
#       exactly as ADR-020 designed it, and NOT a statement about the repo.
#
# (b) is the ordinary case: every workflow-only and frontend-only PR hits it.
# With `hydra-gates-require-full-coverage` on by default, calling it
# `structural` fails the run with exit 98 for a licence problem that does not
# exist. Measured on the fleet's own unpinning PRs — each a single-file
# workflow diff — hrmq#74 went from a CLEAN baseline to red on nothing but
# this, and app-versions#129, opencatalogi#813 and nextcloud-app-template#132
# named gate 28 as their only gate that did not run. hrmq's 168 lib PHP files
# all carry their tags; the gate had simply not been given any of them to read.
#
# THE RISK THIS SUITE GUARDS
# --------------------------
# The obvious "fix" — make an empty read NOT APPLICABLE — walks straight back
# into #172, because case (a) also reads zero files. A gate that stops failing
# is worse than the false positive it replaced. So every assertion here is one
# half of a control pair: for each state that must NOT fail the coverage
# verdict, there is a neighbouring fixture where the same code path MUST.
#
#   wf-only diff, tagged repo   -> NOT APPLICABLE  (b: diff-scoped out)
#   untagged PHP in the diff    -> structural      (a: the real gap, still red)
#   tagged PHP in the diff      -> PASS            (the gate actually compared)
#   no lib/ at all              -> NOT APPLICABLE  (#172's original case)
#
# Run: bash scripts/lib/test_gate_license_triangle_scope.sh  (exit 0 = green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

_TMP="$(mktemp -d "${TMPDIR:-/tmp}/gate28-scope.XXXXXXXX")"
trap 'rm -rf "${_TMP}"' EXIT

_LICENSED_PHP='<?php
/**
 * @copyright Copyright (c) 2026 Conduction
 * @license   EUPL-1.2
 */

namespace OCA\Fixture;

class Tagged
{
    public function value(): int
    {
        return 1;
    }
}
'
_UNLICENSED_PHP='<?php

namespace OCA\Fixture;

class Untagged
{
    public function value(): int
    {
        return 2;
    }
}
'

# _mkrepo <name> <with-lib: yes|no>  — a committed base tree. Echoes the base SHA.
_REPO=""
_BASE=""
_mkrepo() {
    local _name="$1" _withlib="$2"
    _REPO="${_TMP}/${_name}"
    mkdir -p "${_REPO}/appinfo" "${_REPO}/.github/workflows"
    (
        cd "${_REPO}" || exit 1
        git init -q .
        git symbolic-ref HEAD refs/heads/development
        git config user.email "ci@example.invalid"
        git config user.name "gate28 test"
        printf '<?xml version="1.0"?>\n<info><id>fixture</id><version>1.0.0</version></info>\n' > appinfo/info.xml
        printf '{\n  "name": "conduction/fixture",\n  "license": "EUPL-1.2"\n}\n' > composer.json
        printf 'name: ci\non: push\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo base\n' > .github/workflows/ci.yml
        if [ "${_withlib}" = "yes" ]; then
            mkdir -p lib
            printf '%s' "${_LICENSED_PHP}" > lib/Tagged.php
        fi
        git add -A
        git commit -qm "base"
    ) >/dev/null 2>&1
    _BASE="$(git -C "${_REPO}" rev-parse HEAD)"
}

# _run — capture the run. A run that aborts before the COVERAGE line is not a
# result, however many PASS lines precede it.
_OUT=""
_run() {
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" \
        --app-dir "${_REPO}" --base "${_BASE}" --scope-to-diff 2>&1 || true)"
    rm -rf "${_logdir}"
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_REPO} ABORTED before the summary — not a result"
        printf '%s\n' "${_OUT}" | tail -15 | sed 's/^/       /'
        return 1
    fi
    return 0
}

# _expect <PASS|NOT APPLICABLE|structural> <description>
_expect() {
    local _want="$1" _desc="$2" _line
    _line="$(printf '%s' "${_OUT}" | grep -E '^\[gate-28\] ' | head -1)"
    if [ -z "${_line}" ]; then
        _bad "${_desc} — gate-28 emitted NO line at all (a silent gate is the #147 shape)"
        return
    fi
    # `A && _ok || _bad` would be wrong here, and not merely untidy (SC2015):
    # if _ok ever returned non-zero, _bad would ALSO run and the same
    # assertion would be counted twice, once each way. In a harness whose
    # entire job is to count assertions correctly, that is instrument
    # corruption. Explicit if/else.
    local _pattern
    case "${_want}" in
        PASS)          _pattern=': PASS' ;;
        NOTAPPLICABLE) _pattern=': NOT APPLICABLE' ;;
        structural)    _pattern=': SKIPPED \(structural\)' ;;
        *)
            _bad "${_desc} — test bug: unknown expectation '${_want}'"
            return ;;
    esac
    if printf '%s' "${_line}" | grep -qE "${_pattern}"; then
        _ok "${_desc}"
    else
        _bad "${_desc} — got: ${_line}"
    fi
}

# --- 1. (b) THE FALSE RED. Repo HAS licensed lib PHP; the diff touches only a
#            workflow file. Nothing was withheld — there was nothing to read.
_mkrepo scope-wf-only yes
printf 'name: ci\non: push\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo changed\n' > "${_REPO}/.github/workflows/ci.yml"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "workflow-only change" >/dev/null 2>&1
if _run; then
    _expect NOTAPPLICABLE "workflow-only diff in a fully-tagged repo: diff-scoped out, not a gap"
    # And it must NOT be counted against coverage, or the taxonomy is cosmetic.
    #
    # THIS ASSERTION TOOK TWO TRIES TO MEASURE ANYTHING, and both failures are
    # worth naming because they are the same mistake in opposite directions:
    #
    #   v1  matched only `GATES THAT DID NOT RUN: 28`, a summary line the
    #       runner emits only under --require-full-coverage. This suite does
    #       not pass that flag, so the pattern matched nothing and the
    #       assertion passed against the FIXED and the UNFIXED runner alike —
    #       a dead assertion inside the suite whose entire purpose is to tell
    #       those two apart.
    #   v2  also matched the indented `[hydra-gates]   gate-28 <name>` roster
    #       — but the runner prints that roster for NOT-APPLICABLE gates too.
    #       So it fired on the correct behaviour and the assertion failed
    #       against the FIXED runner.
    #
    # The roster line is ambiguous on its own; only its HEADING disambiguates.
    # So read the did-not-run block specifically: from its heading up to the
    # next `[hydra-gates] <CAPITAL>` line that starts a new section.
    _didnotrun_block="$(printf '%s\n' "${_OUT}" \
        | sed -n '/^\[hydra-gates\] GATES THAT DID NOT RUN/,/^\[hydra-gates\] [A-Z].*[a-z]/p')"
    if printf '%s' "${_didnotrun_block}" | grep -qE '(^|[[:space:]])gate-28([[:space:]]|$)|DID NOT RUN:.*\b28\b'; then
        _bad "gate-28 was named in GATES THAT DID NOT RUN despite being NOT APPLICABLE — it will still fail the run under --require-full-coverage"
    else
        _ok "gate-28 excluded from the did-not-run tally"
    fi
fi

# --- 2. (a) THE CONTROL. Untagged lib PHP IS in the diff. This is a real gap
#            and MUST stay red, or this whole change is a mute for #172.
_mkrepo scope-untagged yes
printf '%s' "${_UNLICENSED_PHP}" > "${_REPO}/lib/Untagged.php"
rm -f "${_REPO}/lib/Tagged.php"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "add untagged lib PHP" >/dev/null 2>&1
if _run; then
    _expect structural "untagged lib PHP IN scope: still a structural gap (#172 preserved)"
fi

# --- 3. THE CLEAN PATH. Tagged lib PHP in the diff — the gate compared something.
_mkrepo scope-tagged yes
printf '%s' "${_LICENSED_PHP}" > "${_REPO}/lib/Second.php"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "add tagged lib PHP" >/dev/null 2>&1
if _run; then
    _expect PASS "tagged lib PHP in scope: gate-28 actually compared and passed"
fi

# --- 4. #172's ORIGINAL CASE. No lib/ at all — must remain NOT APPLICABLE and
#        must never be PASS, which is the bug #172 was opened for.
_mkrepo scope-no-lib no
printf 'name: ci\non: push\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo changed\n' > "${_REPO}/.github/workflows/ci.yml"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "workflow change, no lib/" >/dev/null 2>&1
if _run; then
    _expect NOTAPPLICABLE "no lib/ at all: NOT APPLICABLE, never PASS (#172)"
fi

# ===========================================================================
# APPENDED for ConductionNL/.github#178 — the ACCEPTANCE evidence the suite
# above only approximated.
#
# #178 was filed against hermiq#159, a frontend + spec + docs PR, and #182
# fixed it. The four cases above lock that fix in — verified by mutation: with
# the `na` branch removed, cases 1 and 2 both go red. So why more?
#
# Because of what case 1 does NOT run. #178's claim is not "the gate prints
# NOT APPLICABLE"; it is "**the run is not FAILED** by the coverage
# requirement". The suite above never passes `--require-full-coverage`, so it
# proves the first and infers the second. Those came apart once already: #164
# made the flag default TRUE in the WORKFLOW while every local suite ran
# without it, which is precisely how a gate can be green in this file and red
# in CI. The pair below runs the flag and reads the runner's own verdict line.
#
# And case 2's control is the WEAKER of the two true positives. "No file
# carried a tag" is `structural`; a file that carries the WRONG licence is a
# `_fail` — a different branch, a different exit path, and the only one that
# is the gate's actual purpose. Nothing end-to-end exercised it: the drift
# rules are unit-tested in test_check_license_triangle.py, but the wiring from
# helper stdout -> _lt_log -> `wc -l` -> `_fail 28` was not. A helper that
# printed its findings to stderr would keep every unit test green and turn
# this gate into a no-op.
# ===========================================================================

# _run_fullcov — same as _run, but with the flag CI actually sets. The runner
# exits 98 on incomplete coverage; that status is deliberately NOT the
# assertion. Statuses in this package have carried finding COUNTS rather than
# verdicts (#209: gate-19 returned 266 findings as exit status 10, and 256
# would have read as PASS), so the assertion reads the runner's own sentence
# on stdout. The status is captured only to be reported alongside it.
_OUT_FC=""
_STATUS_FC=""
_run_fullcov() {
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _OUT_FC="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" \
        --app-dir "${_REPO}" --base "${_BASE}" --scope-to-diff \
        --require-full-coverage 2>&1)"
    _STATUS_FC=$?
    rm -rf "${_logdir}"
    if ! printf '%s' "${_OUT_FC}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "full-coverage run in ${_REPO} ABORTED before the summary — not a result"
        printf '%s\n' "${_OUT_FC}" | tail -15 | sed 's/^/       /'
        return 1
    fi
    return 0
}

# _expect_gate28 <PASS|NOTAPPLICABLE|structural|FAIL> <output> <description>
# A FAIL-aware sibling of _expect that reads a caller-supplied capture, so the
# full-coverage runs can be asserted without disturbing _OUT.
_expect_gate28() {
    local _want="$1" _out="$2" _desc="$3" _line _pattern
    _line="$(printf '%s' "${_out}" | grep -E '^\[gate-28\] ' | head -1)"
    if [ -z "${_line}" ]; then
        _bad "${_desc} — gate-28 emitted NO line at all (a silent gate is the #147 shape)"
        return
    fi
    case "${_want}" in
        PASS)          _pattern=': PASS' ;;
        NOTAPPLICABLE) _pattern=': NOT APPLICABLE' ;;
        structural)    _pattern=': SKIPPED \(structural\)' ;;
        FAIL)          _pattern=': FAIL' ;;
        *)
            _bad "${_desc} — test bug: unknown expectation '${_want}'"
            return ;;
    esac
    if printf '%s' "${_line}" | grep -qE "${_pattern}"; then
        _ok "${_desc}"
    else
        _bad "${_desc} — got: ${_line}"
    fi
}

# The runner's own sentence for "this run is being failed for coverage". Only
# printed when --require-full-coverage is set AND a gate did not run.
_COVFAIL_LINE='treating incomplete coverage as failure'

_MISMATCHED_PHP='<?php
/**
 * @copyright Copyright (c) 2026 Conduction
 * @license   AGPL-3.0-or-later
 */

namespace OCA\Fixture;

class Drifted
{
    public function value(): int
    {
        return 3;
    }
}
'

# THE FIXTURES BELOW MUST BE OTHERWISE GREEN, and that is not a detail.
#
# The runner's coverage-failure branch lives inside `if [ "${_FAILED}" -eq 0 ]`.
# If ANY other gate has already failed, the run exits with the failure count and
# the coverage sentence is NEVER PRINTED. So an assertion of the form "the
# coverage sentence is absent" passes automatically in any fixture that trips an
# unrelated gate — including on a runner where #178 had been fully reverted.
#
# This was not hypothetical. The first version of case 5 wrote its Vue file to
# `src/App.vue`, which gate-38 (skip-link) fails as a root component without
# <NcContent>. Both full-coverage runs exited 1, not 98, and BOTH new
# assertions were measuring gate-38's failure rather than gate-28's verdict —
# the absence-assertion would have been dead. The file is written to
# `src/components/Widget.vue` for that reason, and the assertion reads the
# runner's positive "ALL … APPLICABLE GATES GREEN" sentence rather than the
# absence of a negative one: a sentence that must be PRESENT cannot be
# satisfied by a run that died earlier.
#
# For the same reason the control at case 6 CANNOT use untagged PHP: an
# untagged file also fails gate-1 (spdx-headers), so `_FAILED` is never 0 and
# the coverage branch is unreachable. It uses the other structural route
# instead — a composer.json with no `license` field, with a correctly tagged
# lib PHP file in the diff — which reaches `structural` with every other gate
# green.

# --- 5. #178 AS REPORTED: a frontend + docs diff (.vue + .md), no PHP at all,
#        in a repo that HAS lib/ and a composer licence. Case 1 used a
#        workflow file; this is the shape the issue was actually filed on.
_mkrepo scope-frontend-only yes
mkdir -p "${_REPO}/src/components"
printf '<template><div>base</div></template>\n' > "${_REPO}/src/components/Widget.vue"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "add a frontend component" >/dev/null 2>&1
_BASE="$(git -C "${_REPO}" rev-parse HEAD)"
printf '<template><div>changed</div></template>\n' > "${_REPO}/src/components/Widget.vue"
printf '# changed\n' > "${_REPO}/README.md"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "frontend + docs only, no PHP" >/dev/null 2>&1
if _run_fullcov; then
    _expect_gate28 NOTAPPLICABLE "${_OUT_FC}" \
        "#178: .vue + .md diff in a tagged repo is NOT APPLICABLE, not structural"
    # THE ASSERTION #178 IS ACTUALLY ABOUT: the run must not be failed for it.
    # Read the POSITIVE verdict, per the note above.
    if printf '%s' "${_OUT_FC}" | grep -qE '^\[hydra-gates\] ALL [0-9]+ APPLICABLE GATES GREEN'; then
        _ok "#178: the PHP-free diff run is GREEN under --require-full-coverage (status ${_STATUS_FC})"
    else
        _bad "#178: PHP-free diff did not end green under --require-full-coverage (status ${_STATUS_FC}) — the false red is back, or the fixture trips an unrelated gate and this assertion is measuring nothing"
    fi
fi

# --- 6. THE CONTROL FOR 5, and the one that makes it mean something. Same
#        flag, same runner, an otherwise-identical fixture: a run whose ONLY
#        problem is a structural gate-28 must still be failed for coverage.
#        Without this, assertion 5 would also pass on a runner that had simply
#        stopped enforcing coverage at all.
#
#        The licence field is dropped in a BASE-ADVANCING commit, not in the
#        diff under test. Putting composer.json in the diff makes gate-4
#        (composer-audit) applicable, and it fails on a fixture with no vendor
#        tree — `_FAILED` becomes 1 and the coverage sentence is unreachable
#        again. The diff must contain the lib PHP file and nothing else.
_mkrepo scope-nolicense-fullcov yes
printf '{\n  "name": "conduction/fixture"\n}\n' > "${_REPO}/composer.json"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "drop the composer license field" >/dev/null 2>&1
_BASE="$(git -C "${_REPO}" rev-parse HEAD)"
printf '%s\n// touched\n' "${_LICENSED_PHP}" > "${_REPO}/lib/Tagged.php"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "touch the tagged lib PHP file" >/dev/null 2>&1
if _run_fullcov; then
    _expect_gate28 structural "${_OUT_FC}" \
        "control: composer.json with no license is still structural under --require-full-coverage"
    if printf '%s' "${_OUT_FC}" | grep -qF "${_COVFAIL_LINE}"; then
        _ok "control: --require-full-coverage DID fail the run on a real gap (status ${_STATUS_FC})"
    else
        _bad "control: --require-full-coverage did NOT fail a run whose only gap is gate-28 (status ${_STATUS_FC}) — the coverage requirement is inert here, so assertion 5 proves nothing"
    fi
fi

# --- 7. THE GATE'S ACTUAL PURPOSE, end to end. A file declaring a licence
#        that composer.json does not — the `_fail 28` branch, which no test
#        above reaches. This is the assertion that would catch the helper
#        writing findings anywhere but stdout.
_mkrepo scope-mismatch yes
printf '%s' "${_MISMATCHED_PHP}" > "${_REPO}/lib/Drifted.php"
git -C "${_REPO}" add -A >/dev/null 2>&1
git -C "${_REPO}" commit -qm "lib PHP declaring AGPL against an EUPL composer.json" >/dev/null 2>&1
if _run; then
    _expect_gate28 FAIL "${_OUT}" \
        "licence MISMATCH in scope (@license AGPL vs composer EUPL): gate-28 FAILS"
    # ...and it must fail for the RIGHT reason. A gate that failed here for any
    # other cause would satisfy the line above while comparing nothing.
    if printf '%s' "${_OUT}" | grep -qE '^\[gate-28\].*@license != composer\.json'; then
        _ok "mismatch failure names the licence comparison as its cause"
    else
        _bad "mismatch failed, but not with the licence-comparison reason — cause unverified"
    fi
fi

echo
echo "== summary =="
echo "   passed: ${_pass_n}"
echo "   failed: ${_fail_n}"
if [ "${_pass_n}" -eq 0 ]; then
    echo "FAIL — zero assertions ran; an empty suite is not a green suite"
    exit 1
fi
[ "${_fail_n}" -eq 0 ] || exit 1
echo "ALL gate-28 scope control pairs PASSED"
exit 0
