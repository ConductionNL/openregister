#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gates_23_33_never_green_over_nothing.sh — acceptance controls for
# gates 23-33.
#
# WHY THIS EXISTS
# ---------------
# On 2026-08-08 one textbook true positive was planted per gate in nldesign and
# all thirteen a11y gates reported PASS: eleven globbed `src/**/*.vue` and
# nldesign ships no `.vue` file. Every green was a green over nothing. The same
# audit, run across gates 23-33 against real fleet repositories, found six more
# gates that could not fail on their own subject matter:
#
#   gate-23  the linter is bake-in gated and exits 0 in WARN mode whether it
#            matched nothing or everything. The gate read only the byte, so
#            `[gate-23] …: PASS` was printed over a planted
#            `TenantIsolationService` on doriath — and over 33 findings on
#            openregister, every one of them a FALSE POSITIVE: the rules'
#            `grep -v -i openregister` filter reads the FILE PATH, which inside
#            the openregister repository never contains that string.
#
#   gate-24  openregister's parity wrapper exits 0 when it cannot locate the
#            canonical JS check, printing "skipping". The gate reported PASS on
#            that byte. The repo that OWNS the integration registry correlated
#            nothing and said it was fine.
#
#   gate-26  `check_visual_coverage.py` returned the FINDING COUNT as its exit
#            status. Measured on openregister: 260 uncovered new page
#            components -> exit 4 -> "FAIL — 4"; 256 -> exit 0 -> PASS, with
#            the helper's own stdout reading "FAIL — 256" on the same run.
#            Identical to .github#209 in gate-19.
#
#   gate-27  `2>/dev/null || true` around a helper that returns 0 on every
#            successful path: the ONLY nonzero exit is a crash, and a crash
#            produced an empty log and a PASS. Measured: 404 files in scope,
#            none judged, verdict PASS.
#
#   gate-29  a bare `[ fail -eq 0 ] && PASS` reached by BOTH guards, so an
#            unscoped run and a repo with no .gitignore printed the same PASS
#            as a run that really inspected a .gitignore change.
#
#   gate-30  `AppHost\Controller\GenericHealth#index` maps by PSR-4 to
#            lib/Controller/AppHost/Controller/…, but openregister DI-binds it
#            to lib/AppHost/Controller/GenericHealthController.php. The gate
#            called the file "not present in this repository" — inside the
#            repository that contains it — so /api/health and /api/metrics were
#            judged by this gate nowhere in the fleet.
#
#   gate-33  a report of `{}` parsed as "no violations" and reported PASS,
#            turning the loud missing-report skip into a silent false green.
#
# Each fixture arm below differs by exactly the thing being detected, so the
# suite fails in BOTH directions: `planted/` must FAIL and NAME the defect, and
# `clean/` must not — otherwise a repair that simply widened the checker until
# it caught everything would pass this file.
#
# Run: bash scripts/lib/test_gates_23_33_never_green_over_nothing.sh
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"
FIXTURES="${PKG_ROOT}/scripts/test-fixtures/gates-23-33"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

_OUT=""
_LOGDIR=""
_run() {  # <fixture-dir> [runner args...]
    local _dir="$1"; shift
    _LOGDIR="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-2333.XXXXXXXX")"
    # The OR-abstraction linter is WARN-only until its bake-in epoch. Force
    # BLOCK so the gate's ability to FAIL is what this suite measures, not the
    # calendar. A second arm below checks the WARN path separately.
    _OUT="$(HYDRA_GATE_LOG_DIR="${_LOGDIR}" \
        HYDRA_OR_GATE_BLOCK_AFTER_EPOCH="${_OR_EPOCH:-0}" \
        bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    # An abort before the summary leaves per-gate PASS lines on stdout and
    # reads exactly like a clean run.
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_dir} ABORTED before the summary — the verdicts above it are not a result"
        printf '%s\n' "${_OUT}" | tail -25 | sed 's/^/       /'
        return 1
    fi
    return 0
}

_verdict() {  # <gate-n> -> the verdict line
    printf '%s' "${_OUT}" | grep -E "^\[gate-$1\] " | head -1
}

_expect() {  # <gate-n> <substring> <description>
    local _line
    _line="$(_verdict "$1")"
    if [ -z "${_line}" ]; then
        _bad "$3 — gate-$1 emitted NO verdict line at all"
        return
    fi
    case "${_line}" in
        *"$2"*) _ok "$3 — ${_line:0:120}" ;;
        *) _bad "$3 — wanted '$2', got: ${_line}" ;;
    esac
}

_expect_log() {  # <log-basename> <substring> <description>
    local _f="${_LOGDIR}/$1"
    if [ ! -f "${_f}" ]; then
        _bad "$3 — $1 was never written"
        return
    fi
    if grep -qF -- "$2" "${_f}"; then
        _ok "$3"
    else
        _bad "$3 — '$2' not in $1; log was: $(head -3 "${_f}" | tr '\n' ' ')"
    fi
}

_expect_stdout() {  # <substring> <description>
    if printf '%s' "${_OUT}" | grep -qF -- "$1"; then
        _ok "$2"
    else
        _bad "$2 — '$1' not on stdout"
    fi
}

if [ ! -d "${FIXTURES}/planted" ] || [ ! -d "${FIXTURES}/clean" ]; then
    echo "FAIL — fixtures missing at ${FIXTURES}. An absent fixture makes every"
    echo "       assertion below vacuous, which is the defect this suite exists"
    echo "       to catch. Refusing to report a pass."
    exit 1
fi

# ===========================================================================
echo "== planted/ — every gate must FAIL and NAME its defect =="
# ===========================================================================
if _run "${FIXTURES}/planted"; then
    _expect 23 "FAIL" "gate-23 names the app-local tenant boundary"
    _expect_log hydra-gate-or-abstraction-anti-patterns.log \
        "TenantIsolationService.php" "gate-23 log names lib/Service/TenantIsolationService.php"

    # gate-24: the wrapper exits 0 saying "skipping" while the repo DOES
    # register leaves. That is not a pass — it is an unverified correlation.
    _expect 24 "SKIPPED (structural)" "gate-24 refuses to read a wrapper's exit-0 skip as a PASS"
    _expect 24 "DOES register integration leaves" "gate-24 says WHY it is structural"

    _expect 26 "FAIL" "gate-26 names the page component with no visual baseline"
    _expect_log hydra-gate-visual-coverage.log \
        "src/views/UnbaselinedPage.vue" "gate-26 log names src/views/UnbaselinedPage.vue"

    _expect 27 "FAIL" "gate-27 names the phantom getLeaf() RPC"
    _expect_log hydra-gate-no-phantom-cross-app-rpc.log \
        "DelegationService.php" "gate-27 log names lib/Service/DelegationService.php"

    _expect 30 "FAIL" "gate-30 names the monitoring endpoint with no declared posture"
    _expect_log hydra-gate-public-monitoring.log \
        "lib/Controller/HealthController.php" "gate-30 log names lib/Controller/HealthController.php"
    # THE DI-BOUND CONTROLLER. Its route name maps by PSR-4 to
    # lib/Controller/AppHost/Controller/…, which does not exist; the class is
    # at lib/AppHost/Controller/. Before the resolver fallback this endpoint
    # was reported as "not present in this repository" and never judged.
    _expect_log hydra-gate-public-monitoring.log \
        "lib/AppHost/Controller/GenericHealthController.php" \
        "gate-30 resolves and JUDGES the DI-bound AppHost controller in this repo"
    # ...and the two carve-outs must still be carved out, or the fix widened
    # the gate into demanding #[PublicPage] on a per-placement badge.
    _expect_log hydra-gate-public-monitoring-notes.log \
        "healthPing#show" "gate-30 still refuses to judge the per-object health-ping badge"
    _expect_log hydra-gate-public-monitoring-notes.log \
        "healthPing#validate" "gate-30 still refuses to judge the POST health-ping endpoint"
    if grep -q 'healthPing' "${_LOGDIR}/hydra-gate-public-monitoring.log" 2>/dev/null; then
        _bad "gate-30 WIDENED: healthPing appears as a FINDING — its only remedy is publishing an outbound-ping oracle"
    else
        _ok "gate-30 did not flag healthPing (its remedy would be a security regression)"
    fi

    _expect 31 "FAIL" "gate-31 names the <img> with no alt"
    _expect_log hydra-gate-img-alt.log "PlantImg.vue" "gate-31 log names src/components/PlantImg.vue"
    if grep -q '@param' "${_LOGDIR}/hydra-gate-img-alt.log" 2>/dev/null; then
        _bad "gate-31 scored a JSDoc line as markup (#220/#235)"
    else
        _ok "gate-31 did not score the JSDoc \`<img>\` in the <script> block"
    fi

    _expect 32 "FAIL" "gate-32 names the mouse-only click target"
    _expect_log hydra-gate-semantic-controls.log "PlantClick.vue" "gate-32 log names src/components/PlantClick.vue"
    # The planted div sits AFTER a nested `<template #list>`; a lazy
    # `<template…>(.*?)</template>` ends the SFC at the first nested slot and
    # deletes this finding entirely.
    _expect_log hydra-gate-semantic-controls.log "row-toggle" \
        "gate-32 sees markup written AFTER a nested <template #slot>"

    _expect 33 "SKIPPED (wiring)" "gate-33 refuses to read \`{}\` as \"no violations\""
    _expect 33 "not a readable axe result object" "gate-33 says WHY the report is unusable"
fi

# ===========================================================================
echo
echo "== clean/ — the SAME gates must not fire (no widening) =="
# ===========================================================================
if _run "${FIXTURES}/clean"; then
    _expect 23 "PASS" "gate-23 clean on an app that consumes the OR abstraction"
    _expect 26 "PASS" "gate-26 clean when the page has a visual baseline"
    _expect 27 "PASS" "gate-27 clean on the ADR-041 event recipe"
    _expect 30 "PASS" "gate-30 clean when both scrape targets declare #[PublicPage]"
    _expect 31 "PASS" "gate-31 clean when every <img> carries alt"
    _expect 32 "PASS" "gate-32 clean when the control is a real <button>"
    _expect 33 "PASS" "gate-33 clean on a real axe report with an empty violations array"
    # A PASS must state the size of its evidence, or it is unreadable.
    _expect_stdout "gate-31 img-alt:" "gate-31 PASS states how many files it inspected"
    _expect_stdout "gate-32 semantic-controls:" "gate-32 PASS states how many files it inspected"
    _expect_stdout "gate-30 public-monitoring:" "gate-30 PASS states how many endpoints it inspected"
    # gate-24 has no wrapper in the clean arm and no leaves — NOT APPLICABLE,
    # never PASS (PR #270's convention).
    _expect 24 "NOT APPLICABLE" "gate-24 with no wrapper and no leaves is na, not PASS"
fi

# ===========================================================================
echo
echo "== gate-24: BOTH registration APIs count as having leaves (.github#349) =="
# ===========================================================================
# `clean/` has no parity wrapper AND no leaf, so its NOT APPLICABLE above is
# correct. Drop ONE file on top of it — a leaf registered through
# `OCA.OpenRegister.integrations.register(...)`, the direct form — and the
# verdict must become SKIPPED (structural): the repo now has a server↔JS pair
# to correlate and nothing correlated it.
#
# The distinction is not cosmetic. `na` does NOT count against coverage;
# `structural` does. A gate that misreads its own subject matter as absent
# switches itself off silently, and prints a confident absence claim while
# doing it — measured on decidesk, whose registered leaf id was enumerable from
# the live JS registry in the same repo's Playwright run.
#
# THE PAIRED ARM MATTERS: `clean/` untouched must STAY `na`. A fix that made
# gate-24 structural everywhere would pass a one-armed version of this test and
# would be a false gap in every repo in the fleet.
_DIRECT="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-24-direct.XXXXXXXX")"
cp -r "${FIXTURES}/clean/." "${_DIRECT}/"
cp "${FIXTURES}/direct-registry-overlay/src/integration-leaf-direct.js" "${_DIRECT}/src/"
if [ ! -f "${_DIRECT}/src/integration-leaf-direct.js" ]; then
    _bad "the direct-registry overlay did not land — every assertion in this arm would be vacuous"
elif grep -rq 'scripts/check-integration-parity.sh' "${_DIRECT}" 2>/dev/null \
     || [ -f "${_DIRECT}/scripts/check-integration-parity.sh" ]; then
    _bad "the direct-registry arm inherited a parity wrapper from clean/, so it exercises the wrapper path and not the leaf-detection path this arm is about"
elif _run "${_DIRECT}"; then
    _expect 24 "SKIPPED (structural)" \
        "gate-24 recognises a leaf registered via integrations.register( — structural, not na"
    _expect 24 "DOES register integration leaves" \
        "gate-24 says WHY it is structural"
    # And the reason must not still claim the repo has no leaves.
    _l24="$(_verdict 24)"
    case "${_l24}" in
        *"registers no integration leaves at all"*)
            _bad "gate-24 still asserts 'registers no integration leaves at all' over a tree that registers one — .github#349 is live: ${_l24:0:160}"
            ;;
        *)  _ok "gate-24 no longer claims the leaf set is empty" ;;
    esac
fi
rm -rf "${_DIRECT}"

# ===========================================================================
echo
echo "== gate-23 in WARN mode: a finding must still be VISIBLE =="
# ===========================================================================
# Before the bake-in epoch the linter exits 0 whatever it found, so the gate
# legitimately cannot fail. It must not therefore be silent: the count and the
# matched rules are stated on stdout, so a WARN-mode green is distinguishable
# from a clean one.
_OR_EPOCH=99999999999
if _run "${FIXTURES}/planted"; then
    _expect 23 "PASS" "gate-23 does not block before its bake-in epoch (by design)"
    _expect_stdout "gate-23 or-abstraction-anti-patterns: 1 rule(s) matched" \
        "gate-23 STATES the WARN-mode finding count instead of printing a bare PASS"
    _expect_stdout "TenantIsolationService.php" \
        "gate-23 surfaces the matched path even when it cannot block"
fi
unset _OR_EPOCH

# ===========================================================================
echo
echo "== unscoped runs: gate-29 must not PASS having read nothing =="
# ===========================================================================
if _run "${FIXTURES}/clean"; then
    _expect 29 "NOT APPLICABLE" "gate-29 unscoped is na (it is intrinsically diff-relative), not PASS"
fi

# ===========================================================================
echo
echo "== a CRASHED checker is not a clean tree (gate-27) =="
# ===========================================================================
# check_phantom_cross_app_rpc.py returns 0 on every successful path, so the
# only way it can exit nonzero is by dying. gate-27 ran it under
# `2>/dev/null || true`, which threw the traceback away, left an empty findings
# log, and printed PASS over every file it had failed to open. Measured on
# doriath with an injected import-time error: 404 files in scope, none judged,
# verdict PASS.
_CRASHPKG="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-2333-crash.XXXXXXXX")"
cp -r "${PKG_ROOT}/scripts" "${_CRASHPKG}/scripts" 2>/dev/null
cat > "${_CRASHPKG}/scripts/lib/check_phantom_cross_app_rpc.py" <<'PYCRASH'
raise RuntimeError("injected: the checker died before judging anything")
PYCRASH
_CRASHLOG="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-2333-crashlog.XXXXXXXX")"
_OUT="$(HYDRA_GATE_LOG_DIR="${_CRASHLOG}" HYDRA_OR_GATE_BLOCK_AFTER_EPOCH=0 \
    bash "${_CRASHPKG}/scripts/run-hydra-gates.sh" "${FIXTURES}/clean" 2>&1 || true)"
if printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
    _expect 27 "SKIPPED (wiring)" "gate-27 with a crashing checker is a wiring skip, never a PASS"
    _expect 27 "NONE were judged" "gate-27 says how many files it failed to judge"
else
    _bad "the crashed-checker arm ABORTED before the summary"
fi
rm -rf "${_CRASHPKG}"

echo
echo "== summary =="
echo "   passed: ${_pass_n}"
echo "   failed: ${_fail_n}"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL gate 23-33 acceptance controls PASSED"
exit 0
