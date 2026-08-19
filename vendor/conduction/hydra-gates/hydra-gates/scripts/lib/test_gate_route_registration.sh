#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_route_registration.sh — control pairs for the route- and
# registration-DETECTION defects in gates 5, 14 and 30.
#
# Closes ConductionNL/.github#213 (the gate-30 half), #218, #223, #237.
#
# WHY THIS EXISTS
# ---------------
# Two defects, over and over, in three gates:
#
#   (a) A SHELL OR REGEX DETAIL CORRUPTED THE INPUT, so the gate measured
#       something other than the code. gate-30's selector alternation
#       `(metrics|health|liveness|readiness|probe)` is lowercase-only under
#       `grep -E`, so `genericHealth#index` and
#       `AppHost\Controller\GenericMetrics#index` matched NOTHING — and the
#       gate printed PASS over a 0-byte log in four repos, openregister among
#       them, which OWNS the fleet's health/metrics engine. `read` without -r
#       ate the backslash out of every namespaced route name (fixed for gates
#       5 and 14 in #217; gate-30 carried the same line).
#
#   (b) THE GATES MODELLED **ONE** REGISTRATION IDIOM and flagged every other
#       legitimate one. A route supplied by `Routes::standard()` is not in
#       appinfo/routes.php (#223). A `Bootstrap::register()` call is not
#       required to sit in Application.php — and this suite's own phpmd gate
#       is what pushes it into a registrar (#237, procest#717). A controller
#       whose name merely contains "health" is not a scrape target (#218).
#
# ⚠️ EVERY FIX HERE WIDENS SOMETHING, AND WIDENING IS HOW A GATE GOES QUIET.
# gate-5 and gate-30 guard authorisation surfaces; over-widening them is the
# failure that matters, not the false positives. So every fixture carries its
# own anti-widening half — a sibling differing by the ONE thing the widening
# accepts, which MUST still fail:
#
#   routes-standard/            5 canonical routes exempt   -> gate-14 PASS on them
#                               `gadget#run` has no route   -> gate-14 FAILS
#   routes-standard-missing-update/
#                               the SAME app minus SettingsController::update()
#                               -> settings#update MUST be reported (#265)
#                               the other nine canonical names MUST NOT be
#   psr4-namespaced-controller/ `AppHost\Controller\GenericHealth#index` resolves
#                               to lib/AppHost/Controller/, the app root, NOT to
#                               lib/Controller/ -> gate-14 PASS (#271)
#                               a conventional `ping#index` in the same repo is
#                               unaffected
#   psr4-namespaced-controller-missing-method/
#                               the SAME app with that method renamed
#                               -> MUST be reported, and as
#                               method-not-found (a 500), not class-not-found
#   delegated-registrar/        AppHost call in a registrar -> 3 generics exempt
#                               `gadget#run` bound nowhere  -> gate-14 FAILS
#   delegated-registrar-absent/ the SAME app minus that one file -> all 4 FAIL
#   monitoring-capitalised/     a capitalised name is now SELECTED -> gate-30 FAILS
#                               a metrics posture 20+ lines up   -> not a finding
#   monitoring-per-object/      healthPing#show / #validate ignored
#                               health#index in the same repo    -> gate-30 FAILS
#   monitoring-per-object-only/ zero scrape targets -> NOT APPLICABLE, never PASS
#   monitoring-none/            zero candidates     -> NOT APPLICABLE, never PASS
#
# Run: bash scripts/lib/test_gate_route_registration.sh   (exit 0 = all green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
# THE RUNNER MUST BE OVERRIDABLE, OR THIS SUITE CANNOT BE MUTATION-CHECKED
# (.github#271). This was hardcoded, so pointing HYDRA_GATES_RUNNER_UNDER_TEST at
# a pre-fix tree — the way every other suite in this package is proved
# non-vacuous — silently kept running the FIXED runner and reported all-green.
# A mutation check that cannot fail is the same defect as a gate that cannot
# fail, one level up, and it is what this whole suite exists to catch.
RUNNER="${HYDRA_GATES_RUNNER_UNDER_TEST:-${PKG_ROOT}/scripts/run-hydra-gates.sh}"
FIXTURES="${PKG_ROOT}/scripts/test-fixtures/route-registration"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

_OUT=""
_RRLOG=""
_RALOG=""
_UNRES=""
_PMLOG=""
_PMNOTES=""
_run() {
    local _dir="$1"; shift
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    _RRLOG="$(cat "${_logdir}/hydra-gate-route-reachability.log" 2>/dev/null || true)"
    _RALOG="$(cat "${_logdir}/hydra-gate-route-auth.log" 2>/dev/null || true)"
    _UNRES="$(cat "${_logdir}/hydra-gate-route-auth-unresolved.log" 2>/dev/null || true)"
    _PMLOG="$(cat "${_logdir}/hydra-gate-public-monitoring.log" 2>/dev/null || true)"
    _PMNOTES="$(cat "${_logdir}/hydra-gate-public-monitoring-notes.log" 2>/dev/null || true)"
    # A run that aborts before its summary leaves the per-gate PASS lines on
    # stdout and reads exactly like a clean run.
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_dir} ABORTED before the summary — verdicts above it are not a result"
        printf '%s\n' "${_OUT}" | tail -20 | sed 's/^/       /'
        return 1
    fi
    return 0
}

_expect_gate() {  # <n> <verdict-substring> <description>
    local _n="$1" _want="$2" _desc="$3" _line
    _line="$(printf '%s' "${_OUT}" | grep -E "^\[gate-${_n}\] " | head -1)"
    if [ -z "${_line}" ]; then
        _bad "${_desc} — gate-${_n} emitted NO verdict line at all"
        return
    fi
    case "${_line}" in
        *": ${_want}"*) _ok "${_desc} — ${_line}" ;;
        *) _bad "${_desc} — wanted '${_want}', got: ${_line}" ;;
    esac
}

_expect_log()     { if printf '%s' "$1" | grep -qE "$2"; then _ok "$3"; else _bad "$3 — no log line matching /$2/. Log was: $(printf '%s' "$1" | tr '\n' '|')"; fi; }
_expect_not_log() { if printf '%s' "$1" | grep -qE "$2"; then _bad "$3 — unexpected: $(printf '%s' "$1" | grep -E "$2" | head -1)"; else _ok "$3"; fi; }
_expect_lines()   { local _n; _n=$(printf '%s' "$1" | grep -c . ); if [ "${_n}" -eq "$2" ]; then _ok "$3"; else _bad "$3 — expected $2 line(s), got ${_n}: $(printf '%s' "$1" | tr '\n' '|')"; fi; }

echo "== gate-5 / gate-14 / gate-30 route + registration detection =="
echo

for _f in routes-standard routes-standard-missing-update \
          psr4-namespaced-controller psr4-namespaced-controller-missing-method \
          delegated-registrar delegated-registrar-absent \
          monitoring-capitalised monitoring-per-object monitoring-per-object-only \
          monitoring-none; do
    [ -d "${FIXTURES}/${_f}" ] || _bad "fixture ${FIXTURES}/${_f} does not exist — this suite would be green on nothing"
done

# ---------------------------------------------------------------------------
# 1. #223 — routes supplied by Routes::standard()
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/routes-standard"; then
    _expect_gate 14 "FAIL" "routes-standard: the unrouted method is still a finding"
    _expect_log "${_RRLOG}" "GadgetController\.php method=run .*rule=missing-route" \
        "routes-standard: gadget#run — no route anywhere — IS reported"
    _expect_not_log "${_RRLOG}" "DashboardController" \
        "routes-standard: dashboard#page / #catchAll come from Routes::standard() and are NOT reported"
    _expect_not_log "${_RRLOG}" "SettingsController" \
        "routes-standard: settings#index / #create / #load likewise"
    _expect_lines "${_RRLOG}" 1 \
        "routes-standard: exactly ONE finding — the exemption is ten names, not a blanket"
fi

# ---------------------------------------------------------------------------
# 1b. #265 — the ten Routes::standard() names must be JUDGED, not just exempted
#
# THE ANTI-WIDENING SIBLING OF 1, INVERTED. #223 taught invariant 1 that those
# ten names exist so it would stop reporting working endpoints as unroutable.
# Invariant 2 asks the opposite question — "does the method the route names
# actually exist?" — and it never asked it about those ten, because it read
# route names as LITERALS out of the leaf's appinfo/routes.php and they are not
# there.
#
# `aliasControllerUnlessLeafDefinesIt()` makes that gap reachable: the moment a
# leaf ships its own SettingsController it owes every method the canonical table
# routes to `settings#`, and a missing one is a ReflectionException 500, not a
# 404. Reproduced on shillinq 2026-08-08 by deleting `update()` — gate-14's
# findings log came back EMPTY and the gate said PASS.
#
# The two fixtures differ by exactly that one method.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/routes-standard-missing-update"; then
    _expect_gate 14 "FAIL" "routes-standard-missing-update: a canonical AppHost route whose method is gone IS a finding"
    _expect_log "${_RRLOG}" "SettingsController\.php route='settings#update' rule=method-not-found-on-target-controller" \
        "routes-standard-missing-update: settings#update is named, and named as method-not-found (a 500, not a 404)"
    # THE CONTROL: the OTHER nine canonical names still resolve here, so this is
    # not "an AppHost adopter now fails on all ten".
    _expect_not_log "${_RRLOG}" "settings#index\|settings#create\|settings#load" \
        "routes-standard-missing-update: index / create / load still resolve and are NOT reported"
    _expect_not_log "${_RRLOG}" "DashboardController" \
        "routes-standard-missing-update: dashboard#page / #catchAll are served by AppHost and stay exempt"
    # Exactly two: the sibling's pre-existing `gadget#run`, plus the one method
    # this fixture deletes. Anything more means the ten names stopped being
    # exempt for the CLASS-ABSENT case and the widening went too far.
    _expect_lines "${_RRLOG}" 2 \
        "routes-standard-missing-update: exactly TWO findings — gadget#run and settings#update, nothing else"
fi

# ---------------------------------------------------------------------------
# 1c. #271 — a NAMESPACED route name resolves relative to the APP ROOT
#
# NC's RouteParser::buildControllerName() does not prefix the app namespace when
# the route name already contains a backslash, so
# `AppHost\Controller\GenericHealth#index` is looked up as the bare class
# `AppHost\Controller\GenericHealthController`. PSR-4 maps `OCA\<App>\` onto
# `lib/`, so that class lives at lib/AppHost/Controller/ — NOT under
# lib/Controller/, which is the only place the resolver looked.
#
# Reproduced 2026-08-08 against this package's own gates-23-33/planted fixture:
#
#   lib/Controller/AppHost/Controller/GenericHealthController.php
#     route='AppHost\Controller\GenericHealth#index'
#     rule=controller-class-not-found
#
# — a path that cannot exist, reported as a missing class, INSIDE the repository
# that ships the file. Same shape as the gate-30 finding: the path the gate
# derives is not the path the app uses.
#
# The false FAIL was only half. Where a DI binding rescued the absence the loop
# `continue`d, so the method-existence check never ran at all — #265's defect at
# a different address. The two fixtures differ by exactly that method.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/psr4-namespaced-controller"; then
    _expect_gate 14 "PASS" "psr4-namespaced-controller: a namespaced route whose PSR-4 file EXISTS is not a finding"
    _expect_not_log "${_RRLOG}" "controller-class-not-found" \
        "psr4-namespaced-controller: no 'class not found' for a class sitting in lib/AppHost/Controller/"
    _expect_not_log "${_RRLOG}" "lib/Controller/AppHost" \
        "psr4-namespaced-controller: the impossible lib/Controller/AppHost/... path is never derived"
fi

if _run "${FIXTURES}/psr4-namespaced-controller-missing-method"; then
    _expect_gate 14 "FAIL" "psr4-namespaced-controller-missing-method: the method-existence check now RUNS on a namespaced route"
    _expect_log "${_RRLOG}" "lib/AppHost/Controller/GenericHealthController\.php route='AppHost.Controller.GenericHealth#index' rule=method-not-found-on-target-controller" \
        "…and names the REAL path plus the real rule (a 500, not a missing class)"
    # THE CONTROL: the conventional sibling in the same repo still resolves.
    _expect_not_log "${_RRLOG}" "PingController" \
        "psr4-namespaced-controller-missing-method: the conventional ping#index route is unaffected"
    _expect_lines "${_RRLOG}" 1 \
        "psr4-namespaced-controller-missing-method: exactly ONE finding"
fi

# ---------------------------------------------------------------------------
# 2. #237 — the AppHost call in a delegated registrar, and its sibling
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/delegated-registrar"; then
    _expect_gate 14 "FAIL" "delegated-registrar: the unbound controller is still a finding"
    _expect_log "${_RRLOG}" "GadgetController\.php route='gadget#run' rule=controller-class-not-found" \
        "delegated-registrar: gadget#run is bound by nothing and IS reported"
    _expect_not_log "${_RRLOG}" "HealthController|MetricsController|PreferencesController" \
        "delegated-registrar: the three AppHost generics are NOT reported"
    _expect_lines "${_RRLOG}" 1 \
        "delegated-registrar: exactly ONE finding"
    _expect_log "${_UNRES}" "health#index — served by the OpenRegister AppHost generic" \
        "delegated-registrar: gate-5 states health#index as NOT JUDGED, naming AppHost"
fi

if _run "${FIXTURES}/delegated-registrar-absent"; then
    _expect_gate 14 "FAIL" "delegated-registrar-absent: nothing calls Bootstrap::register()"
    _expect_lines "${_RRLOG}" 4 \
        "delegated-registrar-absent: ALL FOUR absent controllers are reported — removing the one registrar file flips every verdict"
    _expect_log "${_RRLOG}" "HealthController\.php route='health#index'" \
        "delegated-registrar-absent: health#index is reported when nothing binds it"
fi

# ---------------------------------------------------------------------------
# 3. #213 — a capitalised monitoring word is SELECTED at all
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/monitoring-capitalised"; then
    _expect_gate 30 "FAIL" "monitoring-capitalised: a capitalised route name is no longer invisible"
    _expect_log "${_PMLOG}" "GenericHealthController\.php:[0-9]+ method=index" \
        "monitoring-capitalised: genericHealth#index with no stated posture IS named"
    _expect_log "${_PMLOG}" "AppHost/Controller/GenericLivenessController\.php:[0-9]+ method=index" \
        "monitoring-capitalised: a NAMESPACED route name survives read -r and resolves to its file"
    _expect_not_log "${_PMLOG}" "AppHostControllerGenericLiveness" \
        "monitoring-capitalised: the flattened (backslash-eaten) path does NOT appear"
    _expect_not_log "${_PMLOG}" "GenericMetricsController" \
        "monitoring-capitalised: the ADR-006 admin-only carve-out survives case-folding …"
    _expect_not_log "${_PMLOG}" "GenericMetricsController" \
        "monitoring-capitalised: … and survives a docblock longer than the old 20-line window"
fi

# ---------------------------------------------------------------------------
# 4. #218 — a monitoring word in the name is not a monitoring endpoint
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/monitoring-per-object"; then
    _expect_gate 30 "FAIL" "monitoring-per-object: a real scrape target in the same repo still fails"
    _expect_log "${_PMLOG}" "HealthController\.php:[0-9]+ method=index" \
        "monitoring-per-object: health#index — an unparameterised GET — IS reported"
    _expect_not_log "${_PMLOG}" "HealthPingController" \
        "monitoring-per-object: the per-placement badge is NOT reported (its remedy would strip auth)"
    _expect_lines "${_PMLOG}" 1 \
        "monitoring-per-object: exactly ONE finding"
    _expect_log "${_PMNOTES}" "healthPing#show — not a monitoring scrape target: url .* is per-object" \
        "monitoring-per-object: the notes SAY why healthPing#show was not judged"
    _expect_log "${_PMNOTES}" "healthPing#validate — not a monitoring scrape target: verb is 'POST'" \
        "monitoring-per-object: … and why healthPing#validate was not judged"
fi

# ---------------------------------------------------------------------------
# 5. Zero inputs must never be printed as PASS
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/monitoring-per-object-only"; then
    _expect_gate 30 "NOT APPLICABLE" \
        "zero scrape targets: gate-30 says so out loud instead of passing over a 0-byte log"
    _expect_log "${_OUT}" "\[gate-30\].*2 route name\(s\) contain a monitoring word but NONE is a monitoring scrape target" \
        "zero scrape targets: the verdict line carries the candidate count"
    _expect_lines "${_PMLOG}" 0 "zero scrape targets: the findings log is empty"
fi

if _run "${FIXTURES}/monitoring-none"; then
    _expect_gate 30 "NOT APPLICABLE" \
        "zero candidates: gate-30 states that no route name is monitoring-shaped"
    _expect_log "${_OUT}" "\[gate-30\].*declares no route whose name contains" \
        "zero candidates: the verdict line names the reason"
fi

echo
echo "== summary =="
printf '   passed: %d\n   failed: %d\n' "${_pass_n}" "${_fail_n}"
[ "${_fail_n}" -eq 0 ] || exit 1
echo
echo "ALL route/registration control pairs held."
