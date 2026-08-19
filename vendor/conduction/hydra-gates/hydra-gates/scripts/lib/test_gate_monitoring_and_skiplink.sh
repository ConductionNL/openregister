#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_monitoring_and_skiplink.sh — control pairs for gate-30
# (public-monitoring) and gate-38 (skip-link).
#
# WHY THIS EXISTS
# ---------------
# Both gates were reporting correct code, and both remedies were worse than
# the finding.
#
#   gate-30  demanded #[PublicPage] on every monitoring-shaped route,
#            including /api/metrics. ADR-006 makes metrics admin-only ON
#            PURPOSE — openregister's engine-owned GenericMetricsController
#            carries only #[NoCSRFRequired] and says "admin-only, ADR-006",
#            while its GenericHealthController IS #[PublicPage]. The only way
#            to close the finding was to publish the fleet's metrics to
#            anonymous callers.
#
#   gate-38  looked for <NcContent> in the root component. All 18 fleet apps
#            root on <CnAppRoot>, which RENDERS an NcContent one component
#            deeper — so the gate reported every app in the fleet as shipping
#            no skip link. The only way to close it was to bolt a second,
#            redundant skip link on top of the one already there.
#
# Both fixes widen what counts as an answer, and widening is exactly how a
# gate goes quiet. So each fixture here has a sibling that differs by the ONE
# thing the widening accepts, and must still fail:
#
#   stated/      metrics documents its admin-only posture   -> gate-30 PASS
#                health is #[PublicPage]
#                App.vue is a CnAppRoot                     -> gate-38 PASS
#
#   accidental/  metrics says NOTHING about its posture     -> gate-30 FAIL
#                health is admin-only, however documented   -> gate-30 FAIL
#                App.vue is a bespoke shell whose only      -> gate-38 FAIL
#                mention of NcContent/skip-link is a comment
#
# Run: bash scripts/lib/test_gate_monitoring_and_skiplink.sh  (exit 0 = green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"
FIXTURES="${PKG_ROOT}/scripts/test-fixtures/monitoring-skiplink"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

_OUT=""
_PMLOG=""
_SLLOG=""
_run() {
    local _dir="$1"; shift
    local _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    _PMLOG="$(cat "${_logdir}/hydra-gate-public-monitoring.log" 2>/dev/null || true)"
    _SLLOG="$(cat "${_logdir}/hydra-gate-skip-link.log" 2>/dev/null || true)"
    # A run that aborts before its summary leaves the per-gate PASS lines on
    # stdout and reads exactly like a clean run.
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_dir} ABORTED before the summary — verdicts above it are not a result"
        printf '%s\n' "${_OUT}" | tail -20 | sed 's/^/       /'
        return 1
    fi
    return 0
}

_expect_gate() {  # <n> <PASS|FAIL> <description>
    local _n="$1" _want="$2" _desc="$3" _line
    _line="$(printf '%s' "${_OUT}" | grep -E "^\[gate-${_n}\] " | head -1)"
    if [ -z "${_line}" ]; then
        _bad "${_desc} — gate-${_n} emitted NO verdict line at all"
        return
    fi
    case "${_line}" in
        *": ${_want}"*) _ok "${_desc} — ${_line}" ;;
        *) _bad "${_desc} — wanted ${_want}, got: ${_line}" ;;
    esac
}

_expect_log() {   # <log-contents> <regex> <description>
    if printf '%s' "$1" | grep -qE "$2"; then _ok "$3"; else
        _bad "$3 — no log line matching /$2/"
    fi
}
_expect_not_log() {
    if printf '%s' "$1" | grep -qE "$2"; then
        _bad "$3 — unexpected: $(printf '%s' "$1" | grep -E "$2" | head -1)"
    else _ok "$3"; fi
}

echo "== gate-30 public-monitoring / gate-38 skip-link control pairs =="
echo

for _f in stated accidental; do
    [ -d "${FIXTURES}/${_f}" ] || _bad "fixture ${FIXTURES}/${_f} does not exist — this suite would be green on nothing"
done

# ---------------------------------------------------------------------------
# 1. THE POSITIVE CONTROL, first. Everything in section 2 is only meaningful
#    because these fire.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/accidental"; then
    _expect_gate 30 FAIL "accidental: an undeclared monitoring posture is still reported"
    _expect_log "${_PMLOG}" 'MetricsController.php:[0-9]+ method=index' \
        "accidental: metrics with no stated posture at all is named"
    _expect_log "${_PMLOG}" 'HealthController.php:[0-9]+ method=index' \
        "accidental: an admin-only HEALTH endpoint is named — the carve-out is metrics-only"

    _expect_gate 38 FAIL "accidental: a bespoke shell with no skip link is still reported"
    _expect_log "${_SLLOG}" 'App\.vue: no <NcContent> shell' \
        "accidental: gate-38 names the root component"
    # #214/#216 — the PHP half must keep a true positive. `standalone.php`
    # emits <html> and <body>: it owns the document, so SC 2.4.1 is its to
    # satisfy, and it does not.
    _expect_log "${_SLLOG}" 'templates/standalone\.php: no <NcContent> shell' \
        "accidental: a PHP template that OWNS the document and has no bypass link is still reported"
fi

# ---------------------------------------------------------------------------
# 2. The shapes that were being reported wrongly.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/stated"; then
    _expect_gate 30 PASS "stated: a documented admin-only metrics endpoint is not a finding"
    _expect_not_log "${_PMLOG}" 'MetricsController' \
        "stated: metrics is absent from the log entirely"
    _expect_not_log "${_PMLOG}" 'HealthController' \
        "stated: a #[PublicPage] health endpoint is absent from the log"

    _expect_gate 38 PASS "stated: a CnAppRoot shell counts as having NC's skip link"
    _expect_not_log "${_SLLOG}" 'App\.vue' \
        "stated: gate-38 log is empty"
    _expect_not_log "${_SLLOG}" 'standalone\.php' \
        "stated: a document-owning template WITH a bypass anchor is not a finding"
fi

# ---------------------------------------------------------------------------
# 3. MOUNT POINT vs PAGE ROOT (#214 / #216 / #227).
#
#    These three assertions run against `accidental/` — the fixture where
#    EVERYTHING ELSE fails. That is deliberate: asserting "not reported" in a
#    tree where the gate reports nothing proves nothing at all. Here the gate
#    is demonstrably firing (section 1 named App.vue and standalone.php in
#    this same run), so an absence is a decision rather than an empty scope.
# ---------------------------------------------------------------------------
if _run "${FIXTURES}/accidental"; then
    _expect_log "${_SLLOG}" 'App\.vue|standalone\.php' \
        "mount-point control: gate-38 IS firing in this tree (positive control for the three below)"
    _expect_not_log "${_SLLOG}" 'settings/admin\.php' \
        "#214/#216: a fragment template (<div id=...> mount point) is not a page root"
    _expect_not_log "${_SLLOG}" 'AdminRoot\.vue' \
        "#227: an admin-settings surface cannot own a page shell, so it is not asked for one"
    # And the narrowing must not have swallowed the whole PHP arm: exactly one
    # template is named, and it is the document-owning one.
    if [ "$(printf '%s\n' "${_SLLOG}" | grep -c '\.php:')" = "1" ]; then
        _ok "#214/#216: exactly ONE of the two PHP templates is in scope — the one that owns the document"
    else
        _bad "#214/#216: expected exactly 1 .php finding, got $(printf '%s\n' "${_SLLOG}" | grep -c '\.php:')"
        printf '%s\n' "${_SLLOG}" | sed 's/^/       /'
    fi
fi

# ---------------------------------------------------------------------------
# 4. A BROKEN CLASSIFIER MUST NOT REPORT PASS (#147 / #249).
#
#    gate-38's PHP arm is driven entirely by php_template_scope.py. If that
#    helper crashes and the runner reads its exit byte as an answer, every
#    template classifies as "fragment", the whole arm evaporates, and the gate
#    reports PASS having inspected nothing — a falsely-green gate manufactured
#    by its own plumbing. Both wiring failures are asserted here.
# ---------------------------------------------------------------------------
_broken="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-broken.XXXXXXXX")"
cp -r "${PKG_ROOT}" "${_broken}/pkg" 2>/dev/null
_BROKEN_RUNNER="${_broken}/pkg/scripts/run-hydra-gates.sh"

_expect_skip() {  # <runner> <description>
    local _out _logdir
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gate-test.XXXXXXXX")"
    _out="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "$1" "${FIXTURES}/stated" 2>&1 || true)"
    local _line
    _line="$(printf '%s' "${_out}" | grep -E '^\[gate-38\] ' | head -1)"
    case "${_line}" in
        *"SKIPPED"*) _ok "$2 — ${_line%%—*}" ;;
        *": PASS"*)  _bad "$2 — reported PASS having classified NOTHING: ${_line}" ;;
        *)           _bad "$2 — wanted SKIPPED, got: ${_line:-<no gate-38 line at all>}" ;;
    esac
}

if [ -f "${_BROKEN_RUNNER}" ]; then
    # 4a. helper absent
    mv "${_broken}/pkg/scripts/lib/php_template_scope.py" \
       "${_broken}/pkg/scripts/lib/php_template_scope.py.hidden"
    _expect_skip "${_BROKEN_RUNNER}" "a MISSING classifier reports SKIPPED, not PASS (#147)"
    mv "${_broken}/pkg/scripts/lib/php_template_scope.py.hidden" \
       "${_broken}/pkg/scripts/lib/php_template_scope.py"
    # 4b. helper present but crashing. This is the case an exit-byte boolean
    #     could not tell from "fragment": a crash exits 1, and so does a
    #     fragment. The answer must come from stdout.
    printf 'import sys\nraise SystemExit("boom")\n' \
        > "${_broken}/pkg/scripts/lib/php_template_scope.py"
    _expect_skip "${_BROKEN_RUNNER}" "a CRASHING classifier reports SKIPPED, not PASS (#249)"
else
    _bad "could not stage a broken-helper copy of the package — wiring assertions did not run"
fi
rm -rf "${_broken}"

echo
echo "== summary =="
printf '   passed: %d\n   failed: %d\n' "${_pass_n}" "${_fail_n}"
[ "${_fail_n}" -eq 0 ] || exit 1
echo
echo "ALL control pairs held."
