#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_route_auth.sh — control-pair suite for gate-5 (route-auth) and the
# reachability invariant gate-14 took over from it.
#
# WHY THIS EXISTS
# ---------------
# ConductionNL/.github#153. Gate-5 had two defects, both live on scholiq:
#
#   (a) it reported a RESOLUTION FAILURE as a SECURITY FINDING. scholiq's
#       health/metrics/preferences controllers are ADR-040 AppHost generics
#       registered by \OCA\OpenRegister\AppHost\Bootstrap::register(); the files
#       are absent from the leaf repo by design. The gate said
#       "4 routed method(s) missing auth attribute". They are not missing —
#       the gate was looking in the wrong repository.
#
#   (b) it was not diff-scoped. The missing-file branch `continue`d BEFORE the
#       `_in_scope` call, so those 4 findings fired on a diff of package.json +
#       package-lock.json. Every scholiq PR, Dependabot included, was blocked.
#
# The fix for (a) is the one that can go wrong quietly: "teach the gate about
# AppHost" is one careless edit away from "AppHost apps are exempt", and a gate
# that stops failing is worse than the false positive it replaced. So EVERY
# assertion here is one half of a control pair — for each thing that must not
# fire, there is a neighbouring fixture where the same code path MUST fire.
#
#   unguarded          -> FAIL  (a real IDOR shape: id-taking write, no attribute)
#   guarded            -> PASS  (same routes, attributes present)
#   apphost            -> PASS  (scholiq's shape: no finding, 4 NOT JUDGED)
#   apphost-unguarded  -> FAIL  (AppHost adopter that ALSO ships a bad method)
#   orphan-route       -> gate-5 declines, gate-14 raises it (no dead gate)
#
# Run: bash scripts/lib/test_gate_route_auth.sh   (exit 0 = all green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"
FIXTURES="${PKG_ROOT}/scripts/test-fixtures/route-auth"

_fail_n=0
_pass_n=0

_ok()   { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad()  { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

# ---------------------------------------------------------------------------
# _run <app-dir> [extra runner args...]  — capture the whole run into _OUT.
#
# The runner's contract is that its LAST word is the summary. An abort before
# the summary leaves the per-gate PASS lines on stdout and looks exactly like a
# completed clean run, so every invocation here is rejected unless the
# COVERAGE line is present. A suite that accepts a truncated run is measuring
# nothing.
# ---------------------------------------------------------------------------
_OUT=""
_UNRES=""
_RRLOG=""
_LOGDIR=""
_run() {
    local _dir="$1"; shift
    # Give THIS invocation its own findings directory.
    #
    # These assertions used to read fixed /tmp/hydra-gate-*.log paths and
    # carried a comment saying a concurrent run "would truncate" them — with
    # a snapshot taken after the run as the mitigation. A snapshot cannot
    # help: the truncation happens DURING the run, between the gate's write
    # and its own `wc -l`. Run under the helper-suite harness this suite
    # reported 7 failures while reporting 0 standalone, which is the shape
    # of a measurement contaminated by another process, not of a defect.
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    _UNRES="$(cat "${_logdir}/hydra-gate-route-auth-unresolved.log" 2>/dev/null || true)"
    _RRLOG="$(cat "${_logdir}/hydra-gate-route-reachability.log" 2>/dev/null || true)"
    _LOGDIR="${_logdir}"
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

_expect_out() {   # <regex> <description>
    if printf '%s' "${_OUT}" | grep -qE "$1"; then _ok "$2"; else
        _bad "$2 — no line matching /$1/"
    fi
}
_expect_no_out() { # <regex> <description>
    if printf '%s' "${_OUT}" | grep -qE "$1"; then
        _bad "$2 — unexpected line: $(printf '%s' "${_OUT}" | grep -E "$1" | head -1)"
    else _ok "$2"; fi
}
_expect_log() {   # <snapshot-var-contents> <regex> <description>
    if printf '%s' "$1" | grep -qE "$2"; then _ok "$3"; else
        _bad "$3 — no log line matching /$2/"
    fi
}

echo "== gate-5 route-auth / gate-14 reachability control pairs =="
echo

for _f in unguarded guarded apphost apphost-unguarded orphan-route di-registered-generic prose-exempt auth-declared; do
    if [ ! -d "${FIXTURES}/${_f}" ]; then
        _bad "fixture ${FIXTURES}/${_f} does not exist — this suite would be green on nothing"
    fi
done

# ---------------------------------------------------------------------------
# 1. POSITIVE CONTROL. A real IDOR shape must still fail. Everything below is
#    only meaningful because this one is red-when-it-should-be.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/unguarded"; then
    _expect_gate 5 FAIL "unguarded fixture: a routed, attribute-less write still FAILS gate-5"
    _expect_out 'gate-5\] route-auth: FAIL — 2 routed method' \
        "unguarded fixture: exactly TWO findings (the guarded sibling method is not reported)"
    _RALOG="$(cat "${_LOGDIR}/hydra-gate-route-auth.log" 2>/dev/null || true)"
    _expect_log "${_RALOG}" 'WidgetController.php:[0-9]+ method=update' \
        "unguarded fixture: the snake/lowercase slug's unguarded method is named"
    # camelCase slug. Invisible to gate-5's old `'[a-z_]+#…'` regex.
    _expect_log "${_RALOG}" 'PaymentTransactionController.php:[0-9]+ method=callback' \
        "unguarded fixture: a camelCase route slug is judged at all"
fi

# 2. NEGATIVE CONTROL of the same code path.
if _run "${FIXTURES}/guarded"; then
    _expect_gate 5 PASS "guarded fixture: same routes (camelCase slug included) with attributes PASS"
    _expect_no_out 'NOT JUDGED' "guarded fixture: nothing unresolved (every class resolved)"
fi

# ---------------------------------------------------------------------------
# 3. DEFECT (a). scholiq's shape: AppHost generics produce NO finding, and the
#    fact that they were not judged is STATED rather than silently dropped.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/apphost"; then
    _expect_gate 5 PASS "apphost fixture: AppHost generics produce NO auth finding"
    _expect_out 'gate-5 route-auth: 4 routed entr\(ies\) NOT JUDGED' \
        "apphost fixture: the 4 unresolved entries are reported as NOT JUDGED, not as findings"
    _expect_log "${_UNRES}" 'OpenRegister AppHost generic controller' \
        "apphost fixture: the reason names AppHost explicitly"
    _expect_gate 14 PASS "apphost fixture: gate-14 does not call an AppHost alias unreachable"
fi

# ---------------------------------------------------------------------------
# 4. THE ANTI-DEAD-GATE CONTROL. Same AppHost adoption, plus one genuinely
#    unguarded method. If the AppHost knowledge ever becomes a blanket
#    exemption, this goes green and the suite goes red.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/apphost-unguarded"; then
    _expect_gate 5 FAIL "apphost-unguarded fixture: AppHost adoption does NOT exempt the app's own controllers"
    _expect_out 'gate-5 route-auth: 5 routed entr\(ies\) NOT JUDGED' \
        "apphost-unguarded fixture: the AppHost entries are still merely unjudged"
    # AppHost adoption excuses the FIVE generics and nothing else. `gadget#run`
    # names a class nobody provides — still a reachability defect.
    _expect_gate 14 FAIL "apphost-unguarded fixture: a NON-generic missing controller is still raised"
    _expect_log "${_RRLOG}" "GadgetController.php route='gadget#run' rule=controller-class-not-found" \
        "apphost-unguarded fixture: gate-14 names gadget#run, not the AppHost generics"
    if printf '%s' "${_RRLOG}" | grep -qE '(Health|Metrics|Preferences)Controller'; then
        _bad "apphost-unguarded fixture: gate-14 wrongly reported an AppHost generic"
    else
        _ok "apphost-unguarded fixture: gate-14 reported no AppHost generic"
    fi
fi

# ---------------------------------------------------------------------------
# 5. NO HOLE. A route naming a class this repo does not ship is real, and is
#    now raised by the gate that owns reachability instead of being reported as
#    an auth finding by the gate that cannot see the class.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/orphan-route"; then
    _expect_gate 5 PASS "orphan-route fixture: an unresolvable class is NOT an auth finding"
    _expect_out 'gate-5 route-auth: 2 routed entr\(ies\) NOT JUDGED' \
        "orphan-route fixture: both unresolvable entries are stated"
    _expect_gate 14 FAIL "orphan-route fixture: gate-14 DOES raise the unreachable route"
    _expect_log "${_RRLOG}" 'rule=controller-class-not-found' \
        "orphan-route fixture: gate-14 log names controller-class-not-found"
    _expect_log "${_RRLOG}" 'rule=method-not-found-on-target-controller' \
        "orphan-route fixture: gate-14 still names the missing-method shape too"
fi

# ---------------------------------------------------------------------------
# 5b. THE OTHER WAY AN APPHOST GENERIC REACHES A ROUTE. openconnector
#     (2026-08-07) registers OpenRegister's GenericPreferencesController
#     itself, under the standard controller class name, so the generic gets
#     this app's `appName` and its `pref_` user values stay scoped here. No
#     Bootstrap::register() alias, and the route slug is `genericPreferences`
#     rather than `preferences` — so the five-slug list could not see it and
#     gate-14 reported a working endpoint unreachable.
#
#     The control lives in the SAME fixture: `gadget#run` names a class that
#     is neither on disk nor registered. If _di_binds_controller() ever
#     loosens into "this app registers services, so absences are fine", that
#     assertion goes red here rather than going quiet in the fleet.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/di-registered-generic"; then
    _expect_gate 14 FAIL "di-registered-generic fixture: an unregistered, absent controller is still raised"
    _expect_log "${_RRLOG}" "GadgetController.php route='gadget#run' rule=controller-class-not-found" \
        "di-registered-generic fixture: gate-14 names gadget#run"
    if printf '%s' "${_RRLOG}" | grep -q 'GenericPreferencesController'; then
        _bad "di-registered-generic fixture: gate-14 reported the DI-registered generic as unreachable"
    else
        _ok "di-registered-generic fixture: the DI-registered generic is NOT reported"
    fi
    _expect_gate 5 PASS "di-registered-generic fixture: the absent generic is not an auth finding either"
    _expect_log "${_UNRES}" 'genericPreferences#getPreference' \
        "di-registered-generic fixture: gate-5 states the generic was NOT JUDGED rather than dropping it"
fi

# ---------------------------------------------------------------------------
# 5c. DEFECT (c) — #196. A COMMENT SATISFIED THE GATE.
#
#     This is a false-NEGATIVE fix, so the acceptance test runs the other way
#     round from every pair above: the assertion is that the gate now CATCHES
#     what it used to wave through. Measured against main before the fix, the
#     prose-exempt fixture reported ONE finding (`analytics`); `subscribe`,
#     whose only difference is a docblock sentence naming `#[NoAdminRequired]`,
#     PASSED. Two methods, identical auth posture, opposite verdicts, decided
#     by prose. It must now report TWO.
#
#     ⚠️ The count matters more than the verdict here. FAIL alone would still
#     be FAIL if only `analytics` were found, so a suite asserting only the
#     verdict would have been green BEFORE the fix.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/prose-exempt"; then
    _expect_gate 5 FAIL "prose-exempt fixture: a docblock naming an attribute is not an attribute"
    _expect_out 'gate-5\] route-auth: FAIL — 2 routed method' \
        "prose-exempt fixture: BOTH methods are reported (pre-fix main reported 1)"
    _RALOG="$(cat "${_LOGDIR}/hydra-gate-route-auth.log" 2>/dev/null || true)"
    _expect_log "${_RALOG}" 'method=subscribe' \
        "prose-exempt fixture: the method that used to pass on prose is named"
    _expect_log "${_RALOG}" 'method=analytics' \
        "prose-exempt fixture: its unchanged neighbour is still named"
fi

# 5d. THE CLOSING MOVE, and the anti-unclosable control. Absence of
#     #[NoAdminRequired] IS the admin gate, so an admin-only method has no
#     attribute to satisfy the check with — the tightening ships with an
#     explicit `@auth admin-only <reason>` declaration. All three declaration
#     forms must pass, and the class docblock in this fixture mentions
#     `#[NoAdminRequired]` in prose exactly like prose-exempt's does, so a
#     PASS here cannot come from the old raw-text behaviour creeping back.
if _run "${FIXTURES}/auth-declared"; then
    _expect_gate 5 PASS "auth-declared fixture: attribute, legacy docblock tag and @auth admin-only all satisfy the gate"
    _expect_no_out 'NOT JUDGED' "auth-declared fixture: nothing unresolved"
fi

# ---------------------------------------------------------------------------
# 6. DEFECT (b): diff scoping. Needs a real git repo, so build one per case.
# ---------------------------------------------------------------------------
_TMP="$(mktemp -d)"
trap 'rm -rf "${_TMP}"' EXIT

# _mkrepo <fixture> <name> — copy the fixture into a fresh git repo and commit
# it. Sets the globals ${_REPO} and ${_BASE}. Deliberately NOT a function that
# echoes the path: `_repo="$(_mkrepo …)"` runs it in a SUBSHELL, so ${_BASE}
# would be discarded and every scoped run would be handed an empty base. The
# runner fails closed on that (exit 99), which is the only reason the mistake
# was visible at all rather than silently passing on an empty diff.
_BASE=""
_REPO=""
_mkrepo() {
    local _fx="$1" _name="$2"
    _REPO="${_TMP}/${_name}"
    mkdir -p "${_REPO}"
    cp -r "${FIXTURES}/${_fx}/." "${_REPO}/"
    git -C "${_REPO}" init -q 2>/dev/null
    git -C "${_REPO}" config user.email t@example.invalid
    git -C "${_REPO}" config user.name test
    printf '{"name":"fixture","version":"1.0.0"}\n' > "${_REPO}/package.json"
    git -C "${_REPO}" add -A >/dev/null 2>&1
    git -C "${_REPO}" commit -q -m base >/dev/null 2>&1
    _BASE="$(git -C "${_REPO}" rev-parse HEAD)"
}

# --- 6a. package.json-only diff on the fixture that DOES have a real finding.
#     This is the exact shape that blocked scholiq. It must produce nothing —
#     and note the finding IS there in the tree (assertion 1 proved it), so the
#     verdict here is scoping, not absence.
#
#     ⚠️ RECLASSIFIED 2026-08-08 from PASS to NOT APPLICABLE. The sentence above
#     is the whole argument: "a PASS here is scoping, not absence" is a fact the
#     verdict PASS does not state and NOT APPLICABLE does. Measured on
#     larpingapp, gate-5 reported PASS having judged ZERO routed methods, which
#     is indistinguishable from a repo whose every endpoint was checked and
#     found correct. `na` (not `structural`) per #268: an empty ADR-020 scope is
#     subject matter absent from THIS DIFF, so it must not count against
#     --require-full-coverage. Gates 4/6/7 already answered this situation the
#     same way. 6b and 6c below are the controls that keep this honest — the
#     identical finding must still FAIL the moment the diff touches the
#     controller or appinfo/routes.php.
_mkrepo unguarded scope-deps
printf '{"name":"fixture","version":"1.0.1"}\n' > "${_REPO}/package.json"
printf 'lockfile\n' > "${_REPO}/package-lock.json"
git -C "${_REPO}" add package.json package-lock.json >/dev/null 2>&1
git -C "${_REPO}" commit -q -m bump >/dev/null 2>&1
if _run "${_REPO}" --scope-to-diff --base "${_BASE}"; then
    _expect_gate 5 "NOT APPLICABLE" "package.json-only diff: gate-5 reports NO finding (scoped out, not absent)"
    _expect_out '^\[gate-5\][^:]*: NOT APPLICABLE — 0 routed method\(s\) were judged' \
        "package.json-only diff: gate-5 says how many routed methods it judged (zero)"
fi

# --- 6b. same repo, same finding, but the diff touches the controller.
_mkrepo unguarded scope-ctrl
printf '\n// touched\n' >> "${_REPO}/lib/Controller/WidgetController.php"
git -C "${_REPO}" add lib/Controller/WidgetController.php >/dev/null 2>&1
git -C "${_REPO}" commit -q -m touch >/dev/null 2>&1
if _run "${_REPO}" --scope-to-diff --base "${_BASE}"; then
    _expect_gate 5 FAIL "controller in the diff: the same missing attribute FAILS"
fi

# --- 6c. the diff touches appinfo/routes.php only. Adding or altering a route
#     is exactly when its auth posture must be re-checked, so the routed
#     methods come back into scope even though no controller changed.
_mkrepo unguarded scope-routes
printf '\n// touched\n' >> "${_REPO}/appinfo/routes.php"
git -C "${_REPO}" add appinfo/routes.php >/dev/null 2>&1
git -C "${_REPO}" commit -q -m touch >/dev/null 2>&1
if _run "${_REPO}" --scope-to-diff --base "${_BASE}"; then
    _expect_gate 5 FAIL "routes.php in the diff: routed methods are re-judged"
fi

# --- 6d. AppHost app, package.json-only diff: scholiq's actual PR. Clean.
_mkrepo apphost scope-apphost-deps
printf '{"name":"fixture","version":"1.0.1"}\n' > "${_REPO}/package.json"
git -C "${_REPO}" add package.json >/dev/null 2>&1
git -C "${_REPO}" commit -q -m bump >/dev/null 2>&1
if _run "${_REPO}" --scope-to-diff --base "${_BASE}"; then
    # See 6a for why this is NOT APPLICABLE rather than PASS. On an AppHost app
    # the distinction matters more, not less: the four routed entries here are
    # served by the OpenRegister generic controllers and are UNRESOLVABLE from
    # this repository, so "PASS" would have claimed a clean bill of health for
    # four endpoints whose attributes this run cannot see at all.
    _expect_gate 5 "NOT APPLICABLE" "AppHost app, dependency-only diff: gate-5 clean (the scholiq case)"
    _expect_gate 14 PASS "AppHost app, dependency-only diff: gate-14 clean"
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
echo "ALL gate-5 / gate-14 control pairs PASSED"
exit 0
