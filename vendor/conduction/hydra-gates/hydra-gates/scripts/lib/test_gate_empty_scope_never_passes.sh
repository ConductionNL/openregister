#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_empty_scope_never_passes.sh — a gate must never report PASS over a
# scope it did not open.
#
# WHAT THIS GUARDS (.github#242, #240)
# ------------------------------------
# Gates 19 (e2e-coverage), 25 (contract-coverage), 62 (store-plane) and
# 63 (settings-surface) diff-scoped themselves INSIDE their own helpers, below
# the runner's base resolution, and did it UNCONDITIONALLY — the base ref was
# defaulted even when the caller had asked for no scoping at all.
#
# Two consequences, and the second is why it stayed hidden:
#
#   1. A full-tree run was silently narrowed to a diff against
#      origin/development, which on a mainline checkout is empty.
#   2. The verdict for "I inspected nothing" was PASS, not a skip. So
#      --require-full-coverage — the one assertion built to catch gates that did
#      not run — had nothing to catch. gate-63 was the clearest case: its log
#      said "gate skipped" on the line above a verdict that said PASS.
#
# Measured on openconnector 2026-08-08:
#   gate-19   5 findings as the runner invoked it   ->  412 over the full tree
#   gate-25   PASS as the runner invoked it         ->   32 over the full tree
#
# AND WHAT #268 CORRECTED
# -----------------------
# #258 filed the empty-scope case as `structural`, which COUNTS AGAINST
# --require-full-coverage. So any PR that happened to touch no spec and no
# manifest exited 98 for a gate that had nothing to judge — measured as 4 runs
# across 3 repos (doriath x2, larpingapp, softwarecatalog) blocked on nothing.
#
# The category was the bug, not the skip. The runner's own definitions:
#
#   na          subject matter absent from this repo OR THIS DIFF. Nothing in
#               the repository is missing and no change the author could make
#               would put a spec file into a diff that does not touch one.
#   structural  the subject matter EXISTS and nothing produced the gate's
#               input — a gap the repo CAN close (the axe-report case).
#
# An empty ADR-020 diff scope is the first. Gates 4/6/7/28 already called the
# identical situation `na`. What #258 bought survives the reclassification
# because it lives in the RENDERING, not the accounting: the verdict is
# `NOT APPLICABLE`, which is not `PASS`.
#
# FOUR ARMS, and all four are needed:
#
#   ARM 1  a planted TRUE POSITIVE is still caught in full-tree mode.
#          Widening a checker until it catches nothing is not a fix, so this
#          arm runs FIRST and everything else is meaningless without it.
#   ARM 2  an empty scope is VISIBLE and is never PASS (#242/#240), and it does
#          NOT fail --require-full-coverage (#268).
#   ARM 3  a genuinely non-empty scope with nothing wrong still PASSes, so the
#          fix has not simply turned every gate into a permanent skip.
#   ARM 4  ANTI-WIDENING. A genuinely `structural` gap — the same tree, the
#          same flags, plus --axe-enabled and no axe report — must STILL exit
#          98. Without this arm, ARM 2 could be satisfied by neutering
#          --require-full-coverage altogether, and `na` would become the hole
#          that the whole coverage accounting exists to prevent.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_empty_scope_never_passes.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-emptyscope.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

# ---------------------------------------------------------------------------
# A fixture that carries ONE genuine finding for gate-19 and ONE for gate-25:
# a declared scenario with no @e2e tag, and a routed #[PublicPage] method with
# no Newman/PHPUnit contract test and no @contract exclude.
# ---------------------------------------------------------------------------
_app="${_tmp}/app"
mkdir -p "${_app}/src" "${_app}/openspec/specs/thing" "${_app}/appinfo" \
         "${_app}/lib/Controller"
printf '{"name":"fx","menu":[]}\n' > "${_app}/src/manifest.json"
printf '## Purpose\n\n#### Scenario: a thing happens\n- WHEN x\n- THEN y\n' \
    > "${_app}/openspec/specs/thing/spec.md"
printf "<?php\nreturn ['routes'=>[['name'=>'thing#index','url'=>'/api/thing','verb'=>'GET']]];\n" \
    > "${_app}/appinfo/routes.php"
cat > "${_app}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;
class ThingController {
    #[PublicPage]
    public function index() { return 1; }
}
PHP
(
    cd "${_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
    # A docs-only second commit. This is the ordinary shape of a PR that
    # touches no spec, no manifest and no controller — the EMPTY SCOPE case.
    printf 'docs only\n' > README.md
    git add README.md
    git -c user.email=t@t -c user.name=t commit -qm docs
) >/dev/null 2>&1

_run() {  # _run <outfile> [runner args...]
    local out="$1"; shift
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "${_app}" || exit 1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" "$@" . > "${out}" 2>&1
    )
    return $?
}

# NOTE the `( [A-Z]+)*`: the verdict word is not always one token. "NOT
# APPLICABLE" parsed as "NOT" under the original single-token pattern, so every
# arm comparing against a two-word verdict failed on the string rather than on
# the behaviour it meant to test.
_verdict() { grep -oE "^\[gate-$2\] [^:]+: [A-Z]+( [A-Z]+)*( \([a-z]+\))?" "$1" | head -1 | sed 's/^[^:]*: //'; }

# ---------------------------------------------------------------------------
# ARM 1 — the planted true positives are still caught, full-tree.
# ---------------------------------------------------------------------------
_full="${_tmp}/full.txt"
_run "${_full}"
for _g in 19 25; do
    if grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_full}"; then
        _ok "gate-${_g} still catches its planted true positive over the full tree"
    else
        _bad "gate-${_g} did NOT catch its planted true positive — got: $(_verdict "${_full}" "${_g}")"
    fi
done

# Full-tree must actually OPEN the manifests rather than diff-scope itself to
# nothing: 62/63 are clean in this fixture, so they must PASS, not SKIP.
for _g in 62 63; do
    _v="$(_verdict "${_full}" "${_g}")"
    if [ "${_v}" = "PASS" ]; then
        _ok "gate-${_g} audited the manifest over the full tree (PASS, not a self-inflicted skip)"
    else
        _bad "gate-${_g} full-tree verdict is '${_v}' — expected PASS over a clean manifest"
    fi
done

# ---------------------------------------------------------------------------
# ARM 2 — an empty scope is VISIBLE and never PASS (#242/#240), and it does not
#         fail --require-full-coverage (#268).
# ---------------------------------------------------------------------------
_scoped="${_tmp}/scoped.txt"
_run "${_scoped}" --scope-to-diff --base HEAD~1 --require-full-coverage
_scoped_rc=$?

for _g in 19 25 62 63; do
    _v="$(_verdict "${_scoped}" "${_g}")"
    case "${_v}" in
        "NOT APPLICABLE")
            _ok "gate-${_g} reports NOT APPLICABLE over an empty diff scope"
            ;;
        PASS)
            _bad "gate-${_g} reported PASS over a scope it never opened — this is the #242 defect"
            ;;
        "SKIPPED (structural)"|"SKIPPED (wiring)")
            _bad "gate-${_g} reported '${_v}' over an empty diff scope — this is the #268 regression: an empty ADR-020 scope counts against coverage and exits 98"
            ;;
        *)
            _bad "gate-${_g} empty-scope verdict is '${_v}' — expected NOT APPLICABLE"
            ;;
    esac
done

# The exit code is the whole point of #268: this run has no findings and no
# real coverage gap, so --require-full-coverage must let it through.
if [ "${_scoped_rc}" -eq 98 ]; then
    _bad "--require-full-coverage exited 98 over an empty diff scope — the #268 regression: a PR that touches no spec and no manifest is blocked for a gate that had nothing to judge"
elif [ "${_scoped_rc}" -eq 0 ]; then
    _ok "--require-full-coverage let an empty diff scope through (exit 0)"
else
    _bad "empty-scope run exited ${_scoped_rc}, expected 0 — unexpected verdict"
fi

# ...and it must not be counted as a coverage gap in the summary either. The
# exit code alone would still pass if the four were listed as DID NOT RUN while
# some other gate happened to hold the run open.
if grep -q 'GATES THAT DID NOT RUN' "${_scoped}"; then
    _bad "the empty-scope run reported a coverage gap — expected none; DID-NOT-RUN list: $(sed -n '/GATES THAT DID NOT RUN/,$p' "${_scoped}" | grep -oE 'gate-[0-9]+' | tr '\n' ' ')"
else
    _ok "the empty-scope run reports NO coverage gap at all"
fi

# The declaration must carry a REASON naming the diff-scoping rule. A bare
# "NOT APPLICABLE" is how a gate disappears quietly, which is the failure this
# whole accounting exists to stop.
for _g in 19 25 62 63; do
    if grep -qE "^\[gate-${_g}\][^:]*: NOT APPLICABLE — .+ADR-020" "${_scoped}"; then
        _ok "gate-${_g} states WHY it was not applicable, and names ADR-020"
    else
        _bad "gate-${_g}'s NOT APPLICABLE line has no reason naming ADR-020 diff scoping"
    fi
done

# ---------------------------------------------------------------------------
# ARM 4 — ANTI-WIDENING. `na` must not have become a hole.
#
# ARM 2 asserts that --require-full-coverage does NOT fire over an empty diff
# scope. On its own that assertion is satisfiable by breaking
# --require-full-coverage outright, which would re-open .github#169 — the
# accounting hole this whole mechanism was built to close.
#
# So: THE SAME TREE AND THE SAME FLAGS AS ARM 2, plus --axe-enabled and no
# tests/axe/report.json. That is a GENUINELY structural gap — the input was
# expected, the repo could produce it, and it did not arrive — and it must
# still exit 98.
#
# It has to be this tree specifically. `_FAILED` is evaluated BEFORE the
# coverage branch, so any gate with a real finding pre-empts exit 98 and the
# arm would measure nothing. (Measured while writing this: run it after ARM 3's
# manifest commit and gates 22/53 fail on an unresolvable ajv, the run exits 2,
# and the assertion reads as a widening regression that is not there.)
# ---------------------------------------------------------------------------
_axe="${_tmp}/axe.txt"
_run "${_axe}" --scope-to-diff --base HEAD~1 --require-full-coverage --axe-enabled
_axe_rc=$?

if grep -qE "^\[gate-33\][^:]*: SKIPPED \(structural\)" "${_axe}"; then
    _ok "a genuinely structural gap is still categorised structural (gate-33, axe report expected and absent)"
else
    _bad "gate-33 did not report a structural skip with --axe-enabled and no report — got: $(_verdict "${_axe}" 33)"
fi

if [ "${_axe_rc}" -eq 98 ]; then
    _ok "--require-full-coverage STILL fails a genuinely structural gap (exit 98) — \`na\` did not become a hole"
else
    _bad "--require-full-coverage exited ${_axe_rc} over a real structural gap, expected 98 — the #268 fix has widened into .github#169"
fi

# ---------------------------------------------------------------------------
# ARM 3 — a NON-empty scope with nothing wrong still passes.
#
# Without this arm, "make every empty scope a skip" could be satisfied by
# skipping unconditionally, and the suite would look fixed while checking
# nothing. The second commit here touches the manifest, so 62/63 have real work.
# ---------------------------------------------------------------------------
(
    cd "${_app}" || exit 1
    printf '{"name":"fx","menu":[],"version":"1.0.1"}\n' > src/manifest.json
    git add src/manifest.json
    git -c user.email=t@t -c user.name=t commit -qm "chore: bump manifest"
) >/dev/null 2>&1

_touched="${_tmp}/touched.txt"
_run "${_touched}" --scope-to-diff --base HEAD~1
for _g in 62 63; do
    _v="$(_verdict "${_touched}" "${_g}")"
    if [ "${_v}" = "PASS" ]; then
        _ok "gate-${_g} PASSes when the diff genuinely contains a clean manifest"
    else
        _bad "gate-${_g} returned '${_v}' for a real, clean, in-scope manifest — the gate has been skipped into uselessness"
    fi
done

# ---------------------------------------------------------------------------
# ARM 5 — THE INVERSE INVARIANT. A gate must not report "nothing to judge"
#         when its subject matter IS sitting in the diff.
#
# ARM 3 proves a clean in-scope manifest still PASSes. That is necessary but
# not sufficient: a gate that had been neutered to always-`na` would fail ARM 3
# loudly, but a gate that merely stopped ENFORCING would sail through it. So
# plant a REAL ADR-079 violation in the manifest the diff touches — a
# type:settings page claiming the reserved platform name — and require a FAIL.
#
# Together with ARM 2 this pins both directions:
#   subject absent from the diff  -> na, does not fail the run
#   subject present in the diff   -> a real verdict, and violations still FAIL
# ---------------------------------------------------------------------------
(
    cd "${_app}" || exit 1
    printf '{"name":"fx","menu":[],"pages":[{"id":"settings","type":"settings","title":"Settings"}]}\n' \
        > src/manifest.json
    git add src/manifest.json
    git -c user.email=t@t -c user.name=t commit -qm "feat: a settings page claiming the reserved name"
) >/dev/null 2>&1

_violation="${_tmp}/violation.txt"
_run "${_violation}" --scope-to-diff --base HEAD~1 --require-full-coverage
_violation_rc=$?

_v="$(_verdict "${_violation}" 63)"
case "${_v}" in
    FAIL)
        _ok "gate-63 FAILs a real ADR-079 violation sitting in the diff — the subject was judged, not declared away"
        ;;
    "NOT APPLICABLE")
        _bad "gate-63 declared NOT APPLICABLE over a manifest THE DIFF TOUCHED and which carries a real ADR-079 violation — \`na\` is swallowing a present subject"
        ;;
    *)
        _bad "gate-63 returned '${_v}' for a planted ADR-079 violation in an in-scope manifest — expected FAIL"
        ;;
esac

if [ "${_violation_rc}" -ne 0 ] && [ "${_violation_rc}" -ne 98 ]; then
    _ok "the run exits non-zero on the planted violation (exit ${_violation_rc} = finding count, not a coverage verdict)"
else
    _bad "the run exited ${_violation_rc} with a planted ADR-079 violation in scope — a finding must fail the run on its own merits"
fi

# ---------------------------------------------------------------------------
# GATES 12 AND 13: `src/` EXISTS, AND HOLDS NOT ONE .vue (.github#274, #271)
#
# The same defect one layer down from #272. Those four a11y gates declared
# themselves NOT APPLICABLE on a templates-only repo; gates 12 and 13 did the
# opposite on an nldesign-shaped one — `[ -d src ]` passed, `find src -name
# '*.vue'` matched nothing, the findings log was empty, and both printed PASS.
#
# MEASURED at package sha fef032b (origin/main, post-#272) against a repo whose
# `src/` holds a single `manifest.json` and whose `templates/` holds real
# markup:
#
#     [gate-12] nc-input-labels: PASS
#     [gate-13] modal-isolation: PASS
#
# That is nldesign's exact shape — the shape that let twelve gates certify it in
# #225 — and it is not one `rm` away, it is current. `na` is the honest verdict:
# NcSelect / NcModal / NcDialog are Vue SFC components, a PHP template cannot
# instantiate one, so nothing in such a repo is unverified. But PASS says the
# gate looked and found nothing, and it did not look.
# ---------------------------------------------------------------------------
_novue="${_tmp}/novue"
mkdir -p "${_novue}/src" "${_novue}/templates"
printf '{"name":"nl","pages":[]}\n' > "${_novue}/src/manifest.json"
printf '<div><select name="x"></select></div>\n' > "${_novue}/templates/admin.php"
(
    cd "${_novue}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_novue_out="${_tmp}/novue.txt"
_novue_logs="${_tmp}/novue-logs"
mkdir -p "${_novue_logs}"
(
    cd "${_novue}" || exit 1
    HYDRA_GATE_LOG_DIR="${_novue_logs}" bash "${_runner}" . > "${_novue_out}" 2>&1
)
for _g in 12 13; do
    _v="$(_verdict "${_novue_out}" "${_g}")"
    case "${_v}" in
        "NOT APPLICABLE")
            _ok "gate-${_g}: NOT APPLICABLE when src/ holds no .vue — it says it looked at nothing"
            ;;
        PASS)
            _bad "gate-${_g}: PASS over a src/ containing zero .vue files — the nldesign shape, green over nothing (#225/#274)"
            ;;
        *)
            _bad "gate-${_g}: returned '${_v:-none emitted}' on a src/ with no .vue — expected NOT APPLICABLE"
            ;;
    esac
done
# The reason must say WHY it cannot apply, not merely that it does not. The old
# shared reason claimed these gates inspect ".vue/.js/.ts source", which is
# false — they are .vue-only, and that is the judgement #274 asked to be made
# explicit.
if grep -qE '^\[gate-12\][^:]*: NOT APPLICABLE — .*(Vue SFC|no \.vue)' "${_novue_out}"; then
    _ok "gate-12's na reason states the .vue-only judgement rather than a generic 'no frontend'"
else
    _bad "gate-12's na reason does not state why a template repo cannot contain its subject"
fi

# THE ANTI-WIDENING CONTROL. Add ONE .vue carrying the defect and both gates
# must go back to judging — this is not "skip whenever src/ looks thin".
mkdir -p "${_novue}/src/views"
cat > "${_novue}/src/views/Probe.vue" <<'VUE'
<template>
  <div>
    <NcSelect v-model="v" :options="o" :reduce="(option) => option.value" />
    <NcModal v-if="open" @close="open = false"><p>inline</p></NcModal>
  </div>
</template>
VUE
(
    cd "${_novue}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm probe
) >/dev/null 2>&1
_novue_out2="${_tmp}/novue2.txt"
_novue_logs2="${_tmp}/novue-logs2"
mkdir -p "${_novue_logs2}"
(
    cd "${_novue}" || exit 1
    HYDRA_GATE_LOG_DIR="${_novue_logs2}" bash "${_runner}" . > "${_novue_out2}" 2>&1
)
for _g in 12 13; do
    _v="$(_verdict "${_novue_out2}" "${_g}")"
    if [ "${_v}" = "FAIL" ]; then
        _ok "control: gate-${_g} FAILS on the planted defect once ONE .vue exists — the na is about absence, not about skipping"
    else
        _bad "control FAILED: gate-${_g} returned '${_v:-none}' with a planted unnamed NcSelect / inline NcModal in src/views/Probe.vue"
    fi
done

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_empty_scope_never_passes.sh: ALL PASS"
    exit 0
fi
echo "test_gate_empty_scope_never_passes.sh: ${_failures} FAILURE(S)"
exit 1
