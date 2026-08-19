#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_scope_matrix.sh — the same gate, the same tree, three scopes.
#
# WHY THIS EXISTS
# ---------------
# gate-61 passes at one scope and is stone dead at another. Nothing that runs at
# a single scope could ever have caught it, and nothing did: it was dead on
# EVERY `--full` run, fleet-wide, and reported `NOT APPLICABLE` — which does not
# count against coverage — so it never appeared in any red cell anywhere.
#
# Reproduced from scratch in a synthetic repository, through the real
# `bin/hydra-gates`, with the tree held constant and ONLY the scope flag changed:
#
#   checker `--all` (positive control)  1 registration checked, 1 FAILURE, names the file
#   wrapper, diff TOUCHING the listener FAIL — 1 post-event listener(s)
#   wrapper, diff NOT touching it       NOT APPLICABLE   (legitimate, ADR-020)
#   wrapper `--full`                    NOT APPLICABLE — "the diff against
#                                       'origin/development' put every post-event
#                                       registration out of scope"
#
# The `--full` line cites a diff on a run whose own preamble two lines earlier
# says `Base ref: n/a — --full requested, scanning the entire tree.` **There was
# no diff.** `bin/hydra-gates` does not forward `--base` on `--full`, so the
# runner keeps its hardcoded `origin/development` default and gate-61 passes it
# to the checker unconditionally.
#
# THE GENERAL PROPERTY, which outlives this one gate:
#
#   A `NOT APPLICABLE` whose stated reason names a DIFF is invalid on a run that
#   computed no diff.
#
# That is brief item 4, and it is worth more than a gate-61-specific assertion
# because it catches the NEXT gate that does this. Note the discrimination it
# needs: gates 29/47/48 also decline on a `--full` run, and they are RIGHT to —
# their reason says "this run is not diff-scoped, so there is no change set to
# classify". A co-change gate genuinely cannot exist without a change set. The
# defect is not "mentions a diff"; it is "claims a diff EXCLUDED something",
# on a run that had no diff, while the subject matter is sitting in the tree.
#
# KNOWN DEFECTS
# -------------
# `.github#347` (the gate-61 fix) is deliberately HELD — landing it turns a
# permanently-green gate red in ten repos at once. So this suite cannot assert
# the correct behaviour yet. It asserts the DEFECT instead, loudly, and fails
# the moment the behaviour changes, which is the signal to flip the assertion.
# A known defect that is merely commented out is a defect nobody is watching.
#
# Run: bash scripts/lib/test_gate_scope_matrix.sh
set -uo pipefail

GF_PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
export GF_PKG_ROOT
# shellcheck source=./gate_fixture_support.sh
. "${GF_PKG_ROOT}/scripts/lib/gate_fixture_support.sh"

SRC="${GF_PKG_ROOT}/scripts/test-fixtures/scope-matrix/app"
CHECKER="${GF_PKG_ROOT}/scripts/lib/check_listener_placement.py"

_fail_n=0; _pass_n=0; _defect_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

# _known_defect <issue> <description> <condition-result 0=defect-still-present>
# A live defect is reported, not hidden. If the condition no longer holds the
# fix has landed and the assertion must be flipped — that is a hard failure, so
# the entry cannot rot into a permanent excuse.
_known_defect() {
    local _issue="$1" _desc="$2" _still="$3"
    if [ "${_still}" -eq 0 ]; then
        _defect_n=$((_defect_n + 1))
        printf 'KNOWN DEFECT (still live, %s) — %s\n' "${_issue}" "${_desc}"
    else
        _bad "${_issue} appears to be FIXED — '${_desc}' no longer reproduces. Flip this assertion to enforce the correct behaviour and delete the _known_defect entry."
    fi
}

if [ ! -d "${SRC}" ]; then
    echo "FAIL — scope-matrix fixture missing at ${SRC}; every assertion below would be vacuous."
    exit 1
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/hydra-scope.XXXXXXXX")"
trap 'rm -rf "${WORK}"' EXIT

# ===========================================================================
echo "== positive control: the subject IS detectable in this tree =="
# ===========================================================================
# Without this, a NOT APPLICABLE at full scope is ambiguous between "the gate is
# dead" and "the fixture never contained anything". Run FIRST, always.
gf_build_repo "${WORK}/inherited" "${SRC}"
gf_commit_all "${WORK}/inherited" "base: app with inherited listener debt"
gf_mark_base  "${WORK}/inherited"
printf '\n- unrelated doc tweak\n' >> "${WORK}/inherited/docs/CHANGELOG.md"
gf_commit_paths "${WORK}/inherited" "docs: unrelated change" docs/CHANGELOG.md

_pc="$(cd "${WORK}/inherited" && python3 "${CHECKER}" . --all 2>&1)"
if printf '%s' "${_pc}" | grep -qF 'lib/Listener/InheritedDebtListener.php'; then
    _ok "positive control: checker --all NAMES lib/Listener/InheritedDebtListener.php"
else
    echo "FAIL — the positive control did not fire: check_listener_placement.py --all"
    echo "       did not name the planted listener in a tree that contains it. EITHER the"
    echo "       fixture stopped containing the plant OR the checker went blind. Both are"
    echo "       fatal here, because every arm below reads a NOT APPLICABLE as meaningful"
    echo "       only if this control proves the subject was findable. Refusing to grade."
    printf '%s\n' "${_pc}" | sed 's/^/       /'
    exit 1
fi
if printf '%s' "${_pc}" | grep -qE 'checked 1 post-event registration'; then
    _ok "positive control: exactly 1 registration is present to be judged"
else
    _bad "positive control: expected 'checked 1 post-event registration', got: $(printf '%s' "${_pc}" | tail -2 | tr '\n' ' ')"
fi

# ===========================================================================
echo
echo "== arm 1 — DIFF scope, diff does NOT touch the listener =="
# ===========================================================================
# ADR-020: inherited debt must not block an unrelated PR. NOT APPLICABLE here is
# correct, and it is the only arm in which it is correct.
_out="$(gf_run_wrapper "${WORK}/inherited" "${WORK}/log-diff-untouched")"
_v="$(gf_verdict "${_out}" 61)"
case "${_v}" in
    *"NOT APPLICABLE"*) _ok "gate-61 declines on an unrelated diff (ADR-020) — correct" ;;
    "") _bad "gate-61 emitted no verdict at all on the unrelated-diff arm" ;;
    *) _bad "gate-61 on an unrelated diff wanted NOT APPLICABLE, got: ${_v:0:140}" ;;
esac

# ===========================================================================
echo
echo "== arm 2 — DIFF scope, diff DOES touch the listener =="
# ===========================================================================
# Proves the gate can fire through the wrapper at all. If this arm ever goes
# NOT APPLICABLE, the gate is dead at EVERY scope and arm 3 below proves nothing.
gf_build_repo "${WORK}/newdebt" "${SRC}"
rm "${WORK}/newdebt/lib/Listener/InheritedDebtListener.php"
gf_commit_all "${WORK}/newdebt" "base: no listener yet"
gf_mark_base  "${WORK}/newdebt"
cp "${SRC}/lib/Listener/InheritedDebtListener.php" "${WORK}/newdebt/lib/Listener/"
gf_commit_paths "${WORK}/newdebt" "feat: add the listener (NEW debt)" lib/Listener/InheritedDebtListener.php

_out="$(gf_run_wrapper "${WORK}/newdebt" "${WORK}/log-diff-touched")"
_v="$(gf_verdict "${_out}" 61)"
case "${_v}" in
    *FAIL*) _ok "gate-61 FAILS when the diff adds the listener — ${_v:0:90}" ;;
    *) _bad "gate-61 did NOT fail on a diff that ADDS a violating listener; got: ${_v:0:140}" ;;
esac
if grep -qF 'InheritedDebtListener.php' "${WORK}/log-diff-touched/hydra-gate-listener-work-placement.log" 2>/dev/null; then
    _ok "gate-61 NAMES the planted listener in its log"
else
    _bad "gate-61 failed without naming InheritedDebtListener.php — a bare count is not a finding"
fi

# ===========================================================================
echo
echo "== arm 3 — FULL scope, same tree as arm 1 =="
# ===========================================================================
# `--full` exists to report inherited debt. The positive control above proves
# the debt is there and findable. Anything other than a FAIL here is the defect.
_out="$(gf_run_wrapper "${WORK}/inherited" "${WORK}/log-full" --full)"

if printf '%s' "${_out}" | grep -qF 'Base ref: n/a — --full requested'; then
    _ok "the --full run states it computed no diff"
else
    _bad "the --full run did not announce itself as unscoped; the rest of this arm is unsafe to interpret"
fi

#
# `.github#347` HAS LANDED, so this arm now asserts the CORRECT behaviour
# instead of recording the defect. The verdict deliberately stays `na` — the
# invocation comment in the runner records that sweeping the tree on an
# unscoped run was tried and reverted, because the builder runs unscoped and
# `--all` would surface the whole backlog as blocking findings on every build.
# What changed is that the REASON no longer names a diff the run never
# computed, and that the size of what went unread is stated.
_v="$(gf_verdict "${_out}" 61)"
case "${_v}" in
    *"NOT APPLICABLE"*)
        _ok "gate-61 on --full declines rather than sweeping — the verdict that was deliberately preserved"
        ;;
    *FAIL*)
        _bad ".github#347 was fixed by SWEEPING THE WHOLE TREE on an unscoped run. That was tried and reverted before: the BUILDER runs unscoped, so this surfaces the fleet's whole registration backlog as blocking findings on every build. Expected NOT APPLICABLE with an honest reason. Got: ${_v:0:160}"
        ;;
    *)  _bad "gate-61 on --full gave an unrecognised verdict: ${_v:0:160}" ;;
esac
if printf '%s' "${_v}" | grep -qF 'out of scope'; then
    _bad ".github#347 is LIVE: gate-61 on --full still claims 'the diff ... put every post-event registration out of scope', on a run whose own preamble says it computed no diff, over a tree whose single registration the positive control just failed. 0 of 1 inspected, and the reason cites a diff that does not exist."
else
    _ok "gate-61's --full reason no longer claims a diff excluded anything"
fi
if printf '%s' "${_v}" | grep -qF 'computed NO diff'; then
    _ok "gate-61's --full reason names the ABSENCE of a diff — the falsifiable form"
else
    _bad "gate-61's --full reason does not state that the run computed no diff, so a reader still cannot tell an empty scope from an empty tree: ${_v:0:200}"
fi
# The size of what went unread must be stated, or the skip is unfalsifiable in
# the other direction: '0 of 1' and '0 of 45' print identically without it.
if printf '%s' "${_v}" | grep -qE 'ADVISORY.*[0-9]+ registration\(s\) carrying [0-9]+ finding\(s\)'; then
    _ok "gate-61's --full skip states the size of the backlog it did not inspect"
else
    _bad "gate-61's --full skip does not state how many registrations went unread — the advisory sweep is missing, so '0 of 1' and '0 of 45' are typographically identical: ${_v:0:200}"
fi

# ===========================================================================
echo
echo "== the general property: no NOT APPLICABLE may blame a diff on a --full run =="
# ===========================================================================
# Generic, gate-agnostic. Catches the next gate that does this.
#
# Legitimate on --full (asserted, so a regression to the bad wording is caught):
#   "this run is NOT diff-scoped, so there is no change set to classify"
# Illegitimate on --full:
#   "N file(s) in this diff" / "put ... out of scope"  -> a diff DID the excluding
mapfile -t _blamers < <(
    printf '%s\n' "${_out}" \
        | grep -E '^\[gate-[0-9]+\].*NOT APPLICABLE' \
        | grep -E 'in this diff|out of scope|the diff against' \
        | grep -oE '^\[gate-[0-9]+\]' | grep -oE '[0-9]+' | sort -n -u
)
echo "   gates blaming a diff on a --full run: ${_blamers[*]:-none}"

# 6 and 7 are a WORDING defect, not a verdict defect: this fixture ships no
# lib/Controller at all, so their NOT APPLICABLE is substantively right and only
# their stated reason is wrong. Recorded separately so the two are never conflated.
_EXPECTED_WORDING_ONLY=(6 7 8 9)
_unexpected=()
for _g in "${_blamers[@]:-}"; do
    [ -z "${_g}" ] && continue
    _known=0
    for _w in "${_EXPECTED_WORDING_ONLY[@]}" 61; do [ "${_g}" = "${_w}" ] && _known=1; done
    [ "${_known}" -eq 0 ] && _unexpected+=("${_g}")
done
if [ "${#_unexpected[@]}" -eq 0 ]; then
    _ok "no NEW gate blames a diff for an exclusion on a --full run"
else
    _bad "gate(s) ${_unexpected[*]} report NOT APPLICABLE citing a diff on a --full run, and are not a recorded known defect. Either the gate is scope-dead (the gate-61 shape) or its reason is wrong. Investigate before adding it to the list."
fi

_wording_live=1
for _g in "${_blamers[@]:-}"; do [ "${_g}" = "6" ] && _wording_live=0; done
_known_defect "wording (unfiled)" \
    "gates 6/7/8/9 decline on --full with the reason '0 ... file(s) in this diff'. The verdict is right (this tree has no lib/Controller) but the reason names a diff the run never computed — the same sentence pattern that made gate-61's lie unreadable for weeks." \
    "${_wording_live}"

# And the legitimate wording must STAY legitimate.
_v29="$(gf_verdict "${_out}" 29)"
if printf '%s' "${_v29}" | grep -qF 'intrinsically diff-relative'; then
    _ok "gate-29 declines on --full with an honest reason ('intrinsically diff-relative') — the good pattern"
else
    _bad "gate-29's --full reason changed; it used to state honestly that the run is not diff-scoped. Got: ${_v29:0:140}"
fi

# ===========================================================================
echo
echo "== coverage accounting: NOT APPLICABLE must carry a reason =="
# ===========================================================================
# Brief item 4. A bare `NOT APPLICABLE` is unfalsifiable — the whole gate-61
# story is that a reason nobody could check stood for weeks.
_bare=0
while IFS= read -r _line; do
    # everything after "NOT APPLICABLE" should be an em-dash + prose
    if ! printf '%s' "${_line}" | grep -qE 'NOT APPLICABLE (—|-) .{20,}'; then
        echo "       bare: ${_line:0:120}"
        _bare=$((_bare + 1))
    fi
done < <(printf '%s\n' "${_out}" | grep -E '^\[gate-[0-9]+\].*NOT APPLICABLE')
if [ "${_bare}" -eq 0 ]; then
    _ok "every NOT APPLICABLE on the --full run carries a stated reason"
else
    _bad "${_bare} NOT APPLICABLE verdict(s) carry no reason — an unfalsifiable skip"
fi

echo
echo "== summary =="
echo "   passed:        ${_pass_n}"
echo "   failed:        ${_fail_n}"
echo "   known defects: ${_defect_n} (live, each named above)"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL scope-matrix controls PASSED (${_defect_n} known defect(s) still live)"
exit 0
