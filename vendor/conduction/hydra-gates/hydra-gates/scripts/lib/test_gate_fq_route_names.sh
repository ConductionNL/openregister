#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_fq_route_names.sh — control-pair suite for the FULLY-QUALIFIED
# route-name shape in gate-14 (route-reachability) and gate-5 (route-auth).
#
# WHY THIS EXISTS
# ---------------
# Measured 2026-08-08 on opencatalogi origin/development (9e63d9f3): gate-14
# reported six findings, all of them false.
#
#   lib/Controller/OCAOpenCatalogiAppHostControllerGenericDashboardController.php
#     route='OCAOpenCatalogiAppHostControllerGenericDashboard#catchAll'
#     rule=controller-class-not-found
#
# Two defects, stacked, and the outer one hid the inner:
#
#   1. `while IFS='#' read ctrl method` — WITHOUT `-r`. `read` performs
#      backslash removal, so the route name
#      `OCA\OpenCatalogi\AppHost\Controller\GenericDashboard` arrived FLATTENED
#      as `OCAOpenCatalogiAppHostControllerGenericDashboard`. Every namespaced
#      route name in the fleet was affected: openregister's 55 `Settings\…`
#      routes flattened to `SettingsLlmSettings` and friends, which is why
#      _ctrl_path_from_name's `Settings\Foo -> lib/Controller/Settings/
#      FooController.php` branch — documented since the day it was written —
#      had never once been reached from either gate.
#
#   2. Once un-flattened, the name is a fully-qualified class under the app's
#      own namespace, and neither exemption helper understood that shape.
#      `_apphost_serves` is keyed on the five short slugs Bootstrap aliases;
#      `_di_binds_controller` rebuilds `<app_ns>\Controller\…` from a file
#      path. The route is bound in lib/AppInfo/Application.php under the name
#      verbatim + `Controller`, which is the only name NC will ever look up
#      for it: App::main() does `$container->get($controllerName)` first, and
#      for a name containing `\Controller\` the QueryException branch throws
#      rather than rewriting.
#
# The fix that can go wrong quietly is "backslashes are unverifiable, skip
# them" — that would retire the reachability invariant for every namespaced
# route in the fleet, silently. So both arms are asserted here:
#
#   fq-bound    -> gate-14 PASS  (bound fully-qualified routes are NOT findings)
#   fq-unbound  -> gate-14 FAIL  (an UNBOUND fully-qualified route still is)
#
# Run: bash scripts/lib/test_gate_fq_route_names.sh   (exit 0 = all green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"
FIXTURES="${PKG_ROOT}/scripts/test-fixtures/fq-route-names"

_fail_n=0
_pass_n=0

_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

# ---------------------------------------------------------------------------
# _run <app-dir> — capture the whole run into _OUT, the two route logs into
# _RRLOG / _UNRES. Same contract as test_gate_route_auth.sh: an abort before
# the summary leaves per-gate PASS lines on stdout and looks exactly like a
# clean run, so a run without the COVERAGE line is rejected outright. Each
# invocation gets its own log directory — a concurrent run truncating a shared
# /tmp path is a real, measured contamination (see that suite's note).
# ---------------------------------------------------------------------------
_OUT=""
_RRLOG=""
_UNRES=""
_run() {
    local _dir="$1"; shift
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-fqroute.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    _RRLOG="$(cat "${_logdir}/hydra-gate-route-reachability.log" 2>/dev/null || true)"
    _UNRES="$(cat "${_logdir}/hydra-gate-route-auth-unresolved.log" 2>/dev/null || true)"
    rm -rf "${_logdir}"
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_dir} ABORTED before the summary — verdicts above it are not a result"
        printf '%s\n' "${_OUT}" | tail -20 | sed 's/^/       /'
        return 1
    fi
    return 0
}

_gate_line() { printf '%s' "${_OUT}" | grep -E "^\[gate-$1\] " | head -1; }

# _expect_gate <n> <PASS|FAIL> <description>
_expect_gate() {
    local _n="$1" _want="$2" _desc="$3" _line
    _line="$(_gate_line "${_n}")"
    if [ -z "${_line}" ]; then
        _bad "${_desc} — gate-${_n} emitted NO verdict line at all"
        return
    fi
    case "${_line}" in
        *": ${_want}"*) _ok "${_desc} — ${_line}" ;;
        *) _bad "${_desc} — wanted ${_want}, got: ${_line}" ;;
    esac
}

_expect_log() {   # <log-contents> <fixed-string> <description>
    if printf '%s' "$1" | grep -qF "$2"; then _ok "$3"; else
        _bad "$3 — no log line containing '$2'; log was: $(printf '%s' "$1" | tr '\n' '|')"
    fi
}
_expect_not_log() { # <log-contents> <fixed-string> <description>
    if printf '%s' "$1" | grep -qF "$2"; then
        _bad "$3 — unexpected log line: $(printf '%s' "$1" | grep -F "$2" | head -1)"
    else _ok "$3"; fi
}

echo "== gate-14 / gate-5 fully-qualified route-name control pairs =="
echo

for _f in fq-bound fq-unbound; do
    if [ ! -d "${FIXTURES}/${_f}" ]; then
        _bad "fixture ${FIXTURES}/${_f} does not exist — this suite would be green on nothing"
    fi
done

# ---------------------------------------------------------------------------
# 1. THE DEFECT. Three fully-qualified routes, each bound in Application.php
#    under the name verbatim + `Controller`. No Bootstrap::register() call in
#    this fixture, deliberately: `_apphost_serves` is switched off, so the
#    binding evidence is the ONLY thing that can exempt them. If it stops
#    working, this goes red rather than being carried by the slug list.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/fq-bound"; then
    _expect_gate 14 PASS "fq-bound: DI-bound fully-qualified routes are not reachability findings"
    _expect_gate 5 PASS  "fq-bound: nor are they auth findings"

    # The flattening is the root cause; assert it is gone by name. A resolver
    # that starts stripping backslashes again produces this token and nothing
    # else in this suite would necessarily notice.
    if printf '%s' "${_OUT}${_RRLOG}${_UNRES}" | grep -qF 'OCAFixture'; then
        _bad "fq-bound: a FLATTENED route name (OCAFixture…) reappeared — read -r regressed"
    else
        _ok "fq-bound: no flattened route name anywhere in the run"
    fi

    # The `Settings\Foo` branch of _ctrl_path_from_name is reachable at last:
    # the class is opened and judged rather than reported missing.
    _expect_not_log "${_RRLOG}" 'SettingsWidgetController.php' \
        "fq-bound: Settings\\Widget resolves to lib/Controller/Settings/, not the flattened path"
    _expect_not_log "${_UNRES}" 'Settings\Widget#show' \
        "fq-bound: Settings\\Widget#show is judged, not filed as unresolvable"

    # Not judged is STATED, never silently dropped — same contract as the
    # AppHost generics in test_gate_route_auth.sh.
    _expect_log "${_UNRES}" 'OCA\Fixture\AppHost\Controller\GenericDashboard#page' \
        "fq-bound: gate-5 states the DI-bound generic was NOT JUDGED rather than dropping it"
fi

# ---------------------------------------------------------------------------
# 2. THE ANTI-DEAD-GATE CONTROL. Same app, same three bindings, plus a
#    fully-qualified route that is bound NOWHERE. It must still fail — that is
#    the invariant the fix above is required to preserve.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/fq-unbound"; then
    _expect_gate 14 FAIL "fq-unbound: an UNBOUND fully-qualified route is still raised"

    _expect_log "${_RRLOG}" \
        "lib/AppHost/Controller/GenericGadgetController.php route='OCA\Fixture\AppHost\Controller\GenericGadget#run' rule=controller-class-not-found" \
        "fq-unbound: gate-14 names the unbound class at its PSR-4 path, with the route name intact"

    # The three bound siblings sit in the SAME file. If the exemption were
    # keyed on anything coarser than the individual binding — "the app binds
    # services", "the name is fully qualified" — they would be reported here
    # too, or the gadget would not be.
    for _bound in GenericDashboardController GenericHealthController; do
        _expect_not_log "${_RRLOG}" "${_bound}" \
            "fq-unbound: the DI-bound ${_bound} is NOT reported alongside the unbound one"
    done

    # The other newly-reachable shape: class on disk, method absent. Before
    # the resolver stopped flattening, this was misreported as a missing CLASS.
    _expect_log "${_RRLOG}" \
        "lib/Controller/Settings/WidgetController.php route='Settings\Widget#absentMethod' rule=method-not-found-on-target-controller" \
        "fq-unbound: a Settings\\ route whose method is absent is reported as method-not-found, not class-not-found"

    # Exactly two findings — no more, no fewer. A count assertion is what
    # catches an exemption that has quietly become a blanket one.
    _rr_n="$(printf '%s' "${_RRLOG}" | grep -c . || true)"
    if [ "${_rr_n}" = "2" ]; then
        _ok "fq-unbound: exactly 2 findings (the unbound class + the absent method)"
    else
        _bad "fq-unbound: expected 2 findings, got ${_rr_n}: $(printf '%s' "${_RRLOG}" | tr '\n' '|')"
    fi

    # gate-5 must not turn red on any of this: an unresolvable class is a
    # reachability defect, not an auth finding (ConductionNL/.github#153).
    _expect_gate 5 PASS "fq-unbound: the unresolvable class is not an auth finding either"
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
echo "ALL fully-qualified route-name control pairs PASSED"
exit 0
