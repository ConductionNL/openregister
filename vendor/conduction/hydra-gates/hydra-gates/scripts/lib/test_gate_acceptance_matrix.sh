#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_acceptance_matrix.sh — the never-green-over-nothing property,
# generalised from two gates to the whole declared inventory.
#
# WHY THIS EXISTS
# ---------------
# On 2026-08-11 a fleet sweep proved gate defects by hand, one planted true
# positive at a time, in ten repositories. Every defect it found was in a gate
# that had NO repo-shaped fixture. That is not a coincidence — it is the
# selection effect. The gates with fixtures were the gates that could not
# quietly stop working.
#
# THE ARGUMENT AGAINST RELYING ON THE UNIT TESTS
# ----------------------------------------------
# scripts/lib/ ships ~71 helper self-tests. They are worth having and they are
# not sufficient, and we know the exact size of the gap:
#
#   gate-7 has 86 unit tests. All 86 passed. The gate was still wrong.
#
# `_GUARD_HELPER_NAME_RE` demanded the auth token be the FINAL CamelCase
# segment, so hermiq's verb-object predicates — `canUserAccessAgent()`,
# `canUserModifyAgent()` — were not recognised as guards and all three of its
# gate-7 findings were false positives. The regex was self-consistent; the unit
# tests asserted the regex against strings the regex's author had thought of.
# What none of them could contain was a real repository that spells its
# predicates differently. Only a repo-shaped fixture driven through the REAL
# wrapper closes that.
#
# Three more from the same day, all invisible to a unit test by construction:
#   gate-61  a SCOPE bug. The checker is fine. `bin/hydra-gates` does not
#            forward `--base` on `--full`, so the gate is handed a base that
#            produces an empty diff and reports NOT APPLICABLE — 0 of 45
#            registrations inspected, fleet-wide, on every workflow_dispatch.
#   gate-4   its INPUT was hidden. `.gitignore` excluded composer.lock, so the
#            gate had never audited anything in any repository.
#   gate-19  returned its finding COUNT as an exit STATUS. A byte holds 255, so
#            256 findings would have exited 0 = PASS.
# A unit test over a checker function sees none of these: they live in the
# wrapper, the filesystem, and the process boundary.
#
# WHAT THIS SUITE ASSERTS
# -----------------------
# For every bundle under scripts/test-fixtures/gate-acceptance/:
#   planted/  every declared gate must FAIL **and its output must NAME the
#             planted subject**. A nonzero count is not enough: a gate that
#             fails for an unrelated reason, or that reports a bare number,
#             passed this suite for free before we required the name.
#   clean/    the SAME gates must PASS. Without this arm, "widen the checker
#             until everything trips it" is a passing repair.
#
# NOT APPLICABLE over a fixture built to trigger the gate is a FAILURE of the
# suite, never an excuse. That is the exact shape of the gate-61 defect.
#
# THE COVERAGE RATCHET
# --------------------
# A hand-maintained list of covered gates is the failure mode this package has
# already been bitten by twice (the four-named-suites CI list; the fixture dirs
# that never existed). So coverage is COMPUTED from the bundles, diffed against
# the runner's own declared inventory, and every uncovered gate must appear in
# UNCOVERED.md with a reason. The list can only shrink:
#   * a gate in UNCOVERED.md that now HAS a fixture   -> hard failure
#   * a declared gate with neither fixture nor entry  -> hard failure
# So a gate added to the runner cannot land silently untested, and a fixture
# added cannot leave a stale excuse behind.
#
# Run: bash scripts/lib/test_gate_acceptance_matrix.sh
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${PKG_ROOT}/scripts/run-hydra-gates.sh"
BUNDLES="${PKG_ROOT}/scripts/test-fixtures/gate-acceptance"
UNCOVERED_FILE="${BUNDLES}/UNCOVERED.md"
# Gates fixtured by a dedicated suite outside gate-acceptance/ (gates-23-33,
# scope-matrix, e2e-credibility). Counted as COVERED. Without this the ratchet
# reports eleven genuinely-tested gates as untested, and a ratchet that cries
# wolf is a ratchet somebody switches off.
ELSEWHERE_FILE="${BUNDLES}/COVERED-ELSEWHERE.md"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

# ---------------------------------------------------------------------------
# The declared inventory, read from the runner ITSELF — the same source the
# COVERAGE line counts against. Hardcoding 64 here would rot the moment a gate
# is added, and rot silently, which is the whole disease.
# ---------------------------------------------------------------------------
mapfile -t DECLARED < <(
    grep -oE '_(pass|fail|skip|na) [0-9]+ "[^"]+"' "${RUNNER}" 2>/dev/null \
        | sed -E 's/_[a-z]+ ([0-9]+) "([^"]+)"/\1 \2/' \
        | sort -n -u -k1,1
)
if [ "${#DECLARED[@]}" -lt 40 ]; then
    echo "FAIL — read only ${#DECLARED[@]} declared gates out of ${RUNNER}."
    echo "       The inventory grep no longer matches the runner's shape. Refusing"
    echo "       to report coverage against an inventory we could not read: an"
    echo "       inventory of 0 would make this suite trivially complete."
    exit 1
fi
echo "== runner declares ${#DECLARED[@]} gates =="

# ---------------------------------------------------------------------------
# Run helper. Mirrors test_gates_23_33_never_green_over_nothing.sh.
# ---------------------------------------------------------------------------
_OUT=""
_LOGDIR=""
_run() {  # <fixture-dir> [runner args...]
    local _dir="$1"; shift
    _LOGDIR="$(mktemp -d "${TMPDIR:-/tmp}/hydra-accept.XXXXXXXX")"
    _OUT="$(HYDRA_GATE_LOG_DIR="${_LOGDIR}" \
        HYDRA_OR_GATE_BLOCK_AFTER_EPOCH=0 \
        bash "${RUNNER}" "$@" "${_dir}" 2>&1 || true)"
    # An abort before the summary leaves per-gate PASS lines on stdout and
    # reads exactly like a clean run. Refuse to grade a run that did not finish.
    if ! printf '%s' "${_OUT}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _bad "run in ${_dir} ABORTED before the summary — the verdicts above it are not a result"
        printf '%s\n' "${_OUT}" | tail -20 | sed 's/^/       /'
        return 1
    fi
    return 0
}

_verdict() {  # <gate-n> -> that gate's verdict line
    printf '%s' "${_OUT}" | grep -E "^\[gate-$1\] " | head -1
}

# _grade <gate-n> <wanted-status> <arm> <bundle>
# wanted-status is FAIL / PASS / NOT APPLICABLE / SKIPPED
_grade() {
    local _g="$1" _want="$2" _arm="$3" _bundle="$4" _line
    _line="$(_verdict "${_g}")"
    if [ -z "${_line}" ]; then
        _bad "[${_bundle}/${_arm}] gate-${_g} emitted NO verdict line at all — a gate that says nothing is not a pass"
        return 1
    fi
    # A gate that reports NOT APPLICABLE over a fixture authored to trigger it
    # is the gate-61 defect. Name it as such rather than as a generic mismatch.
    if [ "${_want}" = "FAIL" ] && printf '%s' "${_line}" | grep -q 'NOT APPLICABLE'; then
        _bad "[${_bundle}/${_arm}] gate-${_g} reported NOT APPLICABLE over a fixture built to TRIGGER it — this is the gate-61 shape (a gate with nothing in scope prints the same word as a gate that looked and found nothing). Line: ${_line}"
        return 1
    fi
    case "${_line}" in
        *"${_want}"*) _ok "[${_bundle}/${_arm}] gate-${_g} ${_want} — ${_line:0:100}"; return 0 ;;
        *) _bad "[${_bundle}/${_arm}] gate-${_g} wanted '${_want}', got: ${_line}"; return 1 ;;
    esac
}

# _names <gate-n> <log-basename> <subject> <bundle>
# THE LOAD-BEARING ASSERTION: the finding must name the planted subject.
_names() {
    local _g="$1" _log="$2" _subject="$3" _bundle="$4"
    # "-" means: the subject is stated on stdout rather than in a side log.
    if [ "${_log}" = "-" ]; then
        if printf '%s' "${_OUT}" | grep -qF -- "${_subject}"; then
            _ok "[${_bundle}/planted] gate-${_g} NAMES '${_subject}' on stdout"
        else
            _bad "[${_bundle}/planted] gate-${_g} failed but never NAMED '${_subject}' on stdout — a bare count is not a finding"
        fi
        return
    fi
    local _f="${_LOGDIR}/${_log}"
    if [ ! -f "${_f}" ]; then
        _bad "[${_bundle}/planted] gate-${_g} wrote no ${_log} — it failed without recording WHAT failed"
        return
    fi
    if grep -qF -- "${_subject}" "${_f}"; then
        _ok "[${_bundle}/planted] gate-${_g} NAMES '${_subject}' in ${_log}"
    else
        _bad "[${_bundle}/planted] gate-${_g} failed but '${_subject}' is not in ${_log} — it failed for some OTHER reason, so this fixture proves nothing. Log head: $(head -3 "${_f}" | tr '\n' ' ')"
    fi
}

# ---------------------------------------------------------------------------
# Discover bundles. Discovery, not a list — see the header.
# ---------------------------------------------------------------------------
if [ ! -d "${BUNDLES}" ]; then
    echo "FAIL — ${BUNDLES} does not exist. Every assertion below would be"
    echo "       vacuous, which is precisely the defect this suite exists to catch."
    exit 1
fi

mapfile -t BUNDLE_DIRS < <(find "${BUNDLES}" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
if [ "${#BUNDLE_DIRS[@]}" -eq 0 ]; then
    echo "FAIL — discovered ZERO fixture bundles. An empty run is not a green run."
    exit 1
fi
echo "== discovered ${#BUNDLE_DIRS[@]} fixture bundle(s) =="
echo

COVERED=()

for _bundle in "${BUNDLE_DIRS[@]}"; do
    _bdir="${BUNDLES}/${_bundle}"
    _expect="${_bdir}/expect.conf"
    if [ ! -f "${_expect}" ]; then
        _bad "bundle '${_bundle}' has no expect.conf — a fixture nothing asserts against is not coverage"
        continue
    fi
    if [ ! -d "${_bdir}/planted" ] || [ ! -d "${_bdir}/clean" ]; then
        _bad "bundle '${_bundle}' is missing planted/ or clean/ — one arm alone cannot fail in both directions"
        continue
    fi

    # expect.conf lines: gate <n> <log-basename|-> <planted-verdict> <clean-verdict> <subject...>
    mapfile -t _rows < <(grep -E '^gate[[:space:]]' "${_expect}" || true)
    if [ "${#_rows[@]}" -eq 0 ]; then
        _bad "bundle '${_bundle}' expect.conf declares no gate rows"
        continue
    fi

    echo "== ${_bundle}: planted/ — every declared gate must FAIL and NAME its subject =="
    if _run "${_bdir}/planted"; then
        for _row in "${_rows[@]}"; do
            read -r _kw _g _log _pv _cv _subject <<< "${_row}"
            COVERED+=("${_g}")
            if _grade "${_g}" "${_pv}" planted "${_bundle}"; then
                [ "${_pv}" = "FAIL" ] && _names "${_g}" "${_log}" "${_subject}" "${_bundle}"
            fi
        done
    fi

    echo "== ${_bundle}: clean/ — the SAME gates must not fire (no widening) =="
    if _run "${_bdir}/clean"; then
        for _row in "${_rows[@]}"; do
            read -r _kw _g _log _pv _cv _subject <<< "${_row}"
            _grade "${_g}" "${_cv}" clean "${_bundle}"
        done
    fi
    echo
done

# ---------------------------------------------------------------------------
# The coverage ratchet.
# ---------------------------------------------------------------------------
echo "== coverage ratchet =="

# Fold in the gates covered by dedicated sibling suites.
_elsewhere=()
if [ -f "${ELSEWHERE_FILE}" ]; then
    while read -r _n; do
        [ -n "${_n}" ] && _elsewhere+=("${_n}") && COVERED+=("${_n}")
    done < <(grep -oE '^\| *gate-[0-9]+' "${ELSEWHERE_FILE}" 2>/dev/null | grep -oE '[0-9]+' || true)
    echo "   counted ${#_elsewhere[@]} gate(s) as covered by a sibling suite (COVERED-ELSEWHERE.md)"
else
    _bad "no COVERED-ELSEWHERE.md at ${ELSEWHERE_FILE} — gates fixtured by the sibling suites would be miscounted as untested"
fi

mapfile -t COVERED_U < <(printf '%s\n' "${COVERED[@]}" | sort -n -u)

if [ ! -f "${UNCOVERED_FILE}" ]; then
    _bad "no UNCOVERED.md at ${UNCOVERED_FILE} — without it, an uncovered gate is indistinguishable from a covered one"
else
    _uncovered_declared=()
    while read -r _line; do
        _uncovered_declared+=("${_line}")
    done < <(grep -oE '^\| *gate-[0-9]+' "${UNCOVERED_FILE}" 2>/dev/null | grep -oE '[0-9]+' || true)

    _is_covered() { local n="$1" c; for c in "${COVERED_U[@]}"; do [ "$c" = "$n" ] && return 0; done; return 1; }
    _is_listed()  { local n="$1" c; for c in "${_uncovered_declared[@]}"; do [ "$c" = "$n" ] && return 0; done; return 1; }

    _rot=0
    # (a) a gate listed as uncovered that now HAS a fixture -> un-list it.
    for _n in "${_uncovered_declared[@]}"; do
        if _is_covered "${_n}"; then
            _bad "gate-${_n} is listed in UNCOVERED.md but now HAS planted/clean coverage. Delete its row so CI enforces it from here on — the list may only shrink."
            _rot=$((_rot + 1))
        fi
    done
    # (b) a declared gate with neither a fixture nor a reasoned entry.
    for _entry in "${DECLARED[@]}"; do
        _n="${_entry%% *}"; _nm="${_entry#* }"
        if ! _is_covered "${_n}" && ! _is_listed "${_n}"; then
            _bad "gate-${_n} (${_nm}) is DECLARED by the runner but has neither a planted/clean fixture nor a reasoned row in UNCOVERED.md. A gate can be added to the runner and never tested; this is that moment."
            _rot=$((_rot + 1))
        fi
    done
    [ "${_rot}" -eq 0 ] && _ok "coverage ratchet intact — every declared gate is either fixtured or listed with a reason"
fi

echo
echo "== coverage =="
echo "   declared gates:      ${#DECLARED[@]}"
echo "   with planted/clean:  ${#COVERED_U[@]}"
echo "   covered gate ids:    ${COVERED_U[*]}"

echo
echo "== summary =="
echo "   passed: ${_pass_n}"
echo "   failed: ${_fail_n}"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL gate acceptance controls PASSED (${#COVERED_U[@]} of ${#DECLARED[@]} gates fixtured)"
exit 0
