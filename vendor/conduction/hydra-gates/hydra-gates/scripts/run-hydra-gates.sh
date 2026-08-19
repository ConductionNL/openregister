#!/bin/bash
# SPDX-License-Identifier: EUPL-1.2
#
# ---------------------------------------------------------------------------
# ShellCheck: scoped, deliberate suppressions for this file only.
#
# This runner spent its whole life in ConductionNL/hydra, which has NO GitHub
# Actions — so it had never been ShellChecked until it moved here. Landing it
# in a repository that does run ShellCheck surfaced 22 findings at note and
# warning level. None are errors, and none change what a gate decides:
#
#   SC2001  `sed` where `${var//a/b}` would do — 11 sites, all inside gate
#           logic. Rewriting them is a behaviour change to the gates for a
#           style point, in code with no unit tests of its own.
#   SC2221/SC2222  overlapping `case` globs (e.g. `tests/*` before `*.spec.js`,
#           where `tests/x.spec.js` legitimately matches the first). Both arms
#           set the same variable, so the overlap is intended and inert.
#   SC2162  `read` without -r, SC1003, SC2016, SC2086, SC2295 — long-standing
#           idioms in the file's own parsing helpers.
#
# Suppressed HERE rather than in a repo-level .shellcheckrc on purpose: a
# root-level disable would have switched these checks off for every script in
# this repository, including ones written after this. This directive covers
# exactly one file. Anything newly added to it still gets checked, and clearing
# these findings is worth doing on its own, with the gate output diffed before
# and after.
# ---------------------------------------------------------------------------
# shellcheck disable=SC1003,SC2001,SC2016,SC2086,SC2162,SC2221,SC2222,SC2295
#
# run-hydra-gates.sh — single source of truth for all 61 Hydra mechanical
# quality gates. Exit 0 on all-green; non-zero on any FAIL.
#
# Invoked from:
#   - images/builder/entrypoint.sh       (Rule 0b iteration — mechanical enforcement)
#   - images/reviewer + security         (mandatory first step — via the skill wrapper)
#   - .claude/skills/hydra-gates/SKILL.md (documents + describes invocation)
#   - humans, locally                     (./scripts/run-hydra-gates.sh [options] [app-dir])
#
# Runs against the CURRENT WORKING DIRECTORY unless a path is given. Designed
# for apps following the standard Conduction NC app layout: lib/ + appinfo/
# + optional src/ + tests/.
#
# Options:
#   --scope-to-diff [BASE]   — Phase G: only scan files changed vs BASE
#                              (default origin/development). Inherited debt
#                              in unchanged files is ignored. Required for
#                              reviewer/security post-flight enforcement;
#                              optional for builder (build mode runs full).
#   --base BRANCH            — override the diff base (default origin/development)
#
# When the base resolves to the SAME COMMIT as HEAD — which is what every push
# to a mainline branch looks like — the scope is taken from the push's own
# previous tip (github.event.before) instead. $HYDRA_GATE_PUSH_BEFORE overrides
# that, and is how the invariant suite drives it without a runner. See
# scripts/lib/resolve-push-base.sh.
#
# Output shape (stdout):
#   [gate-N] <gate-name>: PASS | FAIL[<reasons>]
# Gates that FAIL write details to <log-dir>/hydra-gate-<name>.log for debugging,
# where <log-dir> is a PRIVATE per-invocation directory printed on the first
# line of output (see HYDRA_GATE_LOG_DIR below);
# a short summary is emitted on stdout so the wrapper can relay it to the
# builder's focused fix pass.
#
# Exit code is the number of failing gates. Zero when all green.

set -u

# ---------------------------------------------------------------------------
# ERREXIT IS OFF FOR THIS ENTIRE SCRIPT, AND NOTHING MAY TURN IT ON.
#
# A gate returning non-zero is NORMAL — it is how a gate reports findings. So
# this runner deliberately runs under `set -u` only.
#
# Twenty-seven blocks used to wrap a helper call in `set +e … set -e`, reading
# the trailing `set -e` as "restore". It is not a restore: it is an
# unconditional ENABLE, because errexit was never on. The first sits in gate-19,
# so gates 20-64 — forty-five gates — executed under an errexit the code around
# them does not expect. Two comments further down (gate-27, gate-53) even
# document the leak and work around it locally instead of fixing it.
#
# The consequence is not theoretical. Measured 2026-08-08 against a fixture with
# a deliberately crashing `python3`: gate-39's unguarded
# `python3 - "$vue" <<'PYBN'` returned 127, errexit was live, and the run DIED
# THERE — 37 of 64 gates emitted a verdict and 27 NEVER EXECUTED. The abort
# banner does fire, so the run is not silently green; it is simply a whole-suite
# outage caused by one checker having a bad day.
#
# The invariant is therefore: a block MAY disable errexit and MUST NOT enable
# it. Restore sites say `set +e`, which is the state this script actually runs
# in. `scripts/lib/test_gate_errexit_discipline.sh` asserts that no bare
# `set -e` re-enters this file, and `_pass`/`_fail`/`_skip` re-assert `set +e`
# as a backstop so a future gate that leaks cannot carry the leak past its own
# verdict line.
# ---------------------------------------------------------------------------

# Resolve this script's own directory ONCE, as an absolute path, BEFORE any
# `cd` into the app dir below. Gates that shell out to co-located Python
# helpers (scripts/lib/*.py) must use ${SCRIPT_DIR} — resolving via
# `dirname "${BASH_SOURCE[0]}"` AFTER the `cd "${APP_DIR}"` breaks whenever the
# script was invoked by a relative path (e.g. `bash scripts/run-hydra-gates.sh
# /some/app`), because the relative script path no longer resolves from inside
# the app dir.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
# Absolute path to THIS file. The summary reads its own gate inventory back out
# of it (see the coverage block at the bottom) rather than hardcoding a count —
# a hardcoded "63" goes stale the first time someone adds gate 64, and a
# coverage assertion measured against a stale inventory is the very defect the
# assertion exists to catch.
RUNNER_SELF="${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]:-$0}")"

# ---------------------------------------------------------------------------
# ONE PRIVATE LOG DIRECTORY PER INVOCATION.
#
# Every gate below writes its findings to a file and then derives its verdict
# from `wc -l` on that file. Until now ~61 of those paths were hardcoded
# `${HYDRA_GATE_LOG_DIR}/hydra-gate-<name>.log`, shared by every concurrent run on the host.
# Exactly one gate — gate-6 — used `mktemp`, and its comment said why.
#
# The consequence is not noise, it is NON-DETERMINISM IN BOTH DIRECTIONS.
# Two runs of the same commit on the same host, minutes apart, with other
# runs active:
#
#     run A:  gate-7  FAIL 18   gate-28 FAIL  6   gate-39 PASS     gate-45 FAIL 30
#     run B:  gate-7  FAIL 25   gate-28 FAIL 32   gate-39 FAIL 7   gate-45 FAIL 29
#
# gate-39 flipped PASS<->FAIL. A log another process truncated between the
# write and the count reports a number describing neither run, and a
# truncated-to-empty log reports PASS — a falsely-green gate, manufactured
# by the runner's own plumbing. Measured five times across fleet sweeps: a
# repo was credited with another repo's findings, and vice versa.
#
# TMPDIR is honoured so a caller can place the logs somewhere it can collect
# them; the directory is PRINTED once so the paths in the summary stay
# greppable, and is deliberately NOT deleted on exit — the whole point of a
# findings log is that you read it after the run.
# ---------------------------------------------------------------------------
HYDRA_GATE_LOG_DIR="${HYDRA_GATE_LOG_DIR:-}"
if [ -z "${HYDRA_GATE_LOG_DIR}" ]; then
    HYDRA_GATE_LOG_DIR="$(mktemp -d "${TMPDIR:-/tmp}/hydra-gates.XXXXXXXX" 2>/dev/null || true)"
fi
if [ -z "${HYDRA_GATE_LOG_DIR}" ] || [ ! -d "${HYDRA_GATE_LOG_DIR}" ]; then
    # Refuse rather than silently fall back to the shared path. A run whose
    # logs land somewhere another run can truncate is a run whose verdicts
    # cannot be trusted, and "cannot be trusted" must not look like "green".
    echo "[hydra-gates] ERROR: could not create a private log directory under ${TMPDIR:-/tmp}." >&2
    echo "[hydra-gates]        Refusing to run: shared log paths make gate verdicts non-deterministic." >&2
    exit 97
fi
mkdir -p "${HYDRA_GATE_LOG_DIR}" 2>/dev/null || true
echo "[hydra-gates] findings logs: ${HYDRA_GATE_LOG_DIR}"

# ---------------------------------------------------------------------------
# WHICH PACKAGE PRODUCED THIS VERDICT (.github#268)
#
# The fleet consumes this package at `@main`, UNPINNED. So two runs minutes
# apart can be two different programs, and nothing in the output said so.
#
# That is not hypothetical. On 2026-08-08 doriath's `development` tip was gated
# green at 14:21:47Z; a classification change landed on main at 14:21:55Z —
# EIGHT SECONDS later — and the next PR run was red. The diff between "green"
# and "red" was the gates, not the code, and there was no way to see that from
# either log.
#
# It matters most for the STRICT-SUBSET merge rule ("the PR's failing gate
# names are a subset of the base branch's, so the PR introduced nothing new").
# That rule compares a live measurement against a stored one. If the two were
# produced by different packages it is comparing two different programs, and
# the conclusion does not follow.
#
# This line does not FIX that — it makes it CHECKABLE. A comparison can now
# state which package produced each side, and refuse to conclude when they
# differ. The policy question (pin consumers? re-run the baseline with the
# PR's package? record the package in the baseline artifact?) is deliberately
# left open in #268; emitting the identity is the prerequisite for any of them.
#
# Resolution order, most to least trustworthy. The last resort prints UNKNOWN
# rather than nothing: a silent omission is indistinguishable from an older
# package that never had this line, and that ambiguity is the bug.
# ---------------------------------------------------------------------------
_pkg_sha="${HYDRA_GATES_PKG_SHA:-}"
_pkg_origin="caller (HYDRA_GATES_PKG_SHA)"
if [ -z "${_pkg_sha}" ]; then
    # The package's own checkout — set when consumed as a git clone or submodule.
    # `git -C` on the SCRIPT dir, never the app under test: resolving this
    # against the repo being gated would report the APP's sha as the gates' sha,
    # which is worse than reporting nothing.
    _pkg_sha="$(git -C "${SCRIPT_DIR}" rev-parse HEAD 2>/dev/null || true)"
    _pkg_origin="git checkout at ${SCRIPT_DIR}"
fi
if [ -z "${_pkg_sha}" ] && [ -f "${SCRIPT_DIR}/../VERSION" ]; then
    _pkg_sha="$(tr -d '[:space:]' < "${SCRIPT_DIR}/../VERSION" 2>/dev/null || true)"
    _pkg_origin="VERSION file"
fi
if [ -n "${_pkg_sha}" ]; then
    echo "[hydra-gates] gate package: ${_pkg_sha} (${_pkg_origin})"
else
    echo "[hydra-gates] gate package: UNKNOWN — this run cannot say which version of the gates produced its verdicts."
    echo "[hydra-gates] Comparing it against another run (e.g. a strict-subset baseline check) compares two possibly-different programs."
    echo "[hydra-gates] Set HYDRA_GATES_PKG_SHA to make the comparison sound."
fi

SCOPE_TO_DIFF=0
BASE_REF="origin/development"
APP_DIR=""
# Treat "a declared gate did not run" as a failure (exit 98). Off by default so
# a Tier-0 app is not blocked by gates it has no surface for; on, the run
# refuses to report green while any gate's subject matter is unverified.
REQUIRE_FULL_COVERAGE="${HYDRA_GATE_REQUIRE_FULL_COVERAGE:-0}"
# Whether the CALLER undertook to produce gate-33's input (tests/axe/report.json).
# gate-33 is the one gate whose input this runner cannot make for itself: it is
# produced by the Playwright job, and only when `enable-axe: true` is set on the
# shared quality workflow. That makes the ABSENCE of the report ambiguous, and
# the two readings are opposites:
#
#   enable-axe NOT set  — the repo has not opted into runtime accessibility
#                         enforcement. The absence is a declared, visible choice
#                         living in the caller's workflow file, not a gap hidden
#                         in here. NOT APPLICABLE to this run.
#   enable-axe set      — the repo DID opt in and the report still did not
#                         arrive. Something between the browser and this script
#                         broke. That is a real coverage gap and it must fail.
#
# Without this flag the runner cannot tell those apart and has to guess. It used
# to guess "unverified" in both cases, which is why --require-full-coverage was
# unusable in every repo in the fleet.
AXE_ENABLED="${HYDRA_GATE_AXE_ENABLED:-0}"
while [ $# -gt 0 ]; do
    case "$1" in
        --scope-to-diff) SCOPE_TO_DIFF=1; shift ;;
        --require-full-coverage) REQUIRE_FULL_COVERAGE=1; shift ;;
        --axe-enabled) AXE_ENABLED=1; shift ;;
        --base) BASE_REF="$2"; shift 2 ;;
        --base=*) BASE_REF="${1#--base=}"; shift ;;
        *) APP_DIR="$1"; shift ;;
    esac
done
APP_DIR="${APP_DIR:-$(pwd)}"
cd "${APP_DIR}" 2>/dev/null || { echo "[hydra-gates] ERROR: ${APP_DIR} not accessible" >&2; exit 99; }

# When scope-to-diff is requested, derive the changed-files set once.
# Non-diff branches that need the set: each gate below filters its
# file-iteration based on this variable. Empty set = no scoped files =
# nothing to scan = all gates pass (a scoped run only makes sense when
# the caller knows what the PR changed; an empty diff = no PR work =
# nothing to enforce).
CHANGED_FILES=""
if [ "${SCOPE_TO_DIFF}" = "1" ]; then
    # FAIL CLOSED WHEN THE BASE REF DOES NOT EXIST (hydra#399).
    #
    # The diff below used to swallow a bad base with `2>/dev/null || … || true`,
    # leaving CHANGED_FILES empty. Every gate then iterated an empty set and the
    # suite reported "0 failing gates" — indistinguishable from a suite that had
    # actually inspected the PR. Found on design-system#38: that repo's mainline
    # is `main`, the default base `origin/development` does not exist there, and
    # the pre-flight therefore passed having checked nothing. Re-scoped by hand
    # to origin/main it was 29 files and 57 real gates.
    #
    # A base that cannot be resolved is a configuration error, not an empty PR.
    # Refusing is the only safe reading: an unverifiable scope must never be
    # reported as a clean one.
    if ! git -c safe.directory='*' rev-parse --verify --quiet "${BASE_REF}^{commit}" > /dev/null 2>&1; then
        # Try the remote's own default branch before giving up — most repos that
        # trip this simply call their mainline something else.
        _auto_base=$(git -c safe.directory='*' symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null || true)
        if [ -n "${_auto_base}" ] \
            && git -c safe.directory='*' rev-parse --verify --quiet "${_auto_base}^{commit}" > /dev/null 2>&1; then
            echo "[hydra-gates] Base '${BASE_REF}' does not exist — using the remote default '${_auto_base}' instead."
            BASE_REF="${_auto_base}"
        else
            echo "[hydra-gates] ERROR: diff base '${BASE_REF}' does not resolve in this repository." >&2
            echo "[hydra-gates] Scoping to an unresolvable base yields an EMPTY changed-file set," >&2
            echo "[hydra-gates] which would let every gate pass without inspecting anything." >&2
            echo "[hydra-gates] Set --base <ref> or HYDRA_GATE_BASE_REF (e.g. origin/main)." >&2
            exit 99
        fi
    fi

    # A RESOLVING BASE IS NOT A USABLE BASE (third vacuous-pass shape).
    #
    # The check above proves `${BASE_REF}` names a commit. It does NOT prove
    # the two histories share one. On a SHALLOW checkout — `actions/checkout`
    # with the default `fetch-depth: 1`, which is most of the fleet —
    # `origin/development` exists as a ref while its history is truncated, so
    # `git diff ${BASE_REF}...HEAD` fails outright. The old `|| … || true`
    # chain swallowed that failure and left CHANGED_FILES empty, and an empty
    # scope makes EVERY gate iterate nothing and report PASS.
    #
    # Measured on shillinq: a `development` run finished in 22 SECONDS with
    # all 63 gates green. Unscoped, the same commit fails 18. Twenty-two
    # seconds is itself the tell — but nothing in the output said so, and a
    # green run is not read twice.
    #
    # So: require a merge base, and read the diff's exit status DIRECTLY
    # rather than through a `||` chain that cannot tell "no changes" from
    # "the command could not run".
    # THE BASE IS THE SAME COMMIT AS HEAD.
    #
    # This is the shape that actually produced shillinq's 22-second all-green
    # run, and it is not a shallow-history failure: on a push to
    # `development`, `origin/development` and `HEAD` ARE the same commit. The
    # diff is then legitimately empty, git succeeds, and every gate iterates
    # nothing and reports PASS. Verified: shillinq `development`
    # c64e9fe — `git rev-parse HEAD` and `origin/development` identical,
    # `git diff origin/development...HEAD` = 0 files, 52 gates PASS. Run
    # UNSCOPED the same commit fails 18.
    #
    # So EVERY diff-scoped run on a mainline branch is vacuous by
    # construction.
    #
    # THE FIX IS NOT TO REFUSE, AND IT IS NOT TO PASS.
    #
    # Refusing (what this block did when it was written) is correct about the
    # evidence and useless in practice: it fires on every push to development
    # and every push to main, in every repo, forever. A permanently-red gate
    # and a permanently-green gate say the same thing about the code — nothing
    # — and both get filtered out by the people reading them, which is the
    # failure this whole programme exists to end.
    #
    # The base is not MISSING on a push. It is just not the branch's own name.
    # GitHub supplies the pusher's previous tip as `github.event.before`, and
    # the honest scope for a push is `before...HEAD` — what this push actually
    # changed. For a squash-merge that is exactly the squashed commit's diff;
    # for a merge commit it is everything the merge brought in.
    #
    # So try that first, and refuse only when it genuinely cannot be resolved
    # (branch created by this push, force-pushed tip now unreachable, unrelated
    # history). Each of those is named out loud by the helper. Exit 99 remains
    # the answer for "I cannot scope" — it just stops being the answer for
    # "this is a mainline push".
    _base_sha=$(git -c safe.directory='*' rev-parse --verify --quiet "${BASE_REF}^{commit}" 2>/dev/null || true)
    _head_sha=$(git -c safe.directory='*' rev-parse --verify --quiet 'HEAD^{commit}' 2>/dev/null || true)
    if [ -n "${_base_sha}" ] && [ "${_base_sha}" = "${_head_sha}" ]; then
        echo "[hydra-gates] Diff base '${BASE_REF}' IS HEAD (${_head_sha}) — the shape of a push to a mainline branch."
        echo "[hydra-gates] Re-scoping to the push's own previous tip rather than to HEAD."
        if [ -r "${SCRIPT_DIR}/lib/resolve-push-base.sh" ]; then
            # shellcheck source=lib/resolve-push-base.sh
            # shellcheck disable=SC1091  # resolved at runtime from ${SCRIPT_DIR}
            . "${SCRIPT_DIR}/lib/resolve-push-base.sh"
            if _push_base=$(hydra_resolve_push_base "$(pwd)" "${_head_sha}"); then
                BASE_REF="${_push_base}"
                echo "[hydra-gates] Base ref: ${BASE_REF} (github.event.before, push)"
            else
                _push_base=""
            fi
        else
            echo "[hydra-gates] ERROR: scripts/lib/resolve-push-base.sh is missing from this package." >&2
            _push_base=""
        fi
        if [ -z "${_push_base:-}" ]; then
            # AUDIT EVERYTHING RATHER THAN NOTHING (#183).
            #
            # This used to `exit 99`. The reasoning was sound about the evidence
            # — a scoped run against itself inspects nothing — and wrong about
            # the remedy. Refusing fires on every push where the previous tip is
            # unreachable: a branch created by this push, a force-push, a
            # freshly-cloned mirror. The run then reports NO gate at all (exit
            # 99, zero `[gate-N]` lines), which is the same amount of
            # information a green over an empty diff carries: none.
            #
            # The file already says this two screens up — "a permanently-red
            # gate and a permanently-green gate say the same thing about the
            # code, and both get filtered out by the people reading them".
            #
            # There IS a correct scope for "I cannot tell what this push
            # changed": everything. Diffing against the EMPTY TREE yields every
            # tracked file, which is exactly the full-tree audit mode
            # (`--scope-to-diff --base <root>`) that fleet sweeps already use —
            # and it needs no root commit, so it is unambiguous in a repository
            # with several roots or a grafted history.
            #
            # A mainline push now gates the whole tree. That is slower and
            # louder than a diff, and it is the honest answer: nobody told us
            # what changed, so nothing is assumed unchanged.
            _empty_tree=$(git -c safe.directory='*' hash-object -t tree /dev/null 2>/dev/null)
            if [ -n "${_empty_tree}" ] \
                && git -c safe.directory='*' cat-file -e "${_empty_tree}" 2>/dev/null; then
                echo "[hydra-gates] The push's previous tip could not be resolved (reason above)."
                echo "[hydra-gates] FALLING BACK TO A FULL-TREE AUDIT: every tracked file is in scope."
                echo "[hydra-gates] This is deliberate. A scoped run against itself would inspect"
                echo "[hydra-gates] nothing, and refusing outright would gate nothing either — both"
                echo "[hydra-gates] say the same thing about the code. Auditing everything says"
                echo "[hydra-gates] something. Set HYDRA_GATE_PUSH_BEFORE to scope a push narrowly."
                BASE_REF="${_empty_tree}"
            else
                echo "[hydra-gates] ERROR: diff base '${BASE_REF}' IS HEAD (${_head_sha}), the push's" >&2
                echo "[hydra-gates] previous tip could not be used, and the empty tree could not be" >&2
                echo "[hydra-gates] resolved either — so there is no scope left to fall back to." >&2
                echo "[hydra-gates] A scoped run against itself inspects nothing, and every gate" >&2
                echo "[hydra-gates] would report PASS over an empty file set. Refusing." >&2
                echo "[hydra-gates] Pass a --base that is actually behind HEAD, or set" >&2
                echo "[hydra-gates] HYDRA_GATE_PUSH_BEFORE to the commit this push started from." >&2
                exit 99
            fi
        fi
    fi

    # The empty tree deliberately shares no history with HEAD — that is what
    # makes it mean "everything is in scope". The shallow-checkout guard below
    # would reject it for exactly the property we selected it for, so skip it.
    if [ "${BASE_REF}" = "${_empty_tree:-}" ]; then
        :
    elif ! git -c safe.directory='*' merge-base "${BASE_REF}" HEAD > /dev/null 2>&1; then
        echo "[hydra-gates] ERROR: '${BASE_REF}' resolves, but shares NO history with HEAD." >&2
        echo "[hydra-gates] This is what a SHALLOW checkout looks like (fetch-depth: 1)." >&2
        echo "[hydra-gates] The diff would be empty and every gate would pass having read" >&2
        echo "[hydra-gates] nothing. Refusing. Fix: fetch-depth: 0, or a deeper fetch of" >&2
        echo "[hydra-gates] '${BASE_REF}', or run unscoped." >&2
        exit 99
    fi

    # `&& _diff_rc=0 || _diff_rc=$?` and NOT `set -e` / `set +e`.
    #
    # This script runs under `set -u` only — errexit is deliberately OFF,
    # because a gate returning non-zero is normal. An earlier draft of this
    # block used `set +e … set -e`, which does not restore the previous
    # state, it turns errexit ON for the remaining 3,700 lines. The run then
    # ABORTED right after this block, and the abort banner said only
    # "GATE COVERAGE IS INCOMPLETE" — caught by
    # scripts/lib/test_gate_route_auth.sh, which is why that suite exists.
    CHANGED_FILES=$(git -c safe.directory='*' diff --name-only \
        --diff-filter=ACMR "${BASE_REF}...HEAD" 2>/dev/null) && _diff_rc=0 || _diff_rc=$?
    if [ "${_diff_rc}" -ne 0 ]; then
        # Fall back to the two-dot form, still reading the code directly.
        CHANGED_FILES=$(git -c safe.directory='*' diff --name-only \
            --diff-filter=ACMR "${BASE_REF}" 2>/dev/null) && _diff_rc=0 || _diff_rc=$?
    fi
    if [ "${_diff_rc}" -ne 0 ]; then
        echo "[hydra-gates] ERROR: computing the diff against '${BASE_REF}' FAILED (git exit ${_diff_rc})." >&2
        echo "[hydra-gates] An unobtainable scope is not an empty one. Refusing rather than" >&2
        echo "[hydra-gates] reporting a green run over zero inspected files." >&2
        exit 99
    fi
    _cf_count=$(printf '%s' "${CHANGED_FILES}" | grep -c . 2>/dev/null || true)
    _cf_count="${_cf_count:-0}"
    # THE MACHINE-READABLE SCOPE SIZE. One line, one number, emitted exactly
    # once, and it is the ONLY thing downstream may derive "was the scope
    # empty?" from.
    #
    # `bin/hydra-gates` used to answer that question with
    # `grep -q "0 changed file(s)"` over this output — a SUBSTRING match, so
    # `10 changed file(s)` contains it, and so does 20, 30, 100. A run whose
    # header said `10 changed file(s)` and whose every gate ran therefore also
    # printed the "SCOPE WAS EMPTY" epilogue, and that epilogue was being used
    # fleet-wide as the tell for a vacuous run. The report contradicted its own
    # header and the contradiction pointed the wrong way: it manufactured
    # doubt about runs that were fine.
    #
    # Deriving both statements from this one integer is what makes the
    # contradiction impossible, rather than merely unlikely.
    echo "[hydra-gates] SCOPE-FILE-COUNT: ${_cf_count}"
    if [ "${_cf_count}" = "0" ]; then
        # Genuinely zero changed files is a legitimate outcome, but it must be
        # stated as such rather than looking like a scoped run that found nothing.
        echo "[hydra-gates] Scope: diff vs ${BASE_REF} — 0 changed file(s). Base resolves, histories join, git succeeded; this PR changes nothing."
    else
        echo "[hydra-gates] Scope: diff vs ${BASE_REF} — ${_cf_count} changed file(s)"
    fi
else
    echo "[hydra-gates] Scope: full repo"
fi

# Helper — return 0 if $1 (a file path) is in scope (i.e. either we're
# running full-repo OR the file appears in CHANGED_FILES). Used inside
# every gate's file loop to filter out untouched files when
# --scope-to-diff is active.
_in_scope() {
    [ "${SCOPE_TO_DIFF}" = "0" ] && return 0
    [ -z "${CHANGED_FILES}" ] && return 1
    echo "${CHANGED_FILES}" | grep -qxF "$1"
}

# Filter a newline-separated list of file paths (one per line) on stdin,
# writing to stdout only those in scope. No-op if SCOPE_TO_DIFF=0.
_filter_files_by_scope() {
    if [ "${SCOPE_TO_DIFF}" = "0" ]; then cat; return; fi
    while IFS= read -r _f; do
        [ -z "${_f}" ] && continue
        _in_scope "${_f}" && echo "${_f}"
    done
}

# Filter a newline-separated list of "file:line:..." (grep -n format) on
# stdin, writing to stdout only those whose file part is in scope.
_filter_grep_by_scope() {
    if [ "${SCOPE_TO_DIFF}" = "0" ]; then cat; return; fi
    while IFS= read -r _line; do
        [ -z "${_line}" ] && continue
        _f="${_line%%:*}"
        _in_scope "${_f}" && echo "${_line}"
    done
}

# ---------------------------------------------------------------------------
# _enum_tracked <basename-regex> <dir> [<dir>...]
#
# Enumerate the files a gate is meant to judge, RECURSIVELY and completely.
#
# Why this exists: several gates enumerated their surface with a NON-RECURSIVE
# shell glob (`for f in lib/Service/*.php`). That silently skipped every file
# in a sub-namespace — so the DEEPER a class sat, the LESS likely it was
# checked, exactly backwards for security-critical code. Measured on
# openregister, gate-8 (unsafe-auth-resolver) read 227 of 607 Service+Controller
# files (37%) and therefore MISSED a live CWE-863 fail-open in
# lib/Service/Object/PermissionHandler.php. The detection logic was correct; the
# gate simply never opened the file.
#
# Why `git ls-files` and not `find`:
#   - `find` walks untracked and ignored trees. A nested `custom_apps/` inside a
#     working dir MASKS the repo's own files and pulls in a vendored copy of a
#     DIFFERENT app; `vendor/`, `node_modules/`, `dist/` do the same at smaller
#     scale. Measured: `find lib -name '*.php'` on openregister returns 1242
#     paths vs 1218 tracked — 24 phantom files that gates then judge.
#   - Tracked-ness is the correct definition of "code this repo ships".
#
# Falls back to `find` (with explicit prunes) only when the app dir is not a git
# work tree, so container/tarball invocations still scan something.
#
# NB: this is about WHICH FILES EXIST for a gate to consider. It does not
# bypass ADR-020 diff-scoping — every caller still filters through `_in_scope` /
# `_filter_files_by_scope`, which decides which of those files to JUDGE.
# ---------------------------------------------------------------------------
_enum_tracked() {
    local _re="$1"; shift
    [ "$#" -gt 0 ] || return 0
    local _out=""
    if git -c safe.directory='*' rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        _out=$(git -c safe.directory='*' ls-files -z -- "$@" 2>/dev/null | tr '\0' '\n' || true)
    else
        local _d _hit
        for _d in "$@"; do
            [ -d "${_d}" ] || continue
            _hit=$(find "${_d}" \
                \( -path '*/vendor/*' -o -path '*/node_modules/*' \
                   -o -path '*/dist/*' -o -path '*/build/*' \
                   -o -path '*/custom_apps/*' \) -prune -o \
                -type f -print 2>/dev/null || true)
            [ -n "${_hit}" ] && _out="${_out}${_out:+
}${_hit}"
        done
    fi
    [ -n "${_out}" ] || return 0
    printf '%s\n' "${_out}" \
        | grep -E "${_re}" 2>/dev/null \
        | grep -vE '(^|/)(vendor|node_modules|dist|build|custom_apps)/' \
        | sort -u || true
}

# ---------------------------------------------------------------------------
# _count <pattern> <file>  — how many lines of <file> match <pattern> (ERE).
#
# `grep -c` ALREADY prints the count on no-match ("0") and THEN exits 1, so the
# idiom `$(grep -c … || echo 0)` captures BOTH: the variable becomes the
# two-line string "0\n0". `[ "0\n0" -eq 0 ]` is not an integer comparison — it
# errors to stderr and returns 2, so the "clamp to at least 1" guard that
# follows never fires, and the failure message is emitted with an embedded
# newline. That is how gate-22 came to print
#
#     [gate-22] manifest-validation: FAIL — 0
#     1 schema violation(s) in src/manifest.json — see ${HYDRA_GATE_LOG_DIR}/…
#
# on opencatalogi: a real finding sat in the log, the count line read "0", and
# the rest of the message was orphaned onto a second line that no `^\[gate-`
# consumer parses. Diagnosed once before at gate-17 and fixed there only; eight
# other call sites still carried it. One helper, used everywhere.
# ---------------------------------------------------------------------------
_count() {
    local _n
    _n=$(grep -cE "$1" "$2" 2>/dev/null) || true
    _n="${_n%%$'\n'*}"
    case "${_n}" in ''|*[!0-9]*) _n=0 ;; esac
    printf '%s' "${_n}"
}

# _helper_finished <log> <terminal-marker-regex> — did the checker reach its
# own summary line?
#
# WHY THIS EXISTS (.github#330) — THE MIRROR IMAGE OF THE gate-20 DEFECT
# ---------------------------------------------------------------------
# Gates 59-64 use a NUMERIC EXIT PROTOCOL from their helper: 0 pass, 1 findings,
# 3 empty scope, 4 not applicable, 5 tooling missing. The runner switched on
# those codes and sent everything unrecognised to a `_fail` — so a CRASH, which
# also exits 1, arrived at the findings branch. And because the crash wrote a
# traceback rather than `FAIL` lines, `_count '^FAIL'` came back 0 and the next
# line read `[ "${_n}" -eq 0 ] && _n=1`: the runner INVENTED a finding count of
# one for a check that had measured nothing.
#
# MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
#
#   working python3                 python3 that exits 1
#   [gate-59] unclosable-gate: PASS   [gate-59] ... FAIL — config gate(s) read but never written
#   [gate-62] store-plane: NOT APPL.  [gate-62] ... FAIL — 1 naming or discovery violation(s)
#   [gate-63] settings-surface: NOT A [gate-63] ... FAIL — 1 naming or discovery violation(s)
#
# Two of those repos do not even HAVE the subject matter the gate blocked them
# on. This is .github#233/#245's "an environment failure rendered as findings
# about the source" — the same disease as gate-20's silent green, wearing red
# instead. A blind gate and a lying gate are both gates you cannot read.
#
# THE EXIT BYTE CANNOT SEPARATE THEM, because 1 means both "I found things" and
# "I died". What CAN separate them is that every one of these helpers prints a
# TERMINAL SUMMARY LINE — `checked N manifest(s): M failure(s)`,
# `N unclosable gate(s).`, `apphost-autoload-prelude: OK` — on every path that
# reaches a verdict, and a process that died before its own summary never
# printed it. That is the same evidence gates 15/16/17 take from their
# `# count=` marker (.github#271), read from the summary these helpers already
# emit rather than from a marker they would have to grow.
#
# The failure direction is deliberate: no marker ⇒ `_skip ... wiring`, which
# still fails a --require-full-coverage run. This converts a fabricated finding
# into an honest "I could not look", never into a pass.
_helper_finished() {
    grep -qE "$2" "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# ADR-040 AppHost adoption — shared between gate-5 (route-auth) and gate-14
# (route-reachability).
#
# An app that calls `\OCA\OpenRegister\AppHost\Bootstrap::register()` from
# lib/AppInfo/Application.php does NOT ship the dashboard / preferences /
# settings / health / metrics controllers. Bootstrap registers the OpenRegister
# *generic* controllers under the leaf app's conventional class names
# (`OCA\<Leaf>\Controller\HealthController`, ...) via
# `IRegistrationContext::registerService()`, so `appinfo/routes.php` names a
# class that resolves at runtime but has NO FILE in this repository, by design.
#
# Before 2026-08-05 both gates read that absence as a finding:
#   gate-5  reported it as "routed method missing auth attribute" — a SECURITY
#           verdict on a file the gate simply could not open. On scholiq that
#           was 4 findings on every PR (ConductionNL/.github#153). A security
#           gate that cries wolf on correct code is worse than no gate: it
#           trains readers to skip the whole tier.
#   gate-14 deferred to gate-5 and skipped it entirely.
#
# The generic controllers DO carry their auth attributes — in the openregister
# package, which this run has no access to. "I cannot see it" is not "it is
# absent"; only the first of those is true, and only the first may be reported.
#
# NB: Bootstrap uses `aliasControllerUnlessLeafDefinesIt` — a leaf that ships
# its own e.g. SettingsController.php keeps it, and the file therefore exists
# and is judged normally. This helper only ever fires when the file is ABSENT.
# ---------------------------------------------------------------------------
# WHERE THE CALL LIVES IS NOT PART OF THE INVARIANT (ConductionNL/.github#237)
# ---------------------------------------------------------------------------
# This used to grep lib/AppInfo/Application.php and nothing else. That is not
# where the call has to be, and the fleet's own quality gates push it out of
# there: phpmd complains about a long `register()`, the app decomposes it into
# per-concern registrars, and the AppHost call moves one file down. Procest did
# exactly that (procest#717) and its `Bootstrap::register()` now lives in
# lib/AppInfo/Registrar/AppHostRegistrar.php — so _HYDRA_APPHOST read 0 and all
# four of its AppHost routes came back as `controller-class-not-found`. The
# decomposition the gates asked for is what blinded this one.
#
# So: the evidence is "some file this repository ships calls
# `\OCA\OpenRegister\AppHost\Bootstrap::register()`", and it is looked for
# across the whole tracked lib/ tree. BOTH conditions must hold in the SAME
# file — `AppHost\Bootstrap` (the symbol) and `Bootstrap::register(` (the call).
# Split across two files they prove nothing: a comment naming AppHost in one
# place and an unrelated `Bootstrap::register()` in another would combine into
# a blanket exemption.
#
# The site is remembered so the NOT-JUDGED lines can name it. "This gate
# believes you adopt AppHost" is a claim, and a claim with no evidence attached
# is how #199 came to look fixed while still firing.
#
# ⚠️ COMMENTS DO NOT COUNT, and this is not a hypothetical. The first cut of
# this widening was two raw `grep`s, and the very first fixture written to
# disprove it — `delegated-registrar-absent/`, whose Application.php docblock
# reads "NOTHING in this app calls \OCA\OpenRegister\AppHost\Bootstrap::register()"
# — was EXEMPTED BY ITS OWN EXPLANATION. Prose about a call is not a call. The
# old narrow version only ever read one file and was much less likely to meet
# a sentence like that; widening the search to lib/ makes it near-certain,
# because the file that explains the architecture is exactly the file that
# names it. Same defect as gate-64 (.github#184): a checker that greps a
# string matches every comment.
_php_code_only() {
    # Whole-line comments removed. `#[` at line start is a PHP ATTRIBUTE, not
    # a comment, and must survive.
    grep -vE '^[[:space:]]*(//|/\*|\*|#([^[]|$))' "$1" 2>/dev/null
}
_HYDRA_APPHOST=0
_HYDRA_APPHOST_SITE=""
if [ -d lib ]; then
    while IFS= read -r _ah_f; do
        [ -f "${_ah_f}" ] || continue
        _ah_code=$(_php_code_only "${_ah_f}")
        printf '%s\n' "${_ah_code}" | grep -qE 'Bootstrap::register[[:space:]]*\(' || continue
        printf '%s\n' "${_ah_code}" | grep -qE 'AppHost\\+Bootstrap' || continue
        _HYDRA_APPHOST=1
        _HYDRA_APPHOST_SITE="${_ah_f}"
        break
    done < <(_enum_tracked '\.php$' lib \
        | tr '\n' '\0' \
        | xargs -0 -r grep -lE 'Bootstrap::register[[:space:]]*\(' 2>/dev/null \
        || true)
fi
# The five controller class names Bootstrap::register() aliases, as route
# slugs. Source of truth: openregister lib/AppHost/Bootstrap.php
# ::registerControllers(). Deliberately an explicit list, not a wildcard —
# a wildcard would let ANY missing controller hide behind AppHost adoption.
_HYDRA_APPHOST_SLUGS="dashboard preferences settings health metrics"

# The app's own top namespace, read from its own file:
# `namespace OCA\<App>\AppInfo;`. Empty when there is no Application.php, which
# every consumer below treats as "cannot decide" -> no exemption.
_HYDRA_APP_NS=""
if [ -f lib/AppInfo/Application.php ]; then
    _HYDRA_APP_NS=$(grep -m1 -oE '^namespace[[:space:]]+OCA\\[A-Za-z0-9_]+' lib/AppInfo/Application.php \
        | awk '{print $2}')
fi

# ---------------------------------------------------------------------------
# THE CANONICAL APPHOST ROUTE TABLE (ConductionNL/.github#223)
#
# `appinfo/routes.php` is not the only place a route can be declared. An app
# that adopts ADR-040 returns
#
#     \OCA\OpenRegister\AppHost\Routes::standard($extra)
#
# and that builder PREPENDS ten canonical route entries to `$extra` — the
# dashboard page, the SPA catch-all, the settings quartet, the two preference
# routes and the two observability routes. Their names never appear as literals
# in the leaf's routes.php.
#
# Gate-14 invariant 1 asks "is there a route for this controller method?" with
# `grep -qF "'slug#method'" appinfo/routes.php`. For an AppHost adopter that
# question is being asked of the wrong file, and the answer is always no. Every
# app that ships its OWN DashboardController / SettingsController — which is
# the supported shape, `aliasControllerUnlessLeafDefinesIt` exists precisely to
# allow it — was told its working `/` and `/api/settings` endpoints were
# unroutable: 7/7 findings on launchpad, 5 on doriath, 5 on shillinq, and the
# same on openconnector and procest.
#
# The list is explicit and mirrors openregister lib/AppHost/Routes.php
# ::canonicalRoutes() + ::catchAllRoute() (verified against
# openregister@1dcc92cf9, lines 110-126 + 165-176). NOT a wildcard and NOT
# "AppHost adopters are exempt from invariant 1": a controller method with no
# route is still a 404 and still fails, unless its route is one of these ten.
# ---------------------------------------------------------------------------
_HYDRA_APPHOST_ROUTE_NAMES="dashboard#page dashboard#catchAll settings#index settings#create settings#update settings#load preferences#getPreference preferences#setPreference metrics#index health#index"

_HYDRA_APPHOST_ROUTE_TABLE=0
# A COMMENT NAMING THE BUILDER IS NOT A CALL TO IT.
#
# This grepped appinfo/routes.php as raw text, so a comment mentioning
# `Routes::standard()` switched the table on and injected all ten canonical
# names into gate-14's route list. Measured on larpingapp, whose routes.php is
# a plain literal array carrying one accurate comment —
#
#     // Canonical AppHost settings write (OpenRegister\AppHost\Routes::standard()).
#
# — and which was told four routes it does not declare were unreachable
# (dashboard#catchAll, health#index, metrics#index, settings#load), naming two
# controllers it does not ship. Rewording only that comment, with the code
# byte-identical, took gate-14 from `FAIL — 4` to `PASS`; the cheapest way to
# clear it was therefore to delete accurate documentation, which is the
# prose-satisfaction #191/#184 exist to stop.
#
# `_HYDRA_APPHOST`, the sibling detector for the same subject, has always
# stripped comments via `_php_code_only`. One repository, one question, two
# answers — which is visible in the findings themselves: `_apphost_serves`
# correctly declined to exempt health/metrics because `_HYDRA_APPHOST` was 0,
# which is exactly why the phantom routes surfaced instead of being waved
# through. scholiq carries the same comment-only shape.
if [ -f appinfo/routes.php ] \
    && _php_code_only appinfo/routes.php \
       | grep -qE 'AppHost\\+Routes::standard[[:space:]]*\(' ; then
    _HYDRA_APPHOST_ROUTE_TABLE=1
fi

# _apphost_supplies_route <controller#method> — 0 when this app's routes.php
# defers to Routes::standard() AND the name is one of the ten it supplies.
_apphost_supplies_route() {
    [ "${_HYDRA_APPHOST_ROUTE_TABLE}" -eq 1 ] || return 1
    case " ${_HYDRA_APPHOST_ROUTE_NAMES} " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

# _apphost_serves <route-slug> — 0 when this app adopts AppHost AND the slug is
# one of the generics AppHost provides, i.e. the missing file is expected.
_apphost_serves() {
    [ "${_HYDRA_APPHOST}" -eq 1 ] || return 1
    case " ${_HYDRA_APPHOST_SLUGS} " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

# ---------------------------------------------------------------------------
# _app_php_binds_class <fully-qualified-class-name> — 0 when
# lib/AppInfo/Application.php registers a service under EXACTLY that name.
#
# The SINGLE place the needle is built and matched. Both binding helpers below
# derive a class name in their own way and then hand it here, so a change to
# the matching rule can never apply to one shape and silently not the other.
# ---------------------------------------------------------------------------
# THE REGISTRATION SURFACE IS lib/AppInfo/, NOT Application.php
# (ConductionNL/.github#237). Same argument as `_HYDRA_APPHOST` above: nothing
# requires a `registerService()` call to sit in Application.php, and this
# suite's own phpmd gate pushes it out — an app splits `register()` into
# per-concern registrars and every binding moves one file down. Reading only
# Application.php then answers "no such binding" for every controller, which
# is indistinguishable from a correct verdict.
_app_php_binds_class() {
    local _fqcn="$1"
    [ -n "${_fqcn}" ] || return 1

    local _reg_files
    _reg_files=$(_enum_tracked '\.php$' lib/AppInfo)
    [ -n "${_reg_files}" ] || return 1

    # In PHP source the literal carries escaped backslashes.
    local _needle="${_fqcn//\\/\\\\}"

    # Through the environment, NOT `awk -v`: -v runs escape processing over the
    # value, so the `\\` pairs this needle is made of arrive as single
    # backslashes and never match the PHP literal. Silently — the helper just
    # answers "no such binding" for every controller, which reads exactly like
    # a correct verdict.
    # Comment lines removed first, for the reason spelled out at
    # `_HYDRA_APPHOST` above: a docblock explaining which class a route
    # resolves to is not a binding, and the file that explains the
    # architecture is exactly the file that spells the class name out.
    local _f
    while IFS= read -r _f; do
        [ -f "${_f}" ] || continue
        if _php_code_only "${_f}" | _HYDRA_DI_NEEDLE="${_needle}" awk '
            BEGIN { needle = ENVIRON["_HYDRA_DI_NEEDLE"] }
            /registerService(Alias)?[[:space:]]*\(/ { window = 6 }
            window > 0 {
                if (index($0, needle) > 0) { found = 1; exit }
                window--
            }
            END { exit(found ? 0 : 1) }
        '; then
            return 0
        fi
    done <<EOF
${_reg_files}
EOF
    return 1
}

# ---------------------------------------------------------------------------
# _di_binds_controller <controller-file-path> — 0 when lib/AppInfo/Application.php
# registers a service under EXACTLY the class name that path resolves to.
#
# `Bootstrap::register()` is not the only way an AppHost generic reaches a
# route. A leaf that needs the generic constructed with its OWN collaborators
# registers it itself, under the standard controller class name, because that
# is the name NC's `App::main` synthesises from a plain route slug:
#
#   openconnector lib/AppInfo/Application.php
#     $context->registerService(
#         'OCA\\OpenConnector\\Controller\\GenericPreferencesController',
#         static fn ($c) => new GenericPreferencesController(appName: 'openconnector', ...)
#     );
#
# The class resolves at request time and has no file here — the same
# legitimate absence `_apphost_serves` already covers, arrived at a different
# way. The slug list could not see it: it is keyed on the five slugs
# Bootstrap aliases (`preferences`), and this route is named
# `genericPreferences`, so gate-14 called a working endpoint unreachable.
#
# Deliberately NOT a wildcard, and deliberately not "the app adopts AppHost,
# so absences are fine". The evidence required is the exact fully-qualified
# class name appearing as a literal within a few lines of a registerService
# call — i.e. this repository can be shown to bind that specific name. A
# controller that is simply missing still fails, which is the invariant.
#
# TWO WIDENINGS, both narrow (ConductionNL/.github#213, #237)
# ----------------------------------------------------------
# (1) THE REGISTRATION FILE. Same argument as `_HYDRA_APPHOST` above: a
#     `registerService()` call is not required to sit in Application.php, and
#     the phpmd pressure that decomposes Application.php into per-concern
#     registrars moves it out. Every tracked .php under lib/AppInfo/ is read,
#     not just Application.php.
#
# (2) THE UNPREFIXED DI KEY. NC's RouteParser::buildControllerName() appends
#     'Controller' to the route-name segment VERBATIM and does NOT prefix the
#     app namespace when the route name already contains a backslash. So
#
#         ['name' => 'AppHost\Controller\GenericHealth#index', ...]
#
#     is looked up in the container under the bare string
#     'AppHost\Controller\GenericHealthController' — no OCA\<App>\Controller\
#     prefix at all. openregister binds exactly that key (lib/AppInfo/
#     Application.php::registerAppHostObservability, and its own comment
#     records the 503 that taught it), and this helper — which only ever built
#     the prefixed name — answered "no such binding" for both, so gate-14
#     reported OR's own /api/health and /api/metrics as
#     `controller-class-not-found`.
#
#     The bare key is accepted ONLY for a namespaced route name (one whose
#     resolved class part contains a backslash), because that is the only case
#     in which NC produces it. For a plain slug like `widget`, accepting the
#     bare `WidgetController` as evidence would match any stray
#     `WidgetController::class` near a registerService call and turn a genuine
#     missing controller into an exemption.
# ---------------------------------------------------------------------------
_di_binds_controller() {
    local _p="$1"

    # lib/Controller/Sub/FooController.php -> Sub\FooController
    local _rel="${_p#lib/Controller/}"
    _rel="${_rel%.php}"
    local _cls="${_rel//\//\\}"

    [ -n "${_HYDRA_APP_NS}" ] || return 1
    _app_php_binds_class "${_HYDRA_APP_NS}\\Controller\\${_cls}" && return 0

    # ---------------------------------------------------------------------
    # THE RELATIVE KEY (ConductionNL/.github#213). #217 fixed the FULLY
    # QUALIFIED shape (`OCA\<App>\AppHost\Controller\GenericHealth`) and named
    # this one as still-open rather than widening silently. It is the same
    # mechanism one step over: NC's RouteParser appends 'Controller' to the
    # route-name segment VERBATIM, so a route named
    #
    #     ['name' => 'AppHost\Controller\GenericHealth#index', …]
    #
    # is looked up under the bare string 'AppHost\Controller\GenericHealthController'
    # — NOT prefixed with OCA\<App>\Controller\, because the name already
    # contains a backslash. openregister binds exactly that key, and records
    # the 503 that taught it, in
    # lib/AppInfo/Application.php::registerAppHostObservability().
    #
    # Accepted ONLY for a namespaced name, because that is the only case in
    # which NC produces the unprefixed key. For a plain slug the bare needle
    # would be `WidgetController`, which any stray `WidgetController::class`
    # near a registerService call would satisfy — turning a genuinely missing
    # controller into an exemption.
    case "${_cls}" in
        *\\*) _app_php_binds_class "${_cls}" && return 0 ;;
    esac
    return 1
}

# ---------------------------------------------------------------------------
# _di_binds_fq_controller <route-controller-name> — 0 when the route names a
# class that is ALREADY fully qualified under this app's own top namespace AND
# lib/AppInfo/Application.php binds exactly that name + `Controller`.
#
# THIRD SHAPE, measured 2026-08-08 on opencatalogi origin/development
# (9e63d9f3): gate-14 reported 6 findings, all of them wrong.
#
#   appinfo/routes.php
#     ['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#page', …]
#   lib/AppInfo/Application.php
#     $context->registerService(
#         'OCA\\OpenCatalogi\\AppHost\\Controller\\GenericDashboardController',
#         static fn ($c) => new GenericDashboardController(appName: self::APP_ID, …)
#     );
#
# This resolves at runtime, and it is the ONLY thing that can. NC's
# \OC\AppFramework\App::main() does `$container->get($controllerName)` FIRST,
# with the literal name RouteParser::buildControllerName() produced — that
# builder only does `underScoreToCamelCase(ucfirst($controller)) . 'Controller'`,
# so the backslashes survive untouched. And the `catch (QueryException)`
# fallback to `OCA\<App>\Controller\<name>` is not even reachable for this
# shape: its first statement is
#     if (str_contains($controllerName, '\\Controller\\')) { throw new
#         HintException('App ' . strtolower($app) . ' is not enabled'); }
# so a fully-qualified name that is not bound 500s rather than falling back.
# (Read from /var/www/html/lib/private/AppFramework/App.php in a live NC 33.)
#
# Neither existing helper could see it, and each declined for its own correct
# reason — which is why this needed a third, and not a loosening of either:
#   * _apphost_serves     is keyed on the five short slugs Bootstrap aliases
#                         (`dashboard`). The route name is the whole class.
#   * _di_binds_controller takes a FILE PATH and rebuilds
#                         `<app_ns>\Controller\<…>` from it. Fed this route it
#                         reconstructed
#                         `OCA\OpenCatalogi\Controller\OCAOpenCatalogiAppHostControllerGenericDashboardController`,
#                         which matches nothing — the name had already been
#                         flattened (see the `read -r` note in gate-5/gate-14).
#
# The narrower alternative — "add the six opencatalogi names to a list" — was
# rejected: it is the `_HYDRA_APPHOST_SLUGS` failure mode a second time, and
# 2026-08-07 already showed that list going stale against openconnector.
# The BLIND alternative — "a route name containing backslashes is unverifiable,
# skip it" — was rejected harder: it would retire the invariant for every
# namespaced route in the fleet. The evidence demanded here is the same
# evidence _di_binds_controller demands, no weaker: this repository must be
# shown to bind that exact literal near a registerService call. An unbound
# fully-qualified route still FAILS, which is the point.
#
# Scoped to the app's OWN namespace on purpose. `OCA\SomeOtherApp\…` is a class
# this repository does not own and cannot vouch for, so it is left to fail.
# ---------------------------------------------------------------------------
_di_binds_fq_controller() {
    local _name="$1"
    [ -n "${_HYDRA_APP_NS}" ] || return 1
    # Must be fully qualified UNDER THIS APP: `OCA\<App>\` + at least one more
    # segment. The trailing `\` in the prefix is what stops `OCA\Foo` matching
    # a sibling app whose name merely starts the same way (`OCA\FooBar\…`).
    case "${_name}" in
        "${_HYDRA_APP_NS}"\\*) ;;
        *) return 1 ;;
    esac
    # The router appends `Controller` to whatever it was given; the DI key must
    # therefore be the route name verbatim plus that suffix.
    _app_php_binds_class "${_name}Controller"
}

# ---------------------------------------------------------------------------
# _ctrl_path_from_name <route-slug> — the file a routed `controller#method`
# name resolves to. Handles the four shapes Nextcloud accepts:
#
#   OCA\App\AppHost\Controller\GenericDashboard
#                    -> lib/AppHost/Controller/GenericDashboardController.php
#   Settings\Foo  -> lib/Controller/Settings/FooController.php
#   my_thing      -> lib/Controller/MyThingController.php   (snake_case)
#   credentialVerify -> lib/Controller/CredentialVerifyController.php
#
# The first shape is PSR-4, not the `lib/Controller/` convention: a name that
# is already fully qualified under the app's own top namespace is anchored at
# `OCA\<App>\` -> `lib/`, so appending it to `lib/Controller/` would name a
# file that could never exist. Added 2026-08-08 with the opencatalogi fix; see
# _di_binds_fq_controller above for the measurement. A leaf that genuinely
# SHIPS such a class (rather than binding a foreign one) is therefore opened
# and judged normally by both gates, instead of being unresolvable.
#
# SHARED by gate-5 (route-auth) and gate-14 (route-reachability). It used to be
# defined INSIDE gate-14 only, and gate-5 carried its own narrower copy —
# including its own route-name regex, `'[a-z_]+#…'`. That regex matches only
# all-lowercase and snake_case slugs, so EVERY camelCase route was invisible to
# gate-5 and reported nothing, in either direction. Measured on scholiq
# 2026-08-05: 14 of 37 routed names matched; the other 23 — among them
# `paymentTransaction#callback`, `keyAdmin#generateKey` and
# `credentialVerify#verify` — were never opened. A positive control (stripping
# both the attributes AND the docblock tags from a real routed method) still
# reported PASS, which is how this was caught. Two gates reading route names
# through two different regexes is the defect; one helper is the fix.
# ---------------------------------------------------------------------------
_ctrl_path_from_name() {
    local _name="$1"
    case "${_name}" in
        "${_HYDRA_APP_NS:-__no_app_ns__}"\\*)
            # PSR-4: `OCA\<App>\` maps to `lib/`. Strip the app prefix, turn
            # the remaining namespace separators into directory separators.
            local _sub="${_name#"${_HYDRA_APP_NS}"\\}"
            local _sub_last="${_sub##*\\}"
            local _sub_ns="${_sub%\\*}"
            local _sub_last_cap
            _sub_last_cap="$(printf '%s' "${_sub_last}" | awk '{print toupper(substr($0,1,1)) substr($0,2)}')"
            if [ "${_sub_ns}" = "${_sub}" ]; then
                # `OCA\<App>\Foo` — no intermediate namespace.
                echo "lib/${_sub_last_cap}Controller.php"
            else
                echo "lib/${_sub_ns//\\//}/${_sub_last_cap}Controller.php"
            fi
            ;;
        *\\*)
            local _last="${_name##*\\}"
            local _ns="${_name%\\*}"
            # SC2155: declare and assign separately so awk's exit code isn't
            # masked by `local`.
            local _last_cap
            _last_cap="$(printf '%s' "${_last}" | awk '{print toupper(substr($0,1,1)) substr($0,2)}')"
            # EVERY remaining separator becomes a directory separator, not just
            # the last one. `${_ns}` used to be interpolated raw, so a two-level
            # name produced a path with a literal backslash inside it —
            # `lib/Controller/AppHost\Controller/GenericHealthController.php` —
            # which cannot exist on disk and so always resolved to "missing".
            # Invisible until 2026-08-08 because plain `read` had been deleting
            # the backslashes before this branch could ever see two of them.
            #
            #
            # ⚠️ GATE-14 MEASURED THE SAME DEFECT FROM THE OTHER SIDE (#271).
            # Independently of the gate-5 finding above, gate-14 invariant 2
            # reported
            #
            #   lib/Controller/AppHost/Controller/GenericHealthController.php
            #     route='AppHost\Controller\GenericHealth#index'
            #     rule=controller-class-not-found
            #
            # against this package's own gates-23-33/planted fixture — a path
            # that cannot exist, called a missing class, inside the tree that
            # ships the file. And where a DI binding rescued the absence
            # (`_di_binds_fq_controller`) the loop `continue`d, so the
            # METHOD-EXISTENCE check never ran at all: two fixtures differing by
            # one renamed method both reported PASS. That is #265's defect at a
            # different address, and it is why the two-root probe below is
            # asserted by test_gate_route_registration.sh's
            # psr4-namespaced-controller{,-missing-method} pair as well as by
            # gate-5's own suite.
            # ⚠️ TWO ROOTS, BECAUSE A NAMESPACED ROUTE NAME MAY OR MAY NOT SIT
            # UNDER Controller\ (2026-08-08). Nextcloud resolves a route name
            # `A\B\C` against `OCA\<App>\A\B\C`, and PSR-4 maps that to
            # `lib/A/B/CController.php`. The single `lib/Controller/` root is
            # right for `Settings\FileSettings` (which really is
            # `OCA\<App>\Controller\Settings\FileSettings`) and WRONG for
            # `AppHost\Controller\GenericHealth`, whose file is
            # `lib/AppHost/Controller/GenericHealthController.php`.
            #
            # Measured on openregister — the repository that SHIPS those two
            # classes. gate-5 derived a path that does not exist, `_apphost_serves`
            # then matched the name, and both routed methods were filed as
            #
            #   "served by the OpenRegister AppHost generic controller (ADR-040);
            #    its auth attribute ... is NOT visible from this repository"
            #
            # inside openregister. The gate punted to another package while
            # standing in it, so `AppHost\Controller\GenericHealth#index` and
            # `GenericMetrics#index` had their auth posture judged by NOBODY.
            # A derived path is a GUESS; try each root and prefer one that
            # exists. Falls back to the conventional form so an unresolvable
            # name still reports the path a reader would expect.
            local _cand
            for _cand in "lib/Controller/${_ns//\\//}/${_last_cap}Controller.php" \
                         "lib/${_ns//\\//}/${_last_cap}Controller.php"; do
                if [ -f "${_cand}" ]; then
                    echo "${_cand}"
                    return 0
                fi
            done
            echo "lib/Controller/${_ns//\\//}/${_last_cap}Controller.php"
            ;;
        *_*)
            local _camel
            _camel=$(echo "${_name}" | awk -F'_' '{for(i=1;i<=NF;i++) printf toupper(substr($i,1,1)) substr($i,2); print ""}')
            echo "lib/Controller/${_camel}Controller.php"
            ;;
        *)
            local _cap
            _cap=$(printf '%s' "${_name}" | awk '{print toupper(substr($0,1,1)) substr($0,2)}')
            echo "lib/Controller/${_cap}Controller.php"
            ;;
    esac
}

# ---------------------------------------------------------------------------
# _head_block <php-file> <declaration-line> — the annotation region that
# belongs to the method declared on <declaration-line>, as text.
#
# WHY NOT A FIXED 20-LINE SLICE
# -----------------------------
# Gate-5 and gate-30 both asked "is there an auth attribute above this
# method?" by reading `sed -n "$((line-20)),${line}p"`. Twenty lines is an
# arbitrary number and it is wrong in both directions:
#
#   TOO SHORT — openregister lib/Controller/Settings/FileSettingsController.php
#               ::getFileExtractionStats() declares `@NoCSRFRequired` on line
#               301 and opens on line 323, because a `@psalm-return` shape sits
#               between them. The tag is 22 lines up, the window starts at 303,
#               and gate-5 reported a correctly-annotated endpoint as
#               `missing-auth-attribute`. Found 2026-08-08 the moment the
#               backslash fix (#213) made `Settings\FileSettings#…` resolve at
#               all — the route had never been judged before, so the window
#               defect had never been reachable there.
#   TOO LONG  — a short guarded method sitting within 20 lines above an
#               unguarded one donated its `#[NoAdminRequired]` to its
#               neighbour. That is a false NEGATIVE in a security gate, and it
#               is why the `prev_close` clamp below exists.
#
# The region that actually belongs to a declaration is the CONTIGUOUS run of
# attribute lines, PHPDoc lines, `//` comments and blanks immediately above it.
# This walks that run and takes whichever of {the run, the 20-line slice} starts
# EARLIER, so the window can only ever grow — nothing that passes today can
# start failing because of this — and then applies the previous-member clamp
# unchanged, so nothing can borrow an attribute either. Taking the union rather
# than the run alone matters for a multi-line attribute
# (`#[AuthorizedAdminSetting(\n  Application::APP_ID\n)]`), whose middle lines
# are ordinary code and would otherwise cut the run short.
# ---------------------------------------------------------------------------
_head_block() {
    local _file="$1" _def="$2"
    awk -v D="${_def}" '
        NR <= D { line[NR] = $0 }
        NR > D  { exit }
        END {
            # The contiguous annotation run immediately above the declaration.
            run = D
            for (i = D - 1; i >= 1; i--) {
                l = line[i]
                if (l ~ /^[[:space:]]*$/)              { run = i; continue }
                if (l ~ /^[[:space:]]*\/\*\*/)         { run = i; continue }
                if (l ~ /^[[:space:]]*\*/)             { run = i; continue }
                if (l ~ /^[[:space:]]*\/\//)           { run = i; continue }
                if (l ~ /^[[:space:]]*#\[/)            { run = i; continue }
                break
            }
            slice = (D > 20) ? D - 20 : 1
            start = (run < slice) ? run : slice

            # Clamp to the line after the previous member closes. An attribute
            # or docblock for THIS method can only appear after it, so this can
            # never hide a genuine attribute — only stop one being borrowed.
            prev = 0
            for (i = start; i < D; i++) { if (line[i] ~ /^[[:space:]]*\}/) prev = i }
            if (prev >= start) { start = prev + 1 }

            for (i = start; i <= D; i++) print line[i]
        }
    ' "${_file}"
}

# The one regex both route gates read `appinfo/routes.php` through.
_ROUTE_NAME_RX="'name'\s*=>\s*'[A-Za-z][A-Za-z0-9_\\]*#[a-zA-Z0-9_]+'"
_ROUTE_PAIR_RX="[A-Za-z][A-Za-z0-9_\\]*#[a-zA-Z0-9_]+"

_FAILED=0
_EMITTED_GATES=""
# _SKIPPED_GATES holds the gates that skipped for a reason that COUNTS against
# coverage (categories `structural` and `wiring` — see _skip below).
#
# It is still not what the summary subtracts. "Gates that did not run" is
# derived as DECLARED minus _EMITTED_GATES minus _NA_GATES, which stays strictly
# broader than _SKIPPED_GATES: it catches a gate that emitted NOTHING AT ALL
# because its enclosing `if [ -d src ]`-style prerequisite was false and it
# never reached a _skip call. Driving the report off _SKIPPED_GATES instead
# would narrow it back to only the explicit skips and silently reopen the hole
# this accounting exists to close. A silent non-reporter is therefore counted
# against coverage by DEFAULT — to stop counting, a gate must say so, by name
# and with a category.
_SKIPPED_GATES=""
# Gates whose SUBJECT MATTER does not exist in this repository or this diff.
# Held separately from _SKIPPED_GATES because the two mean opposite things to a
# reader deciding whether to trust the run — see _skip below.
_NA_GATES=""
# A reason may arrive with embedded newlines (a helper echoing a multi-line
# message, a miscounted variable). Flatten it: the contract of this runner's
# stdout is ONE `[gate-N] name: VERDICT` line per gate, and every consumer —
# bin/hydra-gates' coverage assertion, the reviewer skill, the builder's Rule 0b
# wrapper — anchors on `^\[gate-`. A verdict that wraps onto a second line is
# a verdict that silently loses its own reason.
_fail() {
    set +e   # backstop — see the errexit invariant at the top of this file
    local _reason
    _reason=$(printf '%s' "${3:-}" | tr '\n' ' ')
    echo "[gate-$1] $2: FAIL${_reason:+ — ${_reason}}"
    _FAILED=$((_FAILED + 1))
    _EMITTED_GATES="${_EMITTED_GATES}$1 "
}
_pass() { set +e; echo "[gate-$1] $2: PASS"; _EMITTED_GATES="${_EMITTED_GATES}$1 "; }

# _optout_text — every place a reason-bearing `[hydra-gate-<name> exclude]` tag
# may legitimately be written, as one stream to grep.
#
# WHY THIS EXISTS
# ---------------
# Each opt-out used to inline two lookups, and on a pull_request BOTH read
# nothing:
#
#   - `${HYDRA_GATE_PR_BODY}` was never exported by the caller workflow, so the
#     first was always an empty string
#   - `git log -1` reads the checked-out commit, and for a pull_request that is
#     GitHub's synthetic MERGE commit (`Merge <sha> into <sha>`) — never a
#     message any author wrote
#
# So every reason-bearing opt-out in this file was unreachable on a PR, which is
# the only place they matter. Measured on hermiq#162: the tag was placed in the
# PR body AND in an explicit head commit, and the finding count did not move
# either time.
#
# Centralised so the three call sites cannot drift apart again, and so a fourth
# gate adding an opt-out inherits the fixed behaviour rather than copying the
# broken pair of lines.
_optout_text() {
    [ -n "${HYDRA_GATE_PR_BODY:-}" ] && printf '%s\n' "${HYDRA_GATE_PR_BODY}"

    # The author's own head commit, when the caller told us which it is. Guarded
    # on rev-parse because a shallow checkout may not contain it — falling back
    # is better than emitting nothing.
    if [ -n "${HYDRA_GATE_HEAD_SHA:-}" ] \
        && git rev-parse --quiet --verify "${HYDRA_GATE_HEAD_SHA}^{commit}" >/dev/null 2>&1; then
        git log -1 --pretty=%B "${HYDRA_GATE_HEAD_SHA}" 2>/dev/null
        return 0
    fi

    git log -1 --pretty=%B 2>/dev/null
}

# _skip <n> <name> <category> <reason> — the gate did NOT run. Distinct from
# PASS on purpose: the gate inspected NOTHING.
#
# The CATEGORY is the point of this helper, and it is mandatory. "Did not run"
# collapses three situations that a reader — and --require-full-coverage — must
# treat differently:
#
#   na          NOT APPLICABLE. The gate's subject matter does not exist here.
#               A Tier-0 app with no src/ has no <img> to be missing an alt; a
#               diff that touches no composer file has no dependency change to
#               audit (ADR-020). Nothing is unverified, because there is nothing
#               to verify. This does NOT count against coverage.
#
#   structural  The subject matter EXISTS and nothing produced the gate's input.
#               An app with src/ that ships no axe report has runtime
#               accessibility defects it has not looked for. A real gap.
#
#   wiring      The gate's own machinery is missing — a helper script absent, a
#               tool not installed. This is the worst of the three because the
#               repository looks fully covered while a check has quietly stopped
#               existing. (Measured 2026-08: a missing helper made gate-7 report
#               PASS over 11 real unguarded IDOR endpoints.)
#
# `structural` and `wiring` both count against coverage and both fail a run
# started with --require-full-coverage. Only `na` does not.
#
# AN EMPTY ADR-020 DIFF SCOPE IS `na`, NEVER `structural` (.github#268)
# ---------------------------------------------------------------------
# The two are easy to confuse and the difference is whether ANYTHING IN THIS
# REPOSITORY COULD HAVE MADE THE GATE RUN.
#
#   structural  something in this repo SHOULD have produced the input and did
#               not, and the repo can be changed so that it does. An app with
#               src/ that ships no axe report can ship one. A composer.json
#               with no `license` field can declare one. The gap is REAL and
#               it is ACTIONABLE HERE — which is exactly why it fails the run.
#
#   na          the input is absent from THIS DIFF. ADR-020 scoping excluded
#               it, which is the entire purpose of ADR-020. Nothing in the
#               repository is missing, nothing is broken, and NO CHANGE THE
#               AUTHOR COULD MAKE would let this gate inspect a file the diff
#               does not contain — short of manufacturing the gate's input,
#               which is the false green this package exists to prevent.
#
# #258 correctly stopped gates 19/25/62/63 printing PASS over an unopened
# scope, but filed the empty-scope case as `structural`. Under
# --require-full-coverage that exited 98 on any PR that happened to touch no
# spec and no manifest: 4 runs across 3 repos blocked on nothing, purely as a
# function of which files the diff contained. Gates 4, 6, 7 and 28 already
# called the identical situation `na` ("0 lib/Controller PHP file(s) in this
# diff"), and the summary header has always read "subject matter absent from
# this repo OR THIS DIFF" — so `na` is both the correct category and the one
# the rest of this file was already using.
#
# What #258 bought is UNCHANGED by that reclassification, because it lives in
# the rendering and not in the accounting: an empty scope prints
# `NOT APPLICABLE`, which is not `PASS`. The invariant to hold when editing
# this file is therefore:
#
#   * an unopened scope must never render as PASS   (#242/#240 — _skip, any category)
#   * an unopened scope must never fail the run     (#268 — category `na`)
#   * a gap the repo COULD close must still fail it (#169 — category structural/wiring)
#
# The category is validated, and an unrecognised one is a HARD FAILURE rather
# than a default. A typo that silently resolved to `na` would be a lever for
# making any gate's absence stop counting — which is precisely the accounting
# hole this whole block exists to close, re-opened from the inside.
# _a11y_markup_files — every file in this repo that ships MARKUP A USER SEES.
#
# WHY THIS EXISTS (.github#225)
# ----------------------------
# The whole accessibility family — gates 31, 32, 34, 35, 36, 37, 39, 40, 42, 43,
# 44, 45 — enumerated `find src -name '*.vue'` and nothing else. An app that
# renders its UI from PHP templates therefore had every one of those gates
# iterate an empty list and report PASS.
#
# Measured 2026-08-08 on nldesign, which ships one `templates/settings/admin.php`
# and a `src/` containing only `manifest.json`: one textbook true positive was
# planted per gate — an `<img>` with no alt, a positive `tabindex`, a focusable
# element inside `aria-hidden="true"`, an icon-only `<button>`, an unlabelled
# `<input>`, a `<table>` with no `<th>`, "click here" link text, and the rest —
# and ALL TWELVE GATES REPORTED PASS. The `[ -d src ]` guard passed, because
# `src/` exists; the glob then matched nothing.
#
# WCAG does not care which templating language produced the DOM. Neither should
# these gates.
#
# SCOPE. Only directories an app renders FROM: src/, templates/, and
# appinfo/templates/. Deliberately NOT the repo root — nldesign carries a
# generated `phpmetrics/*.html` report tree, and auditing build output would
# manufacture findings nobody can act on. node_modules / vendor / dist / build /
# coverage are excluded for the same reason.
_a11y_markup_files() {
    find src templates appinfo/templates -type f \
        \( -name '*.vue' -o -name '*.php' -o -name '*.html' -o -name '*.htm' \) \
        2>/dev/null \
        | grep -vE '(^|/)(node_modules|vendor|dist|build|coverage|phpmetrics|\.git)/' \
        || true
}

# The directories those files can live in — the guard that replaces `[ -d src ]`
# for the a11y family. An app with no src/ but a templates/ still ships markup.
_a11y_has_markup_dir() {
    [ -d src ] || [ -d templates ] || [ -d appinfo/templates ]
}

# _a11y_style_files — every STANDALONE STYLESHEET this repo ships.
#
# WHY THIS EXISTS (.github#287)
# -----------------------------
# Gate-45 (prefers-reduced-motion) has never read a stylesheet. It scans
# `<style>` blocks inside markup and nothing else, so every green it has
# produced is a statement about markup, not about CSS. Motion in a `.css`
# file — which is where a Nextcloud app's app-wide motion actually lives,
# because `css/` is what `Util::addStyle()` loads — was invisible.
#
# Measured 2026-08-09:
#   nldesign     3 stylesheets declaring motion, 0 `prefers-reduced-motion`
#                guards (css/admin.css, css/systems/nldesign/theme.css,
#                css/systems/summer-breeze/element-overrides.css) — gate-45 PASS
#   openregister css/main.css, 7 motion declarations, 0 guards — gate-45 PASS
#                both before and after the file was touched
#
# SCOPE mirrors `_a11y_markup_files` and adds `css/`, the Nextcloud-conventional
# home for an app's stylesheets. Excluded on purpose:
#   - the same generated/third-party trees (node_modules, vendor, dist, build,
#     coverage, phpmetrics)
#   - `*.min.css` — minified third-party output. A minified file is one line, so
#     it would be audited as a single enormous block, and it is not ours to fix.
_a11y_style_files() {
    find css src templates appinfo/templates -type f \
        \( -name '*.css' -o -name '*.scss' -o -name '*.sass' -o -name '*.less' \) \
        2>/dev/null \
        | grep -vE '(^|/)(node_modules|vendor|dist|build|coverage|phpmetrics|\.git)/' \
        | grep -vE '\.min\.(css|scss|sass|less)$' \
        || true
}

# The directories a stylesheet can live in. `css/` is deliberately included even
# though no other a11y gate reads it: an app can ship `css/` with no src/ and no
# templates/ at all, and that stylesheet is still loaded into a real page.
_a11y_has_style_dir() {
    [ -d css ] || _a11y_has_markup_dir
}

_skip() {
    set +e   # backstop — see the errexit invariant at the top of this file
    local _cat _reason
    _cat="${3:-}"
    _reason=$(printf '%s' "${4:-}" | tr '\n' ' ')
    case "${_cat}" in
        na)
            echo "[gate-$1] $2: NOT APPLICABLE — ${_reason}"
            _NA_GATES="${_NA_GATES}$1 "
            ;;
        structural|wiring)
            echo "[gate-$1] $2: SKIPPED (${_cat}) — ${_reason}"
            _SKIPPED_GATES="${_SKIPPED_GATES}$1 "
            ;;
        *)
            echo "[gate-$1] $2: FAIL — internal error: _skip called with reason category '${_cat}', which is not one of na|structural|wiring. Refusing to guess: an unclassified skip would silently stop counting against coverage."
            _FAILED=$((_FAILED + 1))
            _EMITTED_GATES="${_EMITTED_GATES}$1 "
            ;;
    esac
}

# An abort before the summary (set -e / set -u, a helper blowing up, ...) used
# to be indistinguishable from a completed run: the per-gate PASS lines were
# already on stdout, the summary simply never printed, and readers concluded
# "all gates passed". Observed 2026-07-24 — an unguarded ${HYDRA_GATE_PR_BODY}
# under `set -u` killed the run at gate-49, and the remaining gates were
# reported as green because nobody noticed the summary was missing.
# Make that failure mode impossible to misread.
_SUMMARY_REACHED=0
# `_rc` is assigned inside the trap body below, which ShellCheck analyses as its
# own scope. Seed it here so the reference is unambiguous — it used to be
# incidentally satisfied by an unrelated `_rc=$?` in gate-22, which is not
# something the trap should depend on.
_rc=0
trap '_rc=$?; if [ "${_SUMMARY_REACHED}" -eq 0 ]; then
        echo "" >&2
        echo "[hydra-gates] ABORTED before the summary (exit ${_rc}) — GATE COVERAGE IS INCOMPLETE." >&2
        echo "[hydra-gates] The PASS lines above cover only the gates that ran; the rest never executed." >&2
        echo "[hydra-gates] Do NOT treat this run as green." >&2
    fi' EXIT

# Resolve the lib/ dir that ships the gate helpers. Local repo layout is
# scripts/run-hydra-gates.sh + scripts/lib/*.py; container layout flattens
# everything into /usr/local/lib/hydra/. Probe both.
_gate_helper_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd || true)"
if [ ! -f "${_gate_helper_dir}/filter_preexisting_methods.py" ]; then
    _gate_helper_dir="${SCRIPT_DIR}"
fi

# Provenance filter — for method-based gates, distinguish PR-introduced
# findings from pre-existing methods that happen to live in files the PR
# touched. Only runs when --scope-to-diff is active and the helper is
# present. Mutates the log in place; pre-existing entries move to a
# `<log>.preexisting` sibling for the reviewer to surface as informational.
# Argument: the log path(s) to filter. Safe no-op on missing files / missing
# base ref / parse failures.
_filter_preexisting() {
    [ "${SCOPE_TO_DIFF}" = "1" ] || return 0
    [ -f "${_gate_helper_dir}/filter_preexisting_methods.py" ] || return 0
    python3 "${_gate_helper_dir}/filter_preexisting_methods.py" "${BASE_REF}" "$@" 2>&1 \
        | grep -E '^\[filter-preexisting\]' >&2 || true
}

# ---------------------------------------------------------------------------
# Gate 1: SPDX / license headers on every lib/**/*.php
# ---------------------------------------------------------------------------
#
# ⚠️ AN EMPTY SCOPE IS NOT A CLEAN TREE (2026-08-08). This gate reported PASS
# whenever it had nothing to look at — no lib/ at all, or lib/ present but no
# PHP file in the diff. Measured on larpingapp: a README-only commit run with
# --scope-to-diff produced `[gate-1] spdx-headers: PASS` having opened zero
# files. That is the nldesign shape (a glob that matches nothing is
# indistinguishable from a clean result), and it applied to gates 1, 2, 3, 5, 8,
# 9, 10 and 11 simultaneously. `na` is the honest verdict — it keeps the gate
# OUT of _EMITTED_GATES so the coverage summary reports it as not-run instead of
# folding it into ALL GATES GREEN.
if [ ! -d lib ]; then
    _skip 1 "spdx-headers" na "no lib/ directory — this repo ships no PHP under lib/, so there is no file that could carry an @license/@copyright header."
else
    # `grep -r lib/` is recursive but walks UNTRACKED and ignored trees too:
    # on openregister it saw 1242 .php paths vs 1218 tracked, i.e. 24 files the
    # repo does not ship were being judged for SPDX headers. Enumerate the
    # tracked surface instead; the header check itself is unchanged.
    #
    # Scope-filter FIRST, then read: the old order grepped every tracked file in
    # the repo and discarded the out-of-scope answers afterwards.
    _spdx_files=$(_enum_tracked '\.php$' lib | _filter_files_by_scope)
    if [ -z "$(printf '%s' "${_spdx_files}" | grep . || true)" ]; then
        _skip 1 "spdx-headers" na "0 tracked PHP file(s) under lib/ in this diff — nothing was inspected, so missing @license/@copyright headers are UNVERIFIED by this run."
    else
    _missing_license=$(printf '%s\n' "${_spdx_files}" | grep . \
        | xargs -r -d '\n' grep -LE '^[[:space:]]*\*[[:space:]]*@license[[:space:]]' 2>/dev/null)
    _missing_copyright=$(printf '%s\n' "${_spdx_files}" | grep . \
        | xargs -r -d '\n' grep -LE '^[[:space:]]*\*[[:space:]]*@copyright[[:space:]]' 2>/dev/null)
    _ml=$(echo -n "${_missing_license}" | grep -c . || true)
    _mc=$(echo -n "${_missing_copyright}" | grep -c . || true)
    if [ "$((_ml + _mc))" -eq 0 ]; then
        _pass 1 "spdx-headers"
    else
        {
            [ "${_ml}" -gt 0 ] && { echo "Missing @license:"; echo "${_missing_license}" | sed 's/^/  /'; }
            [ "${_mc}" -gt 0 ] && { echo "Missing @copyright:"; echo "${_missing_copyright}" | sed 's/^/  /'; }
        } > ${HYDRA_GATE_LOG_DIR}/hydra-gate-spdx-headers.log
        _fail 1 "spdx-headers" "${_ml} missing @license, ${_mc} missing @copyright"
    fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 2: Forbidden debug helpers in lib/
# ---------------------------------------------------------------------------
#
# ⚠️ SIX RAW-TEXT GREPS, FAILING BOTH WAYS (2026-08-08). `grep -rnE "\bNAME\("`
# over lib/ missed `var_dump ($x)` (PHP allows whitespace before the argument
# list), missed `die;` and `die "msg"` (`die` is a LANGUAGE CONSTRUCT, not a
# function), and did not know `exit` at all — the same construct under a second
# spelling, so one name was banned and its synonym left open. In the other
# direction a comment saying "never use var_dump( here" and a string literal
# `"select dd(x)"` were both reported. The rules moved into
# scripts/lib/check_forbidden_patterns.py, which judges a comment- and
# string-masked copy. See that file for why `: never` exempts `exit`.
if [ ! -d lib ]; then
    _skip 2 "forbidden-patterns" na "no lib/ directory — this repo ships no PHP under lib/, so there is no shipped server code that could carry a debug helper."
else
    _fp_files=()
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        _in_scope "$f" || continue
        _fp_files+=("$f")
    done < <(_enum_tracked '\.php$' lib)
    _fp_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-forbidden-patterns.log
    : > "${_fp_log}"
    _fp_ran=1
    _fp_helper="${SCRIPT_DIR}/lib/check_forbidden_patterns.py"
    if [ "${#_fp_files[@]}" -eq 0 ]; then
        _fp_ran=0
        _skip 2 "forbidden-patterns" na "0 tracked PHP file(s) under lib/ in this diff — nothing was inspected, so shipped debug helpers (var_dump/die/exit/error_log/print_r/dd/dump) are UNVERIFIED by this run."
    elif [ ! -f "${_fp_helper}" ]; then
        _fp_ran=0
        _skip 2 "forbidden-patterns" wiring "check_forbidden_patterns.py not found at ${_fp_helper} — ${#_fp_files[@]} PHP file(s) were in scope and NONE were inspected."
    else
        set +e
        python3 "${_fp_helper}" "${_fp_files[@]}" >> "${_fp_log}" 2>"${_fp_log}.err"
        _fp_rc=$?
        if [ "${_fp_rc}" -ne 0 ]; then
            _fp_ran=0
            _skip 2 "forbidden-patterns" wiring "check_forbidden_patterns.py exited ${_fp_rc} — ${#_fp_files[@]} PHP file(s) were in scope and NONE were judged. See ${_fp_log}.err."
        fi
    fi
    if [ "${_fp_ran}" -eq 1 ]; then
        _forbidden=$(wc -l < "${_fp_log}" 2>/dev/null || echo 0)
        if [ "${_forbidden}" -eq 0 ]; then
            _pass 2 "forbidden-patterns"
        else
            _fail 2 "forbidden-patterns" "${_forbidden} forbidden call(s) — see ${_fp_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 3: Stub scan
# ---------------------------------------------------------------------------
_stub_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-stub-scan.log
: > "${_stub_log}"
# How much surface did this gate actually get to look at? An empty scope must
# not be reported as a clean one — see the gate-1 note.
_stub_scope=0
while IFS= read -r f; do
    _in_scope "$f" && _stub_scope=$((_stub_scope + 1))
done < <(_enum_tracked '\.(php|vue)$' lib src)
grep -rn "In a complete implementation" lib/ src/ 2>/dev/null | _filter_grep_by_scope | head -5 >> "${_stub_log}" || true
# A DELEGATING run() IS NOT A STUB, AND A DEAD LINE MUST NOT CLOSE THE GATE
# (#226).
#
# This arm counted surviving lines after filtering out `try {`, `} catch` and
# every logger call. For the canonical fail-safe wrapper —
#
#     protected function run($argument): void {
#         try { $this->doRun(argument: $argument); }
#         catch (Throwable $e) { $this->logger->error(...); }
#     }
#
# — exactly ONE line survived, and the threshold was `< 2`. Measured on
# portaliq: `lib/BackgroundJob/NotificationDispatchJob.php`, 530 lines and 11
# private methods implementing a complete notification pipeline, reported as a
# stub. Worse, the gate was CLOSED by adding an inert `$unused = 1;` and could
# not be closed by writing correct code — the only other remedy was to inline
# the pipeline back into run(), deleting the try/catch that keeps an exception
# out of the NC cron runner. Both remedies are regressions, which is why
# portaliq reported the finding instead of fixing it.
#
# scripts/lib/check_stub_run_body.py counts non-logger CALLS instead of lines,
# over a comment-masked body with matched braces. Delegation passes; padding
# does not.
_stub_ran=1
if [ -d lib/BackgroundJob ]; then
    _stub_helper="${SCRIPT_DIR}/lib/check_stub_run_body.py"
    _stub_jobs=()
    while IFS= read -r job; do
        [ -f "${job}" ] || continue
        _in_scope "${job}" || continue
        _stub_jobs+=("${job}")
    done < <(_enum_tracked '\.php$' lib/BackgroundJob)
    if [ "${#_stub_jobs[@]}" -eq 0 ]; then
        : # nothing in scope.
    elif [ ! -f "${_stub_helper}" ]; then
        _stub_ran=0
        _skip 3 "stub-scan" wiring "check_stub_run_body.py not found at ${_stub_helper} — ${#_stub_jobs[@]} background job(s) were in scope and NONE had their run() body inspected."
    else
        set +e
        _stub_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-stub-scan.err"
        python3 "${_stub_helper}" "${_stub_jobs[@]}" >> "${_stub_log}" 2>"${_stub_err}"
        _stub_rc=$?
        if [ "${_stub_rc}" -ne 0 ]; then
            _stub_ran=0
            _skip 3 "stub-scan" wiring "check_stub_run_body.py exited ${_stub_rc} — ${#_stub_jobs[@]} background job(s) were in scope and NONE were judged. See ${_stub_err}."
        fi
    fi
fi
if [ -d src ]; then
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        if grep -qE 'fetch[A-Z][A-Za-z]*\s*\(\s*\)\s*\{' "${vue}" \
           && grep -qE "return\s*\[\s*\{\s*label:\s*'(Default|Personal|Test|Demo)" "${vue}"; then
            echo "${vue}: fetch*() returns hard-coded single-entry stub" >> "${_stub_log}"
        fi
        # .vue only: a `fetch*()` stub is a Vue component-method pattern.
    done < <(find src -name '*.vue' 2>/dev/null)
fi
# Stub auth / ignored caller-identity parameter — decidesk#45 pattern
# (2026-04-22). The builder's fix-mode created empty-stub authorize*()
# methods that accept $uid but never reference it; gate-7 passed (regex
# saw the method call exist) but Clyde's semantic review correctly
# flagged them. This check closes that loop: any public method in a
# service/controller that declares a caller-identity parameter
# ($uid / $callerUid / $userId / $caller) but never references it in
# its body is an unfinished stub and fails gate-3. See ADR-021.
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    grep -nE '^\s*public\s+function\s+\w+\s*\([^)]*\$(uid|callerUid|userId|caller)\b' "$f" \
        | while IFS=: read -r _line_no _; do
            _sig=$(sed -n "${_line_no}p" "$f")
            _method=$(echo "$_sig" | grep -oE 'function\s+\w+' | awk '{print $2}')
            _param=$(echo "$_sig" | grep -oE '\$(uid|callerUid|userId|caller)\b' | head -1)
            [ -z "$_method" ] || [ -z "$_param" ] && continue
            # A BODILESS DECLARATION HAS NO BODY TO IGNORE THE PARAM IN
            # (.github#291).
            #
            # The body extraction below stops at a line matching `^    \}`. An
            # INTERFACE file contains no such line — its methods end in `;` and
            # the interface's own closing brace is at column 0 — so for an
            # interface method the awk ran from the declaration to EOF and
            # called the result "the body". The `< 4` skip below, whose comment
            # says it means to skip "abstract/interface forwards", therefore
            # never fired: the synthesised body is as long as the rest of the
            # file.
            #
            # Measured on pipelinq: `lib/Service/Cti/CtiAdapterInterface.php:78`
            #
            #     public function subscribeToPresence(string $userId, string $extension): void;
            #
            # 92-line file, declaration at 78, zero `^    \}` matches, extraction
            # = 15 lines, reported as `rule=caller-identity-ignored`.
            #
            # There is NO correct fix available to the app author: the parameter
            # is part of the interface contract and is genuinely used by the
            # implementor (`AsteriskAdapter::subscribeToPresence` references
            # `$userId` — and is still checked here, with a real body). A
            # declaration cannot reference anything. The only ways to silence it
            # were to delete the parameter, breaking the contract, or to move the
            # method away from the end of the file.
            #
            # Decide it on the SIGNATURE's terminator, which is the property that
            # actually distinguishes the two: `;` = declaration, `{` = body.
            _sig_region=$(awk -v start="${_line_no}" 'NR >= start { printf "%s", $0; if (/[;{]/) exit }' "$f")
            _sig_term=$(printf '%s' "${_sig_region}" | grep -oE '[;{]' | head -1)
            [ "${_sig_term}" = ";" ] && continue
            _body=$(awk -v start="${_line_no}" 'NR >= start { print; if (NR > start && /^    \}/) exit }' "$f")
            _body_lines=$(echo "${_body}" | wc -l)
            # A legitimate ≥3-line method that accepts a caller-identity
            # param and never references it is a stub. Skip very short
            # methods (likely abstract/interface forwards).
            [ "${_body_lines}" -lt 4 ] && continue
            # Count lines matching the param — signature line always has it,
            # so <2 means body never references it.
            _count=$(echo "${_body}" | grep -cF "${_param}")
            if [ "${_count}" -lt 2 ]; then
                echo "${f}:${_line_no} method=${_method} rule=caller-identity-ignored param=${_param}" >> "${_stub_log}"
            fi
        done
done < <(_enum_tracked '\.php$' lib/Service lib/Controller)
if [ "${_stub_ran}" -eq 1 ] && [ "${_stub_scope}" -eq 0 ]; then
    _stub_ran=0
    _skip 3 "stub-scan" na "scope was empty — 0 tracked .php/.vue file(s) under lib/ or src/ in this diff, so NOTHING was inspected; stub code (unfinished run() bodies, 'In a complete implementation' markers, ignored caller-identity parameters) is UNVERIFIED by this run."
fi
if [ "${_stub_ran}" -eq 1 ]; then
    if [ -s "${_stub_log}" ]; then
        _fail 3 "stub-scan" "$(wc -l < "${_stub_log}") finding(s) — see ${_stub_log}"
    else
        _pass 3 "stub-scan"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 4: Composer audit
#
# Audits the LOCK FILE, not the installed tree, whenever a composer.lock
# exists. That is deliberate and it is a fix, not a convenience:
#
# `composer audit` with no vendor/ present does NOT audit the lock. What it
# does depends on the composer version, and BOTH behaviours are wrong:
#
#   composer >= 2.8 : "No installed packages found. Please run composer install
#                      ... or pass --locked", exit 1. The gate then reported
#                      "CVEs or advisories" for a run that found no CVEs and
#                      audited nothing — a configuration error wearing a
#                      security finding's clothes.
#   composer 2.7.x  : "No packages - skipping audit", exit 0 — a SILENT
#                      FAIL-OPEN. The gate passed having audited nothing at all.
#
# Measured 2026-08-03 on openbuild in CI (composer 2.10.2, no vendor/): gate-4
# FAILED, and `--locked` on the same lock reported no advisories whatsoever.
#
# The lock is also the right object to audit: it is what CI installs and what
# pins the transitive tree. `--locked` needs no vendor/, so this gate no longer
# depends on whether some earlier step happened to run `composer install`.
# ---------------------------------------------------------------------------
#
# COVERAGE CLASSIFICATION (gate-4). This gate had THREE ways to emit nothing at
# all, and all three were byte-identical to its success. Each now says which of
# the three it is, because they are not the same fact:
#
#   no composer.json           NOT APPLICABLE. No PHP dependency tree exists to
#                              audit. A JS-only / Tier-0 app is not less secure
#                              for it.
#   composer.json unchanged    NOT APPLICABLE, under ADR-020 diff scoping. The
#     in a --scope-to-diff run  dependency tree this PR ships is the one the
#                              base branch already audited; re-auditing it is
#                              not this PR's gap. This is the case the product
#                              owner named, and the case that made
#                              --require-full-coverage unusable.
#   composer NOT INSTALLED     WIRING. composer.json exists — there IS a
#                              dependency tree, and the tool that audits it is
#                              absent from the runner. This is a real dead gate
#                              and it was the most silent of the three.
if [ ! -f composer.json ]; then
    _skip 4 "composer-audit" na "no composer.json — this repo declares no PHP dependency tree, so there is nothing for \`composer audit\` to audit."
elif ! command -v composer >/dev/null 2>&1; then
    _skip 4 "composer-audit" wiring "composer.json is present but the \`composer\` binary is not on PATH — the dependency tree EXISTS and was NOT audited. Known CVEs in it are UNVERIFIED by this run."
fi
if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
    _run_audit=1
    if [ "${SCOPE_TO_DIFF}" = "1" ]; then
        _in_scope "composer.json" || _in_scope "composer.lock" || _run_audit=0
    fi
    if [ "${_run_audit}" = "0" ]; then
        _skip 4 "composer-audit" na "neither composer.json nor composer.lock is in this diff — under ADR-020 diff scoping the dependency tree is unchanged from the base branch, so this PR introduces no dependency change to audit."
    fi
    if [ "${_run_audit}" = "1" ]; then
        _ca_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-composer-audit.log
        if [ -f composer.lock ]; then
            _ca_mode="--locked"
        else
            # No lock to audit. Auditing the installed tree is the only option,
            # and it is only meaningful if there IS one.
            _ca_mode=""
        fi
        composer audit ${_ca_mode} --format=plain >"${_ca_log}" 2>&1
        _ca_rc=$?
        if [ "${_ca_rc}" -eq 0 ]; then
            # Distinguish "audited, clean" from "audited nothing, called it
            # clean". The second is the 2.7.x fail-open above, and it must never
            # be counted as a pass.
            if grep -qiE "no packages|no installed packages" "${_ca_log}"; then
                _fail 4 "composer-audit" "audited NOTHING (composer found no packages) — this is not a clean audit; see ${_ca_log}"
            else
                _pass 4 "composer-audit"
            fi
        elif grep -qiE "no installed packages found|please run \"?composer install" "${_ca_log}"; then
            _fail 4 "composer-audit" "audit COULD NOT RUN (no installed packages and no lock to audit) — NOT a CVE finding; see ${_ca_log}"
        else
            _fail 4 "composer-audit" "CVEs or advisories — see ${_ca_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 5: Route-auth — every method registered in appinfo/routes.php has
# an NC middleware attribute (#[PublicPage] / #[NoAdminRequired] /
# #[NoCSRFRequired] / #[AuthorizedAdminSetting]) or legacy docblock tag.
#
# TWO DEFECTS FIXED 2026-08-05 (ConductionNL/.github#153):
#
# (a) A RESOLUTION FAILURE WAS REPORTED AS A SECURITY FINDING. When
#     lib/Controller/<X>Controller.php did not exist, or existed without the
#     routed method, the gate wrote a line into the failure log and the verdict
#     read "N routed method(s) missing auth attribute". Those two states are
#     not the same thing and only one of them is a security signal:
#         "the attribute is absent"      -> a finding, and a real one
#         "I could not open the class"   -> the gate learned NOTHING
#     Reported live on scholiq, whose health/metrics/preferences controllers
#     are ADR-040 AppHost generics (see _apphost_serves above). This gate now
#     separates the two: unresolvable entries go to an UNRESOLVED log and are
#     stated as such, never counted as auth findings.
#
#     ⚠️ The dangerous over-correction would be to swallow the unresolved
#     entries. A route whose class this repo genuinely does not ship is a real
#     defect (ReflectionException 500) — it is just not THIS gate's defect. It
#     is now raised by gate-14 (route-reachability), which owns that invariant.
#     Removing it here without adding it there would have created a dead gate.
#
# (b) IT WAS NOT DIFF-SCOPED. The `missing file` branch `continue`d BEFORE the
#     `_in_scope` call, so a route whose controller was absent fired on every
#     PR regardless of the diff — a package.json-only Dependabot bump included.
#     Per ADR-020 a routed method is now judged when the PR touched EITHER the
#     controller that serves it OR appinfo/routes.php itself (adding/altering a
#     route is exactly when its auth posture must be re-checked).
#
# (c) FIFTH DEFECT, 2026-08-08 (#196): A COMMENT SATISFIED THE GATE. The head
#     block is raw text and the test was one grep, so an attribute NAME
#     appearing anywhere — including inside a docblock — counted:
#
#         /**
#          * ADMIN ONLY: `#[NoAdminRequired]` is deliberately NOT used here.
#          */
#         public function analytics(string $productId): JSONResponse
#
#     passed with no attribute at all. This is the EXPENSIVE direction for a
#     security gate: a pass leaves no log, so any method whose prose mentions
#     one of the four names was silently exempt, and anyone could switch the
#     gate off for a method by writing about it. Live on openconnector's
#     ProductSubscriptionsController, where `subscribe()` passed on exactly
#     that sentence and its neighbour `analytics()` — identical auth posture,
#     no such sentence — was reported (openconnector#1165). Two methods,
#     opposite verdicts, decided entirely by prose.
#
#     The attribute test now runs over a COMMENT-MASKED copy of the file
#     (scripts/lib/source_scope.py --mask php, which knows `#` opens a comment
#     but `#[` opens an attribute — #184's distinction). The legacy docblock
#     form is still accepted, but only at DOCBLOCK-TAG POSITION: preceded on
#     its line by nothing but whitespace and comment punctuation. That is
#     where PHP's own docblock parsers require it, and it is not a position
#     prose reaches.
#
#     ⚠️ AND A DECLARATION, BECAUSE THE ALTERNATIVE IS AN UNCLOSABLE GATE.
#     There is no way to say "admin only" with an attribute: the ABSENCE of
#     `#[NoAdminRequired]` IS the admin gate, and adding `@NoAdminRequired`
#     would make the endpoint non-admin. Documenting the posture was
#     genuinely the only option available, and it happened to satisfy the
#     regex — which is why this went unnoticed. Tightening alone would flag
#     every such method across the fleet with no correct fix available, so
#     the tightening lands WITH a marker in the `@spec exclude` family:
#
#         @auth admin-only <reason of at least 20 characters>
#
#     at docblock-tag position. An attribute is an attribute, a deliberate
#     admin-only endpoint is DECLARED, and prose stops being load-bearing.
#     gate-9 (semantic-auth) still owns the question of whether the declared
#     posture matches the body.
# ---------------------------------------------------------------------------
if [ -f appinfo/routes.php ]; then
    _ra_fail=0 _ra_judged=0 _ra_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-route-auth.log
    _ra_unresolved_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-route-auth-unresolved.log
    : > "${_ra_log}"
    : > "${_ra_unresolved_log}"
    _ra_ran=1
    _ra_helper="${SCRIPT_DIR}/lib/source_scope.py"
    _ra_maskdir="${HYDRA_GATE_LOG_DIR}/route-auth-masked"
    mkdir -p "${_ra_maskdir}" 2>/dev/null || true
    # POSITIVE CONTROL ON THE MASK ITSELF. A helper that is missing, or that
    # silently emits its input unchanged, would put the gate straight back
    # into the false-negative it is here to close — and a false negative
    # leaves no log to notice. So the mask is asked a question with a known
    # answer before anything is judged: of these two lines, exactly ONE must
    # survive. Anything else and the gate declines to run (#147, #245).
    _ra_mask_ok=1
    if [ ! -f "${_ra_helper}" ]; then
        _ra_mask_ok=0
    else
        set +e
        _ra_probe=$(printf '// #[NoAdminRequired]\n#[NoAdminRequired]\n' \
            | python3 "${_ra_helper}" --mask php - 2>/dev/null \
            | grep -c 'NoAdminRequired')
        [ "${_ra_probe}" = "1" ] || _ra_mask_ok=0
    fi
    if [ "${_ra_mask_ok}" -eq 0 ]; then
        _ra_ran=0
        _skip 5 "route-auth" wiring "source_scope.py at ${_ra_helper} could not blank a PHP comment (positive control failed) — NO routed method was judged. Without it a docblock mentioning an attribute name would satisfy this gate silently (#196), so the run declines rather than reporting a pass it cannot support."
    fi
    # Comment-masked copy of a controller, cached per path. Echoes the masked
    # path on success and nothing on failure — the caller treats an empty
    # answer as UNRESOLVED, never as "no attribute".
    _ra_masked_copy() {
        local _src="$1" _dst
        _dst="${_ra_maskdir}/$(printf '%s' "${_src}" | tr '/' '_')"
        if [ ! -f "${_dst}" ]; then
            set +e
            if ! python3 "${_ra_helper}" --mask php "${_src}" > "${_dst}.tmp" 2>/dev/null; then
                rm -f "${_dst}.tmp"
                return 1
            fi
            mv "${_dst}.tmp" "${_dst}"
        fi
        printf '%s' "${_dst}"
    }
    # Touching appinfo/routes.php puts every routed method back in scope.
    _ra_routes_touched=0
    _in_scope "appinfo/routes.php" && _ra_routes_touched=1
    # Process substitution, not a pipeline: the loop must run in THIS shell so
    # the log appends and the scope flags are read from one consistent state.
    #
    # `read -r`, not `read`. Without -r, `read` performs backslash removal on
    # the value, so every NAMESPACED route name arrived here FLATTENED:
    # `OCA\OpenCatalogi\AppHost\Controller\GenericDashboard` became
    # `OCAOpenCatalogiAppHostControllerGenericDashboard`, and `Settings\Foo`
    # became `SettingsFoo`. Measured 2026-08-08 on opencatalogi (6 routes) and
    # openregister (55). The flattened name resolves to a path that cannot
    # exist, which is why _ctrl_path_from_name's `Settings\Foo ->
    # lib/Controller/Settings/FooController.php` branch — documented since it
    # was written — had never once been reached from either gate.
    while IFS='#' read -r ctrl method; do
            [ -n "${ctrl:-}" ] || continue
            # The mask's positive control failed; nothing here can be judged.
            [ "${_ra_ran}" -eq 1 ] || continue
            path=$(_ctrl_path_from_name "${ctrl}")
            if [ ! -f "$path" ]; then
                if _apphost_serves "${ctrl}" || _di_binds_controller "${path}" \
                    || _di_binds_fq_controller "${ctrl}"; then
                    echo "${ctrl}#${method} — served by the OpenRegister AppHost generic controller (ADR-040); its auth attribute lives in the openregister package and is NOT visible from this repository" >> "${_ra_unresolved_log}"
                else
                    echo "${ctrl}#${method} — ${path} is not present in this repository; controller class UNRESOLVED (see gate-14, which owns route reachability)" >> "${_ra_unresolved_log}"
                fi
                continue
            fi
            if [ "${_ra_routes_touched}" -eq 0 ]; then
                _in_scope "$path" || continue
            fi
            def_line=$(grep -nE "^\s*public\s+function\s+${method}\s*\(" "$path" | head -1 | cut -d: -f1)
            if [ -z "$def_line" ]; then
                echo "${path}: routed method ${method} does not exist on this class; UNRESOLVED (see gate-14, which owns route reachability)" >> "${_ra_unresolved_log}"
                continue
            fi
            # ⚠️ THIRD DEFECT, found 2026-08-05 by this gate's own positive
            # control (scripts/lib/test_gate_route_auth.sh). The 20-line
            # lookback was not bounded by the START OF THE METHOD, so it read
            # back over the PREVIOUS member. A short guarded method sitting
            # within 20 lines above an unguarded one donated its
            # `#[NoAdminRequired]` to its neighbour and the unguarded method
            # passed. That is a false NEGATIVE in a security gate — the
            # expensive direction, and invisible because a pass leaves no log.
            #
            # ⚠️ FOURTH DEFECT, found 2026-08-08 the moment the `read -r` fix
            # made openregister's `Settings\…` routes resolve: 20 lines is also
            # TOO SHORT. `Settings\FileSettings#getFileExtractionStats` declares
            # `@NoCSRFRequired` 22 lines above its `public function`, because a
            # `@psalm-return` shape sits in between — and a correctly annotated
            # endpoint was reported `missing-auth-attribute`. Both directions
            # now go through _head_block, which takes the contiguous annotation
            # run OR the 20-line slice, whichever starts earlier, and keeps the
            # previous-member clamp.
            #
            # THREE QUESTIONS, THREE TEXTS (#196).
            #
            #   1. an ATTRIBUTE — asked of the comment-MASKED head block, so
            #      `#[NoAdminRequired]` quoted in a docblock is not one.
            #   2. a LEGACY DOCBLOCK TAG — asked of the ORIGINAL, because the
            #      tag lives in a comment by definition, but only at
            #      tag position: nothing before it on the line except
            #      whitespace and comment punctuation.
            #   3. an explicit ADMIN-ONLY DECLARATION with a reason.
            #
            head_block=$(_head_block "$path" "${def_line}")
            _ra_masked_path=$(_ra_masked_copy "$path")
            if [ -z "${_ra_masked_path}" ]; then
                echo "${path}:${def_line} method=${method} — the file could not be comment-masked; NOT JUDGED (a raw-text match here would be the #196 false negative)" >> "${_ra_unresolved_log}"
                continue
            fi
            head_masked=$(_head_block "${_ra_masked_path}" "${def_line}")
            # This entry is about to be JUDGED — as opposed to skipped for
            # scope or parked as unresolved. Counted so an all-skipped run
            # cannot report PASS (see the verdict below).
            _ra_judged=$((_ra_judged + 1))
            _ra_declared=0
            echo "$head_masked" | grep -qE '#\[[^]]*\b(PublicPage|NoAdminRequired|NoCSRFRequired|AuthorizedAdminSetting)\b' && _ra_declared=1
            if [ "${_ra_declared}" -eq 0 ]; then
                echo "$head_block" | grep -qE '^[[:space:]]*(/?\*+[[:space:]]*)?@(PublicPage|NoAdminRequired|NoCSRFRequired)\b' && _ra_declared=1
            fi
            if [ "${_ra_declared}" -eq 0 ]; then
                echo "$head_block" | grep -qE '^[[:space:]]*(/?\*+[[:space:]]*)?@auth[[:space:]]+admin-only[[:space:]]+.{20,}' && _ra_declared=1
            fi
            if [ "${_ra_declared}" -eq 0 ]; then
                echo "${path}:${def_line} method=${method} rule=missing-auth-attribute" >> "${_ra_log}"
            fi
    done < <(grep -oE "${_ROUTE_NAME_RX}" appinfo/routes.php \
        | grep -oE "${_ROUTE_PAIR_RX}" | sort -u)
    _ra_fail=$(_count '.' "${_ra_log}")
    _ra_unres=$(_count '.' "${_ra_unresolved_log}")
    # Stated, never folded into the verdict. Does NOT start with `[gate-` so it
    # cannot be mistaken for a verdict line by any `^\[gate-` consumer.
    if [ "${_ra_unres}" -gt 0 ]; then
        echo "[hydra-gates] gate-5 route-auth: ${_ra_unres} routed entr(ies) NOT JUDGED (controller class unresolvable here) — see ${_ra_unresolved_log}. This is not a pass for them; reachability is gate-14's."
    fi
    if [ "${_ra_ran}" -eq 1 ] && [ "${_ra_judged}" -eq 0 ]; then
        # An empty scope is not a clean tree — see the gate-1 note. Measured on
        # larpingapp: a README-only commit run with --scope-to-diff reported
        # `[gate-5] route-auth: PASS` having judged zero routed methods.
        _ra_ran=0
        _skip 5 "route-auth" na "0 routed method(s) were judged — appinfo/routes.php is not in this diff and no controller serving a route is either, so under ADR-020 no endpoint's auth posture changed in this PR. ${_ra_unres} entr(ies) were additionally unresolvable here."
    fi
    if [ "${_ra_ran}" -eq 1 ]; then
        if [ "${_ra_fail}" -eq 0 ]; then
            _pass 5 "route-auth"
        else
            _fail 5 "route-auth" "${_ra_fail} routed method(s) missing auth attribute — see ${_ra_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 6: Orphan-auth — public is*/requires?*/validate*/authorize*/check*/
# ensure*/verify*/assert* methods in services + controllers must have at
# least one external caller — BUT only when the method is a genuine
# ACCESS CONTROL, not a generic boolean predicate.
#
# The verb prefix alone over-matches: reference-data lookups
# (Iv3TaakveldList::isValidCode / isDeprecated), SLA queries
# (Kcc\SlaCalculator::isBreached), and UI-availability flags
# (WOOAnonymisationAssistService::isLlmAssistAvailable) are all boolean
# predicates that are NOT authorization. Flagging them inflated the fleet
# "dead auth" count with noise, and a noisy security gate gets ignored —
# the exact failure that cost us with gates 56/57.
#
# The detection now lives in scripts/lib/check_orphan_auth.py, which keeps
# the verb prefix as the candidate filter but requires an authorization-
# domain signal (subject param / permission-denial throw / authz-service
# touch / authz name token / @authorization marker) before a method counts
# as access control. A verb-prefixed method with a real auth signal and
# zero callers anywhere in lib/ or src/ is still flagged (the decidesk
# isTransitionAllowed/requiresChairAuthorization/validateQuorum trio and
# the shillinq segregation guard stay caught). Same-file callers still
# count (a public helper called from a sibling method is legit).
#
# The findings log uses a mktemp path (not the fixed
# ${HYDRA_GATE_LOG_DIR}/hydra-gate-orphan-auth.log) so parallel gate runs across multiple
# apps can't clobber each other's counts. See hydra#110.
# ---------------------------------------------------------------------------
_oa_log="$(mktemp "${HYDRA_GATE_LOG_DIR}/hydra-gate-orphan-auth.XXXXXX.log")"
: > "${_oa_log}"
_oa_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _oa_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Service lib/Controller)
_oa_ran=1
# An EMPTY scope is not a clean tree. With zero files the log stays empty, the
# count is 0, and the gate used to report PASS having inspected nothing — the
# same failure family as .github#147, where a missing helper made gate-7 report
# PASS over 11 real unguarded endpoints. SKIPPED is the honest verdict: it is
# excluded from _EMITTED_GATES, so the coverage summary lists this gate under
# "GATES THAT DID NOT RUN" instead of folding it into ALL GATES GREEN.
if [ "${#_oa_files[@]}" -eq 0 ]; then
    _oa_ran=0
    _skip 6 "orphan-auth" na "scope was empty — 0 lib/Service or lib/Controller PHP file(s) in this diff, so NOTHING was inspected; orphaned (defined-but-never-called) authorization methods are UNVERIFIED by this run."
fi
if [ "${#_oa_files[@]}" -gt 0 ]; then
    _oa_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_oa_lib_dir}/check_orphan_auth.py" ]; then
        _oa_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_oa_lib_dir}/check_orphan_auth.py" ]; then
        # A CHECKER THAT COULD NOT RUN MUST NOT JUDGE THE CODE (.github#330 —
        # the #245/#233/#276 rule, applied to the three security gates it had
        # not reached).
        #
        # This was `>> log 2>/dev/null || true`: the helper's message AND its
        # exit status both discarded, with the verdict then taken from
        # `wc -l` on a log nothing had written to — i.e. PASS. That is the
        # gate-20 mechanism verbatim, and gate-20 had NEVER fired in any repo
        # in its entire existence because of it.
        #
        # MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
        #   working python3   [gate-6] orphan-auth: FAIL — 1 orphan method(s)
        #   python3 exit 1    [gate-6] orphan-auth: PASS
        # Same tree, same files in scope. The green was over nothing.
        #
        # The helper ALWAYS returns 0 when it runs, by design (#209 — the
        # count goes to stdout, never into the exit byte; see its `main()`).
        # So a non-zero exit here is unambiguously a crash and can never be a
        # finding count. stderr goes to a .err file rather than /dev/null so
        # the reason it died is recoverable by the reader.
        _oa_err="${_oa_log}.err"
        : > "${_oa_err}"
        python3 "${_oa_lib_dir}/check_orphan_auth.py" "${_oa_files[@]}" \
            >> "${_oa_log}" 2>"${_oa_err}"
        _oa_rc=$?
        if [ "${_oa_rc}" -ne 0 ]; then
            _oa_ran=0
            _oa_why=$(head -3 "${_oa_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 6 "orphan-auth" wiring "check_orphan_auth.py exited ${_oa_rc} — ${#_oa_files[@]} PHP file(s) were in scope and NONE were judged; orphaned (defined-but-never-called) authorization methods are UNVERIFIED by this run. This helper always exits 0 when it runs, so this is a crash, not a finding count. Checker output: ${_oa_why:-<empty>}. See ${_oa_err}."
        fi
    else
        _oa_ran=0
        _skip 6 "orphan-auth" wiring "check_orphan_auth.py not found at ${_oa_lib_dir} — ${#_oa_files[@]} PHP file(s) were in scope and NONE were inspected; orphaned (defined-but-never-called) authorization methods are UNVERIFIED by this run."
    fi
fi
if [ "${_oa_ran}" -eq 1 ]; then
    _filter_preexisting "${_oa_log}"
    _oa_fail=$(wc -l < "${_oa_log}" 2>/dev/null || echo 0)
    if [ "${_oa_fail}" -eq 0 ]; then
        _pass 6 "orphan-auth"
    else
        _fail 6 "orphan-auth" "${_oa_fail} orphan method(s) — see ${_oa_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 7: No-admin-IDOR — every controller method with #[NoAdminRequired]
# / @NoAdminRequired must contain an authorization guard in the body.
# Recognized guard patterns:
#   - OCSForbiddenException thrown
#   - isAdmin( check
#   - ->authorize*/require*/ensure* service call
#   - #[PublicPage] / @PublicPage (explicit public endpoint)
#   - Http::STATUS_(UNAUTHORIZED|FORBIDDEN) response (or numeric 401/403 in
#     response-construction position)
#   - a deny response routed through a helper — ::forbidden( /
#     ->unauthorized( / ::accessDenied( (call position only, so
#     ->forbiddenWords( is not a false guard)
#   - TemplateResponse return type / instantiation — SPA page renderers
#   - (delegated guards, see check_no_admin_idor.py: Pattern 1 same-class
#     guard-helper; Pattern 2 OpenRegister ObjectService/*Mapper RBAC)
#
# Exemptions (never IDOR vectors):
#   - __construct — not a routed action endpoint
#   - Session-scoped endpoint with no caller-supplied object reference
#     (Pattern 3): zero parameters + no request reads + a session-derived
#     identity ($this->userId / getUID()) — there is no direct object
#     reference to substitute, so IDOR is not structurally possible. Fails
#     closed on an unparseable signature.
#   - Methods carrying @no-admin-idor-exempt <reason> (reason required)
#   - Methods whose name starts with preflightedCors (case-insensitive) —
#     Nextcloud OCS/CORS preflight handlers; OPTIONS requests are sent by
#     browsers without credentials so an auth guard would break CORS.
#     Fleet convention: preflightedCors / preflightedCorsItem / etc.
#     (false positives confirmed: opencatalogi 8x, openconnector 3x, 2026-05-27)
#   - Methods whose body only sets Access-Control-* headers (no data access)
#
# The gate is implemented in scripts/lib/check_no_admin_idor.py, a
# brace-aware Python helper (mirrors gate-9's check_semantic_auth.py).
# The Python implementation correctly handles the function-signature
# return-type hint (e.g. TemplateResponse in ": TemplateResponse") and
# properly scopes @NoAdminRequired look-back to the current method's
# docblock — avoiding false positives from preceding method annotations.
# ---------------------------------------------------------------------------
_idor_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-no-admin-idor.log
: > "${_idor_log}"
_idor_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _idor_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Controller)
_idor_ran=1
# See the gate-6 note above: an empty scope inspected nothing, so it cannot be
# a PASS. SKIPPED keeps it out of _EMITTED_GATES and therefore visible in the
# coverage summary rather than silently green.
if [ "${#_idor_files[@]}" -eq 0 ]; then
    _idor_ran=0
    _skip 7 "no-admin-idor" na "scope was empty — 0 lib/Controller PHP file(s) in this diff, so NOTHING was inspected; unguarded #[NoAdminRequired] endpoints (IDOR, OWASP A01:2021) are UNVERIFIED by this run."
fi
if [ "${#_idor_files[@]}" -gt 0 ]; then
    _gate_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_gate_lib_dir}/check_no_admin_idor.py" ]; then
        _gate_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_gate_lib_dir}/check_no_admin_idor.py" ]; then
        # A CHECKER THAT COULD NOT RUN MUST NOT JUDGE THE CODE (.github#330).
        # Same repair, same reason, same measurement as gate-6 above — see the
        # note there. This gate's green history is the one that matters most:
        # a missing helper already made it report PASS over 11 real unguarded
        # IDOR endpoints once (.github#147), and `2>/dev/null || true` left the
        # SECOND route to that same false green — a helper that is present but
        # cannot run — wide open.
        #
        # MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
        #   working python3   [gate-7] no-admin-idor: FAIL — 1 method(s)
        #   python3 exit 1    [gate-7] no-admin-idor: PASS
        #
        # check_no_admin_idor.py ends `return 0  # exit 0 always — caller
        # counts printed lines`, so a non-zero byte here is a crash and never
        # a count.
        _idor_err="${_idor_log}.err"
        : > "${_idor_err}"
        python3 "${_gate_lib_dir}/check_no_admin_idor.py" "${_idor_files[@]}" \
            >> "${_idor_log}" 2>"${_idor_err}"
        _idor_rc=$?
        if [ "${_idor_rc}" -ne 0 ]; then
            _idor_ran=0
            _idor_why=$(head -3 "${_idor_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 7 "no-admin-idor" wiring "check_no_admin_idor.py exited ${_idor_rc} — ${#_idor_files[@]} controller file(s) were in scope and NONE were judged; unguarded #[NoAdminRequired] endpoints (IDOR, OWASP A01:2021) are UNVERIFIED by this run. This helper always exits 0 when it runs, so this is a crash, not a finding count. Checker output: ${_idor_why:-<empty>}. See ${_idor_err}."
        fi
    else
        _idor_ran=0
        _skip 7 "no-admin-idor" wiring "check_no_admin_idor.py not found at ${_gate_lib_dir} — ${#_idor_files[@]} controller file(s) were in scope and NONE were inspected; unguarded #[NoAdminRequired] endpoints (IDOR, OWASP A01:2021) are UNVERIFIED by this run."
    fi
fi
if [ "${_idor_ran}" -eq 1 ]; then
    _filter_preexisting "${_idor_log}"
    _idor_fail=$(wc -l < "${_idor_log}" 2>/dev/null || echo 0)
    if [ "${_idor_fail}" -eq 0 ]; then
        _pass 7 "no-admin-idor"
    else
        # THE GUARD MAY BE TWO FRAMES DOWN (.github#315). The checker can only
        # read the controller method's own body. On a repo that enforces
        # multi-tenancy at the DATA-ACCESS layer, the service in between looks
        # unscoped — `findAll(filters:)`, `updateFromArray(id:)`, no
        # organisation in sight — because the scoping lives in the MAPPER
        # (`MultiTenancyTrait::applyOrganisationFilter()`).
        #
        # Measured on openregister: 8 of 9 findings were that shape, and TWO
        # WERE NEARLY FILED AS VULNERABILITIES by a reader who stopped at the
        # service layer — which is where reading naturally stops, because the
        # service is what the controller calls. Saying so here costs nothing
        # and is the difference between a triage and a wrong security report.
        _fail 7 "no-admin-idor" "${_idor_fail} method(s) with NoAdminRequired + no guard — see ${_idor_log}. BEFORE treating any of these as real: the checker sees ONLY the controller method body. If the endpoint reaches storage through a service or mapper, check whether the guard is enforced THERE (e.g. an organisation/tenant filter applied in the query builder) — and note that a deliberate 404-style tenancy refusal IS a guard, chosen so a 403 cannot become an existence oracle for another tenant's ids."
    fi
fi

# ---------------------------------------------------------------------------
# Gate 8: Unsafe-auth-resolver — no `catch (\Throwable) { return null; }`
# in methods whose name contains Auth/Authorization/Permission/Role/Guard.
# ---------------------------------------------------------------------------
#
# ⚠️ REWRITTEN 2026-08-08. The bash below extracted the method body with
# `/^    \}/` and the catch block with `/^        \}/` — HARD-CODED INDENTATION,
# four spaces and eight. On a tab-indented file neither terminator matches, so
# "the body" ran to end of file: a correctly RETHROWING resolver was reported as
# a fail-open because an unrelated cache method further down returned null from
# its own catch. False positive on correct code, and the apparent detection of
# tab-indented fail-opens was the same over-capture by luck. Braces are the
# language's block delimiter, so scripts/lib/check_unsafe_auth_resolver.py walks
# them, over a comment-masked copy (#184).
_uar_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-unsafe-auth-resolver.log
: > "${_uar_log}"
_uar_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _uar_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Service lib/Controller)
_uar_ran=1
_uar_helper="${SCRIPT_DIR}/lib/check_unsafe_auth_resolver.py"
if [ "${#_uar_files[@]}" -eq 0 ]; then
    _uar_ran=0
    _skip 8 "unsafe-auth-resolver" na "scope was empty — 0 lib/Service or lib/Controller PHP file(s) in this diff, so NOTHING was inspected; fail-open auth resolvers (CWE-863) are UNVERIFIED by this run."
elif [ ! -f "${_uar_helper}" ]; then
    _uar_ran=0
    _skip 8 "unsafe-auth-resolver" wiring "check_unsafe_auth_resolver.py not found at ${_uar_helper} — ${#_uar_files[@]} PHP file(s) were in scope and NONE were inspected; fail-open auth resolvers (CWE-863) are UNVERIFIED by this run."
else
    set +e
    python3 "${_uar_helper}" "${_uar_files[@]}" >> "${_uar_log}" 2>"${_uar_log}.err"
    _uar_rc=$?
    if [ "${_uar_rc}" -ne 0 ]; then
        _uar_ran=0
        _skip 8 "unsafe-auth-resolver" wiring "check_unsafe_auth_resolver.py exited ${_uar_rc} — ${#_uar_files[@]} PHP file(s) were in scope and NONE were judged. See ${_uar_log}.err."
    fi
fi
if [ "${_uar_ran}" -eq 1 ]; then
    _filter_preexisting "${_uar_log}"
    _uar_fail=$(wc -l < "${_uar_log}" 2>/dev/null || echo 0)
    if [ "${_uar_fail}" -eq 0 ]; then
        _pass 8 "unsafe-auth-resolver"
    else
        _fail 8 "unsafe-auth-resolver" "${_uar_fail} fail-open pattern(s) — see ${_uar_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 9: Semantic-auth — annotation must match the method body's actual
# authorization requirement. Observed on decidesk#44 (2026-04-23): builder
# satisfied gate-5 (route-auth) by adding `#[NoAdminRequired]` to load()
# even though the method body calls `requireAdmin()`. Gate-5 accepted any
# auth attribute; this gate catches the semantic mismatch.
#
# Checks:
#  1. #[NoAdminRequired] / @NoAdminRequired with requireAdmin() / isAdmin()
#     in body → mismatch. Use #[AuthorizedAdminSetting(Application::APP_ID)]
#     instead (declaratively admin-only, matches the body's check).
#  2. #[PublicPage] / @PublicPage with requireAdmin() / isAdmin() / userSession
#     getUser() null-check in body → mismatch. Public means no auth; having
#     body checks means the annotation lies to routers and reviewers.
#
# See ADR-005 (security — attribute must match actual requirement) and
# ADR-016 (routes — gate-5 syntactic, gate-9 semantic).
# ---------------------------------------------------------------------------
_sem_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-semantic-auth.log
: > "${_sem_log}"

# W28 (2026-04-24 warnings list) — gate-9 was a flat-regex implementation
# that broke on nested `}` inside the if-body (closures, array literals,
# match-expressions). The current implementation delegates to a brace-
# aware Python helper that walks the PHP source with proper string +
# comment + heredoc skipping. The bash side just feeds it the in-scope
# files and counts the printed violations.
_sem_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _sem_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Controller)
_sem_ran=1
if [ "${#_sem_files[@]}" -eq 0 ]; then
    # An empty scope is not a clean tree — see the gate-1 note.
    _sem_ran=0
    _skip 9 "semantic-auth" na "scope was empty — 0 lib/Controller PHP file(s) in this diff, so NOTHING was inspected; auth-attribute-vs-body semantic mismatches are UNVERIFIED by this run."
fi
if [ "${#_sem_files[@]}" -gt 0 ]; then
    # The helper script is co-located with the gate runner. Two layouts:
    #   local repo: scripts/run-hydra-gates.sh + scripts/lib/check_semantic_auth.py
    #   container:  /usr/local/lib/hydra/run-hydra-gates.sh + /usr/local/lib/hydra/check_semantic_auth.py
    # Probe both — the flat container layout was previously a silent skip
    # because the path resolution only checked the lib/ subdir variant.
    _sem_helper=""
    for _candidate in \
        "${SCRIPT_DIR}/lib/check_semantic_auth.py" \
        "${SCRIPT_DIR}/check_semantic_auth.py"; do
        if [ -f "${_candidate}" ]; then
            _sem_helper="${_candidate}"
            break
        fi
    done
    if [ -n "${_sem_helper}" ]; then
        # A CHECKER THAT COULD NOT RUN MUST NOT JUDGE THE CODE (.github#330).
        # Same repair as gates 6 and 7 above.
        #
        # MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
        #   working python3   [gate-9] semantic-auth: FAIL — 1 mismatch(es)
        #   python3 exit 1    [gate-9] semantic-auth: PASS
        #
        # check_semantic_auth.py ends `return 0  # exit 0 always — caller
        # counts printed lines`, so a non-zero byte here is a crash and never
        # a count.
        _sem_err="${_sem_log}.err"
        : > "${_sem_err}"
        python3 "${_sem_helper}" "${_sem_files[@]}" \
            >> "${_sem_log}" 2>"${_sem_err}"
        _sem_rc=$?
        if [ "${_sem_rc}" -ne 0 ]; then
            _sem_ran=0
            _sem_why=$(head -3 "${_sem_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 9 "semantic-auth" wiring "check_semantic_auth.py exited ${_sem_rc} — ${#_sem_files[@]} controller file(s) were in scope and NONE were judged; auth-attribute-vs-body semantic mismatches are UNVERIFIED by this run. This helper always exits 0 when it runs, so this is a crash, not a finding count. Checker output: ${_sem_why:-<empty>}. See ${_sem_err}."
        fi
    else
        _sem_ran=0
        _skip 9 "semantic-auth" wiring "check_semantic_auth.py not found near ${SCRIPT_DIR} — ${#_sem_files[@]} controller file(s) were in scope and NONE were inspected; auth-attribute-vs-body semantic mismatches are UNVERIFIED by this run."
    fi
fi
if [ "${_sem_ran}" -eq 1 ]; then
    _sem_fail=$(wc -l < "${_sem_log}" 2>/dev/null || echo 0)
    if [ "${_sem_fail}" -eq 0 ]; then
        _pass 9 "semantic-auth"
    else
        _fail 9 "semantic-auth" "${_sem_fail} attribute-vs-body mismatch(es) — see ${_sem_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 10: Initial-state — frontend reads of `getElementById(...).dataset.*`
# in .vue/.js/.ts files. Server-side data must travel via IInitialState
# (PHP) + loadState() from @nextcloud/initial-state. Observed 2026-04-30
# on doriath where AdminRoot.vue read `dataset.version` instead of
# `loadState('doriath', 'version', 'Unknown')`. ADR-004 hard rule.
# ---------------------------------------------------------------------------
#
# ⚠️ THE SINGLE-LINE SHAPE WAS THE ONLY SHAPE IT KNEW (measured 2026-08-08).
# `getElementById\s*\([^)]+\)[^.]*\.dataset` requires the lookup and the
# `.dataset` read to sit on ONE line, so all three of these reported PASS:
#
#   const el = document.getElementById('x')      the TWO-STEP form — the one
#   const v  = el.dataset.version                 doriath's AdminRoot.vue turns
#                                                 into after any refactor
#   document.querySelector('#x').dataset.version  a different lookup, same read
#   document.getElementById('x').getAttribute('data-version')
#
# The gate is widened only along the axis it already covers — reading
# server-injected data out of the DOM — and NOT to a bare `.dataset`, which
# would flag every legitimate `event.target.dataset` in the fleet.
#
# ⚠️ THE SURFACE IS src/ AND js/ (2026-08-08). Guarding on `[ -d src ]` and
# enumerating only src/ produced the `na` BLACKOUT this whole exercise is about:
# nldesign's src/ holds one file, manifest.json, and its entire hand-written
# frontend lives in js/. gate-10 announced "this repo ships no frontend" over a
# repo whose js/admin.js does exactly what this gate exists to catch —
#
#     var settingsEl = document.getElementById('nldesign-settings');
#     var tokenSets  = JSON.parse(settingsEl.getAttribute('data-token-sets'));
#
# — the doriath AdminRoot defect, in the two-step form, in an ADMIN script. `na`
# removes a gate from coverage accounting, so this silently left the
# denominator. js/ is Nextcloud's conventional shipped-script directory; only
# nldesign and openregister track anything there, and minified files are
# excluded because a committed bundle is not authored code.
if [ ! -d src ] && [ ! -d js ]; then
    _skip 10 "initial-state" na "neither src/ nor js/ exists — this repo ships no frontend, so there is no code that could read server data out of the DOM."
else
    _is_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-initial-state.log
    : > "${_is_log}"
    _is_files=()
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        _in_scope "$f" || continue
        _is_files+=("$f")
    done < <(_enum_tracked '\.(vue|js|ts)$' src js | grep -v '\.min\.js$')
    _is_ran=1
    _is_helper="${SCRIPT_DIR}/lib/check_initial_state.py"
    if [ "${#_is_files[@]}" -eq 0 ]; then
        _is_ran=0
        _skip 10 "initial-state" na "0 tracked, non-minified .vue/.js/.ts file(s) under src/ or js/ in this diff — nothing was inspected, so DOM reads of server-injected data are UNVERIFIED by this run."
    elif [ ! -f "${_is_helper}" ]; then
        _is_ran=0
        _skip 10 "initial-state" wiring "check_initial_state.py not found at ${_is_helper} — ${#_is_files[@]} frontend file(s) were in scope and NONE were inspected."
    else
        set +e
        python3 "${_is_helper}" "${_is_files[@]}" >> "${_is_log}" 2>"${_is_log}.err"
        _is_rc=$?
        if [ "${_is_rc}" -ne 0 ]; then
            _is_ran=0
            _skip 10 "initial-state" wiring "check_initial_state.py exited ${_is_rc} — ${#_is_files[@]} frontend file(s) were in scope and NONE were judged. See ${_is_log}.err."
        fi
    fi
    if [ "${_is_ran}" -eq 1 ]; then
        _is_fail=$(wc -l < "${_is_log}" 2>/dev/null || echo 0)
        if [ "${_is_fail}" -eq 0 ]; then
            _pass 10 "initial-state"
        else
            _fail 10 "initial-state" "${_is_fail} DOM read(s) of server data — use loadState()/IInitialState — see ${_is_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 11: Admin-router — admin settings Vue components must NOT be
# registered as vue-router routes; doing so makes them publicly reachable
# as a frontend URL, bypassing the admin check that Nextcloud's settings
# framework enforces. ADR-004 hard rule. Observed 2026-04-30 on doriath
# (commit c7c72e9 removed the dangerous /settings → AdminRoot route).
#
# ⚠️ THIS GATE WAS DEAD (measured 2026-08-08). It read FOUR hard-coded paths —
# src/router/index.{js,ts} and src/router.{js,ts} — and exactly ONE fleet app of
# fifteen (softwarecatalog) has a file at any of them. The other fourteen build
# their router in `src/main.js`, so they were handed `[gate-11] admin-router:
# PASS` with ZERO BYTES inspected. Proof it was dead rather than unexercised:
# the doriath defect re-planted verbatim into larpingapp's real router,
# `routes.push({ path: '/settings', component: AdminRoot })`, reported PASS;
# the identical line in `src/router.js` reported FAIL.
#
# Routers are now DISCOVERED — anything under src/ that CONSTRUCTS one — with
# the four legacy paths kept so softwarecatalog does not regress. No router at
# all is `na`, never PASS: "no client-side router exists" and "the router is
# clean" are different facts and only one of them was ever checked.
#
# The rules themselves live in scripts/lib/check_admin_router.py, which blanks
# comments (#184) and resolves the ENCLOSING ROUTE OBJECT before judging a
# `path:` — see that file for why a bare `path: '/settings'` grep would flag
# openconnector's ADR-079 hand-off, i.e. the remediation, as the defect.
# ---------------------------------------------------------------------------
_ar_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-admin-router.log
: > "${_ar_log}"
_ar_routers=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    grep -qE '\bcreateRouter\s*\(|\bnew[[:space:]]+VueRouter\s*\(' "$f" 2>/dev/null || continue
    _ar_routers+=("$f")
done < <(_enum_tracked '\.(js|ts|mjs)$' src js | grep -v '\.min\.js$')
# The four conventional paths, kept even if they do not construct a router
# themselves (a `routes.js` re-exported into main.js is still the routing table).
for f in src/router/index.js src/router/index.ts src/router.js src/router.ts; do
    [ -f "$f" ] || continue
    case " ${_ar_routers[*]-} " in *" ${f} "*) continue ;; esac
    _ar_routers+=("$f")
done
_ar_scoped=()
for f in ${_ar_routers[@]+"${_ar_routers[@]}"}; do
    _in_scope "$f" || continue
    _ar_scoped+=("$f")
done
_ar_ran=1
if [ "${#_ar_routers[@]}" -eq 0 ]; then
    _ar_ran=0
    _skip 11 "admin-router" na "no file under src/ or js/ constructs a vue-router (no \`createRouter(\` / \`new VueRouter(\`) and none of the four conventional router paths exist — this app has no client-side router, so an admin component cannot be registered as a frontend route."
elif [ "${#_ar_scoped[@]}" -eq 0 ]; then
    _ar_ran=0
    _skip 11 "admin-router" na "this app's router(s) — ${_ar_routers[*]} — are not in this diff, so under ADR-020 the routing table is unchanged from the base branch."
else
    _ar_helper="${SCRIPT_DIR}/lib/check_admin_router.py"
    if [ ! -f "${_ar_helper}" ]; then
        _ar_ran=0
        _skip 11 "admin-router" wiring "check_admin_router.py not found at ${_ar_helper} — ${#_ar_scoped[@]} router file(s) were in scope and NONE were inspected; admin components reachable as frontend routes are UNVERIFIED by this run."
    else
        set +e
        python3 "${_ar_helper}" "${_ar_scoped[@]}" >> "${_ar_log}" 2>"${_ar_log}.err"
        _ar_rc=$?
        if [ "${_ar_rc}" -ne 0 ]; then
            _ar_ran=0
            _skip 11 "admin-router" wiring "check_admin_router.py exited ${_ar_rc} — ${#_ar_scoped[@]} router file(s) were in scope and NONE were judged. See ${_ar_log}.err."
        fi
    fi
fi
if [ "${_ar_ran}" -eq 1 ]; then
    _ar_fail=$(wc -l < "${_ar_log}" 2>/dev/null || echo 0)
    if [ "${_ar_fail}" -eq 0 ]; then
        _pass 11 "admin-router"
    else
        _fail 11 "admin-router" "${_ar_fail} admin route/import — register via AdminSettings.php instead — see ${_ar_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 12: NC-input-labels — every <NcSelect> tag must declare an
# inputLabel (or ariaLabelCombobox). Manual <label> elements break the
# component's internal a11y wiring (WCAG 1.3.1 / 4.1.2). ADR-004 hard
# rule. Observed 2026-04-30 on doriath.
#
# THE ELEMENT ENDS AT A `>` THAT IS NOT INSIDE AN ATTRIBUTE VALUE (#236).
#
# This gate used to extract elements with `grep -oE '<NcSelect[^>]*>'`.
# `[^>]*` stops at the FIRST `>` in the source, and in an NcSelect that is
# usually the arrow of `:reduce="(o) => o.id"` — so every prop written after
# `:reduce` was cut off, including the two this gate looks for. Measured on
# scholiq 2026-08-08: 18 findings, 18 of them false; each flagged element
# already carried `:input-label` AND `:aria-label-combobox`, both after
# `:reduce`. The gate was anti-correlated with its subject there — adding the
# label could not clear it, removing `:reduce` could. Same shape as gate-9's
# `[^)]*` in #198.
#
# The accepted prop set is UNCHANGED, deliberately — this fixes where an
# element ends and which regions of the file are markup, not what counts as a
# name, so the numbers stay comparable.
# ---------------------------------------------------------------------------
if [ -d src ]; then
    _il_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-nc-input-labels.log
    : > "${_il_log}"
    _il_ran=1
    _il_helper="${SCRIPT_DIR}/lib/check_nc_select_labels.py"
    _il_files=()
    # Counted separately from the in-scope list so "this repo has no Vue
    # component at all" can be told apart from "the diff touched none of them".
    _il_present=0
    while IFS= read -r vue; do
        [ -f "${vue}" ] || continue
        _il_present=$((_il_present + 1))
        _in_scope "${vue}" || continue
        _il_files+=("${vue}")
        # .vue only: <NcSelect> is a Vue component. Gate 40 covers the
        # language-agnostic <input>/<select> label rule for PHP templates.
    done < <(find src -name '*.vue' 2>/dev/null)
    if [ "${#_il_files[@]}" -eq 0 ]; then
        # ZERO FILES IS NOT A PASS (.github#274, and #225 one layer down).
        #
        # `[ -d src ]` was the whole guard, and `src/` existing is not the same
        # as `src/` containing a Vue component. nldesign's `src/` holds ONE
        # file — `manifest.json` — and this gate printed PASS there, over an
        # empty glob. That is the exact shape that let twelve a11y gates certify
        # nldesign in #225; gates 12 and 13 were not in that band and kept it.
        #
        # `na`, not `structural`: nothing in the repository is missing. An app
        # that renders from PHP templates has no `<NcSelect>` to name, because
        # `NcSelect` is a Vue SFC component — a PHP template cannot instantiate
        # one, which is why this gate stays `.vue`-only while gate-40 owns the
        # language-agnostic `<input>`/`<select>` label rule for templates. That
        # is the judgement #274 asked for, and it is stated in the reason so a
        # reader can disagree with it.
        _il_ran=0
        if [ "${_il_present}" -eq 0 ]; then
            _skip 12 "nc-input-labels" na "src/ exists but contains NO .vue component, so there is no <NcSelect> to name. This gate is deliberately .vue-only: NcSelect is a Vue SFC component and a PHP/HTML template cannot instantiate one — gate-40 owns the language-agnostic <input>/<select> label rule for templates/. Reported instead of PASS because an empty glob under an existing src/ is what let twelve gates certify nldesign in #225."
        else
            _skip 12 "nc-input-labels" na "${_il_present} .vue component(s) exist here and the diff against '${BASE_REF}' touched none of them, so no <NcSelect> was inspected. Diff-scoped out under ADR-020 — not a gap, and not a pass."
        fi
    elif [ ! -f "${_il_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _il_ran=0
        _skip 12 "nc-input-labels" wiring "check_nc_select_labels.py not found at ${_il_helper} — ${#_il_files[@]} component(s) were in scope and NONE had their NcSelect elements inspected; unnamed comboboxes are UNVERIFIED by this run."
    else
        set +e
        _il_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-nc-input-labels.err"
        python3 "${_il_helper}" "${_il_files[@]}" >> "${_il_log}" 2>"${_il_err}"
        _il_rc=$?
        if [ "${_il_rc}" -ne 0 ]; then
            _il_ran=0
            _skip 12 "nc-input-labels" wiring "check_nc_select_labels.py exited ${_il_rc} — ${#_il_files[@]} component(s) were in scope and NONE were judged. See ${_il_err}."
        fi
    fi
    _il_fail=$(wc -l < "${_il_log}" 2>/dev/null || echo 0)
    [ -z "${_il_fail}" ] && _il_fail=0
    if [ "${_il_ran}" -eq 1 ]; then
        if [ "${_il_fail}" -eq 0 ]; then
            _pass 12 "nc-input-labels"
        else
            _fail 12 "nc-input-labels" "${_il_fail} NcSelect without inputLabel/ariaLabelCombobox — see ${_il_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 13: Modal-isolation — <NcModal> / <NcDialog> markup must live in
# its own file under src/modals/ or src/dialogs/, not inline in parent
# components. ADR-004 hard rule. Observed 2026-04-30 on doriath.
# ---------------------------------------------------------------------------
# THE PATTERN REQUIRED A DELIMITER ON THE SAME LINE (#321).
#
# The test was `grep -qE '<NcModal[ \t>/]|<NcDialog[ \t>/]'`. `grep` matches
# line by line, so a tag opened across several lines — which is how Vue
# components with more than one or two props are ACTUALLY written, and what
# every formatter produces —
#
#     <NcDialog
#         :open="showConfirm"
#         name="Delete lead">
#
# has nothing after `<NcDialog` on its own line. The character class cannot
# match end-of-line, so the tag was invisible.
#
# Measured 2026-08-09 on pipelinq: 0 of 9 real violations seen. The gate passed
# its own planted true positive the whole time, because a plant is written on
# one line. That is the trap this band keeps meeting — a minimal plant and a
# real defect differing in precisely the feature the regex depends on.
#
# The delimiter itself is kept, and end-of-line is added to it: `<NcDialogHeader`
# and `<NcModalX` must still NOT match, or the gate would report every
# component whose name merely starts with the same letters.
#
# COMMENTS ARE MASKED (#294's lesson, applied before it costs anything).
# Measured across the fleet: masking suppresses zero findings today, so this
# buys no reduction now — it stops `<!-- <NcDialog … -->` in a TODO from
# becoming a finding later, which is exactly how gate-20 acquired its
# commented-out call.
if [ -d src ]; then
    _mi_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-modal-isolation.log
    : > "${_mi_log}"
    : > "${_mi_log}.err"
    _mi_rc=0
    # See gate-12 above: `src/` existing is not the same as `src/` containing a
    # Vue component, and this gate printed PASS over an empty glob on nldesign
    # (whose src/ holds one manifest.json). Counted so the two cases can be told
    # apart in the reason (.github#274).
    _mi_present=0
    _mi_scoped=0
    while IFS= read -r vue; do
        case "${vue}" in
            src/modals/*|src/dialogs/*) continue ;;
        esac
        _mi_present=$((_mi_present + 1))
        _in_scope "${vue}" || continue
        _mi_scoped=$((_mi_scoped + 1))
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262). stderr is
        # kept and the status is read, so a broken interpreter reports `wiring`
        # rather than leaving an empty log this gate would call clean.
        set +e
        python3 - "${vue}" <<'PYMI' >> "${_mi_log}" 2>>"${_mi_log}.err"
import re, sys
fname = sys.argv[1]
try:
    src = open(fname, encoding='utf-8', errors='replace').read()
except Exception:
    sys.exit(0)

# HTML comments in the template, block and line comments in <script>.
# `(?<![:\w])` keeps the `//` of a `https://` URL from blanking the rest of
# the line — the same trap gate-45 hit on `url(https://…)`.
src = re.sub(r'<!--.*?-->', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), src, flags=re.DOTALL)
src = re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), src, flags=re.DOTALL)
src = re.sub(r'(?<![:\w])//[^\n]*', lambda m: ' ' * len(m.group(0)), src)

# The delimiter set, PLUS end-of-line and any other whitespace. A lookahead
# rather than a consuming class so the boundary is asserted without being
# eaten. `<NcDialogHeader` still does not match: `H` is neither whitespace,
# `>`, `/`, nor end of input.
if re.search(r'<(NcModal|NcDialog)(?=[\s>/]|$)', src):
    print(f'{fname}: inline NcModal/NcDialog — extract to src/modals/ or src/dialogs/')
PYMI
        _mi_one=$?
        [ "${_mi_one}" -ne 0 ] && _mi_rc=1
        # .vue only: NcModal/NcDialog are Vue components with a .vue-file rule.
    done < <(find src -name '*.vue' 2>/dev/null)
    _mi_fail=$(wc -l < "${_mi_log}" 2>/dev/null || echo 0)
    if [ "${_mi_rc}" -ne 0 ]; then
        _skip 13 "modal-isolation" wiring "the inline modal-isolation checker exited non-zero on at least one of ${_mi_scoped} component(s) — no verdict was produced for them; inline NcModal/NcDialog markup is UNVERIFIED by this run. See ${_mi_log}.err."
    elif [ "${_mi_scoped}" -eq 0 ]; then
        if [ "${_mi_present}" -eq 0 ]; then
            _skip 13 "modal-isolation" na "src/ exists but contains NO .vue component outside src/modals/ and src/dialogs/, so there is no parent component that could inline a modal. NcModal/NcDialog are Vue SFC components; a PHP/HTML template cannot instantiate one. Reported instead of PASS because an empty glob under an existing src/ is what let twelve gates certify nldesign in #225."
        else
            _skip 13 "modal-isolation" na "${_mi_present} .vue component(s) exist here and the diff against '${BASE_REF}' touched none of them, so no component was inspected. Diff-scoped out under ADR-020 — not a gap, and not a pass."
        fi
    elif [ "${_mi_fail}" -eq 0 ]; then
        _pass 13 "modal-isolation"
    else
        _fail 13 "modal-isolation" "${_mi_fail} file(s) with inline modal/dialog — see ${_mi_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 14: Route reachability — every Controller method that returns a
# Response type is registered in appinfo/routes.php, and every routed
# entry resolves to a method that actually exists on the named class.
# Catches the two pre-runtime bug classes documented in ADR-029:
#
#   1. Controller method exists, no route registered → router 404
#      (caught 41 instances on openregister 2026-05-01: profile-actions
#       /tmlo-metadata phantom-ticked `[x] Register route` boxes,
#       file-actions / workflow-operations / nextcloud-entity-relations
#       all shipped controllers + tests with no routes.)
#   2. Route exists, the controller class doesn't expose the method
#      (typically because the method moved during a namespace refactor)
#      → ReflectionException 500. Caught 4 instances on openregister
#      2026-05-01: Settings\SolrManagement#getObjectCollectionFields
#      etc. — methods actually live on Settings\ConfigurationSettings.
#
# Out of scope: cross-request persistence (per-instance state where
# operators expect cross-request behaviour, e.g. FileLockHandler
# pre-22c5625ef). That requires semantic understanding beyond static
# analysis; owned by code-review runtime semantics + ADR-005.
# ---------------------------------------------------------------------------
if [ -d lib/Controller ] && [ -f appinfo/routes.php ]; then
    _rr_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-route-reachability.log
    : > "${_rr_log}"

    # Touching appinfo/routes.php puts every routed entry back in scope for
    # invariant 2 — see the scope note at that loop.
    _rr_routes_touched=0
    _in_scope "appinfo/routes.php" && _rr_routes_touched=1

    # Resource auto-routes (entries inside the top-level `'resources' => [...]`
    # block) generate index/show/create/update/destroy on the named
    # controller; methods on those auto-routes are excluded from invariant 1.
    # The block's keys are PascalCase (e.g. `'Registers'`, `'Configurations'`),
    # which we lowercase-first-char to match the route-slug convention used
    # everywhere else in this gate.
    _rr_auto_resources=$(awk '
        /^[[:space:]]*.resources.[[:space:]]*=>[[:space:]]*\[/ { in_block=1; next }
        in_block && /^[[:space:]]*\][[:space:]]*,[[:space:]]*$/ { in_block=0; next }
        in_block && /^[[:space:]]*.[A-Za-z][A-Za-z0-9_]*.[[:space:]]*=>/ {
            match($0, /[A-Za-z][A-Za-z0-9_]*/); key=substr($0, RSTART, RLENGTH)
            print tolower(substr(key,1,1)) substr(key,2)
        }
    ' appinfo/routes.php | sort -u)

    # ---- Invariant 1: every Response-returning controller method has a route
    # Iterate Controller files. For each public method whose return type is
    # a Response shape, derive the expected `controller#method` name and
    # confirm a route entry exists in appinfo/routes.php.
    # SC2044: while-read instead of for-find so paths with whitespace /
    # newlines are handled safely. Process substitution keeps the loop in
    # the current shell so variable updates survive.
    while IFS= read -r _ctrl_path; do
        [ -n "${_ctrl_path}" ] || continue
        _in_scope "${_ctrl_path}" || continue

        # Derive the controller route slug — strip lib/Controller/ prefix +
        # Controller.php suffix, lowercase the first character. Settings/
        # subnamespace becomes `Settings\Foo`.
        _ctrl_short=$(echo "${_ctrl_path}" | sed -e 's|^lib/Controller/||' -e 's|Controller\.php$||')
        case "${_ctrl_short}" in
            Settings/*) _ctrl_slug="Settings\\$(echo "${_ctrl_short}" | sed 's|^Settings/||')";;
            */*)        _ctrl_slug=$(echo "${_ctrl_short}" | sed 's|/|\\|g'); _ctrl_slug=$(echo "${_ctrl_slug}" | awk -F'\\\\' '{for(i=1;i<NF;i++) printf "%s\\\\", $i; sub(/^./,tolower(substr($NF,1,1)),$NF); print $NF}');;
            *)          _ctrl_slug=$(echo "${_ctrl_short}" | awk '{print tolower(substr($0,1,1)) substr($0,2)}');;
        esac

        # Skip resource-routed controllers — their CRUD quintet is auto-generated.
        # Match against both the slug and a snake_case variant of the short name.
        _ctrl_resource=$(echo "${_ctrl_short}" | awk '{name=tolower(substr($0,1,1)) substr($0,2); print name}')
        if echo "${_rr_auto_resources}" | grep -qxF "${_ctrl_resource}"; then continue; fi

        # Each public method that returns a Response-shaped type.
        # Strategy: pcregrep-style multiline pull — for every `public function
        # X(` at the start of a line, capture the next 0-12 lines until the
        # opening `{`, and grep that buffer for any `): ...Response` return
        # type. Done in plain awk for portability (no pcregrep dependency).
        # Helper-prefixed names are excluded by the gate convention.
        _methods=$(awk -v RESPONSE_RX='Response[ |\\{]' '
            /^[[:space:]]*public[[:space:]]+function[[:space:]]+[a-zA-Z_][a-zA-Z0-9_]*[[:space:]]*\(/ {
                method = $0
                sub(/^[[:space:]]*public[[:space:]]+function[[:space:]]+/, "", method)
                sub(/[[:space:]]*\(.*$/, "", method)
                if (method ~ /^(helper|assert|validate|guard|ensure|prepare|format|render)/) next
                buf = $0
                n = 0
                while (n < 12) {
                    if ((getline nxt) <= 0) break
                    buf = buf "\n" nxt
                    n++
                    if (nxt ~ /\{[[:space:]]*$/) break
                    if (nxt ~ /:[[:space:]]*[A-Za-z\\\\|]+[[:space:]]*\{/) break
                }
                if (buf ~ /:[[:space:]]*[A-Za-z\\\\|]*Response/) print method
            }
        ' "${_ctrl_path}" | sort -u)

        for _m in ${_methods}; do
            [ -z "${_m}" ] && continue
            # Use grep -F (fixed string) on the literal `'controller#method'`
            # phrase. Avoids the `\S`-as-regex-metachar trap that hits any
            # `Settings\Foo` slug under grep -E. The narrower phrase
            # (single-quoted controller#method) is unique enough in the file
            # that false positives from comments / docstrings are vanishingly
            # rare.
            #
            # ⚠️ A literal in appinfo/routes.php is not the only way a route
            # gets declared (ConductionNL/.github#223). An ADR-040 adopter
            # returns `Routes::standard($extra)` and receives ten canonical
            # entries it never spells out — see `_apphost_supplies_route`. For
            # those apps this grep was asking the wrong file and answering "no"
            # every time, which is why five apps were told their working
            # `dashboard#page` / `settings#index` endpoints 404.
            if ! grep -qF "'${_ctrl_slug}#${_m}'" appinfo/routes.php \
                && ! _apphost_supplies_route "${_ctrl_slug}#${_m}"; then
                echo "${_ctrl_path} method=${_m} expected_route='${_ctrl_slug}#${_m}' rule=missing-route" >> "${_rr_log}"
            fi
        done
    done < <(_enum_tracked 'Controller\.php$' lib/Controller)

    # ---- Invariant 2: every routed entry resolves to a method that exists.
    #
    # THE ROUTES AN APPHOST ADOPTER NEVER SPELLS OUT ARE STILL ITS ROUTES
    # (.github#265, closed here).
    #
    # This loop reads route names as LITERALS out of appinfo/routes.php. An
    # ADR-040 adopter returns `\OCA\OpenRegister\AppHost\Routes::standard($extra)`
    # and receives ten canonical entries whose names appear nowhere in its own
    # file — so invariant 2 has never judged a single one of them. Invariant 1
    # was taught about that table in #223 (`_apphost_supplies_route`); invariant
    # 2 was not, and the two invariants ask opposite questions, so the fix to
    # one does nothing for the other.
    #
    # What that costs is not hypothetical. `Bootstrap::aliasControllerUnlessLeaf-
    # DefinesIt()` deliberately lets a leaf keep its OWN SettingsController — and
    # the moment it does, it owes every method the canonical table routes to
    # `settings#`. Delete `update()` and `PUT /api/settings` does not 404: the
    # router matches, `ControllerMethodReflector` reflects, and the request dies
    # with a ReflectionException 500. Reproduced 2026-08-08 on shillinq by
    # deleting exactly that method — gate-14's findings log came back EMPTY and
    # the gate reported PASS. shillinq's own docblock on `update()` spells the
    # hazard out; the gate was the only thing not reading it.
    #
    # The ten names are appended only when this repo's routes.php actually
    # defers to `Routes::standard()`. `_apphost_serves` / `_di_binds_controller`
    # still exempt the legitimate absences below, so an adopter that does NOT
    # ship its own controller is unaffected — the file is missing by design and
    # those helpers say so.
    {
        grep -oE "${_ROUTE_NAME_RX}" appinfo/routes.php | grep -oE "${_ROUTE_PAIR_RX}"
        if [ "${_HYDRA_APPHOST_ROUTE_TABLE}" -eq 1 ]; then
            printf '%s\n' ${_HYDRA_APPHOST_ROUTE_NAMES}
        fi
    } | sort -u \
        | while IFS='#' read -r _ctrl _method; do
            # `read -r` for the reason spelled out in gate-5's loop: plain
            # `read` strips the backslashes out of a namespaced route name, and
            # this gate then reported the resulting nonsense path as a finding.
            # Resolver is shared with gate-5 — see _ctrl_path_from_name above.
            # It lived here, inline, while gate-5 carried a narrower private
            # copy; the divergence is what let 23 of scholiq's 37 routes go
            # unjudged by gate-5.
            _path=$(_ctrl_path_from_name "${_ctrl}")
            # Diff scope: enforce when the PR touched the controller OR
            # appinfo/routes.php itself, so inherited debt doesn't bounce
            # unrelated PRs (per ADR-020) while a newly added or edited route
            # is still checked against the class it names.
            _rr_scoped=0
            [ "${_rr_routes_touched}" -eq 1 ] && _rr_scoped=1
            _in_scope "${_path}" && _rr_scoped=1
            [ "${_rr_scoped}" -eq 1 ] || continue
            if [ ! -f "${_path}" ]; then
                # UNTIL 2026-08-05 this `continue`d with the comment "gate-5
                # already flags this". Gate-5 flagged it as a MISSING AUTH
                # ATTRIBUTE, which it is not, and gate-5 no longer does
                # (ConductionNL/.github#153). The invariant is this gate's:
                # a route naming a class that does not exist is a
                # ReflectionException 500 at request time — precisely
                # invariant 2, one step earlier than a missing method.
                #
                # ADR-040 AppHost generics are the legitimate absence: the
                # class name is bound in the DI container, so the route
                # resolves with no file on disk. Two ways in — Bootstrap
                # aliasing one of its five generics, or the leaf registering
                # the generic itself under the standard controller name, or —
                # opencatalogi, 2026-08-08 — the route naming the leaf's own
                # fully-qualified class and Application.php binding that exact
                # literal. Each is a NAMED binding this repo can be shown to
                # make; an absence with no binding still fails below.
                _apphost_serves "${_ctrl}" && continue
                _di_binds_controller "${_path}" && continue
                _di_binds_fq_controller "${_ctrl}" && continue
                echo "${_path} route='${_ctrl}#${_method}' rule=controller-class-not-found" >> "${_rr_log}"
                continue
            fi
            if ! grep -qE "^[[:space:]]*public function ${_method}[[:space:]]*\(" "${_path}"; then
                echo "${_path} route='${_ctrl}#${_method}' rule=method-not-found-on-target-controller" >> "${_rr_log}"
            fi
        done

    _rr_fail=$(wc -l < "${_rr_log}" 2>/dev/null || echo 0)
    if [ "${_rr_fail}" -eq 0 ]; then
        _pass 14 "route-reachability"
    else
        _fail 14 "route-reachability" "${_rr_fail} unrouted method(s) or wrong-target route(s) — see ${_rr_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 15: Dashboard-antipattern — `type:"dashboard"` page whose custom
# widget body slot template renders `<CnDashboardPage>` (= dashboard-in-
# widget-of-dashboard), or `type:"custom"` page whose component renders
# `<CnDashboardPage>` AND is also referenced as a widget body elsewhere.
# Catches the pipelinq triple-"Dashboard" heading cascade documented in
# hydra#316. Static-grep over src/manifest.json + .vue files; runs in
# under a second on the largest apps. See
# scripts/lib/check_dashboard_antipattern.py for the brace-aware slot
# slicer + manifest walker.
# ---------------------------------------------------------------------------
if [ -f src/manifest.json ]; then
    _da_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-dashboard-antipattern.log
    : > "${_da_log}"
    _da_ran=1
    _da_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_da_lib_dir}/check_dashboard_antipattern.py" ]; then
        _da_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_da_lib_dir}/check_dashboard_antipattern.py" ]; then
        # A CRASHED CHECKER IS NOT A CLEAN ONE (.github#271).
        #
        # This used to be `>> log 2>/dev/null || true` followed by `wc -l`. A
        # helper that never started, or died on a traceback, wrote nothing —
        # so the log was empty, the count was 0, and the gate printed PASS
        # over a check that did not run. Proven 2026-08-08 by running the
        # whole suite with a python3 stub that exits 1: gate-12 and gate-17
        # said SKIPPED (wiring), gate-15 said PASS.
        #
        # stderr goes to a .err file (not /dev/null) so the reason is
        # readable, and the helper's terminal `# count=` marker is what proves
        # it reached its own summary.
        _da_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-dashboard-antipattern.err"
        : > "${_da_err}"
        python3 "${_da_lib_dir}/check_dashboard_antipattern.py" . \
            >> "${_da_log}" 2>"${_da_err}" || true
        if ! grep -qE '^# count=[0-9]+$' "${_da_log}" 2>/dev/null; then
            _da_ran=0
            _da_why=$(head -3 "${_da_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 15 "dashboard-antipattern" wiring "check_dashboard_antipattern.py did not complete — it never printed its terminal '# count=' marker, so src/manifest.json and the .vue tree were NOT inspected and nested dashboard-in-dashboard patterns are UNVERIFIED by this run. Checker output: ${_da_why:-<empty>}. See ${_da_err}."
        fi
        # Drop the marker from the findings log so it is never counted as one.
        sed -i '/^# count=[0-9]*$/d' "${_da_log}" 2>/dev/null || true
        # Filter to scope when --scope-to-diff is set — the helper reports
        # absolute paths, so strip the app-dir prefix before comparing.
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            _da_tmp=$(mktemp)
            while IFS= read -r _line; do
                [ -z "${_line}" ] && continue
                _f="${_line%%:*}"
                # Convert absolute path back to repo-relative if it lives
                # under the current pwd.
                _rel="${_f#${APP_DIR}/}"
                _in_scope "${_rel}" && echo "${_line}" >> "${_da_tmp}"
            done < "${_da_log}"
            mv "${_da_tmp}" "${_da_log}"
        fi
        _da_fail=$(wc -l < "${_da_log}" 2>/dev/null || echo 0)
    else
        _da_ran=0
        _skip 15 "dashboard-antipattern" wiring "check_dashboard_antipattern.py not found at ${_da_lib_dir} — src/manifest.json is present but was NOT inspected; nested dashboard-in-dashboard patterns are UNVERIFIED by this run."
    fi
    if [ "${_da_ran}" -eq 1 ]; then
        if [ "${_da_fail}" -eq 0 ]; then
            _pass 15 "dashboard-antipattern"
        else
            _fail 15 "dashboard-antipattern" "${_da_fail} nested-dashboard pattern(s) — see ${_da_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 16: Spec-coverage — every backend (public/protected) + frontend method
# ADDED or MODIFIED in this PR must carry an `@spec openspec/...` tag in its
# docblock / JSDoc (ADR-003 spec traceability). Diff-scoped at the METHOD
# level (ADR-020): the helper derives the changed-line set itself via
# `git diff -U0` against HYDRA_GATE_BASE_REF, so pre-existing untagged legacy
# methods never block a PR — coverage is enforced going forward only.
# Plumbing (constructors, magic methods, simple accessors, lib/Db, lib/
# Migration, lifecycle hooks, test files, main.js/bootstrap.js) is exempt.
# See scripts/lib/check_spec_coverage.py for the parse + scope logic.
# ---------------------------------------------------------------------------
if [ -d lib ] || [ -d src ]; then
    _sc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-spec-coverage.log
    : > "${_sc_log}"
    _sc_ran=1
    _sc_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_sc_lib_dir}/check_spec_coverage.py" ]; then
        _sc_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_sc_lib_dir}/check_spec_coverage.py" ]; then
        # The helper self-scopes to the PR diff; feed it our --base so a
        # non-default mainline (e.g. --base main) is honoured. Always diff-
        # scoped — a full-repo @spec sweep would flag the entire legacy
        # surface, which is the wrong contract (ADR-020).
        # A CRASHED CHECKER IS NOT A CLEAN ONE (.github#271) — same repair as
        # gate-15 above. `2>/dev/null || true` + `wc -l` reported PASS for a
        # helper that never ran; verified 2026-08-08 with a python3 stub that
        # exits 1. The terminal `# count=` marker is the evidence it finished.
        _sc_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-spec-coverage.err"
        : > "${_sc_err}"
        # EMPTY SCOPE IS NOT A PASS (.github#361). gate-16 is diff-scoped BY
        # DESIGN (see the ADR-020 note above). On a full-repo run there is no
        # diff to scope to: the runner passes BASE_REF unguarded, the checker
        # falls back to its `origin/development` default, and on `development`
        # itself that diffs the branch against itself — `# count=0` → PASS over
        # a scope nothing was ever read from.
        #
        # Measured on one tree, changing only this input: pipelinq 0 / 185
        # (origin/beta) / 1466 (report) · openregister 0 / 232 / 234 · docudesk
        # 0 / 290 / 471 · procest 0 / 113 / 445. Caught live on openregister
        # #2422, where gates 19/25/26 printed NOT APPLICABLE and gate-16 printed
        # PASS four lines apart over the same empty scope.
        #
        # gate-19 already guards this (the #242 fix, ~285 lines below); gate-16
        # was simply never given the same treatment. Deliberately NOT a literal
        # copy of gate-19's else-branch: gate-19 falls back to a full sweep,
        # which for gate-16 would flag the entire legacy @spec surface — the
        # wrong contract per ADR-020, and a false RED in every repo. Reporting
        # NOT APPLICABLE with a reason that names the ABSENCE of a diff is both
        # honest and incapable of going false-RED.
        # ALWAYS run the checker, on every scope. An earlier draft of this fix
        # skipped the invocation entirely on a full run — and the package's own
        # `test_gate_crashed_checker_is_not_a_finding.sh` caught the regression:
        # a CRASHED checker became invisible at full scope, because nothing ran
        # to crash. Running it costs nothing and keeps that invariant intact.
        HYDRA_GATE_BASE_REF="${BASE_REF}" \
            python3 "${_sc_lib_dir}/check_spec_coverage.py" . \
            >> "${_sc_log}" 2>"${_sc_err}" || true
        # Order matters: WIRING first (did the checker finish?), then SCOPE (is
        # its answer meaningful?). A crash must never be reported as an empty
        # scope, and an empty scope must never be reported as a pass.
        if ! grep -qE '^# count=[0-9]+$' "${_sc_log}" 2>/dev/null; then
            _sc_ran=0
            _sc_why=$(head -3 "${_sc_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 16 "spec-coverage" wiring "check_spec_coverage.py did not complete — it never printed its terminal '# count=' marker, so NO changed method was inspected and @spec traceability (ADR-003/ADR-020) is UNVERIFIED by this run. Checker output: ${_sc_why:-<empty>}. See ${_sc_err}."
        elif [ "${SCOPE_TO_DIFF}" != "1" ]; then
            # The checker RAN and finished — but on a full-repo run it had no
            # diff to scope to, so `# count=0` means "nothing was read", not
            # "nothing is wrong". Category MUST be one of na|structural|wiring:
            # `_skip` treats anything else as an internal-error FAIL, which is
            # exactly the false RED this change exists to prevent (my first
            # draft passed `scope` and would have done that fleet-wide).
            _sc_ran=0
            _skip 16 "spec-coverage" na "full-repo run computed NO diff, and gate-16 is diff-scoped by design (ADR-020) — so NO changed method was inspected and @spec traceability is UNVERIFIED by this run. This is NOT a pass. Re-measure with an explicit base (HYDRA_GATE_BASE_REF=origin/beta) or run with --scope-to-diff."
        fi
        sed -i '/^# count=[0-9]*$/d' "${_sc_log}" 2>/dev/null || true
        _sc_fail=$(wc -l < "${_sc_log}" 2>/dev/null || echo 0)
    else
        _sc_ran=0
        _skip 16 "spec-coverage" wiring "check_spec_coverage.py not found at ${_sc_lib_dir} — no changed method was inspected; @spec traceability (ADR-003/ADR-020) is UNVERIFIED by this run."
    fi
    if [ "${_sc_ran}" -eq 1 ]; then
        if [ "${_sc_fail}" -eq 0 ]; then
            _pass 16 "spec-coverage"
        else
            _fail 16 "spec-coverage" "${_sc_fail} changed method(s) missing @spec — see ${_sc_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 17: Redundant CRUD controllers / services (ADR-022)
# ---------------------------------------------------------------------------
# Per ADR-022 (apps-consume-or-abstractions), a controller method or service
# method whose body is a literal pass-through to OpenRegister's ObjectService
# is dead code — the frontend already hits /apps/openregister/api/objects via
# `useObjectStore` from @conduction/nextcloud-vue. Wrapping it in a
# per-schema `MeetingController::index/create/show/update/destroy` plus a
# parallel `MeetingService::create/read/update/delete` ships ~250 lines per
# schema with zero callers.
#
# This gate flags only methods whose NAME shapes like CRUD (index/show/
# create/update/delete/save/find/etc.) AND whose body's effective work is
# one ObjectService call. Domain methods named after the action (publishX,
# transitionY, generateZ, reviseAgenda) escape the filter even when their
# body is short, so state-machine wrappers that just toggle one field
# don't false-positive.
#
# Observed on decidesk#60 (2026-04-19): 5 MeetingController CRUD methods +
# 4 MeetingService CRUD methods, ~260 lines, never called from the
# frontend. Deleted in 2026-04-28 retrofit. This gate prevents the same
# pattern from recurring.
_redundant_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-redundant-controller.log
# ${SCRIPT_DIR}, resolved once at the top BEFORE the `cd "${APP_DIR}"`.
#
# This line used to re-resolve `dirname "${BASH_SOURCE[0]}"` HERE, which is
# relative to the APP directory by the time it runs. Invoked by a relative
# path — `bash dotgithub/hydra-gates/scripts/run-hydra-gates.sh /some/app`,
# which is how a human runs it — the `cd` failed and, under the `&&`, the
# whole runner ABORTED at gate 17. The remaining ~46 gates never executed.
# The run does announce "ABORTED before the summary", so it is not silently
# green, but it is a whole-suite outage triggered by nothing worse than the
# caller's choice of path spelling.
SCRIPT_DIR_REDUNDANT="${SCRIPT_DIR}"
# Use a bash array so the multi-line `${CHANGED_FILES}` (newline-separated by
# `git diff --name-only`) stays a single argument. Previously the unquoted
# `${_changed_files_arg}` expansion word-split on newlines/spaces, causing
# argparse to reject every file after the first as "unrecognized arguments"
# and the gate to fail spuriously. Observed 2026-05-27 on PR #739 canary.
_redundant_args=()
# THE SCOPE LIST GOES IN A FILE, NOT IN ARGV (#245).
#
# `--changed-files=${CHANGED_FILES}` is ONE argument, and a single argument is
# capped at MAX_ARG_STRLEN — 128 KiB on Linux — regardless of ARG_MAX being
# 2 MB. openregister's root-scoped list is 404,828 bytes across 7,224 files, so
# the exec raised E2BIG, python3 never started, the log held only the shell's
# "Argument list too long", and `grep -c '^lib/'` counted zero findings in it.
# The gate then reported `FAIL — 0 pass-through method(s)`: a crashed checker
# wearing a finding count, and a count of zero at that. Reproduced verbatim in
# openregister's root-scoped baseline.
_redundant_scope_file="${HYDRA_GATE_LOG_DIR}/hydra-gate-17-scope.txt"
if [ "${SCOPE_TO_DIFF}" = "1" ] && [ -n "${CHANGED_FILES}" ]; then
    printf '%s\n' "${CHANGED_FILES}" > "${_redundant_scope_file}" 2>/dev/null \
        && _redundant_args+=("--changed-files-file=${_redundant_scope_file}")
fi
_redundant_args+=("${APP_DIR}")
if python3 "${SCRIPT_DIR_REDUNDANT}/lib/detect-redundant-controllers.py" \
        "${_redundant_args[@]}" > "${_redundant_log}" 2>&1; then
    _pass 17 "redundant-controller"
elif ! grep -q '^# count=' "${_redundant_log}" 2>/dev/null; then
    # THE CHECKER DID NOT FINISH. Its terminal `# count=<n>` marker is absent,
    # so it never reached its own summary — E2BIG, a traceback, an OOM kill, a
    # missing interpreter. It has no verdict to give, and the old code turned
    # that into `FAIL — 0 pass-through method(s)` by counting matches in a log
    # that contains an error message rather than findings.
    #
    # A crash is a distinct state: never PASS, and never a finding count that
    # was never measured. --require-full-coverage counts this against coverage.
    _redundant_why=$(head -3 "${_redundant_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
    _skip 17 "redundant-controller" wiring "detect-redundant-controllers.py did not complete — it never printed its terminal '# count=' marker, so NO controller or service method was inspected and ADR-022 pass-through wrappers are UNVERIFIED by this run. Checker output: ${_redundant_why:-<empty>}. See ${_redundant_log}."
elif grep -q '^# count=0$' "${_redundant_log}" 2>/dev/null; then
    # The python script ran cleanly and printed its terminal `# count=0`
    # line — it found zero pass-through controllers. The non-zero exit
    # came from somewhere else (log file permission collision when the
    # same path was first written by a different uid/umask, transient
    # PHP parser error on an unrelated stub, etc.) — NOT from a real
    # finding. Treating as fail would trip Rule 0b's retry loop into
    # 40-turn-per-iter Claude sessions trying to fix code that is by
    # the script's own measure already clean. Observed 2026-05-27 on
    # pipelinq #561 canary-5: builder produced a clean leaf migration
    # (349/349 tests pass, all 19 gates green per its own self-report)
    # but Rule 0b's re-run hit the script's zero-finding non-zero exit
    # and burned 40 turns + $1+ on a fix loop with no fixable code.
    _pass 17 "redundant-controller"
else
    # `grep -c` prints the count (0 on no match); the old `|| echo 0`
    # appended a second "0" on the zero-match exit-1, so the failure
    # message read "0\n0 pass-through method(s)". Drop the fallback.
    _redundant_count=$(grep -c '^lib/' "${_redundant_log}" 2>/dev/null)
    _fail 17 "redundant-controller" "${_redundant_count:-0} pass-through method(s) — see ${_redundant_log}"
fi

# ---------------------------------------------------------------------------
# Gate 18: Notification-dialect — guard the canonical
# x-openregister-notifications dialect (ADR-031). Two checks:
#
#   (a) HARD FAIL — legacy dialect in any lib/Settings/*register*.json. The
#       obsolete dialect (singular `channel`/`recipient`, `lifecycleEnter`,
#       `trigger.calculated`, `idempotencyKey`, `alsoDispatchLifecycle`,
#       `@self.` recipient refs) was migrated off the fleet (scholiq was the
#       last holdout, since-migrated 2026-05-26). The canonical dialect uses
#       plural `channels`/`recipients` arrays, `trigger.type`, and a
#       per-locale `subject` map. Detection is scoped to the
#       x-openregister-notifications block via a JSON-parsing helper —
#       NOT a whole-file grep — because registers legitimately carry `@self.`
#       in aggregation filters and a `channel` property on unrelated schemas;
#       a whole-file grep false-positives on decidesk + scholiq (verified
#       2026-05-26) and a gate that false-positives gets disabled.
#
#   (b) WARNING (non-failing) — imperative object-notification dispatch in a
#       LEAF app: a class whose name ends `NotificationService`, a
#       `createNotification()` + `->notify(` usage, or `implements INotifier`
#       under lib/. ADR-031 says declare x-openregister-notifications instead
#       of hand-rolling an IManager::notify() dispatcher. This is a WARNING,
#       not a FAIL: decidesk (DecisionNotificationService, mid-migration) and
#       launchpad (DashboardShareService + Notification/Notifier) both carry
#       legitimate transitional/non-object-event dispatch, so a hard fail
#       would false-positive. The OpenRegister engine app itself is skipped
#       entirely (it owns lib/Service/Notification/AnnotationNotificationDispatcher.php
#       and legitimately uses IManager::notify()). The warning prints advisory
#       lines for reviewer attention but does NOT increment the failure count.
#
# See ADR-031 "The x-openregister-notifications dialect (canonical)".
# ---------------------------------------------------------------------------
_nd_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-notification-dialect.log
: > "${_nd_log}"
# OpenRegister engine marker — only the engine app ships this dispatcher.
# Its presence suppresses check (b) entirely (the engine legitimately calls
# IManager::notify()).
_nd_is_engine=0
[ -f lib/Service/Notification/AnnotationNotificationDispatcher.php ] && _nd_is_engine=1

# ---- (a) Legacy dialect in register files — HARD FAIL.
# Enumerate the SAME register-JSON surface as gates 51/54/56: `*register*.json`
# anywhere under lib/Settings PLUS every register.d/ fragment (fragments are
# named by topic — `10-bookings-*.json` — so a `*register*` name filter alone
# never sees them). The previous `-maxdepth 1 -name '*register*.json'` read 1 of
# shillinq's 147 register files (0.7%) and 2 of procest's 20 (10%): the legacy
# notification dialect was effectively unpoliced in every fragment-based app.
_nd_register_files=$(_enum_tracked '(register[^/]*\.json|/register\.d/[^/]*\.json)$' lib/Settings | _filter_files_by_scope || true)
# Tracks whether the BLOCKING half (a) actually ran. Half (b) below is a pure-
# bash advisory that needs no helper: it still runs, and still prints its
# non-blocking WARNING line, even when (a) could not run. Only (a) decides the
# gate's PASS/FAIL verdict, so only (a)'s absence turns the gate into a SKIP.
_nd_ran=1
if [ -n "${_nd_register_files}" ]; then
    _nd_lib_dir="${SCRIPT_DIR}/lib"
    if [ -f "${_nd_lib_dir}/check_notification_dialect.py" ]; then
        # A CRASHED CHECKER IS NOT A CLEAN ONE (.github#271).
        #
        # This helper's own contract is "exit 0 always; the printed lines are
        # the findings" — so ANY non-zero exit means it did not run, and the
        # old `2>/dev/null || true` turned that into an empty log and a PASS.
        # Verified 2026-08-08 with a python3 stub that exits 1: gate-18 said
        # PASS while not one register file had been opened.
        #
        # The failure count is written to a file, not a variable: the loop runs
        # in a pipeline subshell, so a variable assigned inside it does not
        # survive (the classic `while read` + pipe trap).
        _nd_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-notification-dialect.err"
        _nd_crash_marker="${HYDRA_GATE_LOG_DIR}/hydra-gate-notification-dialect.crashed"
        : > "${_nd_err}"; rm -f "${_nd_crash_marker}"
        # shellcheck disable=SC2086
        echo "${_nd_register_files}" | while IFS= read -r _rf; do
            [ -n "${_rf}" ] || continue
            python3 "${_nd_lib_dir}/check_notification_dialect.py" "${_rf}" >> "${_nd_log}" 2>>"${_nd_err}"
            _nd_rc=$?
            if [ "${_nd_rc}" -ne 0 ]; then
                echo "${_rf} (exit ${_nd_rc})" >> "${_nd_crash_marker}"
            fi
        done
        if [ -s "${_nd_crash_marker}" ]; then
            _nd_ran=0
            _nd_why=$(head -3 "${_nd_err}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _nd_crashed_n=$(wc -l < "${_nd_crash_marker}" 2>/dev/null || echo '?')
            _skip 18 "notification-dialect" wiring "check_notification_dialect.py exited non-zero on ${_nd_crashed_n} register file(s) — its contract is 'always exit 0, findings on stdout', so a non-zero exit means it did not judge the file. The obsolete legacy notification dialect (ADR-031) is UNVERIFIED for those files by this run. Checker output: ${_nd_why:-<empty>}. See ${_nd_err} and ${_nd_crash_marker}."
        fi
    else
        _nd_ran=0
        _skip 18 "notification-dialect" wiring "check_notification_dialect.py not found at ${_nd_lib_dir} — register file(s) were in scope and NONE were inspected; the obsolete legacy notification dialect (ADR-031) is UNVERIFIED by this run. The imperative-dispatch advisory below is unaffected and still ran."
    fi
fi
_nd_fail=$(wc -l < "${_nd_log}" 2>/dev/null || echo 0)

# ---- (b) Imperative dispatch in a leaf app — WARNING only (not a failure).
_nd_warn_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-notification-dialect-warn.log
: > "${_nd_warn_log}"
if [ "${_nd_is_engine}" = "0" ] && [ -d lib ]; then
    # NotificationService-named classes.
    grep -rlE 'class\s+[A-Za-z0-9_]*NotificationService\b' lib/ --include='*.php' 2>/dev/null \
        | _filter_files_by_scope \
        | sed 's/$/: class named *NotificationService (declare x-openregister-notifications instead of imperative dispatch — ADR-031)/' \
        >> "${_nd_warn_log}" || true
    # implements INotifier.
    grep -rlE 'implements\s+[A-Za-z0-9_,\ ]*\bINotifier\b' lib/ --include='*.php' 2>/dev/null \
        | _filter_files_by_scope \
        | sed 's/$/: implements INotifier (declare x-openregister-notifications instead of imperative dispatch — ADR-031)/' \
        >> "${_nd_warn_log}" || true
    # createNotification() + ->notify( in the same file.
    for _pf in $(grep -rlE 'createNotification\s*\(' lib/ --include='*.php' 2>/dev/null | _filter_files_by_scope || true); do
        [ -f "${_pf}" ] || continue
        if grep -qE '->notify\s*\(' "${_pf}" 2>/dev/null; then
            echo "${_pf}: createNotification() + ->notify() dispatch (declare x-openregister-notifications instead of imperative dispatch — ADR-031)" >> "${_nd_warn_log}"
        fi
    done
fi
_nd_warn=$(wc -l < "${_nd_warn_log}" 2>/dev/null || echo 0)

if [ "${_nd_ran}" -eq 1 ]; then
    if [ "${_nd_fail}" -eq 0 ]; then
        _pass 18 "notification-dialect"
    else
        _fail 18 "notification-dialect" "${_nd_fail} legacy-dialect token(s) in register file(s) — see ${_nd_log}"
    fi
fi
# Advisory half (b) — deliberately OUTSIDE the _nd_ran guard and deliberately
# never a failure. It is pure bash, so it ran regardless of the helper.
if [ "${_nd_warn}" -gt 0 ]; then
    echo "[gate-18] notification-dialect: WARNING — ${_nd_warn} imperative-dispatch site(s) (advisory, non-blocking) — see ${_nd_warn_log}"
fi

# ---------------------------------------------------------------------------
# Gate 19: E2e-coverage — every #### Scenario: in an openspec spec file that
# is ADDED or MODIFIED in a PR must be referenced by at least one Playwright
# e2e test file under tests/e2e/** via an @e2e annotation, OR must carry a
# reason-bearing `@e2e exclude <reason>` in its spec block. A bare
# `@e2e exclude` (no reason) is treated as non-compliant, mirroring gate-16's
# `@spec exclude` rule.
#
# Diff-scoped (ADR-020): only spec files touched by the PR are checked.
# Untouched legacy scenarios in unchanged spec files are never flagged.
#
# A whole spec can be excluded (e.g. pure-backend API contracts covered by
# Newman) by placing `@e2e exclude <reason>` after the spec's ## Purpose
# heading, which suppresses all its scenarios without per-scenario markers.
#
# See scripts/lib/check_e2e_coverage.py for the parse + annotation logic.
# See .claude/skills/hydra-gate-e2e-coverage/SKILL.md for the fix action.
# ---------------------------------------------------------------------------
if [ -d openspec/specs ] || [ -d tests/e2e ]; then
    _e2e_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-e2e-coverage.log
    : > "${_e2e_log}"
    _e2e_ran=1
    _e2e_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_e2e_lib_dir}/check_e2e_coverage.py" ]; then
        _e2e_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_e2e_lib_dir}/check_e2e_coverage.py" ]; then
        # check_e2e_coverage.py exits with a STATUS: 0 pass, 1 fail, 2 error.
        # It used to exit with the finding COUNT, which is why the count below
        # is read from stdout and never from the byte. stderr is folded into
        # the log so a traceback is visible rather than discarded — a crash
        # that printed nothing anywhere was how this gate hid before.
        # Capture the exit code directly — avoids the grep -c bug where grep
        # exits 1 on zero matches, causing "|| echo 0" to append a second "0",
        # leaving _e2e_fail="0\n0" which fails the -eq integer comparison.
        # SCOPE ONLY WHEN THE CALLER ASKED FOR IT (#242). BASE_REF was passed
        # unconditionally, so an UNSCOPED run — the mode a fleet audit uses —
        # was silently narrowed to the diff against origin/development, came
        # back empty, and the helper printed PASS over a repo it never opened.
        # Measured on openconnector: 5 findings scoped, 412 over the full tree.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_e2e_lib_dir}/check_e2e_coverage.py" . \
                >> "${_e2e_log}" 2>&1
        else
            python3 "${_e2e_lib_dir}/check_e2e_coverage.py" . \
                >> "${_e2e_log}" 2>&1
        fi
        _e2e_fail=$?
        set +e
    else
        _e2e_ran=0
        _skip 19 "e2e-coverage" wiring "check_e2e_coverage.py not found at ${_e2e_lib_dir} — no spec scenario was inspected; @e2e traceability (ADR-020) is UNVERIFIED by this run."
    fi
    if [ "${_e2e_ran}" -eq 1 ]; then
        # THE COUNT COMES FROM STDOUT. An exit code is one byte: this helper
        # once returned 266 findings as 10, and 256 findings would have left
        # as 0 — reported as PASS. It later clamped, and then a 404-finding
        # run exited 255, so the byte carried neither the count nor a status.
        # It is a status now, and the honest number is the printed one.
        _e2e_count=$(grep -oE 'FAIL — [0-9]+ scenario' "${_e2e_log}" 2>/dev/null \
            | tail -1 | grep -oE '[0-9]+' || true)
        # NO VERDICT LINE MEANS NO VERDICT (.github#271). Exit 1 is EXIT_FAIL,
        # but a helper that died before printing anything cannot honour its own
        # status contract — with a python3 that exits 1 for every invocation
        # this gate reported "FAIL — an unreported number of scenario(s)". That
        # is a finding count nobody measured, wearing a blocking verdict. If the
        # helper never printed its own `FAIL — N scenario(s)` summary, it did
        # not finish: wiring, not a fail.
        _e2e_no_verdict=0
        if [ "${_e2e_fail}" -eq 1 ] && [ -z "${_e2e_count}" ]; then
            _e2e_no_verdict=1
        fi
        [ -z "${_e2e_count}" ] && _e2e_count="an unreported number of"
        if [ "${_e2e_no_verdict}" -eq 1 ]; then
            _e2e_ran=0
            _skip 19 "e2e-coverage" wiring "check_e2e_coverage.py exited 1 (EXIT_FAIL) but never printed its own 'FAIL — N scenario(s)' summary, so it did not reach the end of its run — there is no finding count and no verdict. Reporting this as a failure would block a PR on a number nobody measured. @e2e traceability (ADR-020) is UNVERIFIED by this run. See ${_e2e_log}."
        elif [ "${_e2e_fail}" -eq 0 ]; then
            _pass 19 "e2e-coverage"
        elif [ "${_e2e_fail}" -eq 3 ]; then
            # EMPTY SCOPE. Specs exist; the diff selected none of them. Not a
            # pass — the gate inspected nothing and says so out loud (#242).
            # `na`, NOT structural (#268): nothing in this repository is
            # missing and no change the author could make would put a spec
            # file into a diff that does not touch one. See _skip's header.
            _e2e_ran=0
            _skip 19 "e2e-coverage" na "the diff against '${BASE_REF}' touched NO spec file, so no scenario was inspected. Diff-scoped out under ADR-020, exactly as gates 4/6/7 are for the same diff — not a gap: the specs in this repo are unchanged from the base branch, so this PR introduces no scenario whose @e2e traceability could be missing. This gate runs on the next PR that touches a spec. See ${_e2e_log}."
        elif [ "${_e2e_fail}" -eq 4 ]; then
            _e2e_ran=0
            _skip 19 "e2e-coverage" na "no openspec/specs/*/spec.md in this repository — there is no declared scenario for an e2e test to trace back to."
        elif [ "${_e2e_fail}" -ge 2 ]; then
            # The helper fell over. It inspected nothing, so it has no verdict
            # to give — say so instead of reporting a fail count it never
            # measured. --require-full-coverage counts this against coverage.
            _e2e_ran=0
            _skip 19 "e2e-coverage" wiring "check_e2e_coverage.py exited ${_e2e_fail} (error) — no scenario verdict was produced; @e2e traceability (ADR-020) is UNVERIFIED by this run. See ${_e2e_log}."
        else
            _fail 19 "e2e-coverage" "${_e2e_count} scenario(s) missing @e2e — see ${_e2e_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 20: OR ObjectService API — catch calls to fabricated methods that
# don't exist on OpenRegister's ObjectService. The four offenders observed
# in the wild are `findObjects(` (plural — real API is `findAll`),
# `findObject(` (singular — real API is `find`), `createFromArray(`,
# and `deleteFromId(`. PHPStan misses these because OR is baseline-
# suppressed in app repos, so they ship green and only blow up at runtime
# with `BadMethodCallException`. Observed 2026-06-06 on shillinq#123
# (PR #229 SettingsService::seedDimensions invoked `findObjects(...)`
# which would have 500'd on first import) and previously documented in
# the [[or-objectservice-api]] memory note.
#
# Real ObjectService surface (from openregister): find / findAll /
# saveObject / createObject / updateObject / deleteObject. Anything else
# named like an OR call is fabricated.
#
# Scoped to PHP files under lib/, diff-aware when --scope-to-diff is on.
#
# ---------------------------------------------------------------------------
# A PATTERN THAT STARTS WITH `-` IS AN OPTION, NOT A PATTERN (.github#271)
# ---------------------------------------------------------------------------
# This gate has never fired. Not "rarely" — never, in any repo, since it was
# written. The search was
#
#     grep -nE "->${_pat//(/\\(}" "${_file}"
#
# and the expanded pattern is `->findObjects\(`. grep parses that as OPTIONS
# because it begins with `-`:
#
#     $ grep -nE "->findObjects\(" lib/Controller/ActionsController.php
#     grep: invalid option -- '>'
#     exit 2
#
# `2>/dev/null || true` swallowed both the message and the status, so `_hits`
# came back empty for every pattern on every file, `_or_hits` stayed 0, and the
# gate printed PASS. Measured 2026-08-08 by planting a textbook
# `$this->objectService->findObjects(...)` in openregister: the gate reported
# PASS over it.
#
# Two changes, and the second matters as much as the first:
#
#  1. `--` before the pattern, so grep stops option parsing. Without this the
#     gate cannot fire at all.
#
#  2. THE RECEIVER IS NOW PART OF THE PATTERN. The old heuristic was "the FILE
#     mentions ObjectService somewhere", which is not a statement about the
#     call. Turning on (1) alone produces 14 findings on openregister and 5 on
#     shillinq that are all FALSE: `createFromArray()` is a real method on
#     OpenRegister's *Mappers* (`$this->registerMapper->createFromArray(...)`,
#     `$this->schemaMapper->createFromArray(...)`), and those files mention
#     ObjectService elsewhere. Repairing the grep without tightening the
#     receiver would swap a dead gate for a noisy one, and a noisy gate gets
#     switched off. The receiver must itself be named `*[Oo]bjectService`.
#
# With both, the fleet measurement across openregister + pipelinq + shillinq is
# exactly ONE finding, and it is real: shillinq
# lib/Controller/BookingNotificationController.php resolves
# `OCA\OpenRegister\Service\ObjectService` from the container inside its
# non-admin authorisation guard and calls `findObject(...)`, which does not
# exist on it (verified against openregister lib/Service/ObjectService.php:
# find / findAll / saveObject / deleteObject / createObject / updateObject).
#
# A grep that ERRORS (rc >= 2) is now a wiring skip, never a pass — the same
# rule the helper-backed gates in this suite follow.
#
# ---------------------------------------------------------------------------
# A COMMENTED-OUT CALL IS NOT A CALL (.github#294)
# ---------------------------------------------------------------------------
# The first thing the un-blinded gate reported in the fleet was not a call. It
# was openconnector `lib/Service/SearchService.php:189`:
#
#     // $directory = $this->objectService->findObjects(filters: [...]);
#
# a line that has been commented out. grep has no idea what a comment is, so
# the gate's very first live finding was false — and a gate whose first finding
# is false is a gate people learn to ignore.
#
# The fix is the pass gate-5 received in #196: run the file through
# `source_scope.py --mask php` first, which blanks `//`, `#` and `/* */`
# comments while PRESERVING offsets and newlines, so grep still reports the
# real line number. `#[` is left alone — it opens a PHP 8 attribute, not a
# comment, and swallowing it would delete `#[NoAdminRequired]`.
#
# String CONTENTS are kept (php_mask's default). A fabricated method name
# inside a string is not a call either, but blanking strings would delete
# evidence other gates in this file rely on, and no fleet repo has that shape.
#
# The mask is a helper, so it inherits this gate's own rule: if it cannot run,
# the gate reports `wiring` and NOT a pass. A mask that silently returned
# nothing would blank every file and make this gate green everywhere — the
# 2026-08-08 failure mode in a new costume.
# ---------------------------------------------------------------------------
if [ -d lib ]; then
    _or_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-or-objectservice-api.log
    : > "${_or_log}"
    : > "${_or_log}.err"
    _or_hits=0
    _or_ran=1
    _or_broken=""
    _or_mask="${SCRIPT_DIR}/lib/source_scope.py"
    # Receiver-anchored: the call must be made ON something named
    # `*[Oo]bjectService` — `$this->objectService->`, `$objectService->`,
    # `$this->orObjectService?->`. `$this->schemaMapper->createFromArray()` is
    # a different class's real method and is deliberately not matched.
    _OR_FABRICATED_RX='(\$this->[A-Za-z_]*[Oo]bjectService|\$[A-Za-z_]*[Oo]bjectService)\??->(findObjects|findObject|createFromArray|updateFromArray|deleteFromId)[[:space:]]*\('
    while IFS= read -r _file; do
        [ -z "${_file}" ] && continue
        [ -f "${_file}" ] || continue
        _in_scope "${_file}" || continue
        # Comments blanked BEFORE the search (#294). Offsets and newlines are
        # preserved by php_mask, so grep's -n line numbers still address the
        # real file.
        set +e
        _or_masked=$(python3 "${_or_mask}" --mask php "${_file}" 2>>"${_or_log}.err")
        _or_mrc=$?
        if [ "${_or_mrc}" -ne 0 ] || [ ! -f "${_or_mask}" ]; then
            # A MASK THAT DID NOT RUN IS NOT AN EMPTY FILE. Falling back to the
            # raw text would silently restore the #294 false positive; treating
            # its empty output as clean would make this gate green everywhere.
            _or_ran=0
            _or_broken="${_file}"
            break
        fi
        # `--` terminates option parsing. The pattern is anchored on `$`, not
        # `-`, since #271, but the guard stays: it is the whole defect.
        _hits=$(printf '%s\n' "${_or_masked}" | grep -nE -- "${_OR_FABRICATED_RX}" 2>/dev/null)
        _or_rc=$?
        if [ "${_or_rc}" -ge 2 ]; then
            # grep could not run (bad pattern, unreadable file, option-parse
            # error). It has no verdict about this file; say so rather than
            # counting its silence as clean.
            _or_ran=0
            _or_broken="${_file}"
            break
        fi
        [ -z "${_hits}" ] && continue
        while IFS= read -r _line; do
            [ -z "${_line}" ] && continue
            # The match was made on the MASK; report the ORIGINAL line, so the
            # log shows the code a reader will find at that line rather than a
            # row of spaces where a comment used to be.
            _or_no=${_line%%:*}
            _or_src=$(sed -n "${_or_no}p" "${_file}" 2>/dev/null)
            echo "${_file}:${_or_no}:${_or_src}  rule=or-objectservice-fabricated-method" >> "${_or_log}"
            _or_hits=$((_or_hits + 1))
        done <<< "${_hits}"
    done < <(_enum_tracked '\.php$' lib)
    if [ "${_or_ran}" -eq 0 ]; then
        _skip 20 "or-objectservice-api" wiring "the comment mask or grep did NOT complete on ${_or_broken} — no call site was judged from that file onward. Calls to methods that do not exist on OpenRegister's ObjectService are UNVERIFIED by this run. See ${_or_log}.err."
    elif [ "${_or_hits}" -eq 0 ]; then
        _pass 20 "or-objectservice-api"
    else
        _fail 20 "or-objectservice-api" "${_or_hits} call(s) to fabricated OR ObjectService methods (use find / findAll / saveObject / createObject / updateObject / deleteObject) — see ${_or_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 21: conflict-marker scan — catch un-resolved `<<<<<<<` / `=======` /
# `>>>>>>>` markers in tracked source. Observed 2026-06-06 on procest
# #17 (PR #46 DSOIntakeController.php), #12 (PR #41 appinfo/routes.php),
# #10 (PR #42 package.json + l10n/{en,nl}.{js,json}): the Builder's
# `fix(code-review bounded): Juan post-run mechanical commit` step
# committed raw conflict markers without resolving them, producing
# branches that no longer parsed PHP/JSON. The Codeberg "mergeable:
# true" flag is not a reliable proxy because that check only inspects
# diff base, not file syntax.
#
# This gate fails fast at build/review time, BEFORE the orchestrator
# flips a label, so the orphaned-merge state never reaches review.
#
# Scope: all PHP/JS/TS/Vue/JSON/MD files in repo (or diff-scoped when
# --scope-to-diff is on). The marker pattern is anchored to start-of-
# line + at least 7 chars + space, which is git's canonical conflict
# marker shape and unlikely to collide with prose / code.
# ---------------------------------------------------------------------------
_cm_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-conflict-markers.log
: > "${_cm_log}"
_cm_hits=0
# Match git's exact marker shapes: `<<<<<<< ` / `======= ` (end of line OK) / `>>>>>>> `
# at the start of a line. Length is exactly 7 chars of the marker glyph; the
# trailing content for << / >> is a ref name, ======= can be bare.
while IFS= read -r _file; do
    [ -z "${_file}" ] && continue
    [ ! -f "${_file}" ] && continue
    _in_scope "${_file}" || continue
    # grep -l would short-circuit but we want a line count for the log.
    _matches=$(grep -nE '^(<{7}[[:space:]]|>{7}[[:space:]]|={7}$)' "${_file}" 2>/dev/null || true)
    [ -z "${_matches}" ] && continue
    echo "${_file}:" >> "${_cm_log}"
    echo "${_matches}" | head -5 | sed 's/^/  /' >> "${_cm_log}"
    _cm_hits=$((_cm_hits + 1))
done < <(find lib appinfo src tests openspec l10n appinfo \
            \( -name '*.php' -o -name '*.js' -o -name '*.ts' \
             -o -name '*.vue' -o -name '*.json' -o -name '*.md' \
             -o -name '*.yaml' -o -name '*.yml' -o -name '*.xml' \) \
             2>/dev/null)
if [ "${_cm_hits}" -eq 0 ]; then
    _pass 21 "conflict-markers"
else
    _fail 21 "conflict-markers" "${_cm_hits} file(s) with unresolved conflict markers — see ${_cm_log}"
fi

# ---------------------------------------------------------------------------
# Gate 22: manifest-validation — every app that ships src/manifest.json must
# validate it against the canonical @conduction/nextcloud-vue schema. Per
# ADR-024 (app-manifest fleet-wide adoption) and openspec/changes/
# adopt-app-manifest. Apps without a manifest (Tier 0) are silently skipped.
#
# Behavior:
#   - No src/manifest.json   → skip (PASS quietly, no log line)
#   - Has src/manifest.json  → validate it with the HYDRA-VENDORED canonical
#     validator, scripts/lib/check_manifest.js (the same one gate-53 runs over
#     the assembled manifest): canonical ADR-040 schema, merged AppHost blocks,
#     plus the post-schema semantic checks JSON Schema cannot express.
#
# WHY THE APP'S OWN `npm run check:manifest` IS NO LONGER THE AUTHORITY
# (2026-08-03). The gate used to PREFER the app's package.json script and only
# fall back to the vendored validator. Three things follow from that, all of
# them measured:
#
#   1. The verdict was app-owned. pipelinq's `check:manifest` is a 50-line
#      structural guard that asserts four top-level keys exist; it certifies any
#      manifest as OK. An app could turn a fleet gate green by writing a weaker
#      checker — the exact "I made the gate green by lying" shape gate-35 exists
#      to catch elsewhere.
#   2. The verdict was WRONG in the other direction too. opencatalogi's and
#      shillinq's `check:manifest` runs tests/validate-manifest.js against a
#      per-app vendored schema copy, which without Ajv falls back to a hardcoded
#      "v1.x enum" that predates ADR-040. It rejects `type: "roadmap"` — a page
#      type the CANONICAL schema has accepted since 2.x. gate-22 was failing
#      apps for conforming to the standard.
#   3. Those app scripts announce their own downgrade
#      ("no schema candidate resolved; falling back to structural lint") on a
#      line the gate neither surfaced nor acted on.
#
# The app script still RUNS when defined — its output is captured and surfaced
# as an advisory line — but it can no longer decide this gate.
#
# FAIL-CLOSED ON A DEGRADED VALIDATION (exit 3). If Ajv cannot be resolved, the
# vendored validator can only run its structural lint, which checks the AppHost
# blocks and nothing else. Reporting that as PASS is a silent fallback to a
# weaker check — the defect class this package exists to remove — so it FAILs
# with a named reason instead, exactly as gate-53 already refuses to run without
# Ajv. `ajv` is in every fleet app's package-lock.json, so a `npm ci` (which CI
# does) resolves it; a bare checkout without node_modules will now say so out
# loud rather than certifying a manifest it never schema-validated.
#
# Skill: .claude/skills/hydra-gate-manifest-validation/SKILL.md
# Spec: openspec/changes/adopt-app-manifest/specs/adopt-app-manifest/spec.md
# ---------------------------------------------------------------------------
if [ -f src/manifest.json ]; then
    _mv_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-manifest-validation.log
    : > "${_mv_log}"
    _mv_validator="${SCRIPT_DIR}/lib/check_manifest.js"
    # Diff-scope: when --scope-to-diff is set and src/manifest.json was NOT
    # touched in this PR, the gate runs informationally (PASS without
    # spending time on a clean manifest the PR didn't touch).
    if [ "${SCOPE_TO_DIFF}" = "1" ] && ! _in_scope "src/manifest.json"; then
        _pass 22 "manifest-validation"
    elif [ ! -f "${_mv_validator}" ]; then
        _fail 22 "manifest-validation" "vendored validator missing at ${_mv_validator} — gate misconfiguration, fail-closed"
    elif ! command -v node >/dev/null 2>&1; then
        # WIRING, and named as such. Without this the `node …` below is a
        # command-not-found (127), which falls through to the catch-all branch
        # and is reported as "1 schema violation(s) in src/manifest.json" — a
        # missing interpreter wearing a finding's clothes, and a reason nobody
        # can act on. Same shape as gate-4 reporting "CVEs or advisories" for a
        # composer that could not run.
        _skip 22 "manifest-validation" wiring "src/manifest.json exists but \`node\` is not on PATH — the vendored canonical validator (${_mv_validator}) could not be executed, so the manifest was NOT schema-validated. This is a missing tool in the runner environment, not a finding about the manifest."
    else
        set +e
        node "${_mv_validator}" src/manifest.json >> "${_mv_log}" 2>&1
        _mv_rc=$?
        set +e
        # Advisory: run the app's own check:manifest when it exists, purely so
        # a divergence between it and the canonical validator is VISIBLE.
        _mv_app_note=""
        if [ -f package.json ] && grep -q '"check:manifest"' package.json 2>/dev/null; then
            echo "--- advisory: the app's own \`npm run check:manifest\` (NOT the gate verdict) ---" >> "${_mv_log}"
            set +e
            npm run --silent check:manifest >> "${_mv_log}" 2>&1
            _mv_app_rc=$?
            set +e
            if [ "${_mv_app_rc}" -ne 0 ]; then
                _mv_app_note=" [advisory: the app's own check:manifest also exits ${_mv_app_rc} — see ${_mv_log}]"
            fi
        fi
        if grep -q 'no schema candidate resolved\|falling back to structural lint\|Falling back to a structural lint' "${_mv_log}" 2>/dev/null; then
            echo "[gate-22] NOTE: an app-local manifest checker announced a fallback to a weaker structural lint. That line is advisory only — this gate's verdict comes from the vendored canonical validator."
        fi
        case "${_mv_rc}" in
            0)  _pass 22 "manifest-validation" ;;
            3)  _fail 22 "manifest-validation" "SCHEMA VALIDATION DID NOT HAPPEN — Ajv is not resolvable, so the vendored validator could only run its AppHost structural lint. A weaker check reported as a pass is not a pass. Run \`npm ci\` (ajv is already in package-lock.json) or set NODE_PATH; see ${_mv_log}" ;;
            2)  _fail 22 "manifest-validation" "vendored canonical schema could not be loaded — gate misconfiguration; see ${_mv_log}" ;;
            *)  _mv_fail=$(_count '^at ' "${_mv_log}")
                [ "${_mv_fail}" -eq 0 ] && _mv_fail=1
                _fail 22 "manifest-validation" "${_mv_fail} schema violation(s) in src/manifest.json — see ${_mv_log}${_mv_app_note}" ;;
        esac
    fi
fi

# ---------------------------------------------------------------------------
# Gate 23: OR abstraction anti-patterns — single shared grep gate backing the
# seven `consume-or-*-fleet-wide` umbrellas + `optional-integration-pattern`.
# Per ADR-022 (apps-consume-or-abstractions): apps must not re-grow approval
# chains, audit-trail listeners, tenant middleware, RBAC services, workflow
# engines, or hit shared-PDOK directly — those live in OpenRegister /
# openconnector. Script ships in WARN mode for the first 90 days post-
# acceptance, then auto-switches to BLOCK on the bake-in epoch.
#
# Source-of-truth script: scripts/lint-or-abstraction-anti-patterns.sh
# (covers the seven anti-pattern families; exit code 0 in WARN mode, 1 in
# BLOCK mode when any match is found).
#
# Spec refs:
#   - openspec/changes/consume-or-approval-workflow-fleet-wide/tasks.md HYDRA-1.2
#   - openspec/changes/consume-or-audit-trail-fleet-wide/tasks.md HYDRA-1.2
#   - openspec/changes/consume-or-rbac-fleet-wide/tasks.md HYDRA-1.2
#   - openspec/changes/consume-or-tenant-fleet-wide/tasks.md HYDRA-1.2
#   - openspec/changes/consume-or-workflow-engine-fleet-wide/tasks.md HYDRA-1.2
#   - openspec/changes/shared-pdok-via-openconnector/tasks.md
# ---------------------------------------------------------------------------
_or_abs_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-or-abstraction-anti-patterns.log
: > "${_or_abs_log}"
# Skip when lib/ is absent (frontend-only repo).
if [ -d lib ]; then
    _or_abs_script=""
    # Prefer the colocated script from SCRIPT_DIR so the gate runs even when
    # invoked against an app dir (the script lives in hydra/scripts/, not
    # the app dir).
    if [ -f "${SCRIPT_DIR}/lint-or-abstraction-anti-patterns.sh" ]; then
        _or_abs_script="${SCRIPT_DIR}/lint-or-abstraction-anti-patterns.sh"
    elif [ -f scripts/lint-or-abstraction-anti-patterns.sh ]; then
        _or_abs_script="scripts/lint-or-abstraction-anti-patterns.sh"
    fi
    if [ -n "${_or_abs_script}" ]; then
        # Script defaults its search root to `lib` when no arg is passed.
        if bash "${_or_abs_script}" lib >> "${_or_abs_log}" 2>&1; then
            # A WARN-MODE FINDING IS STILL A FINDING.
            #
            # The linter is bake-in gated: until its BLOCK epoch it exits 0
            # whether it matched nothing or matched thirty things. This branch
            # read only the byte, so `[gate-23] or-abstraction-anti-patterns:
            # PASS` was printed over both — byte-identical output for a clean
            # repo and for a repo full of the exact anti-patterns the gate
            # exists to name. Measured 2026-08-08: doriath, one planted
            # `lib/Service/TenantIsolationService.php`, gate said PASS and the
            # log said `[consume-or-tenant-fleet-wide] … TenantIsolationService`.
            #
            # The linter now prints `or_abstraction_findings=<n>` as its last
            # line. Read it, state it, and surface the matched rules — the same
            # treatment gate-24 already gives its WARN-only advisory lines.
            _or_abs_warn=$(sed -n 's/^or_abstraction_findings=\([0-9]*\).*/\1/p' "${_or_abs_log}" | tail -1)
            [ -z "${_or_abs_warn}" ] && _or_abs_warn=$(_count '^\s+\[' "${_or_abs_log}")
            if [ "${_or_abs_warn}" -gt 0 ]; then
                echo "  [gate-23] OR-abstraction anti-patterns — ${_or_abs_warn} rule(s) MATCHED and are reported WARN-only until the bake-in epoch (they do not fail this run, and they will once it passes):"
                grep -E '^  \[|^    ' "${_or_abs_log}" 2>/dev/null | head -40 | sed 's/^/  /'
            fi
            echo "[hydra-gates] gate-23 or-abstraction-anti-patterns: ${_or_abs_warn} rule(s) matched (see ${_or_abs_log}); $(sed -n 's/.*\bmode=\([A-Z]*\).*/\1/p' "${_or_abs_log}" | tail -1) mode."
            _pass 23 "or-abstraction-anti-patterns"
        else
            # In BLOCK mode (post bake-in epoch) the script exits 1 on any
            # match. Count the per-rule lines in the log.
            _or_abs_hits=$(_count '^\s+\[' "${_or_abs_log}")
            [ "${_or_abs_hits}" -eq 0 ] && _or_abs_hits=1
            _fail 23 "or-abstraction-anti-patterns" "${_or_abs_hits} OR-abstraction match(es) — see ${_or_abs_log}"
        fi
    else
        # A MISSING HELPER MUST NOT REPORT PASS (#147). This branch said
        # "fail-open" and then printed a PASS, which is not failing open — it
        # is reporting a verdict nobody produced.
        echo "lint-or-abstraction-anti-patterns.sh helper not found; nothing was inspected" >> "${_or_abs_log}"
        _skip 23 "or-abstraction-anti-patterns" wiring "lint-or-abstraction-anti-patterns.sh not found next to this runner nor at scripts/ in the app — lib/ exists and NOT ONE file was inspected; ADR-022 OR-abstraction duplication is UNVERIFIED by this run."
    fi
else
    _skip 23 "or-abstraction-anti-patterns" na "this repository has no lib/ directory, so there is no PHP for an app-local approval chain / audit listener / tenant middleware / RBAC service / workflow engine to live in. Nothing was inspected and nothing could be."
fi

# ---------------------------------------------------------------------------
# Gate 24: ADR-019 integration parity — every registered render-surface
# integration in @conduction/nextcloud-vue MUST declare a COMPLETE render pair
# for its declared `renderMode` (AD-11/AD-13, extended by ADR-066 decision #7):
#   * renderMode: 'component' (default) → a sidebar `tab` AND a `widget` SFC
#     pair, exactly as before (unchanged behaviour, backward compatible).
#   * renderMode: 'mount'              → a `mount(el, props)` AND an `unmount(el)`
#     function pair instead — a cross-Vue-major leaf ships no SFC tab/widget and
#     satisfies parity with the mount pair.
# The parity contract therefore moved from the literal "tab AND widget" to "a
# complete render pair for the declared renderMode", keeping the AD-11/AD-13
# guarantee that a render-surface leaf can actually render while admitting the
# mount shape. The canonical Node check ships in nextcloud-vue
# (`scripts/check-integration-parity.js`); openregister carries a thin bash
# wrapper at `scripts/check-integration-parity.sh` that locates the JS check
# (env override / installed dep / sibling checkout) and exits 0-skip when
# neither is available.
#
# This gate looks for the wrapper in the app dir; when absent (app has no
# integration descriptors of its own), it skips silently. When present, the
# wrapper's own resolution logic decides between RUN (JS check found) and
# SKIP (JS check absent — authoritative gate runs in nextcloud-vue CI). The
# orchestrator gate is the "additional safety net" called out in
# `openregister/openspec/changes/pluggable-integration-registry/tasks.md`.
#
# ADR-066 (cross-app-leaf-registration) Decision 4 — extended by Decision 7 —
# adds a server↔JS descriptor↔`id` correlation for `render-surface` leaves that
# also asserts renderMode AGREEMENT: the server `LeafDescriptor.renderMode` MUST
# equal the JS registration's `renderMode` under the shared `id`. The
# nextcloud-vue check (`scripts/check-integration-parity.js`) runs a WARN-only
# cross-reference against the repo it executes in (process.cwd() — which is the
# app dir here, since this gate cd's into it): it correlates server-side
# render-surface `LeafDescriptor` ids + renderMode (scanned from lib/**.php
# `new LeafDescriptor(...)`) against JS `registerIntegration({ id, renderMode })`
# call sites (src/**), flagging BOTH ways — a phantom render surface (a
# descriptor discoverable in the `openregister.integrations.leaves` capability
# whose JS pair never registered), an orphan JS registration (a pair with no
# server descriptor), and a renderMode MISMATCH under a shared id (server says
# 'component' while JS registered 'mount', or vice-versa). It is WARN-first per
# the fleet's gate bake-in pattern: the cross-ref NEVER changes the JS check's
# exit code, so it cannot fail this gate — advisory `⚠` lines are surfaced below
# on PASS for the bake-in epoch. Only the HARD render-pair-for-renderMode parity
# check (AD-11/AD-13, renderMode-aware per Decision 7) can fail gate-24 today,
# and only when it already hard-fails — this extension does NOT tighten the
# gate's fail posture. Promotion of the cross-ref to a blocking check, plus the
# deferred cross-repo join (this library's own builtins ↔ each consuming app's
# PHP, and a live capability-payload assertion), is tracked as an ADR-066
# follow-up.
#
# Spec ref: openspec/changes/pluggable-integration-registry/tasks.md
#           (hydra-side bullet "Add parity check to hydra quality gate");
#           openspec/architecture/adr-066-cross-app-leaf-registration.md
#           (Decision 4 — parity correlation is a gate-24 concern; Decision 7 —
#           renderMode-keyed render pair + server↔JS renderMode agreement).
# ---------------------------------------------------------------------------
#
# Like gate-33, this gate used to vanish without a trace when its prerequisite
# was absent: no scripts/check-integration-parity.sh → no output at all. It was
# measured absent in MOST fleet repos on 2026-08-03, which is why fleet coverage
# was never the declared gate count anywhere. It now says so.
_parity_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-integration-parity.log
: > "${_parity_log}"
#
# COVERAGE CLASSIFICATION (gate-24). "No parity script" was one message for two
# opposite situations, so it is split on the gate's OWN SUBJECT MATTER rather
# than on the presence of its checker — which is the only test that cannot
# drift away from what the gate actually correlates:
#
#   no LeafDescriptor and     NOT APPLICABLE. Parity is a correlation between
#   no registerIntegration    two sets. Both are empty, so there is no pair that
#                             could be phantom, orphaned, or renderMode-mismatched.
#   either one present, but   STRUCTURAL. This repo DOES register leaves and
#   no parity script          nothing correlates the two halves. A phantom
#                             render surface here is invisible, which is the
#                             exact defect ADR-066 Decision 4 exists to catch.
#
# Deciding this from `[ -f scripts/check-integration-parity.sh ]` alone is what
# made every repo in the fleet report the same skip regardless of whether it had
# any leaves at all.
_parity_has_php=0
_parity_has_js=0
if [ -d lib ] && grep -rqE 'new[[:space:]]+LeafDescriptor[[:space:]]*\(' lib/ --include='*.php' 2>/dev/null; then
    _parity_has_php=1
fi
# THE JS PROBE MUST MATCH BOTH SUPPORTED REGISTRATION APIs, NOT ONE.
#
# `registerIntegration(descriptor)` is the convenience wrapper exported from
# @conduction/nextcloud-vue (src/integrations/registry.js). The registry object
# it wraps is equally canonical and is called directly as
# `OCA.OpenRegister.integrations.register(descriptor)` — including by
# nextcloud-vue's OWN built-in leaves (src/integrations/builtin/files.js).
#
# Probing for only the wrapper made this gate produce a FALSE ABSENCE CLAIM, and
# the claim was load-bearing: an app registering leaves via the direct form was
# told "this repo registers no integration leaves at all", which routes it to
# `na` (not counted as a gap) instead of `structural` (counted). So the one gate
# that exists to correlate the server and JS halves of a leaf switched itself
# off, silently, in exactly the repos that have leaves to correlate.
#
# MEASURED across the fleet checkout, both forms are live:
#   registerIntegration(     -> hermiq/src/integration-leaf.js,
#                               openconnector/src/integration.js,
#                               procest/src/main.js
#   integrations.register(   -> decidesk/src/integrations/registerDecisionsLeaf.js,
#                               openregister/src/main.js
# decidesk registers `decidesk-decisions` through the second form and was
# reported by this gate as having no leaves at all, on a --full run, while that
# very id was present in the live JS registry.
#
# `integrations\.register` cannot collide with `unregister(` (the qualifier is
# part of the match) nor with `registerIntegrationIcons(` (the `\(` anchors it).
if [ -d src ] && grep -rqE '\b(registerIntegration|integrations\.register)[[:space:]]*\(' src/ 2>/dev/null; then
    _parity_has_js=1
fi
if [ ! -f scripts/check-integration-parity.sh ]; then
    if [ "${_parity_has_php}" = "0" ] && [ "${_parity_has_js}" = "0" ]; then
        _skip 24 "integration-parity" na "no scripts/check-integration-parity.sh, and this repo registers no integration leaves at all — no \`new LeafDescriptor(\` in lib/ and neither \`registerIntegration(\` nor \`integrations.register(\` in src/. There is no server↔JS pair for parity to correlate."
    else
        _skip 24 "integration-parity" structural "no scripts/check-integration-parity.sh, but this repo DOES register integration leaves (lib/ LeafDescriptor: ${_parity_has_php}, src/ registerIntegration: ${_parity_has_js}). server↔JS leaf parity (ADR-066 Decisions 4/7: phantom render surfaces, orphan JS registrations, renderMode mismatch) is UNVERIFIED — a leaf whose other half never registered is invisible to this run."
    fi
fi
if [ -f scripts/check-integration-parity.sh ]; then
    if bash scripts/check-integration-parity.sh >> "${_parity_log}" 2>&1; then
        # THE WRAPPER'S EXIT 0 HAS TWO MEANINGS, AND ONE OF THEM IS "I DIDN'T RUN".
        #
        # openregister's wrapper cannot always locate the canonical Node check
        # (the published @conduction/nextcloud-vue package ships no scripts/
        # dir), and in that case it prints
        #
        #   i integration parity: canonical JS check not found locally — skipping.
        #
        # and exits 0 — by design, so it does not break builds. This branch read
        # that 0 as a pass, so openregister — the repo that owns the integration
        # registry — printed `[gate-24] integration-parity: PASS` on every run
        # while correlating exactly nothing. Measured 2026-08-08: the gate said
        # PASS and its own log said "skipping" on the same run.
        #
        # A self-declared skip is classified on the gate's OWN SUBJECT MATTER,
        # exactly as the no-wrapper branch above is — not on the wrapper's byte.
        # (hermiq's wrapper is the honest shape: it exits 1 and says "Refusing
        # to report a pass" when its machinery is absent, so it never lands
        # here.)
        if grep -qiE 'skipping|not found locally|could not be located' "${_parity_log}" 2>/dev/null \
            && ! grep -qE '^(✓|✗)' "${_parity_log}" 2>/dev/null; then
            if [ "${_parity_has_php}" = "0" ] && [ "${_parity_has_js}" = "0" ]; then
                _skip 24 "integration-parity" na "scripts/check-integration-parity.sh ran but SKIPPED (it could not locate the canonical JS check), and this repo registers no integration leaves at all — no \`new LeafDescriptor(\` in lib/ and neither \`registerIntegration(\` nor \`integrations.register(\` in src/. There is no server↔JS pair for parity to correlate. See ${_parity_log}."
            else
                _skip 24 "integration-parity" structural "scripts/check-integration-parity.sh ran but SKIPPED — it could not locate the canonical JS check, so NOTHING was correlated — while this repo DOES register integration leaves (lib/ LeafDescriptor: ${_parity_has_php}, src/ registerIntegration: ${_parity_has_js}). server↔JS leaf parity (ADR-066 Decisions 4/7: phantom render surfaces, orphan JS registrations, renderMode mismatch) is UNVERIFIED by this run — this is NOT a pass. See ${_parity_log}."
            fi
        else
        # The WARN-only ADR-066 cross-ref advisory lines start with `⚠`/its
        # bullets; surface them on PASS so a phantom/orphan leaf is visible in
        # CI during the bake-in epoch (they never fail the gate).
        if grep -q '⚠ server↔JS leaf parity' "${_parity_log}" 2>/dev/null; then
            echo "  [gate-24] ADR-066 server↔JS leaf parity — advisory warnings (WARN-only):"
            grep -E '^(⚠|  - )' "${_parity_log}" 2>/dev/null | sed 's/^/    /'
        fi
        _pass 24 "integration-parity"
        fi
    else
        # WARN-only advisory lines carry "phantom"/"orphan"/"NO matching" and a
        # `⚠` header — never "missing"/"mismatch"/`^✗` — so this hard-failure
        # count excludes them by construction.
        _parity_hits=$(_count '^✗|missing|mismatch' "${_parity_log}")
        [ "${_parity_hits}" -eq 0 ] && _parity_hits=1
        _fail 24 "integration-parity" "${_parity_hits} parity violation(s) — see ${_parity_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 25: Contract-coverage — every controller method ADDED in this PR that is
# registered in appinfo/routes.php AND publicly reachable (#[PublicPage] /
# #[NoAdminRequired]) is a new network-facing endpoint and MUST be covered by an
# automated contract test: a Newman/Postman collection assertion under
# tests/integration/*.postman_collection.json that hits its URL, OR a PHPUnit
# controller test under tests/** that exercises the method, OR a reason-bearing
# `@contract exclude <reason>` in its docblock. A bare `@contract exclude` is
# non-compliant (mirrors gate-16/gate-19's exclude rule).
#
# API-layer companion to gate-19 (UI e2e) + gate-16 (spec). Closes the loop so a
# newly-exposed endpoint can never merge without a wire-contract proof. Diff-
# scoped (ADR-020): only methods whose declaration line was ADDED are checked, so
# pre-existing endpoints (legacy debt) never block a PR.
#
# See scripts/lib/check_contract_coverage.py for the route-table + diff logic.
# See .claude/skills/hydra-gate-contract-coverage/SKILL.md for the fix action.
# ---------------------------------------------------------------------------
if [ -f appinfo/routes.php ]; then
    _cc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-contract-coverage.log
    : > "${_cc_log}"
    _cc_ran=1
    _cc_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_cc_lib_dir}/check_contract_coverage.py" ]; then
        _cc_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_cc_lib_dir}/check_contract_coverage.py" ]; then
        # The helper exits with a STATUS: 0 pass, 1 fail, 2 error, 3 empty
        # scope, 4 not applicable. It used to exit with the uncovered-endpoint
        # COUNT, so the count below is read from stdout and never from the byte.
        # stderr is folded into the log so a traceback is visible rather than
        # discarded.
        #
        # SCOPE ONLY WHEN THE CALLER ASKED FOR IT (#242). BASE_REF was passed
        # unconditionally, so an unscoped run was silently narrowed to the diff
        # against origin/development, came back empty, and the helper printed
        # PASS having opened nothing. Measured on openconnector: PASS scoped,
        # 32 uncovered public endpoints over the full tree.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_cc_lib_dir}/check_contract_coverage.py" . \
                >> "${_cc_log}" 2>&1
        else
            python3 "${_cc_lib_dir}/check_contract_coverage.py" . \
                >> "${_cc_log}" 2>&1
        fi
        _cc_fail=$?
        set +e
    else
        _cc_ran=0
        _skip 25 "contract-coverage" wiring "check_contract_coverage.py not found at ${_cc_lib_dir} — appinfo/routes.php is present but NO endpoint was inspected; wire-contract coverage of newly-exposed endpoints is UNVERIFIED by this run."
    fi
    if [ "${_cc_ran}" -eq 1 ]; then
        # THE COUNT COMES FROM STDOUT, not from the byte.
        _cc_count=$(grep -oE 'FAIL — [0-9]+ new public endpoint' "${_cc_log}" 2>/dev/null \
            | tail -1 | grep -oE '[0-9]+' || true)
        [ -z "${_cc_count}" ] && _cc_count="an unreported number of"
        if [ "${_cc_fail}" -eq 0 ]; then
            _pass 25 "contract-coverage"
        elif [ "${_cc_fail}" -eq 3 ]; then
            # EMPTY SCOPE — `na`, not structural (#268). See _skip's header.
            _cc_ran=0
            _skip 25 "contract-coverage" na "the diff against '${BASE_REF}' touched NO lib/Controller file, so no endpoint was inspected. Diff-scoped out under ADR-020, exactly as gates 6/7 are for the same diff — not a gap: this PR exposes no new endpoint whose wire contract could be untested. This gate runs on the next PR that touches a controller. See ${_cc_log}."
        elif [ "${_cc_fail}" -eq 4 ]; then
            _cc_ran=0
            _skip 25 "contract-coverage" na "no appinfo/routes.php — this app exposes no routed endpoint whose wire contract could be tested."
        elif [ "${_cc_fail}" -ge 2 ]; then
            _cc_ran=0
            _skip 25 "contract-coverage" wiring "check_contract_coverage.py exited ${_cc_fail} (error) — no endpoint verdict was produced; wire-contract coverage is UNVERIFIED by this run. See ${_cc_log}."
        else
            _fail 25 "contract-coverage" "${_cc_count} new public endpoint(s) missing a contract test — see ${_cc_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 26: Visual-coverage — every new Vue page/view component ADDED in this PR
# (a .vue file added under src/views/ | src/pages/, OR a component referenced as
# a manifest `"type":"page"` entry whose file was added) MUST have a visual-
# regression proof: a spec/baseline under tests/e2e/visual/** referencing it, OR
# an e2e workflow test under tests/e2e/** that drives it, OR a reason-bearing
# `@visual exclude <reason>` marker inside the .vue file. A bare `@visual
# exclude` is non-compliant (mirrors gate-16/gate-19/gate-25's exclude rule).
#
# Visual-layer companion to gate-19 (behavioural e2e) + gate-25 (API contract).
# New screens cannot merge without a pixel/structural baseline or an audited
# waiver. Diff-scoped (ADR-020): only ADDED page components are checked, so
# untouched legacy pages never block a PR.
#
# See scripts/lib/check_visual_coverage.py for the page discovery + diff logic.
# See .claude/skills/hydra-gate-visual-coverage/SKILL.md for the fix action.
# ---------------------------------------------------------------------------
if [ -d src ]; then
    _vc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-visual-coverage.log
    : > "${_vc_log}"
    _vc_ran=1
    _vc_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_vc_lib_dir}/check_visual_coverage.py" ]; then
        _vc_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_vc_lib_dir}/check_visual_coverage.py" ]; then
        # THE EXIT BYTE IS A STATUS, NOT A COUNT (#209's defect, in gate-26).
        #
        # `check_visual_coverage.py` used to `return len(findings)` into
        # `sys.exit`, and this branch read that byte as the finding count. An
        # exit status is one byte, so the count came back mod 256. Measured on
        # openregister 2026-08-08 with 260 and then 256 planted uncovered page
        # components:
        #
        #   260 findings  -> exit 4  -> "[gate-26] FAIL — 4 new page component(s)"
        #   256 findings  -> exit 0  -> "[gate-26] visual-coverage: PASS"
        #
        # while the helper's own stdout said `FAIL — 256` on the same run. The
        # helper now returns 0/1/2/3 like gate-25's and puts the count on
        # stdout, and the count is read from there.
        #
        # stderr is folded into the log rather than discarded: `2>/dev/null`
        # turned a traceback into a silent nonzero status, which this branch
        # then printed as a confident finding count with an empty log (#245).
        #
        # SCOPE ONLY WHEN THE CALLER ASKED FOR IT (#242), same as gate-25 two
        # blocks up. BASE_REF was exported unconditionally, so a full-tree run
        # was narrowed to the diff against origin/development inside the
        # helper, came back empty, and the gate reported a verdict having
        # opened nothing. gate-26 was one of the six gates that could not be
        # reached in full-repo mode at all.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_vc_lib_dir}/check_visual_coverage.py" . \
                >> "${_vc_log}" 2>&1
        else
            python3 "${_vc_lib_dir}/check_visual_coverage.py" . \
                >> "${_vc_log}" 2>&1
        fi
        _vc_fail=$?
        set +e
    else
        _vc_ran=0
        _skip 26 "visual-coverage" wiring "check_visual_coverage.py not found at ${_vc_lib_dir} — src/ is present but NO page component was inspected; visual-regression coverage of new screens is UNVERIFIED by this run."
    fi
    if [ "${_vc_ran}" -eq 1 ]; then
        # THE COUNT COMES FROM STDOUT, not from the byte.
        _vc_count=$(grep -oE 'FAIL — [0-9]+ new page component' "${_vc_log}" 2>/dev/null \
            | tail -1 | grep -oE '[0-9]+' || true)
        [ -z "${_vc_count}" ] && _vc_count="an unreported number of"
        if [ "${_vc_fail}" -eq 0 ]; then
            _pass 26 "visual-coverage"
        elif [ "${_vc_fail}" -eq 3 ]; then
            # EMPTY SCOPE — `na`, not PASS (#268). Same reading as gate-25's.
            _vc_ran=0
            _skip 26 "visual-coverage" na "the diff against '${BASE_REF}' ADDED no page component (nothing under src/views|src/pages, no new manifest page component), so no screen was inspected. Diff-scoped out under ADR-020 — not a gap: this PR adds no screen whose appearance could be unbaselined. See ${_vc_log}."
        elif [ "${_vc_fail}" -ge 2 ]; then
            # A CRASHED CHECKER IS NOT A CLEAN TREE (#245, #249).
            _vc_ran=0
            _skip 26 "visual-coverage" wiring "check_visual_coverage.py exited ${_vc_fail} (error) — no page verdict was produced; visual-regression coverage of new screens is UNVERIFIED by this run. See ${_vc_log}."
        else
            _fail 26 "visual-coverage" "${_vc_count} new page component(s) missing a visual baseline — see ${_vc_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 27: No-phantom-cross-app-rpc — forbid the phantom cross-app *RPC*
# patterns ADR-041 (decision #2) bans. A cross-app *command* (one app
# invoking another Conduction NC app's business action) MUST use a typed
# IEventDispatcher event (the RequestedEvent + synchronous result-slot +
# ConcludedEvent recipe in ADR-041), NOT the OpenRegister integration
# registry, NOT a non-existent integration service, NOT a server-side HTTP
# call to a sibling app's REST route. Every historical cross-app delegation
# built this way was a phantom no-op (failed closed, never reached the
# target). This gate stops the pattern recurring.
#
# Four rules (see scripts/lib/check_phantom_cross_app_rpc.py):
#   A  ->getLeaf(           — registry has no getLeaf; always phantom
#   B  ->call('<appId>',…)  — quoted FLEET app id as 1st arg = registry RPC
#                             (legit dispatchers take an OBJECT 1st arg, so
#                              OpenConnector external-source dispatch never
#                              matches)
#   C  OCA\OpenRegister\Service\IntegrationService — class does not exist
#                             (real classes live under …\Service\Integration\,
#                              app-local *IntegrationService classes excluded)
#   D  IClientService ->post/->get to a SIBLING app's linkToRoute('<app>.…')
#                             WITHOUT session-forwarding (Cookie / OCS-
#                             APIRequest / requesttoken / allow_local_address).
#                             Session-forwarding in-cluster delegation is
#                             RBAC-respecting and is NOT flagged.
#
# Diff-scoped (ADR-020): only files changed vs BASE are scanned, so legacy
# debt in untouched files never blocks an unrelated PR. The canonical
# replacement recipe is in the change spec + ADR-041.
#
# See .claude/skills/hydra-gate-no-phantom-cross-app-rpc/SKILL.md for the
# detection algorithm and the fix action (the event-contract recipe).
# ---------------------------------------------------------------------------
_pcar_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-no-phantom-cross-app-rpc.log
: > "${_pcar_log}"
_pcar_files=()
# Audit lib/ PHP (the command-dispatch site) and src/ JS/Vue/TS (a phantom
# getLeaf could also live in a frontend store calling an OR endpoint).
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _pcar_files+=("$f")
done < <(find lib src \( -name '*.php' -o -name '*.vue' -o -name '*.js' -o -name '*.ts' \) \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' \
    -not -path '*/dist/*' -not -path '*/build/*' 2>/dev/null)
_pcar_ran=1
if [ "${#_pcar_files[@]}" -gt 0 ]; then
    _pcar_lib_dir="$(cd "${SCRIPT_DIR}/lib" 2>/dev/null && pwd)"
    if [ ! -f "${_pcar_lib_dir}/check_phantom_cross_app_rpc.py" ]; then
        _pcar_lib_dir="${SCRIPT_DIR}/lib"
    fi
    if [ -f "${_pcar_lib_dir}/check_phantom_cross_app_rpc.py" ]; then
        # A CRASHED CHECKER IS NOT A CLEAN TREE (#245, #249).
        #
        # This was `2>/dev/null || true`, and the helper `return 0`s on every
        # successful path — so the ONLY way it can exit nonzero is by dying.
        # That state discarded the traceback, produced an empty findings log,
        # and the count below then read 0 lines and printed PASS. A file this
        # gate could not parse and a file with no phantom RPC in it were
        # byte-identical in the output.
        set +e
        _pcar_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-no-phantom-cross-app-rpc.err"
        : > "${_pcar_err}"
        python3 "${_pcar_lib_dir}/check_phantom_cross_app_rpc.py" "${_pcar_files[@]}" \
            >> "${_pcar_log}" 2>"${_pcar_err}"
        _pcar_rc=$?
        set +e
        if [ "${_pcar_rc}" -ne 0 ]; then
            _pcar_ran=0
            _skip 27 "no-phantom-cross-app-rpc" wiring "check_phantom_cross_app_rpc.py exited ${_pcar_rc} — ${#_pcar_files[@]} PHP/Vue/JS/TS file(s) were in scope and NONE were judged; phantom cross-app RPC patterns (ADR-041) are UNVERIFIED by this run. See ${_pcar_err}."
        fi
    else
        _pcar_ran=0
        _skip 27 "no-phantom-cross-app-rpc" wiring "check_phantom_cross_app_rpc.py not found at ${_pcar_lib_dir} — ${#_pcar_files[@]} PHP/Vue/JS/TS file(s) were in scope and NONE were inspected; phantom cross-app RPC patterns (ADR-041) are UNVERIFIED by this run."
    fi
fi
# Count findings. NOTE: gates 25/26 above leave `set -e` ENABLED, so a
# `grep -c .` on an empty log (exit 1, no matches) would kill the script
# here. Disable errexit for the count, then restore. wc -l avoids the
# grep -c double-zero bug entirely (always exits 0, prints the line count).
set +e
_pcar_fail=$(wc -l < "${_pcar_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_pcar_fail}" ] && _pcar_fail=0
if [ "${_pcar_ran}" -eq 1 ]; then
    if [ "${#_pcar_files[@]}" -eq 0 ]; then
        # NOTHING WAS OPENED. An empty findings log has two causes that mean
        # opposite things, and PASS was printed for both: "every file is
        # clean" and "I selected no files". Only the first is a verdict.
        _skip 27 "no-phantom-cross-app-rpc" na "no lib/ or src/ PHP/Vue/JS/TS file is in scope for this run (no such file in the repository, or the diff touches none under ADR-020), so no cross-app call site was inspected. Nothing was judged and nothing could be."
    elif [ "${_pcar_fail}" -eq 0 ]; then
        _pass 27 "no-phantom-cross-app-rpc"
    else
        _fail 27 "no-phantom-cross-app-rpc" "${_pcar_fail} phantom cross-app RPC pattern(s) — use the ADR-041 event recipe; see ${_pcar_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 28: License triangle — every per-file `@license` PHPDoc tag in lib/ and
# the `license` field in composer.json MUST agree (Conduction convention:
# EUPL-1.2). Gate-1 (SPDX) only checks PRESENCE of the @license tag; this gate
# checks that the VALUES line up.
#
# SCOPE, stated exactly, because the name oversells it: this compares TWO
# locations — composer.json `.license` and per-file `@license` PHPDoc tags under
# lib/. It does NOT read appinfo/info.xml. The `<licence>` element is outside
# this gate's reach entirely, so nothing here can be read as a verdict on it.
# (ADR-014 currently tells apps to declare `<licence>agpl</licence>` there while
# the whole fleet declares EUPL-1.2 and the appstore's own info.xsd enumerates
# EUPL-1.2 as valid. That contradiction is an ADR decision, not a gate change,
# and is deliberately not resolved here.)
#
# WHY THE SKIP BRANCHES BELOW EXIST. Until 2026-08-05 this gate ran its
# comparison inside `if [ -n "${_composer_lic}" ] && [ -d lib ]` but called
# `_pass 28` unconditionally afterwards. A repo with no lib/ — every Python
# ExApp sidecar in the fleet: valtimo, openklant, opentalk, openzaak — therefore
# reported `[gate-28] license-triangle: PASS` having opened zero files, and the
# coverage accounting counted it as a gate that reported a result. That is the
# falsely-GREEN shape the coverage block exists to make impossible: a PASS and a
# no-op were byte-identical in the output. A gate that inspected nothing now
# says so.
# ---------------------------------------------------------------------------
_lt_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-license-triangle.log
: > "${_lt_log}"
_lt_checked=0
_composer_lic=""
if [ -f composer.json ]; then
    # composer.json's `license` may be a string ("EUPL-1.2") or an array of
    # SPDX identifiers (`["EUPL-1.2", "MIT"]` for dual-licensed projects).
    # Emit pipe-joined so the bash check can match a file's @license value
    # against ANY entry in the set.
    _composer_lic=$(python3 -c "
import json, sys
try:
    v = json.load(open('composer.json')).get('license', '')
    if isinstance(v, list):
        print('|'.join(str(x) for x in v))
    else:
        print(v)
except Exception:
    print('')
" 2>/dev/null)
fi
# `_lt_ran=0` for the two cases #172's taxonomy below cannot express: the
# helper is absent, and the DIFF SCOPE is empty. The rest — no lib/, no
# composer.json, a composer.json without a `license` — is left to that
# taxonomy, which distinguishes them deliberately. Collapsing those into one
# `na` here would undo #172.
#
# EMPTY SCOPE IS NOT A GAP (and the comment this replaces said it was).
# `_lt_files` empty has two causes that the final `else` below stated as one:
#
#   a) the repo has lib/**/*.php, but THIS DIFF touches none of them. That is
#      ADR-020 diff-scoping working — the same state gate-4 reports as NOT
#      APPLICABLE for the same diff. Nothing is missing and no change in the
#      repo could make the gate run on a diff that does not touch PHP.
#   b) lib/ exists but holds no tracked .php at all. Also no subject matter.
#
# Neither is (c) "files WERE in scope and none carried a declaration", which
# is the genuine structural gap the final `else` describes. Before this fix
# all three printed (c) — a message that is FALSE for (a): with zero files in
# scope, "0 in-scope file carried a declaration" is true only vacuously.
#
# The cost was not cosmetic. `hydra-gates-require-full-coverage` defaults to
# TRUE (#164), and a structural gap FAILS the run — so gate-28 turned RED
# every PR in the fleet whose diff does not touch lib/**/*.php, which is most
# of them. Measured on nldesign with the real runner, both arms:
#
#   diff = .github/workflows/code-quality.yml   SKIPPED (structural) -> exit 98
#   diff = 3 files under lib/                   PASS
#
# The gate was reporting the DIFF's shape as the REPOSITORY's defect.
_lt_ran=1
_lt_helper="${SCRIPT_DIR}/lib/check_license_triangle.py"
if [ -n "${_composer_lic}" ] && [ -d lib ]; then
    _lt_files=()
    _lt_tracked=0
    while IFS= read -r _php; do
        [ -z "${_php}" ] && continue
        _lt_tracked=$((_lt_tracked + 1))
        _in_scope "${_php}" || continue
        _lt_files+=("${_php}")
    done < <(_enum_tracked '\.php$' lib)
    if [ "${#_lt_files[@]}" -eq 0 ]; then
        _lt_ran=0
        if [ "${_lt_tracked}" -gt 0 ]; then
            _skip 28 "license-triangle" na "this diff touches none of the ${_lt_tracked} tracked lib/**/*.php file(s), so there is no per-file @license declaration in scope for composer.json's license=${_composer_lic} to be compared against. Diff-scoped out under ADR-020, exactly as gate-4 is for the same diff — not a gap: no change in this repository can make this gate inspect a file the diff does not contain. It runs on the next PR that touches PHP."
        else
            _skip 28 "license-triangle" na "lib/ exists but contains no tracked .php file, so there are no per-file @license declarations for composer.json's license=${_composer_lic} to be compared against. Nothing was inspected and nothing could be."
        fi
    elif [ ! -f "${_lt_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147). This is the one state
        # #172's chain would misread: with no helper, `_lt_checked` stays 0 and
        # the chain would call it `structural` ("no file carried a tag"), which
        # is a claim about the REPOSITORY. The repository is fine; the gate is
        # broken, and those must not wear the same words.
        _lt_ran=0
        _skip 28 "license-triangle" wiring "check_license_triangle.py not found at ${_lt_helper} — ${#_lt_files[@]} PHP file(s) were in scope and NONE had their licence declarations read; licence drift is UNVERIFIED by this run."
    else
        # Findings to the log (stdout), the compared-file count back here
        # (stderr). `2>&1 >>file |` duplicates the CURRENT stdout — the pipe —
        # onto fd 2 BEFORE stdout is redirected to the log, so the two streams
        # separate. The count keeps #172's PASS-only-if-something-was-compared
        # rule intact now that the reading lives in a helper.
        _lt_checked=$(python3 "${_lt_helper}" "${_composer_lic}" "${_lt_files[@]}" \
            2>&1 >> "${_lt_log}" | sed -n 's/^declared_files=//p' | head -1)
        [ -z "${_lt_checked}" ] && _lt_checked=0
    fi
fi
_lt_fail=$(wc -l < "${_lt_log}" 2>/dev/null || echo 0)
if [ "${_lt_ran}" -eq 0 ]; then
    : # the gate already declared itself via _skip above (no lib/, empty scope,
      # or a missing helper). Falling through would print a second verdict.
elif [ "${_lt_fail}" -ne 0 ]; then
    _fail 28 "license-triangle" "${_lt_fail} file(s) with @license != composer.json — see ${_lt_log}"
elif [ "${_lt_checked}" -gt 0 ]; then
    _pass 28 "license-triangle"
elif [ ! -d lib ]; then
    _skip 28 "license-triangle" na "this repo has no lib/ directory, so there are no per-file @license PHPDoc tags for composer.json's license to be compared against. Nothing was inspected and nothing could be. Typical of the Python ExApp sidecars, whose application code lives in ex_app/ and whose only PHP is phpcs-custom-sniffs/. This gate becomes applicable the moment PHP app code lands under lib/."
elif [ ! -f composer.json ]; then
    # NOT APPLICABLE, not structural. This gate compares two declarations; with
    # no composer.json there is no second declaration to compare against and no
    # change inside this repo can create one to compare. Nothing is missing —
    # the gate simply has no subject matter. (Contrast the branch below: a
    # composer.json that exists but declares no license IS a fixable gap.)
    _skip 28 "license-triangle" na "lib/ exists but this repo has no composer.json, so there is no \`license\` declaration for per-file @license tags to be compared against. This gate compares two declarations and only one exists here."
elif [ -z "${_composer_lic}" ]; then
    _skip 28 "license-triangle" structural "lib/ and composer.json both exist, but composer.json declares no \`license\` field, so there is nothing to compare per-file @license tags against. Per-file/composer license agreement is UNVERIFIED by this run. Fixable here: add \`\"license\": \"EUPL-1.2\"\` to composer.json."
else
    # Reaching here now means something it did not mean before: files WERE in
    # scope, the helper DID read them, and not one carried a declaration. The
    # empty-scope case that used to land here — and made this message false —
    # is caught above and reported as NOT APPLICABLE.
    _skip 28 "license-triangle" structural "lib/ exists and composer.json declares license=${_composer_lic}, and ${#_lt_files[@]} lib/**/*.php file(s) WERE in scope, but not one carried an @license or SPDX-License-Identifier declaration, so NOTHING was compared. Per-file/composer license agreement is UNVERIFIED by this run. (Presence of the tag is gate-1's job, not this gate's.)"
fi

# ---------------------------------------------------------------------------
# Gate 29: Gitignore-then-commit oversight — the PR adds a path to .gitignore
# AND has tracked files at exactly that path. The ignore rule only prevents
# future re-adds; existing tracked files persist until `git rm --cached <path>`.
# Observed: opencatalogi#539 (116 .phpunit.cache/ files shipped to main
# alongside a new .phpunit.cache/ ignore rule). Only fires under --scope-to-diff
# because the check is intrinsically diff-relative.
# ---------------------------------------------------------------------------
#
# WHAT THIS GATE DOES **NOT** DO, stated because two agents got it wrong on the
# same day in the surrounding code: it never calls `git check-ignore`. That
# command answers from the WORKING TREE by default and reports a file that is
# already TRACKED as "not ignored" — a clean bill of health manufactured for
# free — unless it is given `--no-index`. This gate asks the opposite,
# tracked-side question directly (`git ls-files`), so the trap does not apply.
# If a future edit reaches for `check-ignore` here, it needs `--no-index`.
#
# PASS REQUIRES THAT SOMETHING WAS OPENED. The verdict below used to be a bare
# `[ fail -eq 0 ] && PASS`, and BOTH guards above fall through to it: an
# unscoped (full-repo) run and a repo with no .gitignore each printed
# `[gate-29] gitignore-then-commit: PASS` having read zero lines of diff. That
# is byte-identical to the run that examined a real .gitignore change and found
# it clean, which is the one distinction this gate's output has to carry.
_gi_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-gitignore-then-commit.log
: > "${_gi_log}"
_gi_ran=0
_gi_new_count=0
# Negations added by this diff. Counted separately from _gi_new_count because a
# `!` line is not an ignore rule, but it IS a line this gate read and decided
# about — folding it into "nothing was added" would print a verdict that
# misdescribes the diff, and folding it into "rules checked" would claim a
# lookup that never happened. See .github#293.
_gi_neg_count=0
if [ "${SCOPE_TO_DIFF}" = "1" ] && [ -f .gitignore ]; then
    # Lines newly added to .gitignore in this PR (excluding blanks + comments)
    _new_ignores=$(git -c safe.directory='*' diff "${BASE_REF}...HEAD" -- .gitignore 2>/dev/null \
        | grep -E '^\+[^+]' | sed 's/^+//' | grep -vE '^\s*(#|$)' || true)
    _gi_ran=1
    if [ -n "${_new_ignores}" ]; then
        while IFS= read -r _pat; do
            [ -z "${_pat}" ] && continue
            # A LEADING `!` IS A NEGATION — IT UN-IGNORES (.github#293).
            #
            # This loop used to strip the bang (`sed 's|^\!||'`) and look the
            # remainder up in `git ls-files`, which inverts the rule's meaning:
            # a `!` line that matches a tracked file is the CORRECT, INTENDED
            # state, not an oversight. Measured on openbuild@development:
            #
            #   ignore_pattern=!tests/vitest/setup.js      tracked=tests/vitest/setup.js
            #   ignore_pattern=!tests/e2e/global-setup.ts  tracked=tests/e2e/global-setup.ts
            #
            # both of which exist to rescue real source from a broader
            # `**/setup*` rule three lines above them.
            #
            # That is worse than a noisy finding: the only edit that clears it
            # is DELETING the negations, which would ignore two real test
            # files — so the gate could talk an author into causing the exact
            # defect it exists to detect. It was also unclosable; openbuild
            # fixed its 5 genuine findings (openbuild#161) and this stayed at 2.
            #
            # NOTE FOR ANY FUTURE EDIT: do NOT reach for `git check-ignore` to
            # decide this. It EXITS 0 WHEN A NEGATION MATCHES, so an exit-code
            # probe reports "ignored" for a file that is explicitly un-ignored
            # — the wrong instrument in the other direction. Whether a line is
            # an ignore rule is a property of the PATTERN, answered here.
            case "${_pat}" in
                [[:space:]]*\!*|\!*)
                    case "$(printf '%s' "${_pat}" | sed -E 's|^[[:space:]]+||')" in
                        \!*) _gi_neg_count=$((_gi_neg_count + 1)); continue ;;
                    esac
                    ;;
            esac
            _gi_new_count=$((_gi_new_count + 1))
            # Strip leading slash + trailing slash to get the directory/file
            # prefix to match against `git ls-files` output.
            _prefix=$(echo "${_pat}" | sed -E 's|^/||; s|/$||')
            [ -z "${_prefix}" ] && continue
            # Skip wildcard-only entries (e.g. "*.log") — they'd match too broadly
            # in `git ls-files` and the real signal is path-prefix shape.
            case "${_prefix}" in
                \**|*\*\**) continue ;;
            esac
            # Find tracked files whose path starts with the prefix (cap at 5).
            # The prefix is DATA, not a pattern: `.phpunit.cache` was being
            # interpolated raw into an ERE, where every `.` matches any
            # character and a `+` or `(` is a syntax error that makes grep exit
            # 2 and the finding disappear. Escape it.
            _gi_rx=$(printf '%s' "${_prefix}" | sed -E 's/[][\\.^$*+?(){}|\/]/\\&/g')
            git ls-files 2>/dev/null | grep -E "^${_gi_rx}(/|$)" | head -5 \
                | while IFS= read -r _tracked; do
                    [ -z "${_tracked}" ] && continue
                    echo "ignore_pattern=${_pat} tracked_file=${_tracked} rule=gitignore-then-commit-oversight" >> "${_gi_log}"
                done
        done <<< "${_new_ignores}"
    fi
fi
_gi_fail=$(wc -l < "${_gi_log}" 2>/dev/null || echo 0)
if [ "${_gi_fail}" -ne 0 ]; then
    _fail 29 "gitignore-then-commit" "${_gi_fail} tracked file(s) at newly-ignored path(s) — see ${_gi_log}"
elif [ "${_gi_ran}" -eq 1 ] && [ "${_gi_new_count}" -gt 0 ]; then
    # The only shape that earns a PASS: a diff that really added ignore rules,
    # each of which was really looked up against the tracked file list.
    _gi_neg_note=""
    if [ "${_gi_neg_count}" -gt 0 ]; then
        _gi_neg_note=" ${_gi_neg_count} negation(s) (\`!\`) were NOT looked up — a negation un-ignores, so a tracked file behind one is the intended state (.github#293)."
    fi
    echo "[hydra-gates] gate-29 gitignore-then-commit: ${_gi_new_count} newly-added .gitignore rule(s) checked against the tracked file list; none has tracked files behind it.${_gi_neg_note}"
    _pass 29 "gitignore-then-commit"
elif [ "${_gi_ran}" -eq 1 ] && [ "${_gi_neg_count}" -gt 0 ]; then
    # The diff added lines, but every one of them was a negation. Saying
    # "adds no non-comment line" here (the branch below) would misdescribe the
    # diff, and PASS would claim a lookup that never happened.
    _skip 29 "gitignore-then-commit" na "the diff adds ${_gi_neg_count} .gitignore line(s) and every one is a NEGATION (\`!\`), which un-ignores rather than ignores. A tracked file behind a negation is the intended state, so there is no new ignore rule whose path could already be tracked. See .github#293."
elif [ "${SCOPE_TO_DIFF}" != "1" ]; then
    _skip 29 "gitignore-then-commit" na "this check is intrinsically diff-relative — it asks whether THIS change adds an ignore rule over files that are already tracked — and this run is not --scope-to-diff, so there is no set of newly-added rules to look up. Nothing was inspected and nothing could be. It runs on every PR."
elif [ ! -f .gitignore ]; then
    _skip 29 "gitignore-then-commit" na "this repository has no .gitignore, so this diff cannot have added an ignore rule over already-tracked files."
else
    _skip 29 "gitignore-then-commit" na "the diff against '${BASE_REF}' adds no non-comment line to .gitignore, so there is no new ignore rule whose path could already be tracked. Diff-scoped out under ADR-020."
fi

# ---------------------------------------------------------------------------
# Gate 30: Public-monitoring — controllers with monitoring-shaped route names
# (metrics, health, liveness, readiness, probe) MUST be annotated `#[PublicPage]`
# / `@PublicPage`. Without it, NC middleware defaults to admin-login-required
# and the route silently 401s/redirects to /login for the actual consumer
# (Prometheus scraper, kubelet, external uptime monitor). Gate-5 (route-auth)
# only verifies SOME annotation is present — this gate verifies the right one
# is present for monitoring callers.
#
# THREE DEFECTS FIXED (ConductionNL/.github#213, #218)
# ---------------------------------------------------
# (a) THE SELECTOR WAS LOWERCASE-ONLY. `(metrics|health|…)` under `grep -E`
#     matches no capital, so `AppHost\Controller\GenericHealth#index`,
#     `genericMetrics#index` and `chatHealth#…` matched NOTHING. Four repos
#     — openregister among them — reported PASS over a 0-byte log while
#     shipping exactly the endpoints this gate exists for. A gate that
#     selects zero inputs and prints PASS is indistinguishable from a gate
#     that checked everything, which is the whole failure mode.
#
# (b) `read` WITHOUT -r, the same defect as gates 5 and 14: a namespaced
#     route name lost its backslash before the file lookup.
#
# (c) IT MATCHED ANY CONTROLLER WITH 'health' IN THE NAME, and its remedy was
#     a security regression. launchpad's `healthPing#show` is a per-placement
#     status badge: `GET /api/health-ping/{placementId}`, 401 anonymous, then
#     `canViewPlacement()` BEFORE any work is done. It is not a scrape target
#     and adding `#[PublicPage]` to satisfy this gate would publish an
#     outbound-ping oracle to anonymous callers.
#
#     The discriminator is not the name — it is the SHAPE OF THE ROUTE. A
#     Prometheus scraper, a kubelet probe or an uptime monitor has no session
#     and no object in mind: it issues a GET against a FIXED url. So a
#     monitoring endpoint for the purposes of this gate is a name-matching
#     route that is ALSO an unparameterised GET. `healthPing#show` carries a
#     `{placementId}` (per-object, so per-object authorisation is correct and
#     required); `healthPing#validate` is a POST (it submits a candidate
#     config). Neither is something a monitor can call. `health#index` on
#     `/api/health` still is, and is still enforced strictly.
#
# HOW THIS GATE MAY END (acceptance: never a silent green)
# -------------------------------------------------------
# PASS is reserved for a run that actually OPENED a monitoring method. Every
# other outcome says which of the four it was, with counts, so "nothing to
# check" can never again be printed as "checked and fine".
# ---------------------------------------------------------------------------
_pm_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-public-monitoring.log
_pm_notes=${HYDRA_GATE_LOG_DIR}/hydra-gate-public-monitoring-notes.log
: > "${_pm_log}"
: > "${_pm_notes}"
_pm_candidates=0
_pm_targets=0
_pm_scoped=0
_pm_inspected=0
_pm_absent=0
if [ -f appinfo/routes.php ] && [ -d lib/Controller ]; then
    # Pull every route entry as `name<TAB>url<TAB>verb`. A route's url and verb
    # are part of the evidence now (see (c) above), and grepping the name alone
    # cannot see them. Entries are one-per-line in every fleet routes.php, but
    # `requirements` sometimes wraps onto the next line, so the record is
    # extended until both url and verb are in hand (bounded at 4 lines).
    _pm_entries=$(awk '
        /['"'"'"][[:space:]]*name[[:space:]]*['"'"'"][[:space:]]*=>/ {
            buf = $0; n = 0
            while (n < 4 && (buf !~ /['"'"'"]url['"'"'"][[:space:]]*=>/ || buf !~ /['"'"'"]verb['"'"'"][[:space:]]*=>/)) {
                if ((getline nxt) <= 0) break
                buf = buf " " nxt
                n++
            }
            name = _val(buf, "name"); url = _val(buf, "url"); verb = _val(buf, "verb")
            if (name != "") print name "\t" url "\t" verb
        }
        function _val(s, key,   rx, seg, q, rest, endq) {
            rx = "[\"'"'"']" key "[\"'"'"'][[:space:]]*=>[[:space:]]*[\"'"'"']"
            if (match(s, rx) == 0) return ""
            rest = substr(s, RSTART + RLENGTH)
            q = substr(s, RSTART + RLENGTH - 1, 1)
            endq = index(rest, q)
            if (endq == 0) return ""
            return substr(rest, 1, endq - 1)
        }
    ' appinfo/routes.php 2>/dev/null)

    # -i, so a capitalised or camelCased monitoring word is seen. Defect (a).
    _pm_monitoring_rx='^[A-Za-z0-9_\]*(metrics|health|liveness|readiness|probe)[A-Za-z0-9_]*#[A-Za-z0-9_]+$'

    while IFS=$'\t' read -r _pm_name _pm_url _pm_verb; do
        [ -n "${_pm_name}" ] || continue
        printf '%s' "${_pm_name}" | grep -qiE "${_pm_monitoring_rx}" || continue
        _pm_candidates=$((_pm_candidates + 1))

        # Defect (c): only an unparameterised GET is something an external
        # monitor can call. Anything else is a domain endpoint that happens to
        # have a monitoring word in its name, and demanding #[PublicPage] on it
        # is asking for an authorisation bypass.
        case "${_pm_verb}" in
            GET|get|Get) ;;
            *) echo "${_pm_name} — not a monitoring scrape target: verb is '${_pm_verb}', a monitor issues GET" >> "${_pm_notes}"; continue ;;
        esac
        case "${_pm_url}" in
            *'{'*) echo "${_pm_name} — not a monitoring scrape target: url '${_pm_url}' is per-object (has a {placeholder}); its caller is a UI with a session, and per-object authorisation belongs here" >> "${_pm_notes}"; continue ;;
        esac
        _pm_targets=$((_pm_targets + 1))

        # `read -r` above (defect (b)) plus the SHARED resolver, so a
        # namespaced route name lands on the file gates 5 and 14 use. The
        # private `awk -F'_'` copy this gate carried could not spell
        # `AppHost\Controller\GenericHealth` at all.
        _pm_ctrl="${_pm_name%%#*}"
        _pm_method="${_pm_name#*#}"
        _ctrl_path=$(_ctrl_path_from_name "${_pm_ctrl}")
        if [ ! -f "${_ctrl_path}" ]; then
            # THE PSR-4 GUESS IS NOT THE ONLY PLACE THE CLASS CAN LIVE.
            #
            # A route name like `AppHost\Controller\GenericHealth#index` maps
            # by convention to lib/Controller/AppHost/Controller/…, but
            # openregister DI-BINDS that controller name to
            # OCA\OpenRegister\AppHost\Controller\GenericHealthController,
            # which sits at lib/AppHost/Controller/GenericHealthController.php.
            # The guess misses, `_apphost_serves` then matches on the name, and
            # this gate declared the file "not in this repository" — inside the
            # repository that contains it. Measured 2026-08-08: with
            # `#[PublicPage]` deleted from openregister's own
            # GenericHealthController, in a diff that touched that very file,
            # gate-30 still reported NOT APPLICABLE. The fleet's /api/health and
            # /api/metrics were judged by this gate nowhere, including at home.
            #
            # So: before giving up, look for the class in this repo by its
            # basename, and accept it only when exactly one tracked file under
            # lib/ carries it. One match is an identification; two are a guess,
            # and a guess is what produced the wrong answer above.
            _pm_base="$(basename "${_ctrl_path}")"
            _pm_found=$(_enum_tracked "/${_pm_base}\$" lib 2>/dev/null | head -3)
            if [ "$(printf '%s\n' "${_pm_found}" | grep -c . )" = "1" ] && [ -f "${_pm_found}" ]; then
                echo "${_pm_name} — resolved to ${_pm_found} (the PSR-4 path ${_ctrl_path} does not exist; this controller is DI-bound under a different directory). JUDGED." >> "${_pm_notes}"
                _ctrl_path="${_pm_found}"
            fi
        fi
        if [ ! -f "${_ctrl_path}" ]; then
            _pm_absent=$((_pm_absent + 1))
            if _apphost_serves "${_pm_ctrl}" || _di_binds_controller "${_ctrl_path}"; then
                echo "${_pm_name} — served by the OpenRegister AppHost generic controller (ADR-040); its posture lives in the openregister package and is NOT visible here" >> "${_pm_notes}"
            else
                echo "${_pm_name} — ${_ctrl_path} is not present in this repository; NOT JUDGED (reachability is gate-14's)" >> "${_pm_notes}"
            fi
            continue
        fi
        _in_scope "${_ctrl_path}" || { echo "${_pm_name} — ${_ctrl_path} not touched by this diff (ADR-020)" >> "${_pm_notes}"; continue; }
        _pm_scoped=$((_pm_scoped + 1))
        _ctrl="${_pm_ctrl}"
        _method="${_pm_method}"
        _method_line=$(grep -nE "^[[:space:]]*public function ${_method}[[:space:]]*\(" "${_ctrl_path}" | head -1 | cut -d: -f1)
        if [ -z "${_method_line}" ]; then
            echo "${_pm_name} — ${_ctrl_path} exists but has no public function ${_method}(); NOT JUDGED (gate-14 owns that)" >> "${_pm_notes}"
            continue
        fi
        _pm_inspected=$((_pm_inspected + 1))
            # Inspect annotations above the method declaration (up to 20 lines back)
            # Same window helper as gate-5. A monitoring controller's docblock
            # is routinely longer than 20 lines (a `@psalm-return` shape, a
            # metrics table), and this gate would then miss the `#[PublicPage]`
            # sitting just above it — a false positive whose only remedy is to
            # add an attribute that is already there.
            _annotations=$(_head_block "${_ctrl_path}" "${_method_line}")
            # Folded for the carve-out test below: the slug may be
            # `GenericMetrics` or `AppHost\Controller\GenericMetrics`, and a
            # case-sensitive `*metrics*` sees neither — the same lowercase-only
            # blindness as defect (a), which would have applied the strict
            # health rule to every capitalised metrics endpoint in the fleet.
            _ctrl_fold=$(printf '%s' "${_ctrl}" | tr '[:upper:]' '[:lower:]')
            # An explicitly declared ADMIN posture is an answer too. What this
            # gate is really preventing is an ACCIDENTAL posture: in Nextcloud
            # the absence of `#[NoAdminRequired]` IS the admin gate, so a
            # deliberate admin-only endpoint and a forgotten attribute look
            # identical in the source. `#[AuthorizedAdminSetting(...)]` is the
            # one positive, code-level way to say "admin required" and settles
            # it either way.
            # Anchored to ATTRIBUTE position (`#[` at the start of a line) or
            # PHPDoc-TAG position (`* @`), never as a loose substring. The
            # 20-line window is a blind slice, so it routinely contains a class
            # docblock, and prose about an attribute is not an attribute: a
            # sentence reading "no #[PublicPage] here on purpose" was closing
            # this gate. Same anchoring gate-9 already applies for the same
            # reason (openregister#1419, 8 of 10 findings were prose).
            _pm_ok='^[[:space:]]*#\[PublicPage\]|^[[:space:]]*\*[[:space:]]*@PublicPage\b|^[[:space:]]*#\[AuthorizedAdminSetting\('
            case "${_ctrl_fold}" in
                *metrics*)
                    # ADR-006 makes /api/metrics admin-only ON PURPOSE, and the
                    # engine that owns the decision says so in prose rather than
                    # in an attribute: openregister's GenericMetricsController
                    # carries only #[NoCSRFRequired] and documents "admin-only,
                    # ADR-006" — while its GenericHealthController IS
                    # #[PublicPage]. Demanding #[PublicPage] on metrics asks the
                    # fleet to publish its metrics to anonymous callers to
                    # satisfy a gate, which is the gate overriding the
                    # architecture it exists to encode.
                    #
                    # A stated admin-only posture therefore counts here, and
                    # ONLY here. It is weaker evidence than an attribute — prose
                    # can lie — but the alternative is a finding whose only
                    # remedy is a security regression. health / liveness /
                    # readiness / probe keep the strict requirement; the engine
                    # agrees with the gate on those.
                    _pm_ok="${_pm_ok}"'|^[[:space:]]*\*.*[Aa]dmin-only'
                    ;;
            esac
            if ! echo "${_annotations}" | grep -qE "${_pm_ok}"; then
                echo "${_ctrl_path}:${_method_line} method=${_method} rule=monitoring-endpoint-missing-public-page" >> "${_pm_log}"
            fi
    # Process substitution, NOT a pipeline: the five counters below decide
    # whether this gate is allowed to say PASS, and a `| while` runs the body
    # in a subshell where every one of them stays 0. That is precisely how a
    # gate reports "checked, fine" having opened nothing.
    done < <(printf '%s\n' "${_pm_entries}")
fi
_filter_preexisting "${_pm_log}"
_pm_fail=$(wc -l < "${_pm_log}" 2>/dev/null || echo 0)
# ---------------------------------------------------------------------------
# THE VERDICT. PASS requires that a monitoring method was actually OPENED.
#
# Until 2026-08-08 this was `[ fail -eq 0 ] && PASS`, and an empty findings log
# was the only input. An empty log has two causes that mean opposite things —
# "every monitoring endpoint declares its posture" and "I selected no inputs" —
# and the lowercase-only selector (defect (a)) made the second one common:
# openregister, which OWNS the fleet's health/metrics engine, printed PASS over
# a 0-byte log on every run. Each branch below states its counts so the reader
# can tell which of the four happened without re-running anything.
# ---------------------------------------------------------------------------
if [ "${_pm_fail}" -gt 0 ]; then
    _fail 30 "public-monitoring" "${_pm_fail} monitoring endpoint(s) missing @PublicPage — see ${_pm_log}"
elif [ "${_pm_inspected}" -gt 0 ]; then
    # State the size of the evidence. A PASS with no number attached is the
    # thing this whole block exists to stop being writable: gate-30 printed one
    # over 0 inputs for months. Does NOT start with `[gate-` so it cannot be
    # mistaken for a verdict line by any `^\[gate-` consumer — same convention
    # as gate-5's NOT-JUDGED line.
    echo "[hydra-gates] gate-30 public-monitoring: ${_pm_inspected} monitoring endpoint(s) inspected of ${_pm_candidates} name-matched candidate(s)${_pm_targets:+; ${_pm_targets} scrape target(s)}. See ${_pm_notes} for every candidate this run did NOT judge, and why."
    _pass 30 "public-monitoring"
elif [ ! -f appinfo/routes.php ] || [ ! -d lib/Controller ]; then
    _skip 30 "public-monitoring" na "no appinfo/routes.php + lib/Controller in this repository — there is no routed monitoring endpoint to judge."
elif [ "${_pm_candidates}" -eq 0 ]; then
    _skip 30 "public-monitoring" na "appinfo/routes.php declares no route whose name contains metrics/health/liveness/readiness/probe."
elif [ "${_pm_targets}" -eq 0 ]; then
    _skip 30 "public-monitoring" na "${_pm_candidates} route name(s) contain a monitoring word but NONE is a monitoring scrape target (an unparameterised GET) — see ${_pm_notes}. Per-object and write endpoints keep their own authorisation; this gate deliberately did not judge them."
elif [ "${_pm_absent}" -eq "${_pm_targets}" ]; then
    _skip 30 "public-monitoring" na "${_pm_targets} monitoring endpoint(s) are routed here but their controller class is not in this repository (ADR-040 AppHost generics) — see ${_pm_notes}. Their posture lives in the openregister package; NOT a pass for them."
elif [ "${_pm_scoped}" -eq 0 ]; then
    _skip 30 "public-monitoring" na "${_pm_targets} monitoring endpoint(s) found; none of their controllers is touched by this diff (ADR-020) — see ${_pm_notes}."
else
    _skip 30 "public-monitoring" structural "${_pm_targets} monitoring endpoint(s) found and ${_pm_scoped} controller file(s) opened, but NOT ONE routed method could be located — see ${_pm_notes}. The posture of those endpoints is UNVERIFIED by this run."
fi
# ---------------------------------------------------------------------------
# Gate 31: Img-alt — every `<img>` tag in .vue files must declare an `alt` /
# `:alt` / `v-bind:alt` attribute. Per WCAG 2.2 AA SC 1.1.1 (Non-text Content),
# `<img>` elements need a text alternative; decorative images get `alt=""`.
# Without it, screen-reader users hear the image filename or nothing.
#
# Scope: literal `<img ...>` tags in the RENDERED MARKUP of the file.
# Excludes `<NcAvatarMenu>` / `<NcUserBubble>` / other component wrappers —
# those handle accessibility internally per their own component contract.
#
# PROSE IS NOT MARKUP (#220, #235).
#
# This gate used to `tr '\n' ' '` the whole file and grep the result, so
# `<script>`, `<style>` and every comment were scanned as if they rendered.
# Measured: on openbuild ALL THREE findings were JSDoc lines reading
# `* @param {Event} e - The `<img>` `error` event`, and on launchpad the one
# finding was a docblock explaining that CnDashboardIcon resolves a URL to an
# `<img>`. Neither repository contained an unlabelled image. The log itself
# said so — a real tag prints with its attributes, those printed as the bare
# four characters `<img>`.
#
# Extraction moved to scripts/lib/check_markup_a11y.py, which reads the
# markup scope only (source_scope.markup_mask) and ends an element at a `>`
# that is not inside a quoted attribute value. The RULE is unchanged — same
# accepted alt spellings — so the numbers stay comparable.
#
# References:
#   - ADR-010 (NL Design — WCAG 2.2 AA)
#   - openspec/architecture/wcag-coverage.md SC 1.1.1
#   - ConductionNL/.github#220, #235
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _ia_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-img-alt.log
    : > "${_ia_log}"
    _ia_ran=1
    _ia_helper="${SCRIPT_DIR}/lib/check_markup_a11y.py"
    _ia_files=()
    while IFS= read -r vue; do
        [ -f "${vue}" ] || continue
        _in_scope "${vue}" || continue
        _ia_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_ia_files[@]}" -eq 0 ]; then
        # NOTHING WAS OPENED, so there is no verdict to give. The comment that
        # used to sit here said "the verdict below describes the diff" — it did
        # not: the verdict below was a bare `[ fail -eq 0 ] && PASS`, so an
        # empty scope printed `[gate-31] img-alt: PASS`, byte-identical to a run
        # that read every .vue in the app and found every image labelled. That
        # is the shape the whole a11y band was falsely green in on nldesign
        # (zero .vue files, eleven gates PASS).
        _ia_ran=0
        _skip 31 "img-alt" na "no markup file (src|templates|appinfo/templates **/*.vue|php|html) is in scope for this run — the repository has none, or the diff touches none under ADR-020. No <img> was inspected and none could be."
    elif [ ! -f "${_ia_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _ia_ran=0
        _skip 31 "img-alt" wiring "check_markup_a11y.py not found at ${_ia_helper} — ${#_ia_files[@]} markup file(s) were in scope and NONE were inspected; images without a text alternative (WCAG 1.1.1) are UNVERIFIED by this run."
    else
        set +e
        _ia_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-img-alt.err"
        python3 "${_ia_helper}" --rule img-alt "${_ia_files[@]}" >> "${_ia_log}" 2>"${_ia_err}"
        _ia_rc=$?
        if [ "${_ia_rc}" -ne 0 ]; then
            # A CRASHED CHECKER IS NOT A CLEAN FILE (#245, #249). stderr kept.
            _ia_ran=0
            _skip 31 "img-alt" wiring "check_markup_a11y.py exited ${_ia_rc} — ${#_ia_files[@]} markup file(s) were in scope and NONE were judged. See ${_ia_err}."
        fi
    fi
    _ia_fail=$(wc -l < "${_ia_log}" 2>/dev/null || echo 0)
    [ -z "${_ia_fail}" ] && _ia_fail=0
    if [ "${_ia_ran}" -eq 1 ]; then
        if [ "${_ia_fail}" -eq 0 ]; then
            # State the size of the evidence: a PASS with no number attached is
            # what eleven a11y gates printed on nldesign, which ships zero .vue
            # files. Not prefixed `[gate-` so no `^\[gate-` consumer reads it as
            # a verdict — same convention as gate-30's.
            echo "[hydra-gates] gate-31 img-alt: ${#_ia_files[@]} markup file(s) inspected, 0 unlabelled <img>."
            _pass 31 "img-alt"
        else
            _fail 31 "img-alt" "${_ia_fail} <img> tag(s) without alt attribute — see ${_ia_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 32: Semantic-controls — `<div>` / `<span>` / `<a>` (without href) /
# generic block elements carrying a `@click` (or `v-on:click`) handler MUST
# also declare `role="button"` (or another interactive role) AND a `tabindex`
# AND a keyboard handler (`@keydown` / `@keyup` / `@keypress`). Without all
# three, the control is mouse-only — fails WCAG 2.2 AA SC 2.1.1 (Keyboard)
# and 4.1.2 (Name, Role, Value) because screen readers see a non-interactive
# element with a click handler.
#
# The right fix is almost always to use `<NcButton>` / `<button>` / `<a href>`
# — built-in keyboard handling, focus styling, and correct role.
#
# Scope: literal HTML tags in `.vue` templates. Component wrappers
# (`<NcButton>`, `<NcActionButton>`, `<NcListItem>`, etc.) handle this
# internally and are not in scope. `<a href="...">` is excluded because a
# real anchor is keyboard-accessible by default.
#
# References:
#   - ADR-010 (NL Design — WCAG 2.2 AA)
#   - openspec/architecture/wcag-coverage.md SC 2.1.1, 4.1.2
# ---------------------------------------------------------------------------
# A COMMENT IS NOT AN ELEMENT — IN EITHER DIRECTION (#236).
#
# This gate flattened the whole file and grepped it, so the explanatory
# comment above a repaired element was itself scored as the element. Measured
# on softwarecatalog: three files reported, the logged "tag" was `<div
# @click>` with no attributes — the comment, not the markup — and REWORDING
# THE COMMENTS took the gate FAIL(3) -> PASS with the elements byte-identical
# across both runs.
#
# The false positive is the cheap half. The expensive half is that a
# genuinely bad element could be explained away by writing a comment about
# it, and writing a comment is the natural next step for someone chasing this
# gate. Scanning proceeds over markup scope only (comments and <script>
# blanked) via scripts/lib/check_markup_a11y.py, which also ends an element at
# a `>` outside a quoted value — so a `@click` written after
# `:title="o.find(x => x.id)"` is now visible, which the old `[^>]*` could
# not see at all.
#
# The RULE is carried over unchanged: same required trio, same `<a href>` and
# bare-`@click.stop` exemptions.
#
#   - ConductionNL/.github#236
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _sc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-semantic-controls.log
    : > "${_sc_log}"
    _sc_ran=1
    _sc_helper="${SCRIPT_DIR}/lib/check_markup_a11y.py"
    _sc_files=()
    while IFS= read -r vue; do
        [ -f "${vue}" ] || continue
        _in_scope "${vue}" || continue
        _sc_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_sc_files[@]}" -eq 0 ]; then
        # See gate-31 above: an empty scope was reported as PASS.
        _sc_ran=0
        _skip 32 "semantic-controls" na "no markup file (src|templates|appinfo/templates **/*.vue|php|html) is in scope for this run — the repository has none, or the diff touches none under ADR-020. No click target was inspected and none could be."
    elif [ ! -f "${_sc_helper}" ]; then
        _sc_ran=0
        _skip 32 "semantic-controls" wiring "check_markup_a11y.py not found at ${_sc_helper} — ${#_sc_files[@]} markup file(s) were in scope and NONE were inspected; keyboard-inaccessible click targets (WCAG 2.1.1 / 4.1.2) are UNVERIFIED by this run."
    else
        set +e
        _sc_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-semantic-controls.err"
        python3 "${_sc_helper}" --rule semantic-controls "${_sc_files[@]}" >> "${_sc_log}" 2>"${_sc_err}"
        _sc_rc=$?
        if [ "${_sc_rc}" -ne 0 ]; then
            _sc_ran=0
            _skip 32 "semantic-controls" wiring "check_markup_a11y.py exited ${_sc_rc} — ${#_sc_files[@]} markup file(s) were in scope and NONE were judged. See ${_sc_err}."
        fi
    fi
    _sc_fail=$(wc -l < "${_sc_log}" 2>/dev/null || echo 0)
    [ -z "${_sc_fail}" ] && _sc_fail=0
    if [ "${_sc_ran}" -eq 1 ]; then
        if [ "${_sc_fail}" -eq 0 ]; then
            echo "[hydra-gates] gate-32 semantic-controls: ${#_sc_files[@]} markup file(s) inspected, 0 mouse-only click target."
            _pass 32 "semantic-controls"
        else
            _fail 32 "semantic-controls" "${_sc_fail} non-semantic element(s) with @click but missing role/tabindex/keyboard handler — use <NcButton> or <button> — see ${_sc_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 33: Axe-core report — consume the axe-core violations JSON produced by
# `scripts/run-browser-tests.sh`. Static gates can only catch AST-visible
# issues; axe-core runs against the rendered DOM and catches contrast,
# landmark structure, ARIA validity, and live-region issues that no regex
# can reliably detect. See WCAG 2.2 AA SC 1.4.3, 1.4.11, 1.3.1, 4.1.2, 4.1.3.
#
# Contract: if `tests/axe/report.json` exists, parse its `violations` array
# and fail on any entry with `impact` of `serious` or `critical`.
#
# THIS GATE HAS NEVER RUN — ANYWHERE (measured 2026-08-03, fleet-wide).
#
# The report it consumes is documented as the output of
# `scripts/run-browser-tests.sh`. That script exists in NO app in the fleet,
# and `tests/axe/report.json` exists in none either — while `axe-core` sits in
# every app's package.json devDependencies, so the prerequisite LOOKS wired.
# Until 2026-08-03 the missing report made the gate emit NOTHING: no line, no
# count, no trace. Its silence was byte-identical to a pass, and it disappeared
# under an "ALL 63 GATES GREEN" banner. Every green this fleet has produced
# therefore excluded accessibility RUNTIME checking (contrast, landmark
# structure, ARIA validity, live regions) — the exact class of defect the
# static gates 31/32/35-45 cannot see.
#
# It now reports SKIPPED with the reason, is counted as NOT RUN by the summary
# and by bin/hydra-gates' coverage assertion, and can be made a hard failure
# with --require-full-coverage.
#
# To make it RUN, as of 2026-08-04: set `enable-axe: true` on the shared
# quality workflow (ConductionNL/.github/.github/workflows/quality.yml). It
# runs `@axe-core/playwright` against the app's routes inside the Playwright
# job — the only job with both a booted Nextcloud and a browser — and hands
# tests/axe/report.json to the gates job as an artifact. Settings-only apps
# have no root route and must also set `axe-routes`.
#
# Failing that, produce tests/axe/report.json any other way you like
# (`new AxeBuilder({ page }).analyze()` → `fs.writeFileSync(...)`), or add the
# documented scripts/run-browser-tests.sh. See the `hydra-gate-axe` skill for
# the canonical snippet.
#
# Whatever produces it: do NOT write a report on a path where the browser did
# not actually render the app. This gate reports PASS on a file containing
# exactly `{}` — an empty or crashed-step report turns the loud skip above into
# a silent false pass, which is strictly worse than never having run.
#
# References:
#   - ADR-010 (NL Design — WCAG 2.2 AA)
#   - openspec/architecture/wcag-coverage.md (axe column)
#   - .claude/skills/hydra-gate-axe/SKILL.md (how the report is produced)
# ---------------------------------------------------------------------------
_axe_report="tests/axe/report.json"
#
# COVERAGE CLASSIFICATION (gate-33). This gate's input is the one thing this
# script cannot produce for itself — it comes from a browser, in the Playwright
# job, and only when the caller sets `enable-axe: true`. So the absence of the
# report is read against what the caller SAID it would do:
#
#   no src/                   NOT APPLICABLE. No frontend, no rendered DOM.
#   src/, enable-axe NOT set  NOT APPLICABLE to this run. The repo has not opted
#                             into runtime accessibility enforcement, and that
#                             choice is a visible line in its own workflow file
#                             — it is not a gap hidden inside this script. This
#                             is what makes --require-full-coverage usable
#                             fleet-wide instead of red everywhere on day one.
#   src/, enable-axe SET,     STRUCTURAL. The repo DID opt in and the report
#   report absent             still did not arrive — the Playwright job skipped,
#                             crashed, or the artifact was rejected. A real gap,
#                             and it fails. (quality.yml fails the job for this
#                             independently; both, deliberately.)
#
# The middle case is the one that could be abused into a mute, so it is stated
# in the output as a choice with a named way to change it, never as silence.
if [ ! -f "${_axe_report}" ]; then
    if [ ! -d src ]; then
        _skip 33 "axe-core" na "no src/ and no ${_axe_report} — no frontend to run axe-core against in this repo."
    elif [ "${AXE_ENABLED}" = "1" ]; then
        _skip 33 "axe-core" structural "the caller set enable-axe/--axe-enabled, so a ${_axe_report} was EXPECTED, and none arrived. axe-core never ran against a rendered DOM: contrast / landmark / ARIA-validity / live-region accessibility is UNVERIFIED. The Playwright job that produces it was skipped, failed, or its artifact was rejected — that is the thing to fix, not this gate."
    else
        _skip 33 "axe-core" na "no ${_axe_report}, and the caller did not set enable-axe — this repo has not opted into runtime accessibility enforcement. Runtime a11y (contrast / landmark / ARIA-validity / live-region) is therefore NOT enforced here, by a choice recorded in the caller's workflow rather than in this run. To enforce it, set \`enable-axe: true\` on ConductionNL/.github's quality.yml; this gate then becomes blocking and its absence becomes a failure."
    fi
fi
if [ -f "${_axe_report}" ]; then
    _axe_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-axe.log
    : > "${_axe_log}"
    # Parse with python so we don't add a jq dependency. Counts violations
    # by impact and emits one line per serious/critical violation for the
    # detail log. Exit code 0 if zero serious-or-critical; 1 otherwise.
    # `{}` IS NOT "NO VIOLATIONS" (#148's remaining half).
    #
    # The skip branches above make an ABSENT report loud. A report that is
    # PRESENT but carries no `violations` key at all was still read as a clean
    # result — `data.get('violations', [])` supplies the empty list — so a
    # crashed capture step, a truncated artifact or a placeholder file turned
    # the loud skip into a silent PASS, which is strictly worse than never
    # having run. Every real axe result object HAS the key (axe-core always
    # emits `violations`, even when empty); its absence means the producer
    # never got that far. Exit 2 = "this is not an axe report".
    python3 - "${_axe_report}" "${_axe_log}" <<'PYAXE'
import json, sys
path, log = sys.argv[1], sys.argv[2]
try:
    with open(path) as f:
        data = json.load(f)
except Exception as e:
    with open(log, 'w') as f:
        f.write(f"axe-report-unreadable: {e}\n")
    sys.exit(2)
if not isinstance(data, dict) or 'violations' not in data:
    with open(log, 'w') as f:
        f.write(
            "axe-report-shapeless: %s parses as JSON but has no `violations` key, "
            "so it is not an axe result object. axe-core always emits that key, "
            "empty or not — its absence means the run that was supposed to "
            "produce this file never reached the assertion.\n" % path
        )
    sys.exit(2)
violations = data.get('violations') or []
if not isinstance(violations, list):
    with open(log, 'w') as f:
        f.write("axe-report-shapeless: `violations` is not a list\n")
    sys.exit(2)
blocking = [v for v in violations if isinstance(v, dict) and v.get('impact') in ('serious', 'critical')]
with open(log, 'w') as f:
    for v in blocking:
        rule = v.get('id', '?')
        impact = v.get('impact', '?')
        help_url = v.get('helpUrl', '')
        targets = []
        for n in v.get('nodes', [])[:3]:
            t = n.get('target', [])
            targets.append(' > '.join(t) if isinstance(t, list) else str(t))
        f.write(f"axe-rule={rule} impact={impact} nodes={len(v.get('nodes', []))} help={help_url} targets={targets}\n")
print(
    "[hydra-gates] gate-33 axe-core: report read — %d violation(s) present, "
    "%d serious/critical. A PASS here is a PASS over that number, not over "
    "silence." % (len(violations), len(blocking))
)
sys.exit(0 if not blocking else 1)
PYAXE
    _axe_rc=$?
    _axe_fail=$(wc -l < "${_axe_log}" 2>/dev/null || echo 0)
    if [ "${_axe_rc}" -ge 2 ]; then
        # Present but not an axe result object — see the parser's own message.
        _skip 33 "axe-core" wiring "${_axe_report} exists but is not a readable axe result object ($(head -1 "${_axe_log}" 2>/dev/null)). NO rendered-DOM violation was inspected; runtime accessibility is UNVERIFIED by this run. Fix the step that writes the report, not this gate."
    elif [ "${_axe_fail}" -eq 0 ]; then
        _pass 33 "axe-core"
    else
        _fail 33 "axe-core" "${_axe_fail} serious/critical axe violation(s) — see ${_axe_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 34: Window-confirm — flag literal `window.confirm(` / `window.alert(`
# / `window.prompt(` calls in `.vue` / `.js` / `.ts` files. ADR-004 hard
# rule: native browser dialogs break Nextcloud's theming + WCAG. Use
# `NcDialog` or `CnFormDialog` instead. References WCAG 2.2 SC 3.3.4
# (Error Prevention) for destructive-action confirmations and SC 4.1.2
# (Name, Role, Value) — native window dialogs don't expose a queryable
# role to assistive tech that matches the surrounding NC shell.
# ---------------------------------------------------------------------------
#
# IT GREPPED PROSE, AND IT MISSED THE BRACKET FORM (#224).
#
# `grep -rnE '\bwindow\.(confirm|alert|prompt)\s*\('` failed in both
# directions, measured in four arms on doriath:
#
#   arm 1  a comment saying the component deliberately AVOIDS
#          window.confirm()                             -> FAIL, false RED
#   arm 2  the same file, comment deleted                -> PASS  (control)
#   arm 3  `if (!window['confirm']('Delete everything?'))` -> PASS, false GREEN
#   arm 4  the same code as `window.confirm(...)`        -> FAIL  (control)
#
# Arm 1 punishes the code that did the right thing and teaches people not to
# write down why. Arm 3 is the serious one — on doriath the native call it
# hid guarded a CASCADING DELETE, and `window['confirm']` is what several
# minifiers and some lint autofixes emit. `const { confirm } = window` sails
# through the old regex too.
#
# scripts/lib/check_js_call_sites.py anchors on the executable regions of the
# file (comments blanked, string CONTENTS blanked) and then reads the
# original text at the same offset, so a documentation string is not a call
# and a bracket-access call is.
#
#   - ConductionNL/.github#224
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _wc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-window-confirm.log
    : > "${_wc_log}"
    _wc_ran=1
    _wc_helper="${SCRIPT_DIR}/lib/check_js_call_sites.py"
    _wc_files=()
    # templates/ too (#225): a native dialog opened from an inline <script> in a
    # PHP template breaks theming and WCAG exactly as one in a .vue does.
    while IFS= read -r _f; do
        [ -f "${_f}" ] || continue
        case "${_f}" in
            *.vue|*.js|*.ts|*.php|*.html|*.htm) ;;
            *) continue ;;
        esac
        _in_scope "${_f}" || continue
        _wc_files+=("${_f}")
    done < <(find src templates appinfo/templates -type f \
        \( -name '*.vue' -o -name '*.js' -o -name '*.ts' \
           -o -name '*.php' -o -name '*.html' -o -name '*.htm' \) 2>/dev/null \
        | grep -vE '(^|/)(node_modules|vendor|dist|build|coverage|phpmetrics|\.git)/' || true)
    if [ "${#_wc_files[@]}" -eq 0 ]; then
        : # nothing in scope; the verdict below describes the diff.
    elif [ ! -f "${_wc_helper}" ]; then
        _wc_ran=0
        _skip 34 "window-confirm" wiring "check_js_call_sites.py not found at ${_wc_helper} — ${#_wc_files[@]} file(s) were in scope and NONE were inspected; native browser dialogs are UNVERIFIED by this run."
    else
        set +e
        _wc_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-window-confirm.err"
        python3 "${_wc_helper}" --rule native-dialog "${_wc_files[@]}" >> "${_wc_log}" 2>"${_wc_err}"
        _wc_rc=$?
        if [ "${_wc_rc}" -ne 0 ]; then
            _wc_ran=0
            _skip 34 "window-confirm" wiring "check_js_call_sites.py exited ${_wc_rc} — ${#_wc_files[@]} file(s) were in scope and NONE were judged. See ${_wc_err}."
        fi
    fi
    _wc_fail=$(wc -l < "${_wc_log}" 2>/dev/null || echo 0)
    [ -z "${_wc_fail}" ] && _wc_fail=0
    if [ "${_wc_ran}" -eq 1 ]; then
        if [ "${_wc_fail}" -eq 0 ]; then
            _pass 34 "window-confirm"
        else
            _fail 34 "window-confirm" "${_wc_fail} native dialog call(s) — use NcDialog / CnFormDialog — see ${_wc_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 35: Img-alt-empty-only — escalates gate 31. Detects `<img alt="">`
# (literal empty alt — i.e. "explicitly decorative") paired with a `:src`
# / `v-bind:src` whose bound expression contains a semantic noun
# (`avatar`, `photo`, `thumbnail`, `picture`, `headshot`, `portrait`,
# `profilePicture`). These are content images by name — silencing gate 31
# with `alt=""` is the "I made the gate green by lying" anti-pattern.
#
# Decorative images that are decorative-by-shape still pass (e.g. a static
# `<img src="img/decoration.svg" alt="">`). The gate only fires when the
# bound src name explicitly signals dynamic user content.
#
# References:
#   - ADR-010 (NL Design — WCAG 2.2 AA)
#   - openspec/architecture/wcag-coverage.md SC 1.1.1 (Non-text Content)
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _iae_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-img-alt-empty-only.log
    : > "${_iae_log}"
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _flat=$(tr '\n' ' ' < "${vue}")
        echo "${_flat}" \
            | grep -oE '<img\b[^>]*>' 2>/dev/null \
            | while IFS= read -r tag; do
                [ -z "${tag}" ] && continue
                # Must have literal alt="" (not bound `:alt=""` — bound expressions
                # with computed-default-empty are out of scope; the developer there
                # at least went through a prop pipeline).
                # Both quote styles: `alt=''` renders identically to `alt=""`
                # and was invisible here. Measured as a PASS on a planted
                # `<img src='/img/avatar.png' alt=''> in both a .vue app and a
                # PHP-template app.
                echo "${tag}" | grep -qE 'alt[[:space:]]*=[[:space:]]*("[[:space:]]*"|'"'"'[[:space:]]*'"'"')' || continue
                # Must have a src that names a semantic noun. A BOUND :src /
                # v-bind:src carries the noun in its expression; a PHP template
                # or plain HTML carries it in the literal attribute value
                # (`src="<?php p($avatarUrl) ?>"`, `src="/img/avatar.png"`).
                # Both mean the same thing — the author knew the image was a
                # person or a picture and still gave it an empty alt (#225).
                _src_expr=$(echo "${tag}" | grep -oE '(:src|v-bind:src|src)[[:space:]]*=[[:space:]]*("[^"]*"|'"'"'[^'"'"']*'"'"')' | head -1 || true)
                [ -z "${_src_expr}" ] && continue
                if echo "${_src_expr}" | grep -qiE '\b(avatar|photo|thumbnail|picture|headshot|portrait|profilePicture)\b'; then
                    echo "${vue}: ${tag} rule=empty-alt-on-semantic-bound-src" >> "${_iae_log}"
                fi
            done
    done < <(_a11y_markup_files)
    _iae_fail=$(wc -l < "${_iae_log}" 2>/dev/null || echo 0)
    if [ "${_iae_fail}" -eq 0 ]; then
        _pass 35 "img-alt-empty-only"
    else
        _fail 35 "img-alt-empty-only" "${_iae_fail} <img alt=\"\"> on semantic-bound src — see ${_iae_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 36: Tabindex-positive — flag any `tabindex="N"` with N ≥ 1 (positive
# tabindex). Per WCAG 2.2 AA SC 2.4.3 (Focus Order), positive tabindex
# values pull the element out of natural document order and into a
# parallel "tab sequence" that almost never matches user expectations.
# The only correct values are `tabindex="0"` (in natural order) or
# `tabindex="-1"` (programmatically focusable, not in tab order).
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 2.4.3 (Focus Order)
#   - WHATWG / W3C: "Authors should generally use `tabindex='0'` or
#     `tabindex='-1'`. Positive integer values are very rarely useful."
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _tp_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-tabindex-positive.log
    : > "${_tp_log}"
    # Match tabindex with quoted positive integer. Allow whitespace
    # inside the quotes. Excludes tabindex="0", tabindex="-1", and any
    # form where the value is bound (`:tabindex="..."` is reviewer-judgment).
    # templates/ too (#225): focus order is a property of the rendered DOM, not
    # of the language that emitted it.
    #
    # BOTH QUOTE STYLES. The pattern read `"` only, so `tabindex='5'` — the
    # same rendered DOM, the same defect — reported PASS in both a .vue app
    # and a PHP-template app when it was planted there. Zero occurrences in
    # the fleet today, which is precisely why it could sit here unnoticed.
    grep -rnE 'tabindex[[:space:]]*=[[:space:]]*["'"'"'][[:space:]]*[1-9][0-9]*[[:space:]]*["'"'"']' src/ templates/ appinfo/templates/ \
        --include='*.vue' --include='*.js' --include='*.ts' \
        --include='*.php' --include='*.html' --include='*.htm' 2>/dev/null \
        | _filter_grep_by_scope >> "${_tp_log}" || true
    _tp_fail=$(wc -l < "${_tp_log}" 2>/dev/null || echo 0)
    if [ "${_tp_fail}" -eq 0 ]; then
        _pass 36 "tabindex-positive"
    else
        _fail 36 "tabindex-positive" "${_tp_fail} positive tabindex value(s) — use \"0\" or \"-1\" — see ${_tp_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 37: Aria-hidden-focusable — flag elements with `aria-hidden="true"`
# that ALSO declare a focusable mechanism (`tabindex` with any value,
# `role="button|link|menuitem|tab|..."`, or a native focusable tag like
# `<a href>` / `<button>` / `<input>` / `<select>` / `<textarea>`).
#
# This is one of the most-cited axe-core violations: the element is
# hidden from assistive tech but still in the tab order, so keyboard
# users land on a control that screen readers don't announce. Result:
# focus lands on "nothing" — confusing and disorienting. WCAG 2.2 AA
# SC 4.1.2 (Name, Role, Value).
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 4.1.2 (Name, Role, Value)
#   - axe-core rule `aria-hidden-focus` (impact: serious)
# ---------------------------------------------------------------------------
#
# `tabindex="-1"` IS NOT FOCUSABLE (#222)
# --------------------------------------
# Until 2026-08-08 this gate treated `tabindex` WITH ANY VALUE as proof of
# focusability. `tabindex="-1"` is the attribute that REMOVES an element from
# the tab order — the one value that proves the opposite of what the gate
# concluded — so the gate's own subject was inverted for every element that
# had already been fixed. The canonical hidden-file-input pattern
#
#     <input type="file" :aria-hidden="true" tabindex="-1" @change="…">
#
# is correct, and the gate's advice was to remove `aria-hidden` (exposing an
# unnamed control to screen readers) or to remove `tabindex="-1"` (putting a
# control screen readers cannot see BACK in the tab order — the very defect
# this gate exists to catch). Both remediations regress accessibility.
#
# Implementation moved to scripts/lib/check_aria_hidden_focusable.py, with
# both-arms tests in scripts/lib/test_check_aria_hidden_focusable.py.
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _ahf_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-aria-hidden-focusable.log
    : > "${_ahf_log}"
    _ahf_ran=1
    _ahf_helper="${SCRIPT_DIR}/lib/check_aria_hidden_focusable.py"
    _ahf_files=()
    # `_a11y_markup_files`, not `find src -name '*.vue'` (#225 / #261). WCAG
    # does not care which templating language produced the DOM, and an
    # `aria-hidden` focusable is the same defect in a .php template as in a
    # .vue one. The helper reads markup, not Vue specifically.
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _ahf_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_ahf_files[@]}" -eq 0 ]; then
        :   # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_ahf_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _ahf_ran=0
        _skip 37 "aria-hidden-focusable" wiring "check_aria_hidden_focusable.py not found at ${_ahf_helper} — ${#_ahf_files[@]} markup file(s) were in scope and NONE were inspected; focusable elements hidden from assistive tech (WCAG 4.1.2) are UNVERIFIED by this run."
    else
        # THE EXIT CODE IS A STATUS, THE FINDINGS ARE STDOUT (gate-19 / #249).
        # `2>/dev/null || true` would swallow a traceback AND the failure, so a
        # crashed helper left an empty log and the gate reported PASS. stderr
        # is kept; a non-zero exit is a wiring failure, not a clean sheet.
        #
        # `set +e` only, never `set -e` after: errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top, and
        # scripts/lib/test_gate_errexit_discipline.sh which enforces it).
        set +e
        python3 "${_ahf_helper}" "${_ahf_files[@]}" >> "${_ahf_log}" 2>>"${_ahf_log}.err"
        _ahf_rc=$?
        if [ "${_ahf_rc}" -ne 0 ]; then
            _ahf_ran=0
            _skip 37 "aria-hidden-focusable" wiring "check_aria_hidden_focusable.py exited ${_ahf_rc} — ${#_ahf_files[@]} markup file(s) were in scope and no verdict was produced; focusable elements hidden from assistive tech (WCAG 4.1.2) are UNVERIFIED by this run. See ${_ahf_log}.err."
        fi
    fi
    _ahf_fail=$(wc -l < "${_ahf_log}" 2>/dev/null || echo 0)
    if [ "${_ahf_ran}" -eq 1 ]; then
        if [ "${_ahf_fail}" -eq 0 ]; then
            _pass 37 "aria-hidden-focusable"
        else
            _fail 37 "aria-hidden-focusable" "${_ahf_fail} aria-hidden=true on focusable element(s) — remove aria-hidden OR remove the focusable mechanism — see ${_ahf_log}"
        fi
    fi
fi


# ---------------------------------------------------------------------------
# Gate 38: Skip-link — every app PAGE ROOT must include a skip-to-content
# affordance, either via NC's shell (typically inherited by mounting under
# <NcContent>) or via an explicit `<a href="#main">` / `<a href="#content">`
# link as the first focusable element. Per WCAG 2.2 AA SC 2.4.1 (Bypass
# Blocks).
#
# A PAGE ROOT IS NOT A MOUNT POINT (#214 / #216 / #227)
# ----------------------------------------------------
# SC 2.4.1 is a property of a DOCUMENT: "a mechanism is available to bypass
# blocks of content repeated on multiple WEB PAGES". Only the thing that owns
# the document can satisfy it. Two shapes in this fleet own no document, and
# this gate was reporting all of them:
#
#   templates/**/*.php   Nextcloud's `Template` renderer SUBSTITUTES an app
#                        template into core's own page. Every one of the 30
#                        PHP templates in this fleet is a fragment — measured:
#                        NOT ONE emits <html>, <head> or <body>. The typical
#                        body is literally `<div id="procest-settings"></div>`,
#                        a Vue mount point. The skip link for that page is
#                        emitted by NC core, above this file's first byte.
#                        8 templates across 6 apps failed this way.
#
#   Admin / personal     `AdminRoot.vue` is rendered INTO core's Settings
#   settings roots       page, inside the section core already built. It is a
#                        <CnAdminSettingsShell> — a stack of
#                        <NcSettingsSection>s — not an app shell. #227: these
#                        surfaces cannot own a page shell, so they cannot own
#                        a skip link either.
#
# In both cases the gate's implied remedy was to ADD a skip link to an element
# that is not the page root: a second "skip to content" anchor pointing into
# the middle of a page that already has one, announced to every screen-reader
# user ahead of core's real one. A WCAG 2.4.1 REGRESSION demanded by a WCAG
# 2.4.1 gate, which is why the finding could not be closed honestly.
#
# WHAT REMAINS IN SCOPE, AND STILL FAILS
# --------------------------------------
#   - src/App.vue, src/views/App.vue        — the app's own shell
#   - **/*Root.vue that is NOT a settings surface
#   - a PHP template that DOES own the document, i.e. emits <html>/<body>
#     (a standalone / PublicPage template rendered outside core's shell). No
#     fleet app has one today; the gate is ready for the first that does, and
#     the fixture pair in scripts/test-fixtures/monitoring-skiplink proves it
#     still fires. Note this WIDENS the PHP arm: it used to look only at
#     templates/settings/, and now asks every app template the question.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 2.4.1
#   - ConductionNL/.github#214, #216, #227
# ---------------------------------------------------------------------------
if [ -d src ] || [ -d templates ] || [ -d appinfo/templates ]; then
    _sl_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-skip-link.log
    : > "${_sl_log}"
    # A Vue root that is a SETTINGS SURFACE, not a page root. Two independent
    # signals, because neither alone covers the fleet:
    #   name  — all 8 settings roots measured across the fleet are called
    #           AdminRoot.vue / PersonalRoot.vue / AdminSettings.vue, and 3 of
    #           them (petstore, portaliq, openregister) are NOT under a
    #           settings/ path, so a path rule alone would miss them.
    #   shell — a root rendering <CnAdminSettingsShell> / <NcSettingsSection> /
    #           <CnSettingsSection> is a stack of settings sections mounted
    #           into core's Settings page, whatever it happens to be called.
    _sl_is_settings_surface() {
        local _f="$1"
        case "${_f##*/}" in
            AdminRoot.vue|PersonalRoot.vue|AdminSettings.vue|PersonalSettings.vue) return 0 ;;
        esac
        case "${_f}" in
            */views/settings/*|*/views/admin/*|*/components/userSettings/*) return 0 ;;
        esac
        grep -qE '<CnAdminSettingsShell\b|<NcSettingsSection\b|<CnSettingsSection\b' "$_f" 2>/dev/null
    }
    # A PHP template owns the document only if it EMITS one.
    #
    # This is deliberately NOT a grep. The first cut of it was, and the very
    # first fixture defeated it: that fixture's own explanatory COMMENT
    # contained the word `<html>`, so a bare `<div id="x"></div>` mount point
    # classified as a page root. That is the gate-64 defect verbatim — a
    # checker that greps a string literal misses every constant and matches
    # every comment, failing both ways at once. scripts/lib/php_template_scope.py
    # classifies EMITTED MARKUP, with PHP regions and HTML comments removed.
    #
    # THE ANSWER COMES FROM STDOUT, THE EXIT CODE IS A STATUS (gate-19 / #249).
    # An earlier draft used the exit byte as the boolean — 0 owns, 1 fragment —
    # and a helper that CRASHES also exits 1. Every template would have read as
    # a fragment, the whole PHP arm would have evaporated, and the gate would
    # have reported PASS on nothing. One `--classify` call for the whole set
    # prints `<path>: page-root|fragment`, and a non-zero exit is a wiring
    # failure rather than an answer.
    _sl_check() {
        local _f="$1"
        _in_scope "$_f" || return 0
        if grep -qE '<NcContent\b|<NcAppContent\b|<NcAppContentList\b' "$_f" 2>/dev/null; then return 0; fi
        # The shared app shell. `<CnAppRoot>` (@conduction/nextcloud-vue) IS an
        # <NcContent> — it renders one as its own root element and puts the
        # router-view inside an <NcAppContent> — so an app whose App.vue is a
        # CnAppRoot has NC's skip-link, one component deeper than this grep can
        # see.
        #
        # All 18 fleet apps root on CnAppRoot, so this gate reported every one
        # of them as shipping no skip link. Same principle already written down
        # for the AppHost generics in gate-5/gate-14: "I cannot see it" is not
        # "it is absent", and only the first of those is true here.
        #
        # This does not weaken the gate for an app that writes its own shell —
        # a root component that is neither an NcContent nor a CnAppRoot still
        # has to carry a skip-link affordance of its own.
        if grep -qE '<CnAppRoot\b' "$_f" 2>/dev/null; then return 0; fi
        # The skip-link affordance must be an actual anchor or marked
        # element — not just a stray mention of the words. Accept:
        #   - <a ... class="skip-link" ...> or class containing skip-link / skip-nav
        #   - <a href="#main"> / <a href="#content"> / <a href="#main-content">
        #   - id="skip-link" / id="skip-to-content" on any element
        if grep -qE '<a\b[^>]*(class\s*=\s*"[^"]*skip-(link|nav|to-content)|href\s*=\s*"#(main|content|main-content)")' "$_f" 2>/dev/null; then return 0; fi
        if grep -qE 'id\s*=\s*"skip-(link|to-content|nav)"' "$_f" 2>/dev/null; then return 0; fi
        echo "${_f}: no <NcContent> shell, no skip-link affordance" >> "${_sl_log}"
    }
    for _f in src/App.vue src/views/App.vue; do
        [ -f "$_f" ] && _sl_check "$_f"
    done
    while IFS= read -r _f; do
        [ -z "$_f" ] && continue
        # #227: a settings surface is not a page root and cannot own a skip
        # link. Skipping it is the only honest verdict — the alternative
        # remedy regresses the page that already has one.
        _sl_is_settings_surface "$_f" && continue
        _sl_check "$_f"
    done < <(_enum_tracked 'Root\.vue$' src)
    # #214 / #216: a PHP template is checked only when it owns the document.
    # `templates/settings/` is no longer the scope — the question asked of
    # EVERY app template is whether it emits <html>/<body>.
    _sl_ran=1
    if [ -d templates ] || [ -d appinfo/templates ]; then
        _sl_helper="${SCRIPT_DIR}/lib/php_template_scope.py"
        _sl_php=()
        # ONE SCOPE DEFINITION FOR THE FAMILY (#225 / #261). This arm used its
        # own `find templates appinfo/templates -name '*.php'`, which is
        # `_a11y_markup_files` minus the exclusions — so a generated
        # phpmetrics/ or vendor/ template would have been audited here and
        # nowhere else. Filtering the shared enumeration keeps the two from
        # drifting, and there is deliberately no third definition.
        while IFS= read -r _f; do
            [ -z "$_f" ] && continue
            case "$_f" in *.php) ;; *) continue ;; esac
            _in_scope "$_f" && _sl_php+=("$_f")
        done < <(_a11y_markup_files)
        if [ "${#_sl_php[@]}" -eq 0 ]; then
            :   # nothing in scope; the verdict below describes the diff, as everywhere else.
        elif [ ! -f "${_sl_helper}" ]; then
            # A MISSING HELPER MUST NOT REPORT PASS (#147). Without the
            # classifier every template silently reads as a fragment, the
            # whole PHP arm evaporates, and the gate goes green on nothing.
            _sl_ran=0
            _skip 38 "skip-link" wiring "php_template_scope.py not found at ${_sl_helper} — ${#_sl_php[@]} PHP template(s) were in scope and NONE were classified; a document-owning template with no bypass mechanism (WCAG 2.4.1) is UNVERIFIED by this run."
        else
            _sl_cls_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-skip-link.classify.err"
            # `set +e` only, never `set -e` after. Errexit is OFF for this
            # whole script and nothing may turn it on (#243, and the invariant
            # at the top of this file). Before that landed, a non-zero helper
            # here killed the entire runner mid-sweep instead of reaching the
            # _skip below — measured at 21 later gates silently lost, the run
            # ending on the abort banner.
            set +e
            _sl_cls=$(python3 "${_sl_helper}" --classify "${_sl_php[@]}" 2>"${_sl_cls_err}")
            _sl_rc=$?
            if [ "${_sl_rc}" -ne 0 ]; then
                # The classifier fell over. It answered nothing, so there is no
                # verdict to give — stderr is KEPT, not discarded (#249).
                _sl_ran=0
                _skip 38 "skip-link" wiring "php_template_scope.py exited ${_sl_rc} — ${#_sl_php[@]} PHP template(s) were in scope and NONE were classified; a document-owning template with no bypass mechanism (WCAG 2.4.1) is UNVERIFIED by this run. See ${_sl_cls_err}."
            else
                while IFS= read -r _line; do
                    case "${_line}" in
                        *": page-root") _sl_check "${_line%: page-root}" ;;
                    esac
                done <<< "${_sl_cls}"
            fi
        fi
    fi
    _sl_fail=$(wc -l < "${_sl_log}" 2>/dev/null || echo 0)
    if [ "${_sl_ran}" -eq 1 ]; then
        if [ "${_sl_fail}" -eq 0 ]; then
            _pass 38 "skip-link"
        else
            _fail 38 "skip-link" "${_sl_fail} page root(s) without skip-link / <NcContent> — see ${_sl_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 39: Button-name — `<NcButton>` / `<button>` tags with NO text content
# AND no `aria-label` / `aria-labelledby` / `title` are invisible to screen
# readers (announced as just "button"). Common shape: icon-only buttons
# like `<NcButton><CloseIcon /></NcButton>`. Per WCAG 2.2 AA SC 4.1.2
# (Name, Role, Value).
#
# Pass conditions for a button tag:
#   (a) has aria-label / aria-labelledby / title / name, LITERAL OR BOUND, OR
#   (b) has non-trivial text content (not just an icon-component child), a
#       Vue interpolation {{ ... }}, or an explicit <template #default>.
#
# A BOUND NAME IS STILL A NAME (#222 family)
# ------------------------------------------
# The accepted-attribute regex was:
#
#     r'(^|\s)(:?aria-label|aria-labelledby|v-bind:aria-label|title)\s*='
#
# `:?` binds to the FIRST alternative only, so `:aria-label` was accepted
# while `:title`, `v-bind:title` and `:aria-labelledby` were not. Vue
# templates bind almost every user-visible string, because it has to go
# through `t()`:
#
#     <button type="button" class="…__remove"
#             :title="t('openbuild', 'Remove tab')"
#             @click="removeTab(index)">
#
# That button HAS a name. ALL 22 of openbuild's findings were this exact
# shape — a correctly translated bound title read as a missing one — and the
# only way to close them was to add a second, redundant name. What matters is
# whether the attribute reaches the DOM, and `:title` reaches it exactly as
# `title` does. Note the gate already accepted static `title=`; accepting the
# bound form is a consistency fix, not a new claim about how strong a name
# `title` is.
#
# Implementation: scripts/lib/check_button_name.py, tests in
# scripts/lib/test_check_button_name.py — every accepted shape ships with the
# genuinely-unnamed button it must not swallow.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 4.1.2
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _bn_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-button-name.log
    : > "${_bn_log}"
    _bn_ran=1
    _bn_helper="${SCRIPT_DIR}/lib/check_button_name.py"
    _bn_files=()
    # `_a11y_markup_files`, not `find src -name '*.vue'` (#225 / #261). An
    # icon-only <button> with no name is the same defect in a .php template.
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _bn_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_bn_files[@]}" -eq 0 ]; then
        :   # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_bn_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _bn_ran=0
        _skip 39 "button-name" wiring "check_button_name.py not found at ${_bn_helper} — ${#_bn_files[@]} markup file(s) were in scope and NONE were inspected; unnamed controls (WCAG 4.1.2) are UNVERIFIED by this run."
    else
        # Exit code is a STATUS, findings are STDOUT (gate-19 / #249); stderr
        # is kept so a traceback is visible instead of becoming a clean sheet.
        # `set +e` only, never `set -e` after — errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top).
        set +e
        python3 "${_bn_helper}" "${_bn_files[@]}" >> "${_bn_log}" 2>>"${_bn_log}.err"
        _bn_rc=$?
        if [ "${_bn_rc}" -ne 0 ]; then
            _bn_ran=0
            _skip 39 "button-name" wiring "check_button_name.py exited ${_bn_rc} — ${#_bn_files[@]} markup file(s) were in scope and no verdict was produced; unnamed controls (WCAG 4.1.2) are UNVERIFIED by this run. See ${_bn_log}.err."
        fi
    fi
    _bn_fail=$(wc -l < "${_bn_log}" 2>/dev/null || echo 0)
    if [ "${_bn_ran}" -eq 1 ]; then
        if [ "${_bn_fail}" -eq 0 ]; then
            _pass 39 "button-name"
        else
            _fail 39 "button-name" "${_bn_fail} icon-only button(s) without an accessible name — see ${_bn_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 40: Form-label-association — generalises gate 12 (NcSelect-only) to
# every form input shape: `<input>`, `<textarea>`, `<NcTextField>`,
# `<NcCheckboxRadioSwitch>`, `<NcRichContenteditable>`, `<NcInputField>`.
# Per WCAG 2.2 AA SC 1.3.1 (Info and Relationships) and 3.3.2 (Labels or
# Instructions).
#
# Pass conditions for an input element:
#   (a) has aria-label / :aria-label / aria-labelledby, OR
#   (b) has an `id=` attribute paired with some `<label for=>` in the file
#       referencing the same id — literal OR bound expression, so
#       `:for="`f-${id}`"` + `:id="`f-${id}`"` associates, OR
#   (c) it is a DESCENDANT of a `<label>` element (implicit association —
#       `<label><input><span>Safe mode</span></label>` needs no id at all), OR
#   (d) for the NC* components: has a `label` / `:label` / `inputLabel`
#       prop (their published API), OR
#   (e) for NcCheckboxRadioSwitch: has non-empty DEFAULT SLOT content, which
#       nc-vue renders into its own <label>. This one is load-bearing: the
#       only way to satisfy the previous implementation was to add
#       `aria-label`, and `aria-label` OVERRIDES the visible text — an
#       accessibility REGRESSION demanded by an accessibility gate. 463 of
#       the fleet's 1,211 findings were this shape, and two burn-down PRs
#       (opencatalogi#808, docudesk#385) were stuck un-mergeable on it, OR
#   (f) input `type` is `hidden` / `submit` / `button` / `reset` / `image`.
#
# Comments and <script>/<style> blocks are excluded: markup that does not
# ship is not a control.
#
# Implementation: scripts/lib/check_form_labels.py, with both-ways tests in
# scripts/lib/test_check_form_labels.py — every relaxation above ships with
# the true-positive case it must not swallow.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 1.3.1, 3.3.2
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _fl_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-form-label-association.log
    : > "${_fl_log}"
    _fl_ran=1
    _fl_helper="${SCRIPT_DIR}/lib/check_form_labels.py"
    _fl_files=()
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _fl_files+=("${vue}")
    done < <(_a11y_markup_files)
    # NOTE: an empty in-scope set is NOT declared `na` here. Every other gate
    # in this family reports PASS over an empty diff scope — that is what
    # ADR-020 diff scoping MEANS — and tests/test-hydra-gates-bin.sh asserts
    # that none of gates 10/12/13/26/31..45 goes NOT APPLICABLE while src/
    # exists. Making gate-40 alone answer differently would drift the
    # applicability table away from the guards it mirrors, which is the one
    # way that change could hide a live gate.
    if [ "${#_fl_files[@]}" -eq 0 ]; then
        : # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_fl_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _fl_ran=0
        _skip 40 "form-label-association" wiring "check_form_labels.py not found at ${_fl_helper} — ${#_fl_files[@]} .vue file(s) were in scope and NONE were inspected; unlabelled form controls (WCAG 1.3.1 / 3.3.2) are UNVERIFIED by this run."
    else
        # ONE python process for the whole file set, not one per file. The
        # per-file heredoc this replaced took over two minutes on a 21-repo
        # sweep, almost all of it interpreter startup.
        #
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249). `2>/dev/null
        # || true` discarded the traceback AND the failure, so a broken
        # interpreter left an empty log and this gate called it clean.
        # Measured 2026-08-08 on opencatalogi with a `python3` that exits 1 on
        # every call: gate-40 printed PASS over the 13 real findings it had
        # reported one run earlier, while every a11y gate that read its
        # helper's return code correctly reported SKIPPED (wiring).
        #
        # `set +e` only, never `set -e` after — errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top).
        set +e
        python3 "${_fl_helper}" "${_fl_files[@]}" >> "${_fl_log}" 2>>"${_fl_log}.err"
        _fl_rc=$?
        if [ "${_fl_rc}" -ne 0 ]; then
            _fl_ran=0
            _skip 40 "form-label-association" wiring "check_form_labels.py exited ${_fl_rc} — ${#_fl_files[@]} markup file(s) were in scope and no verdict was produced; unlabelled form controls (WCAG 1.3.1 / 3.3.2) are UNVERIFIED by this run. See ${_fl_log}.err."
        fi
    fi
    _fl_fail=$(wc -l < "${_fl_log}" 2>/dev/null || echo 0)
    if [ "${_fl_ran}" -eq 1 ]; then
        if [ "${_fl_fail}" -eq 0 ]; then
            _pass 40 "form-label-association"
        else
            _fail 40 "form-label-association" "${_fl_fail} form input(s) without an associated label — see ${_fl_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 41: Html-lang — Nextcloud's shell sets `<html lang>` from user locale
# for app routes, but app-owned PHP templates under `appinfo/templates/` and
# `templates/` (admin/settings + public PublicPage routes) sometimes don't
# inherit it. Detect templates that emit an `<html>` element without a `lang`
# attribute. Per WCAG 2.2 AA SC 3.1.1 (Language of Page).
#
# Rule: a template that EMITS an `<html>` opening tag must carry `lang=` on
# it. Templates that never render one are out of scope — measured, all 30 app
# templates in this fleet are fragments substituted into core's page, and core
# emitted their `<html lang>` long before the template's first byte.
#
# THREE DEFECTS FIXED 2026-08-08 (#266):
#
# (a) A PHP COMMENT MENTIONING `<html>` MADE A MOUNT POINT A PAGE ROOT. The
#     search ran `re.search(r'<html\b([^>]*)>', txt)` over the RAW file, so
#
#         <?php
#         // Core emitted the <html> element for it, with its lang attribute,
#         // long before this file.
#         ?>
#         <div id="app-settings"></div>
#
#     reported `1 <html> tag(s) without lang=` for a file containing no
#     `<html>` element at all. This is gate-64's defect (#184) and the one
#     gate-38 shipped a fix for (#247), verbatim — and it fails the other way
#     too: a commented-out `<html lang="en">` would have SATISFIED the gate
#     for a template that really does emit an unlangged one. The search now
#     runs over `php_template_scope.emitted_markup`, which is the same answer
#     gate-38 uses. `[^>]*` also went — a `>` inside an attribute value is not
#     the end of the tag (#198, #236).
#
# (b) A THIRD SCOPE DEFINITION. This gate enumerated its own
#     `find templates appinfo/templates -name '*.php'`, which is
#     `_a11y_markup_files` minus the exclusions, so a generated `phpmetrics/`
#     or `vendor/` template was audited here and nowhere else. It was the last
#     a11y gate with a private enumeration; there are now two definitions in
#     the family, not three, and one of them is shared.
#
# (c) NO WIRING GUARD. The block was an inline `python3 - <<'PYHL' >> log
#     2>/dev/null`, so a crashed interpreter left an empty log and the gate
#     reported PASS (#147 / #249). Note this gate is quiet across the fleet
#     today for a reason unrelated to it being correct — no app emits a
#     document — so a silent failure here would have been invisible
#     indefinitely.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 3.1.1
#   - ConductionNL/.github#266
# ---------------------------------------------------------------------------
if [ -d templates ] || [ -d appinfo/templates ]; then
    _hl_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-html-lang.log
    : > "${_hl_log}"
    _hl_ran=1
    _hl_helper="${SCRIPT_DIR}/lib/php_template_scope.py"
    _hl_files=()
    while IFS= read -r _f; do
        [ -z "$_f" ] && continue
        case "$_f" in *.php) ;; *) continue ;; esac
        _in_scope "$_f" && _hl_files+=("$_f")
    done < <(_a11y_markup_files)
    if [ "${#_hl_files[@]}" -eq 0 ]; then
        : # nothing in scope; the verdict below describes the diff.
    elif [ ! -f "${_hl_helper}" ]; then
        _hl_ran=0
        _skip 41 "html-lang" wiring "php_template_scope.py not found at ${_hl_helper} — ${#_hl_files[@]} PHP template(s) were in scope and NONE were inspected; a document without a declared language (WCAG 3.1.1) is UNVERIFIED by this run."
    else
        set +e
        _hl_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-html-lang.err"
        python3 "${_hl_helper}" --html-lang "${_hl_files[@]}" >> "${_hl_log}" 2>"${_hl_err}"
        _hl_rc=$?
        if [ "${_hl_rc}" -ne 0 ]; then
            _hl_ran=0
            _skip 41 "html-lang" wiring "php_template_scope.py exited ${_hl_rc} — ${#_hl_files[@]} PHP template(s) were in scope and NONE were judged. See ${_hl_err}."
        fi
    fi
    _hl_fail=$(wc -l < "${_hl_log}" 2>/dev/null || echo 0)
    [ -z "${_hl_fail}" ] && _hl_fail=0
    if [ "${_hl_ran}" -eq 1 ]; then
        if [ "${_hl_fail}" -eq 0 ]; then
            _pass 41 "html-lang"
        else
            _fail 41 "html-lang" "${_hl_fail} <html> tag(s) without lang= — see ${_hl_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 42: Link-text-quality — flag links with low-quality text that
# doesn't describe the destination. Pattern-match known anti-patterns:
# "Click here", "Read more", "Learn more", "Here", "More", "Details", and
# empty link bodies. Per WCAG 2.2 AA SC 2.4.4 (Link Purpose — In Context).
#
# Higher false-positive risk than the AST gates — surrounding context can
# make "Read more" accessible-in-context. Links with aria-label or Vue
# interpolations are accepted unconditionally.
#
# A CRASHED CHECKER REPORTED PASS (#147 / #249)
# --------------------------------------------
# This gate ran `python3 - "$vue" <<'PYLQ' >> log 2>/dev/null` once per file
# and never read an exit status. Measured 2026-08-08 on opencatalogi with a
# `python3` on PATH that exits 1 on every call:
#
#   gate-40 PASS   gate-42 PASS   gate-44 PASS      <- the three inline ones
#   gate-34/37/38/39/41/43 SKIPPED (wiring)         <- the six behind a helper
#
# gate-40 printed PASS over the 13 real findings it had reported a run
# earlier. An empty log is not a clean sheet, and `2>/dev/null` hid the
# traceback that would have said so. Implementation moved to
# scripts/lib/check_link_text.py so this gate gets the same return-code guard
# as the rest of the family — and one interpreter start for the whole file
# set instead of one per file.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 2.4.4
#   - ConductionNL/.github#147, #249
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _lq_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-link-text-quality.log
    : > "${_lq_log}"
    _lq_ran=1
    _lq_helper="${SCRIPT_DIR}/lib/check_link_text.py"
    _lq_files=()
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _lq_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_lq_files[@]}" -eq 0 ]; then
        :   # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_lq_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _lq_ran=0
        _skip 42 "link-text-quality" wiring "check_link_text.py not found at ${_lq_helper} — ${#_lq_files[@]} markup file(s) were in scope and NONE were inspected; non-descriptive link text (WCAG 2.4.4) is UNVERIFIED by this run."
    else
        # `set +e` only, never `set -e` after — errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top).
        set +e
        python3 "${_lq_helper}" "${_lq_files[@]}" >> "${_lq_log}" 2>>"${_lq_log}.err"
        _lq_rc=$?
        if [ "${_lq_rc}" -ne 0 ]; then
            _lq_ran=0
            _skip 42 "link-text-quality" wiring "check_link_text.py exited ${_lq_rc} — ${#_lq_files[@]} markup file(s) were in scope and no verdict was produced; non-descriptive link text (WCAG 2.4.4) is UNVERIFIED by this run. See ${_lq_log}.err."
        fi
    fi
    _lq_fail=$(wc -l < "${_lq_log}" 2>/dev/null || echo 0)
    if [ "${_lq_ran}" -eq 1 ]; then
        if [ "${_lq_fail}" -eq 0 ]; then
            _pass 42 "link-text-quality"
        else
            _fail 42 "link-text-quality" "${_lq_fail} link(s) with non-descriptive text — see ${_lq_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 43: Table-headers — EVERY `<th>` in a `.vue` template must declare
# `scope="col"` / `scope="row"`. Without it, screen readers can't associate
# data cells with their headers. Per WCAG 2.2 AA SC 1.3.1 (Info and
# Relationships).
#
# ONE `scope=` USED TO GREEN THE WHOLE TABLE (#222)
# ------------------------------------------------
# Until 2026-08-08 the check was:
#
#     if re.search(r'<th\b[^>]*\bscope\s*=', body): continue   # whole table OK
#
# A SINGLE `scope=` anywhere in the table accepted every other header in it.
# Proven by negative control: removing exactly one `scope=` from a passing
# table still reported PASS. The common shape — someone scopes the first
# column and stops — was indistinguishable from a correct table, and the row
# headers screen-reader users actually need were never asked for. The rule is
# now "any unscoped header fails the table", not "any scoped header passes it".
#
# Two failure shapes:
#   - rule=th-without-scope: one or more <th> in the table lack scope=
#     (the line reports `unscoped=N/M`)
#   - rule=table-without-th: <table> with <td> rows but no <th> at all
#
# COUNTING: one finding per TABLE, not per <th> — the number stays a count of
# defect sites, comparable with what this gate reported before the fix, and a
# table is what a person actually sits down and repairs. A finding count is
# not a defect count, and the two must not be silently swapped mid-repair.
#
# Wrapper components (<CnDataTable>, <NcTable>) own their own markup and are
# not in scope, as before.
#
# Implementation: scripts/lib/check_table_headers.py, tests in
# scripts/lib/test_check_table_headers.py.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 1.3.1
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _th_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-table-headers.log
    : > "${_th_log}"
    _th_ran=1
    _th_helper="${SCRIPT_DIR}/lib/check_table_headers.py"
    _th_files=()
    # `_a11y_markup_files`, not `find src -name '*.vue'` (#225 / #261). An
    # unscoped header is the same defect in a .php template as in a .vue one.
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _th_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_th_files[@]}" -eq 0 ]; then
        :   # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_th_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _th_ran=0
        _skip 43 "table-headers" wiring "check_table_headers.py not found at ${_th_helper} — ${#_th_files[@]} markup file(s) were in scope and NONE were inspected; unassociated table headers (WCAG 1.3.1) are UNVERIFIED by this run."
    else
        # Exit code is a STATUS, findings are STDOUT (gate-19 / #249); stderr
        # is kept so a traceback is visible instead of becoming a clean sheet.
        # `set +e` only, never `set -e` after — errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top).
        set +e
        python3 "${_th_helper}" "${_th_files[@]}" >> "${_th_log}" 2>>"${_th_log}.err"
        _th_rc=$?
        if [ "${_th_rc}" -ne 0 ]; then
            _th_ran=0
            _skip 43 "table-headers" wiring "check_table_headers.py exited ${_th_rc} — ${#_th_files[@]} markup file(s) were in scope and no verdict was produced; unassociated table headers (WCAG 1.3.1) are UNVERIFIED by this run. See ${_th_log}.err."
        fi
    fi
    _th_fail=$(wc -l < "${_th_log}" 2>/dev/null || echo 0)
    if [ "${_th_ran}" -eq 1 ]; then
        if [ "${_th_fail}" -eq 0 ]; then
            _pass 43 "table-headers"
        else
            _fail 43 "table-headers" "${_th_fail} <table>(s) with a <th> missing scope= — see ${_th_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 44: Autocomplete-attr — `<input>` with name attributes / ids that
# match well-known autofill categories MUST declare an `autocomplete=`
# attribute so password managers + browser autofill work. Per WCAG 2.2 AA
# SC 1.3.5 (Identify Input Purpose).
#
# THREE DEFECTS FIXED 2026-08-08:
#
# (a) A CRASHED CHECKER REPORTED PASS. Inline `python3 - "$vue" <<'PYAC' >>
#     log 2>/dev/null`, once per file, exit status never read — so a broken
#     interpreter left an empty log and the gate called it clean (#147 /
#     #249). Measured with a `python3` that exits 1: gates 40, 42 and 44 all
#     said PASS while the six a11y gates behind a helper correctly said
#     SKIPPED (wiring).
#
# (b) DOUBLE-QUOTED VALUES ONLY. `name\s*=\s*"([^"]+)"` never saw
#     `<input id='b44-tel' type='text' name='telephone'>` — same rendered
#     DOM, same defect, PASS in both a .vue app and a PHP-template app.
#
# (c) FIRST NAME-LIKE ATTRIBUTE WINS. `re.search` over name/id/v-model
#     stopped at the first hit, so `<input id="e" type="text" name="email">`
#     was judged on `e` alone. The plainest textbook case for this gate.
#
# `[^>]*` also went: a `>` inside an attribute value is not the end of the
# tag (#259, #198, #236). Implementation in scripts/lib/check_autocomplete.py.
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 1.3.5
#   - ConductionNL/.github#147, #249, #259
# ---------------------------------------------------------------------------
if _a11y_has_markup_dir; then
    _ac_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-autocomplete-attr.log
    : > "${_ac_log}"
    _ac_ran=1
    _ac_helper="${SCRIPT_DIR}/lib/check_autocomplete.py"
    _ac_files=()
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _ac_files+=("${vue}")
    done < <(_a11y_markup_files)
    if [ "${#_ac_files[@]}" -eq 0 ]; then
        :   # nothing in scope; the PASS below describes the diff, as everywhere else.
    elif [ ! -f "${_ac_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _ac_ran=0
        _skip 44 "autocomplete-attr" wiring "check_autocomplete.py not found at ${_ac_helper} — ${#_ac_files[@]} markup file(s) were in scope and NONE were inspected; inputs with no declared purpose (WCAG 1.3.5) are UNVERIFIED by this run."
    else
        # `set +e` only, never `set -e` after — errexit is OFF for this whole
        # script and nothing may turn it on (see the invariant at the top).
        set +e
        python3 "${_ac_helper}" "${_ac_files[@]}" >> "${_ac_log}" 2>>"${_ac_log}.err"
        _ac_rc=$?
        if [ "${_ac_rc}" -ne 0 ]; then
            _ac_ran=0
            _skip 44 "autocomplete-attr" wiring "check_autocomplete.py exited ${_ac_rc} — ${#_ac_files[@]} markup file(s) were in scope and no verdict was produced; inputs with no declared purpose (WCAG 1.3.5) are UNVERIFIED by this run. See ${_ac_log}.err."
        fi
    fi
    _ac_fail=$(wc -l < "${_ac_log}" 2>/dev/null || echo 0)
    if [ "${_ac_ran}" -eq 1 ]; then
        if [ "${_ac_fail}" -eq 0 ]; then
            _pass 44 "autocomplete-attr"
        else
            _fail 44 "autocomplete-attr" "${_ac_fail} semantic input(s) without autocomplete= — see ${_ac_log}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 45: Prefers-reduced-motion — every `<style>` block in `.vue` files
# that declares `transition:` or `animation:` properties MUST also contain
# a `@media (prefers-reduced-motion: reduce)` block that disables or
# shortens motion. Per WCAG 2.2 SC 2.3.3 (Animation from Interactions —
# AAA, but a common Dutch toegankelijkheidsverklaring audit checkpoint).
#
# References:
#   - openspec/architecture/wcag-coverage.md SC 2.3.3 (AAA, audit-common)
# ---------------------------------------------------------------------------
# THE GUARD MUST MIRROR THE ENUMERATOR (#225 / #261, finished here).
#
# This gate reads `_a11y_markup_files` — src/, templates/ and appinfo/templates/
# — but was still gated on `[ -d src ]`, the guard that enumerator replaced.
# On an app that renders from PHP templates and has no src/ (nldesign is the
# fleet's example) the gate emitted nothing, and the central applicability
# table then declared it NOT APPLICABLE — "this repo ships no frontend" — over
# a `templates/settings/admin.php` that ships real markup. Measured 2026-08-08:
# a textbook `transition:` with no reduced-motion fallback was planted in that
# exact file and this gate reported NOT APPLICABLE while its siblings 31/32/34/
# 36/37/39/43, which had been migrated, reported on it (43 FAILED on it).
#
# `na` over a live defect is worse than the PASS it replaced: PASS at least
# counts as a claim someone can dispute, whereas `na` removes the gate from the
# coverage arithmetic entirely — the run then reports "all applicable gates
# green" with the defect inside it.
# A STYLESHEET IS WHERE THE MOTION ACTUALLY IS (#287).
#
# This gate scanned `<style>` blocks in markup and NOTHING ELSE — it had never
# opened a `.css` file in its entire existence. In a Nextcloud app the app-wide
# motion lives in `css/`, because that is what `Util::addStyle()` loads; a
# `<style scoped>` block only ever styles one component.
#
# Measured 2026-08-09: nldesign ships three stylesheets with motion and zero
# reduced-motion guards, and openregister's `css/main.css` has seven motion
# declarations and zero guards. Gate-45 said PASS on both. Every gate-45 green
# in the fleet before this commit was a statement about markup only.
#
# The unit differs by file type, and deliberately so:
#   markup      one `<style>` block. A guard in a DIFFERENT block on the same
#               page does not cover this one — `scoped` blocks are independent.
#   stylesheet  the whole file. `@media (prefers-reduced-motion: reduce)`
#               is conventionally written once at the bottom of a stylesheet
#               and disables motion for everything above it, so requiring a
#               guard per rule would flag correct code.
if _a11y_has_style_dir; then
    _rm_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-prefers-reduced-motion.log
    : > "${_rm_log}"
    : > "${_rm_log}.err"
    _rm_seen=0
    _rm_rc=0
    # THE GATE MUST ACCEPT THE FIX PEOPLE WILL ACTUALLY WRITE (#287).
    #
    # The canonical remedy for unguarded motion is ONE universal reset, written
    # once for the whole app:
    #
    #   @media (prefers-reduced-motion: reduce) {
    #     *, *::before, *::after {
    #       animation-duration: 0.01ms !important;
    #       transition-duration: 0.01ms !important;
    #     }
    #   }
    #
    # It lives in a single stylesheet and covers every other one. A per-file
    # rule would report every OTHER stylesheet as a finding the day that reset
    # lands — the gate would punish the correct fix. So the whole style corpus
    # is scanned once for a universal reset first, and a repo that has one is
    # globally guarded.
    #
    # NOT diff-scoped, deliberately: the reset is usually in a file this PR did
    # not touch. Scoping it would resurrect the false positives it exists to
    # prevent. Measured 2026-08-09: NO repo in the fleet has one today, so this
    # pre-pass suppresses nothing right now — it is here so that fixing the
    # 43 findings this commit surfaces actually turns the gate green.
    #
    # Fails CLOSED: any error leaves the flag at 0, i.e. more findings, never
    # fewer. A crashed pre-pass must not manufacture a global exemption.
    _rm_global=0
    if [ -n "$(_a11y_style_files)" ]; then
        _rm_global=$(_a11y_style_files | python3 -c '
import re, sys
UNIVERSAL = re.compile(
    r"@media[^{]*prefers-reduced-motion[^{]*\{(?:[^{}]|\{[^{}]*\})*?(?<![\w.#\[-])\*",
    re.IGNORECASE | re.DOTALL)
for line in sys.stdin:
    p = line.strip()
    if not p:
        continue
    try:
        if UNIVERSAL.search(open(p, encoding="utf-8", errors="replace").read()):
            print(1)
            break
    except Exception:
        continue
else:
    print(0)
' 2>>"${_rm_log}.err" || echo 0)
        [ "${_rm_global}" = "1" ] || _rm_global=0
    fi
    export HYDRA_RM_GLOBAL_GUARD="${_rm_global}"
    while IFS= read -r vue; do
        _in_scope "${vue}" || continue
        _rm_seen=$((_rm_seen + 1))
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
        #
        # `2>/dev/null` alone discarded the traceback AND the failure, so a
        # broken interpreter left an empty log and this gate called it clean.
        # Measured 2026-08-08 with a `python3` that exits 1 on every call:
        # every gate in this band that read its checker's return code said
        # SKIPPED (wiring); this one said PASS. stderr is kept so the reason
        # is recoverable, and the status is read.
        set +e
        python3 - "$vue" <<'PYRM' >> "${_rm_log}" 2>>"${_rm_log}.err"
import os, re, sys
fname = sys.argv[1]
try:
    src = open(fname, encoding='utf-8', errors='replace').read()
except Exception:
    sys.exit(0)

# A repo-wide universal reduced-motion reset covers every stylesheet AND every
# scoped <style> block (it is `*` + `!important`), so it globally guards. See
# the pre-pass in the runner for why this is not diff-scoped.
if os.environ.get('HYDRA_RM_GLOBAL_GUARD') == '1':
    sys.exit(0)

STYLESHEET = re.compile(r'\.(css|scss|sass|less)$', re.IGNORECASE)
MOTION = re.compile(r'\b(transition|animation)\s*:\s*([^;}]*)', re.IGNORECASE)

# THE GUARD MUST RECOGNISE THE MEDIA QUERIES PEOPLE ACTUALLY WRITE (#287).
#
# The old pattern demanded `@media` followed IMMEDIATELY by `(`. It therefore
# did not recognise `@media screen and (prefers-reduced-motion: reduce)`, nor
# the `@media (prefers-reduced-motion)` shorthand, nor the inverse
# `@media (prefers-reduced-motion: no-preference) { ...motion here... }` idiom.
# All three are correct, and all three would have been reported as findings the
# moment stylesheets came into scope — a false-positive engine.
GUARD = re.compile(r'@media[^{]*prefers-reduced-motion', re.IGNORECASE)

# A COMMENTED-OUT DECLARATION IS NOT A DECLARATION (#294, same lesson).
def _mask_comments(text, scss):
    text = re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), text, flags=re.DOTALL)
    if scss:
        # `//` only in SCSS/SASS/LESS dialects, and never the `//` of a
        # `url(https://...)`, which is why the colon is excluded before it.
        text = re.sub(r'(?<!:)//[^\n]*', lambda m: ' ' * len(m.group(0)), text)
    return text

def _motion_without_guard(block):
    """True when the block animates something and never guards it.

    `transition: none` / `animation: none` are how a reduced-motion fallback
    is WRITTEN. Counting them as motion would make every correct guard block
    its own finding."""
    if GUARD.search(block):
        return False
    for m in MOTION.finditer(block):
        value = m.group(2).strip().lower()
        if value.split()[0:1] in ([], ['none'], ['unset'], ['initial'], ['inherit']):
            continue
        return True
    return False

def _is_minified(text):
    """Generated/minified output, detected by CONTENT rather than by filename.

    `.min.css` is caught by the enumerator, but webpack writes `css/main-<hash>
    .chunk.css` with no `.min` in the name — app-versions ships five of them,
    every one a single 3 kB line of `data-v-` scoped rules. Findings in
    generated CSS are unactionable in the file they are reported against: the
    fix belongs in the source the bundler compiled. A 500-character line is
    something no hand-written stylesheet has and every minified one does."""
    return any(len(line) > 500 for line in text.split('\n'))

if STYLESHEET.search(fname):
    if _is_minified(src):
        sys.exit(0)
    scss = not fname.lower().endswith('.css')
    if _motion_without_guard(_mask_comments(src, scss)):
        print(f'{fname}: stylesheet rule=motion-without-reduced-motion-fallback')
else:
    for m in re.finditer(r'<style\b([^>]*)>(.*?)</style>', src, re.IGNORECASE | re.DOTALL):
        attrs, block = m.group(1), m.group(2)
        scss = bool(re.search(r'lang\s*=\s*["\']?(scss|sass|less)', attrs, re.IGNORECASE))
        if _motion_without_guard(_mask_comments(block, scss)):
            print(f'{fname}: <style> rule=motion-without-reduced-motion-fallback')
PYRM
        # `$?` immediately after a heredoc-fed command is the command's status;
        # captured into a named variable rather than tested inline so
        # ShellCheck's SC2181 ("check the exit code directly") does not fire on
        # a construct where `if ! cmd <<HEREDOC` is not available.
        _rm_one=$?
        [ "${_rm_one}" -ne 0 ] && _rm_rc=1
    done < <(_a11y_markup_files; _a11y_style_files)
    _rm_fail=$(wc -l < "${_rm_log}" 2>/dev/null || echo 0)
    if [ "${_rm_rc}" -ne 0 ]; then
        _skip 45 "prefers-reduced-motion" wiring "the inline reduced-motion checker exited non-zero on at least one of ${_rm_seen} markup/stylesheet file(s) — no verdict was produced for them; motion without a prefers-reduced-motion fallback is UNVERIFIED by this run. See ${_rm_log}.err."
    elif [ "${_rm_seen}" -eq 0 ]; then
        # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
        _skip 45 "prefers-reduced-motion" na "scope was empty — 0 markup or stylesheet file(s) (css/, src/, templates/, appinfo/templates/) in this diff, so NO <style> block and NO stylesheet was inspected; motion without a prefers-reduced-motion fallback is UNVERIFIED by this run."
    elif [ "${_rm_fail}" -eq 0 ]; then
        _pass 45 "prefers-reduced-motion"
    else
        _fail 45 "prefers-reduced-motion" "${_rm_fail} <style> block(s)/stylesheet(s) with motion but no reduced-motion fallback — see ${_rm_log}"
    fi
fi


# ---------------------------------------------------------------------------
# Gate 46: Spec-anchor-existence — every `@spec openspec/...` PHPDoc/JSDoc
# tag in a changed file must resolve to an existing file AND (when a
# `#fragment` is present) an existing section anchor. Gate-16 checks the
# tag EXISTS; this gate checks its TARGET resolves. Observed 2026-07-03
# on opencatalogi#85 where `@spec openspec/specs/federation/spec.md
# #requirement-directory-self-detection` pointed at a non-existent
# requirement — gate-16 accepted the tag because it was present.
#
# Skill: .claude/skills/hydra-gate-spec-anchor-existence/SKILL.md
# ---------------------------------------------------------------------------
# THE SCOPE MUST BE EVERY PLACE A `@spec` TAG IS WRITTEN (#322).
#
# The enumerator was `find lib src`. It has never opened a test file — and
# `tests/` is where a large share of the fleet's `@spec` tags live, because a
# test is the natural place to name the requirement it proves. Measured
# 2026-08-09 across the 21 apps that carry an `openspec/`: 272 unresolved
# targets in `tests/`, in 16 repos, that no run has ever reported.
#
# The textbook case is procest. `tests/Unit/BackgroundJob/DsoDeadlineJobTest.php`
# carries `@spec openspec/changes/dso-omgevingsloket/tasks.md#T14`, and that
# tasks.md numbers its tasks T01–T08 and its verifications V01–V10. There is no
# T14 and there never was. The identical tag in `lib/` would have failed this
# gate since #246.
#
# `tests/` ONLY — `openspec/` IS DELIBERATELY NOT IN SCOPE.
#
# It looks like the obvious next directory and it is a trap, measured rather
# than assumed: adding it yields 292 findings, and the bulk are documentation
# TEMPLATES that quote the tag syntax rather than use it —
# `openspec/changes/{name}/tasks.md#task-N`, `openspec/changes/<slug>/tasks.md`,
# `openspec/.../tasks.md#task-N` — in context-briefs and proposals across
# shillinq, pipelinq and others. Those placeholders cannot resolve and are not
# meant to. Auditing them would make gate-46 a noise generator on exactly the
# files that explain what the gate wants, which is how a real finding gets
# buried. If per-document annotation is wanted later it needs a way to tell a
# quoted example from a live tag; it is not this gate's job today.
#
# WHAT THIS IS NOT. #322 as filed reports that `tasks.md` targets are "never
# existence-checked" — 353 of them on doriath. That premise does not hold on
# this package, and the correction is recorded here so nobody re-fixes it: a
# planted `@spec openspec/changes/does-not-exist-at-all/tasks.md#task-1` IS
# reported as "target file not found", and a planted `#task-99999` against a
# real tasks.md IS reported as "anchor not found". The 353 tags all resolve
# through `build_archive_index`, which exists precisely for the
# archived-under-a-date-prefix case the issue describes — doriath's
# `openspec/changes/implement-user-sharing/tasks.md` resolves to
# `openspec/changes/archive/2026-06-14-implement-user-sharing/tasks.md`, and
# that file exists at the very commit the issue measured. What made the gate
# say PASS there is ADR-020 diff scoping, not blindness. The REAL hole the
# investigation uncovered is the one fixed above: `tests/` was never enumerated.
_sae_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-spec-anchor-existence.log
: > "${_sae_log}"
_sae_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _sae_files+=("$f")
done < <(find lib src tests \( -name '*.php' -o -name '*.vue' -o -name '*.js' -o -name '*.ts' -o -name '*.md' \) \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' \
    -not -path '*/dist/*' -not -path '*/build/*' 2>/dev/null)
_sae_ran=1
_sae_helper="${SCRIPT_DIR}/lib/check_spec_anchors.py"
if [ "${#_sae_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    #
    # This branch was a bare `:` and the gate fell through to `_pass 46`. On a
    # diff that touches no lib/ or src/ file — a README, a workflow, a
    # composer bump — the gate opened no file at all and printed PASS, exactly
    # the shape #258 removed from gates 19/25/62/63 and #268 then categorised.
    # Gates 4/6/7/28 have said `na` for the identical situation since #268.
    _sae_ran=0
    _skip 46 "spec-anchor-existence" na "scope was empty — 0 lib/, src/ or tests/ file(s) in this diff, so NO @spec target was resolved. Diff-scoped out under ADR-020: nothing in this repository is missing, and no change the author could make would let this gate inspect a file the diff does not contain. It runs on the next PR that touches annotated code."
elif [ ! -f "${_sae_helper}" ]; then
    # A MISSING HELPER MUST NOT REPORT PASS (#147). The gate previously
    # carried its resolver inline, so "the helper is absent" was not a
    # reachable state; now that it lives in scripts/lib it is, and an absent
    # resolver looks exactly like a repository with no dangling anchors.
    _sae_ran=0
    _skip 46 "spec-anchor-existence" wiring "check_spec_anchors.py not found at ${_sae_helper} — ${#_sae_files[@]} file(s) were in scope and NONE had their @spec targets resolved; dangling spec references are UNVERIFIED by this run."
else
    # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
    #
    # `|| true` swallowed the status, so a resolver that died left an empty
    # log and this gate called the repository clean. Measured 2026-08-08 with
    # a `python3` that exits 1 on every call: gate-46 printed PASS over the
    # 277 unresolved @spec findings, across 104 distinct targets, that it had
    # reported one run earlier on the same tree. This gate has form here —
    # its helper did not exist at all in packages before #246, which is how
    # three repos pinned at v1.3.0 ran it and saw nothing.
    set +e
    python3 "${_sae_helper}" "${_sae_log}" "${_sae_files[@]}" 2>>"${_sae_log}.err"
    _sae_rc=$?
    if [ "${_sae_rc}" -ne 0 ]; then
        _sae_ran=0
        _skip 46 "spec-anchor-existence" wiring "check_spec_anchors.py exited ${_sae_rc} — ${#_sae_files[@]} file(s) were in scope and no verdict was produced; dangling @spec targets are UNVERIFIED by this run. See ${_sae_log}.err."
    fi
fi
set +e
_sae_fail=$(wc -l < "${_sae_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_sae_fail}" ] && _sae_fail=0
if [ "${_sae_ran}" -eq 1 ]; then
    if [ "${_sae_fail}" -eq 0 ]; then
        _pass 46 "spec-anchor-existence"
    else
        # A FINDING COUNT IS NOT A DEFECT COUNT.
        #
        # One dangling target annotated on 15 methods emits 15 findings, and
        # portaliq's "100 findings" were 29 distinct targets. Reporting only
        # the raw line count made gate-46 look like a mountain of separate
        # defects and drove people to grind tags one file at a time, when the
        # actual work is one repoint per TARGET. Both numbers are printed so
        # the size of the job is legible from the summary line.
        set +e
        _sae_targets=$(sed 's/^[^:]*: //' "${_sae_log}" 2>/dev/null | sort -u | wc -l | tr -d ' ')
        set +e
        [ -z "${_sae_targets}" ] && _sae_targets="?"
        _fail 46 "spec-anchor-existence" "${_sae_fail} unresolved @spec finding(s) from ${_sae_targets} distinct target(s) — fix the TARGET, not each tag; see ${_sae_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 47: Security-change-has-tests — any PR touching security-sensitive
# code (auth annotations, CSRF, session, URL parsing, permission checks)
# must ALSO touch at least one file under tests/. Observed 2026-07-03 on
# opencatalogi#85 (SSRF hardening) and opencatalogi#86 (DELETE scope) —
# both shipped security-adjacent changes with zero test files touched and
# both had blockers surface only via manual review.
#
# Opt-out: `[hydra-gate-security-change-has-tests exclude] <reason>` in
# the PR body or head commit message (≥ 20 chars).
#
# Skill: .claude/skills/hydra-gate-security-change-has-tests/SKILL.md
# ---------------------------------------------------------------------------
_scht_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-security-change-has-tests.log
: > "${_scht_log}"
_scht_ran=1
_scht_helper="${SCRIPT_DIR}/lib/check_security_cochange.py"
if [ "${SCOPE_TO_DIFF}" != "1" ] || [ -z "${BASE_REF}" ]; then
    # NO DIFF, NO VERDICT — and NOT a PASS (#242/#240/#258/#268).
    #
    # This gate's whole subject is "what did this change touch, and did it also
    # touch a test". A builder full-repo run has no base to diff against, so the
    # `if` above was simply false and the gate fell through to `_pass 47`,
    # announcing a co-change verdict it had not formed. Every full-repo run in
    # the fleet — every builder iteration — printed that green.
    _scht_ran=0
    _skip 47 "security-change-has-tests" na "this run is not diff-scoped (no --scope-to-diff / no base ref), so there is no change set to classify. This gate compares a PR's hunks against the tests it touched; on a whole-repository run there is no such pair, and no change in this repository could create one."
elif [ "${SCOPE_TO_DIFF}" = "1" ] && [ -n "${BASE_REF}" ]; then
    if [ ! -f "${_scht_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147).
        _scht_ran=0
        _skip 47 "security-change-has-tests" wiring "check_security_cochange.py not found at ${_scht_helper} — the diff was NOT classified; a security change shipping without a test co-change is UNVERIFIED by this run."
        _scht_sec=""
        _scht_has_test="1"
    else
        # CLASSIFY ON THE HUNKS, NOT THE FILE.
        #
        # This was `grep -qE "<tokens>" "$f"` over the WHOLE FILE, so a file
        # counted as a security change if a token appeared anywhere in it —
        # in a method the PR never went near, in an import, in prose. Two
        # agents hit it the same day: a PR whose hunks were CSS custom
        # properties and a chevron column was told to add a CSRF test, and a
        # provably comment-only PR (all 30 changed lib/ lines inside
        # docblocks) was told to co-change tests. Neither used the opt-out,
        # which is the tell — people do not reach for an opt-out when they
        # believe the finding is wrong.
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
        #
        # `2>/dev/null || true` threw away the traceback and the status, and
        # "no output" is exactly what a clean diff produces — so a dead
        # classifier was indistinguishable from a clean bill of health.
        # Measured 2026-08-08 on a diff that DROPS #[NoCSRFRequired] from a
        # controller with no test co-change: this gate reported FAIL with a
        # working interpreter and PASS with one that exits 1, while gate-48,
        # which reads its helper's status, said SKIPPED (wiring) on the same
        # run over the same diff.
        set +e
        _scht_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-security-change-has-tests.err"
        _scht_sec=$(python3 "${_scht_helper}" "${BASE_REF}" "$(pwd)" 2>"${_scht_err}")
        _scht_rc=$?
        if [ "${_scht_rc}" -ne 0 ]; then
            _scht_ran=0
            _scht_sec=""
            _skip 47 "security-change-has-tests" wiring "check_security_cochange.py exited ${_scht_rc} — the diff was NOT classified; a security change shipping without a test co-change is UNVERIFIED by this run. See ${_scht_err}."
        fi
        _scht_has_test=""
        while IFS= read -r f; do
            [ -z "$f" ] && continue
            case "$f" in
                tests/*|*/tests/*|*.spec.js|*.spec.ts|*.spec.vue|*Test.php) _scht_has_test="1" ;;
            esac
        done <<< "$(git diff --name-only "${BASE_REF}...HEAD" 2>/dev/null || true)"
    fi
    if [ -n "${_scht_sec}" ] && [ -z "${_scht_has_test}" ]; then
        # Check for opt-out in PR body or head commit message
        _scht_optout_re='\[hydra-gate-security-change-has-tests exclude\][[:space:]]+.{20,}'
        _scht_optout=""
        _optout_text | grep -qE "${_scht_optout_re}" && _scht_optout="1"
        if [ -z "${_scht_optout}" ]; then
            printf "%s\n" "${_scht_sec}" >> "${_scht_log}"
        fi
    fi
fi
_scht_fail=$(wc -l < "${_scht_log}" 2>/dev/null | tr -d ' ')
[ -z "${_scht_fail}" ] && _scht_fail=0
if [ "${_scht_ran}" -eq 1 ]; then
    if [ "${_scht_fail}" -eq 0 ]; then
        _pass 47 "security-change-has-tests"
    else
        _fail 47 "security-change-has-tests" "${_scht_fail} security-touching change(s) without a test co-change — see ${_scht_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 48: CSRF-cochange — when the diff REMOVES @NoCSRFRequired /
# #[NoCSRFRequired] from a controller method, the SAME diff must touch
# every frontend caller of that endpoint so it sends a CSRF-satisfying
# header (`OCS-APIRequest: true`, `requesttoken`, or `@nextcloud/axios`).
# Observed 2026-07-03 on opencatalogi#79 — @NoCSRFRequired removed on
# destroy(), delete-modal fetch() still had no CSRF header.
#
# Skill: .claude/skills/hydra-gate-csrf-cochange/SKILL.md
# ---------------------------------------------------------------------------
_csrf_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-csrf-cochange.log
: > "${_csrf_log}"
_csrf_ran=1
if [ "${SCOPE_TO_DIFF}" != "1" ] || [ -z "${BASE_REF}" ]; then
    # NO DIFF, NO VERDICT — and NOT a PASS (#242/#240/#258/#268). Identical
    # reasoning to gate-47 above: "was an attribute REMOVED" is a question only
    # a diff can answer, and a full-repo run printed PASS without asking it.
    _csrf_ran=0
    _skip 48 "csrf-cochange" na "this run is not diff-scoped (no --scope-to-diff / no base ref), so there is no removal to detect. Whether a controller DROPPED @NoCSRFRequired is a property of a change set, not of a checkout, and no change in this repository could make a whole-repository run able to answer it."
elif [ "${SCOPE_TO_DIFF}" = "1" ] && [ -n "${BASE_REF}" ]; then
    # Find removed @NoCSRFRequired lines in changed PHP files.
    #
    # A REMOVED COMMENT IS NOT A REMOVED ATTRIBUTE (#191).
    #
    # This used to be `grep -E '^-.*(@NoCSRFRequired|#\[NoCSRFRequired\])'`.
    # `^-.*` puts no constraint on where the token sits, so nldesign went red
    # for ONE removed sentence of a class docblock —
    #
    #   - * (#[PublicPage] + #[NoCSRFRequired]) and the response contract are owned by
    #
    # replaced by another sentence saying the same thing. Nothing about CSRF
    # changed in that diff, and the cheapest way to clear the finding was to
    # REWORD A COMMENT: a gate satisfiable by prose manufactures the
    # appearance of a security review. scripts/lib/check_csrf_removal.py
    # requires the token in a code position — `#[` at the start of the
    # content, or `@NoCSRFRequired` at docblock-tag position.
    _csrf_helper="${SCRIPT_DIR}/lib/check_csrf_removal.py"
    if [ ! -f "${_csrf_helper}" ]; then
        # A MISSING HELPER MUST NOT REPORT PASS (#147). Without it the gate
        # sees no removals at all and goes green on every diff.
        _csrf_ran=0
        _skip 48 "csrf-cochange" wiring "check_csrf_removal.py not found at ${_csrf_helper} — the controller diff was NOT examined; a dropped @NoCSRFRequired is UNVERIFIED by this run."
        _csrf_removed=""
    else
        set +e
        _csrf_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-csrf-cochange.err"
        _csrf_removed=$(git diff -U0 "${BASE_REF}...HEAD" -- 'lib/Controller/*.php' 2>/dev/null \
            | python3 "${_csrf_helper}" 2>"${_csrf_err}")
        _csrf_rc=$?
        if [ "${_csrf_rc}" -ne 0 ]; then
            _csrf_ran=0
            _csrf_removed=""
            _skip 48 "csrf-cochange" wiring "check_csrf_removal.py exited ${_csrf_rc} — the controller diff was NOT examined. See ${_csrf_err}."
        fi
    fi
    if [ -n "${_csrf_removed}" ]; then
        # Look for frontend co-change signals in the diff.
        #
        # The `|| echo 0` here was worse than cosmetic: on ZERO signals grep
        # printed "0" and exited 1, the fallback appended a second "0", and the
        # `-eq 0` test below then ERRORED on "0\n0" and took the else branch —
        # i.e. "no frontend co-change at all" was read as "co-change found" and
        # the gate PASSED. A CSRF-protection removal with no frontend counterpart
        # is precisely what this gate exists to stop, so the bug was fail-OPEN.
        _csrf_fe_signals=$(git diff "${BASE_REF}...HEAD" -- 'src/**/*.vue' 'src/**/*.js' 'src/**/*.ts' 2>/dev/null \
            | grep -cE '^\+.*(OCS-APIRequest|requesttoken|@nextcloud/axios|getRequestToken)' 2>/dev/null || true)
        _csrf_fe_signals="${_csrf_fe_signals%%$'\n'*}"
        case "${_csrf_fe_signals}" in ''|*[!0-9]*) _csrf_fe_signals=0 ;; esac
        if [ "${_csrf_fe_signals}" -eq 0 ]; then
            # -----------------------------------------------------------------
            # "NO CO-CHANGE IN THE DIFF" IS NOT "NO CO-CHANGE NEEDED".
            #
            # The question above is asked of the DIFF. A PR whose callers have
            # ALWAYS sent a token has no signal to add, and so could not pass —
            # the only exits were a waiver or staying red.
            #
            # Measured on larpingapp#298, which closes a LIVE CSRF-forgery hole:
            # `SettingsController::create()` and `reimport()` carried
            #
            #     * @NoCSRFRequired removed to close the CSRF-forgery surface (closes #206).
            #
            # at docblock-tag position, where Nextcloud's
            # ControllerMethodReflector reads it as the annotation being
            # PRESENT — the sentence announcing the removal was what kept CSRF
            # disabled. Deleting it is the fix, and all three callers already
            # sent `requesttoken` while the shared CnAdminSettingsShell uses
            # @nextcloud/axios. The cheapest way to green would have been a
            # cosmetic edit under src/ containing the word `requesttoken`:
            # exactly the prose-satisfaction #191 warns against.
            #
            # So ask the state instead of the diff — is any mutating caller
            # unprotected RIGHT NOW? Conservative in the direction that matters:
            # every caller protected => the endpoint's caller is protected too;
            # any caller unprotected => we cannot show it is not this endpoint's,
            # so the removal still blocks. opencatalogi#79 (a delete-modal
            # fetch() with no header) is still caught — its own test pins that.
            # -----------------------------------------------------------------
            _csrf_callers_helper="${SCRIPT_DIR}/lib/check_csrf_callers.py"
            _csrf_unprotected=""
            _csrf_callers_ran=0
            if [ -f "${_csrf_callers_helper}" ] && [ -d src ]; then
                set +e
                _csrf_callers_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-csrf-callers.err"
                # Assignment inside the `if` so the helper's own status is
                # tested directly — a crashed interpreter must NOT be read as
                # "no unprotected callers found", which is the fail-open shape
                # this gate has been bitten by before.
                if _csrf_unprotected=$(python3 "${_csrf_callers_helper}" . 2>"${_csrf_callers_err}"); then
                    _csrf_callers_ran=1
                else
                    _csrf_unprotected=""
                fi
                set +e
            fi
            # Check for opt-out
            _csrf_optout_re='\[hydra-gate-csrf-cochange exclude\][[:space:]]+.{20,}'
            _csrf_optout=""
            _optout_text | grep -qE "${_csrf_optout_re}" && _csrf_optout="1"
            if [ "${_csrf_callers_ran}" -eq 1 ] && [ -z "${_csrf_unprotected}" ]; then
                # Stated, never silent: a green here is a claim about the
                # callers, and the reader must be able to see which claim.
                echo "[gate-48] no CSRF signal was ADDED by this diff, and none was needed:" \
                    "every mutating call site under src/ already carries one" \
                    "(requesttoken / OCS-APIRequest / getRequestToken / @nextcloud/axios)." \
                    "Checked by scripts/lib/check_csrf_callers.py over the working tree."
            elif [ -z "${_csrf_optout}" ]; then
                echo "@NoCSRFRequired removed but no frontend CSRF-signal added in diff:" >> "${_csrf_log}"
                echo "${_csrf_removed}" >> "${_csrf_log}"
                if [ "${_csrf_callers_ran}" -eq 1 ] && [ -n "${_csrf_unprotected}" ]; then
                    echo "UNPROTECTED mutating call site(s) — these are why the removal blocks:" >> "${_csrf_log}"
                    echo "${_csrf_unprotected}" >> "${_csrf_log}"
                elif [ "${_csrf_callers_ran}" -eq 0 ]; then
                    echo "(caller state NOT inspected: check_csrf_callers.py missing or no src/ — falling back to the diff-only question)" >> "${_csrf_log}"
                fi
            fi
        fi
    fi
fi
set +e
_csrf_fail=$(wc -l < "${_csrf_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_csrf_fail}" ] && _csrf_fail=0
if [ "${_csrf_ran}" -eq 1 ]; then
    if [ "${_csrf_fail}" -eq 0 ]; then
        _pass 48 "csrf-cochange"
    else
        _fail 48 "csrf-cochange" "@NoCSRFRequired dropped without frontend CSRF co-change — see ${_csrf_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 49: Controller-exception-translation — a controller method that
# calls a service function with documented `@throws DoesNotExistException`
# (or NotFoundException, PermissionException, ValidationException, ...)
# must either wrap the call in try/catch translating to JSONResponse, OR
# declare the same @throws in its own docblock so propagation is
# intentional. Observed 2026-07-03 on opencatalogi#86 — destroy() called
# ObjectService::deleteObject() which re-throws DoesNotExistException on
# scope-mismatch, but destroy() had no try/catch → HTTP 500 on the exact
# defended path.
#
# Skill: .claude/skills/hydra-gate-controller-exception-translation/SKILL.md
# ---------------------------------------------------------------------------
_cxt_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-controller-exception-translation.log
: > "${_cxt_log}"
_cxt_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _cxt_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Controller)
_cxt_rc=0
if [ "${#_cxt_files[@]}" -gt 0 ]; then
    # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262). The status
    # of this inline python was never read, so a broken interpreter left an
    # empty log and the gate called every controller clean.
    set +e
    python3 - "${_cxt_log}" "${_cxt_files[@]}" 2>>"${_cxt_log}.err" << 'PY'
import re, sys, os
log_path = sys.argv[1]
files = sys.argv[2:]
# Documented-throw shapes we track — these are known-not-auto-translated by NC's dispatcher.
TRACKED = [
    'DoesNotExistException',
    'MultipleObjectsReturnedException',
    'NotFoundException',
    'PermissionException',
    'ValidationException',
    'ForbiddenException',
    'CustomValidationException',
    'AppendOnlyException',
    'ArchivalImmutableException',
]
METHOD_RE = re.compile(
    r'(/\*\*[\s\S]*?\*/)?\s*'                    # optional preceding docblock
    r'(public|protected|private)\s+function\s+'
    r'(?P<name>\w+)\s*\([^)]*\)[^{]*\{',
    re.MULTILINE,
)
def _method_bodies(src):
    out = []
    for m in METHOD_RE.finditer(src):
        start = m.end() - 1  # position of the `{`
        depth = 0
        p = start
        while p < len(src):
            c = src[p]
            if c == '{':
                depth += 1
            elif c == '}':
                depth -= 1
                if depth == 0:
                    out.append({
                        'name': m.group('name'),
                        'docblock': m.group(1) or '',
                        'body': src[start+1:p],
                        'start_line': src[:m.start()].count('\n') + 1,
                    })
                    break
            p += 1
    return out
for fp in files:
    try:
        with open(fp, encoding='utf-8', errors='replace') as f:
            src = f.read()
    except OSError:
        continue
    methods = _method_bodies(src)
    for m in methods:
        body = m['body']
        # Find calls of shape $this->prop->method(...) which is a proxy for "calls a service"
        service_calls = re.findall(r'\$this->\w+->(\w+)\s*\(', body)
        if not service_calls:
            continue
        # Heuristic: if the method body ALREADY has try/catch of one of the tracked exceptions,
        # or its docblock declares @throws for one of them, accept it as intentionally handled.
        #
        # `\Throwable` and `\Exception` count too. They are SUPERSETS of every
        # name in TRACKED, so a method catching one of them handles all nine and
        # more. Rejecting them reported the broadest possible translation as no
        # translation at all — measured on hermiq#162, where three controller
        # methods each caught \Throwable, returned a translated JSON error and
        # logged the cause, and were still reported as unhandled.
        #
        # The cost of that false positive is not the red check. It is that the
        # finding pushes the author toward the NARROWER handler: catch the one
        # named exception, leave everything else to become a framework 500 with
        # a stack trace — which for a #[NoAdminRequired] method reaches a
        # non-admin. A gate that rejects the stronger guarantee teaches people
        # to write the weaker one.
        _CATCH_ALL = ['Throwable', 'Exception']
        try_ok = re.search(
            r'catch\s*\(\s*[\\\w]*(' + '|'.join(TRACKED + _CATCH_ALL) + r')\b',
            body,
        )
        # Same reasoning on the docblock side: `@throws \Throwable` declares a
        # superset of the tracked names, so it is at least as informative.
        throws_ok = any(x in (m['docblock'] or '') for x in TRACKED + _CATCH_ALL)
        if try_ok or throws_ok:
            continue
        # Otherwise: if any SERVICE CALL invokes a known-throwy shape, log the
        # method. Matched against `service_calls` — the `$this->prop->name(`
        # receivers collected above — and NOT against free text in the body.
        #
        # The free-text form asked two unrelated questions and ANDed them:
        # "does this method call any service at all?" and "does one of these
        # words appear anywhere?". `getObject` is on the list because
        # OpenRegister's ObjectService HAS a getObject() — but ObjectEntity has
        # one too, and that one is a plain array accessor that throws nothing.
        # So `$entity->getObject()`, which is how every controller reads an
        # object it already holds, counted as a risky service call. On
        # openconnector that was 9 of 12 findings, each pointing at a line
        # doing nothing riskier than reading a property off an entity in hand.
        #
        # `find` joins the list at the same time: ObjectService::find()
        # documents `@throws Exception If the object is not found`, and reading
        # one object by id is the commonest way a controller meets that throw.
        # `findAll` deliberately does NOT join it — that one documents no
        # throws, and adding it would flag every list endpoint in the fleet,
        # trading one set of false positives for another.
        RISKY = re.compile(
            r'^(deleteObject|findObject|find|saveObject|updateObject'
            r'|loadObject|get(One|Object|Register|Schema))$'
        )
        risky = any(RISKY.match(name) for name in service_calls)
        if risky:
            with open(log_path, 'a', encoding='utf-8') as g:
                g.write(f"{fp}:{m['start_line']}: {m['name']}() calls a service method that may throw a tracked exception, "
                        f"but has no matching try/catch and no @throws declaration\n")
PY
    _cxt_rc=$?
fi
# Opt-out: `[hydra-gate-controller-exception-translation exclude] <reason>` in
# the PR body or head commit message (≥ 20 chars).
if [ -s "${_cxt_log}" ]; then
    _cxt_optout_re='\[hydra-gate-controller-exception-translation exclude\][[:space:]]+.{20,}'
    _cxt_optout=""
    _optout_text | grep -qE "${_cxt_optout_re}" && _cxt_optout="1"
    [ -n "${_cxt_optout}" ] && : > "${_cxt_log}"
fi
set +e
_cxt_fail=$(wc -l < "${_cxt_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_cxt_fail}" ] && _cxt_fail=0
if [ "${_cxt_rc}" -ne 0 ]; then
    _skip 49 "controller-exception-translation" wiring "the inline exception-translation checker exited ${_cxt_rc} — ${#_cxt_files[@]} lib/Controller file(s) were in scope and no verdict was produced; untranslated service exceptions are UNVERIFIED by this run. See ${_cxt_log}.err."
elif [ "${#_cxt_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268) — same category
    # gates 6/7 already use for the identical "0 controllers in this diff".
    _skip 49 "controller-exception-translation" na "scope was empty — 0 lib/Controller PHP file(s) in this repo or this diff, so NO controller method was inspected; untranslated service exceptions (HTTP 500 on a defended path) are UNVERIFIED by this run."
elif [ "${_cxt_fail}" -eq 0 ]; then
    _pass 49 "controller-exception-translation"
else
    _fail 49 "controller-exception-translation" "${_cxt_fail} controller method(s) missing try/catch or @throws — see ${_cxt_log}"
fi

# ---------------------------------------------------------------------------
# Gate 50: Security-config-fail-mode — controllers/services reading a
# security-relevant config key via `$this->config->getValueString(...)`
# must handle the empty-default explicitly (fail closed, log-warn, or
# guard). Silent fallback on empty deactivates the defense. Observed
# 2026-07-03 on opencatalogi#86 — empty `listing_register` OR
# `listing_schema` silently deactivated WOO-515's scope-DELETE guard.
#
# Skill: .claude/skills/hydra-gate-security-config-fail-mode/SKILL.md
# ---------------------------------------------------------------------------
_scfm_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-security-config-fail-mode.log
: > "${_scfm_log}"
_scfm_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _scfm_files+=("$f")
done < <(_enum_tracked '(Controller|Service)[^/]*\.php$' lib)
_scfm_rc=0
if [ "${#_scfm_files[@]}" -gt 0 ]; then
    # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
    set +e
    python3 - "${_scfm_log}" "${_scfm_files[@]}" 2>>"${_scfm_log}.err" << 'PY'
import re, sys
log_path = sys.argv[1]
files = sys.argv[2:]
SEC_KEY = re.compile(
    # FIRST ARGUMENT: THE APP ID, IN ANY FORM.
    #
    # This was `['\"][^'\"]+['\"]` — a QUOTED STRING LITERAL only. The
    # fleet-standard idiom is a class constant:
    #
    #     $this->appConfig->getValueString(Application::APP_ID, 'listing_register', '')
    #
    # so every read written that way was invisible to this gate. Measured
    # 2026-08-08: an unguarded read of `listing_register` — the exact
    # opencatalogi#86 defect this gate was built for — reported PASS with
    # `Application::APP_ID`, and FAIL with `'larpingapp'`, one token apart.
    # 7 real security-relevant reads across 5 fleet repos sit behind a
    # constant today. Same family as #184: a checker that matches a string
    # literal misses every constant.
    #
    # LITERAL OR CLASS CONSTANT — AND DELIBERATELY NOT AN ARBITRARY EXPRESSION.
    #
    # The first draft of this fix accepted `[^,()]+`, i.e. anything up to the
    # comma, which also takes `$app` and `$this->appName`. That is a strictly
    # larger widening than the defect measured, and a before/after sweep of 12
    # repos caught what it costs: softwarecatalog went 23 -> 64 findings, and
    # 47 of the new ones are reads inside SETTINGS READ-OUTS —
    #
    #     'sendgridApiKey' => $this->config->getValueString($app, 'email_sendgrid_api_key', ''),
    #
    # entries in an array literal that assembles the admin settings payload.
    # There is no defense being deactivated there and nothing to guard: the
    # finding has no legitimate end state, which is the unclosable-gate shape
    # (#252). A gate that emits 47 of those in one repo teaches people to stop
    # reading it.
    #
    # So the accepted shapes are the ones the fleet actually writes for an app
    # id — a quoted literal, or a class constant (`Application::APP_ID`,
    # `self::APP_ID`, `static::APP_ID`) — which is exactly the blind spot that
    # was measured: 7 security-relevant reads across 5 repos.
    #
    # KNOWN BLIND SPOT, STATED RATHER THAN QUIETLY ENFORCED: a read whose app
    # id is a plain variable is still invisible to this gate. It is not a safe
    # shape, it is an unmeasured one — separating the settings read-outs from
    # the real scope decisions among them needs a data-flow question this
    # regex cannot ask.
    r"getValue(?:String|Bool|Int)\s*\(\s*"
    r"(?:['\"][^'\"]*['\"]|(?:self|static|parent|[A-Z]\w*)(?:\\\w+)*::\w+)"
    r"\s*,\s*['\"]"
    r"(?P<key>[^'\"]*"
    r"(?:register|schema|allow[_-]?list|allow_?list|whitelist|blocklist|"
    # Quote-or-underscore-anchored short tokens so `author_name`,
    # `authenticate`, `oauth_client_id` don't spuriously match, while
    # bare `auth`/`rbac`/`permission` and long-form suffixed keys
    # (auth_key, basic_auth, rbac_scope, permission_check) do. Uses
    # lookaround so neither anchor consumes the closing key-quote —
    # a consuming form corrupts the outer capture and would fail on
    # `basic_auth` etc. because it eats the trailing `'`.
    r"csrf|(?<=['\"]|_)rbac(?=['\"]|_)|(?<=['\"]|_)permission(?=['\"]|_)|(?<=['\"]|_)auth(?=['\"]|_)|"
    r"_secret|_key|_token|instance_aliases|trusted_domains|trusted_proxies)"
    r"[^'\"]*)"
    r"['\"]",
    re.IGNORECASE,
)
for fp in files:
    try:
        with open(fp, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        continue
    src = ''.join(lines)
    for m in SEC_KEY.finditer(src):
        # Find the line number of the match
        lineno = src[:m.start()].count('\n') + 1
        # WINDOW STARTS WHERE THE CALL ENDS, NOT WHERE IT STARTS.
        #
        # It was `lines[lineno:lineno+10]` — ten lines after the line the
        # match BEGAN on, excluding that line. Two false positives fall out of
        # that, and both are ordinary code:
        #
        #   multi-line call   PHPCS-formatted reads span five lines each:
        #                         $registerId = $this->appConfig->getValueString(
        #                             Application::APP_ID,
        #                             'register',
        #                             ''
        #                         );
        #                     Two of those plus a blank line put the guard on
        #                     the ELEVENTH line — one outside the window.
        #                     Measured on procest lib/Service/AiService.php:580
        #                     and :967, where the guard is a textbook
        #                     `if (empty($registerId) === true || empty($schemaId)
        #                     === true) { $this->logger->warning(...); return; }`
        #                     and the gate called both unguarded.
        #
        #   same-line guard   `'ai_api_key_set' => $this->appConfig->getValueString(
        #                     Application::APP_ID, 'ai_api_key', '') !== ''`
        #                     handles the empty default ON THE MATCH LINE, which
        #                     the window started AFTER. procest:710.
        #
        # Both are fixed by anchoring the window to the END of the call
        # expression: balance parentheses forward from the `(`, then take the
        # remainder of THAT line plus ten more. A single-line read gets the
        # same ten lines it always did, so nothing is loosened for the shape
        # this gate was built on — only the shapes it could not see.
        _open = src.find('(', m.start())
        _depth, _p = 0, _open
        while _p < len(src) and _p != -1:
            if src[_p] == '(':
                _depth += 1
            elif src[_p] == ')':
                _depth -= 1
                if _depth == 0:
                    break
            _p += 1
        _end_line = src[:_p].count('\n') + 1 if _p < len(src) else lineno
        # `_end_line - 1` is the 0-based index of the call's closing line, so
        # the window INCLUDES the rest of that line (the same-line case).
        window = ''.join(lines[_end_line-1:_end_line+10])
        # Look for fail-mode signals
        #
        # THE EMPTY-COMPARE ARM MUST SURVIVE A COMPOUND CONDITION.
        #
        # It was `if\s*\(\s*[\$\w\->]+\s*(===|…)\s*['\"]{2}\s*\)` — a closing
        # paren required IMMEDIATELY after the empty string. So the correct,
        # idiomatic two-key guard
        #
        #     if ($reg === '' || $sch === '') { return []; }
        #
        # did not match, and BOTH reads above it were reported as unguarded.
        # Measured 2026-08-08 on larpingapp: two findings, zero defects. That
        # is the worst possible false positive for this gate, because the code
        # it rejects is the code it is asking for — an author who "fixes" it
        # by splitting into two single-key ifs has changed nothing and learned
        # that the gate rewards shape over meaning.
        #
        # The trailing `\s*\)` is dropped: the condition continues with `||`,
        # `&&`, `)` or anything else, and none of those change whether an
        # empty-string comparison happened.
        #
        # The leading `if\s*\(` is dropped for the same reason. A guard does
        # not have to be an `if` — larpingapp's SetupController::isProvisioned()
        # fails closed with
        #
        #     return $registerId !== '' && $schemaMarker !== '';
        #
        # which is a complete empty-default handler and was reported as
        # unguarded the moment the constant-app-id fix made the read visible.
        # What this gate actually asks is "did the code compare the value
        # against empty"; the statement it sits in is not the question.
        has_guard = re.search(
            r"(===|!==|==|!=)\s*['\"]{2}"                                     # empty-string compare
            r"|['\"]{2}\s*(===|!==|==|!=)"                                    # …written the other way round
            r"|\bempty\s*\("                                                  # empty()
            r"|->logger->(warning|error|critical|alert)"                      # log-warn
            r"|throw\s+new\s+"                                                # throw
            r"|return\s+new\s+(JSONResponse|Response|DataResponse)",          # early return
            window,
        )
        if not has_guard:
            with open(log_path, 'a', encoding='utf-8') as g:
                g.write(f"{fp}:{lineno}: security-relevant config read of \"{m.group('key')}\" has no fail-mode guard within 10 lines\n")
PY
    _scfm_rc=$?
fi
set +e
_scfm_fail=$(wc -l < "${_scfm_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_scfm_fail}" ] && _scfm_fail=0
if [ "${_scfm_rc}" -ne 0 ]; then
    _skip 50 "security-config-fail-mode" wiring "the inline security-config checker exited ${_scfm_rc} — ${#_scfm_files[@]} Controller/Service file(s) were in scope and no verdict was produced; a security-relevant config key silently defaulting to empty is UNVERIFIED by this run. See ${_scfm_log}.err."
elif [ "${#_scfm_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    _skip 50 "security-config-fail-mode" na "scope was empty — 0 lib/**/*Controller.php or *Service.php file(s) in this repo or this diff, so NO config read was inspected; a security-relevant key silently defaulting to empty is UNVERIFIED by this run."
elif [ "${_scfm_fail}" -eq 0 ]; then
    _pass 50 "security-config-fail-mode"
else
    _fail 50 "security-config-fail-mode" "${_scfm_fail} unsafe security-config read(s) — see ${_scfm_log}"
fi

# ---------------------------------------------------------------------------
# Gate 51: Schema-property-titles — every property of every schema in a
# changed OpenRegister register MUST carry a human-friendly English `title`
# and a `description`. The nextcloud-vue form renderer uses
# `label: prop.title || key` (fieldsFromSchema), so a property without a
# `title` shows its raw technical key (`governanceBody`, `closedAt`) to end
# users. ADR-011 (schema standards). Reference exemplars: docudesk,
# softwarecatalog.
#
# Diff-scoped at the PROPERTY level (ADR-020): under --scope-to-diff the
# helper self-scopes to lines changed vs BASE (via HYDRA_GATE_BASE_REF +
# `git diff -U0`), so only properties ADDED or MODIFIED in the PR are checked
# — legacy title debt in a TOUCHED register never blocks an unrelated PR
# (titles enforced going forward only, exactly like gate-16). Builder
# full-repo runs leave the env unset and ratchet every property. The helper
# recurses into nested object `properties` and array `items.properties`.
#
# Skill: .claude/skills/hydra-gate-schema-property-titles/SKILL.md
# ---------------------------------------------------------------------------
_spt_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-schema-property-titles.log
: > "${_spt_log}"
_spt_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _spt_files+=("$f")
done < <(_enum_tracked '(register[^/]*\.json|/register\.d/[^/]*\.json)$' lib/Settings)
_spt_ran=1
if [ "${#_spt_files[@]}" -gt 0 ]; then
    _spt_helper="${SCRIPT_DIR}/lib/check_schema_property_meta.py"
    if [ -f "${_spt_helper}" ]; then
        # Diff-scoped at the PROPERTY level (ADR-020) when --scope-to-diff is
        # set: the helper self-scopes to lines changed vs BASE_REF, so legacy
        # title debt in a touched register never blocks an unrelated PR —
        # titles are enforced going forward only (mirrors gate-16). Builder
        # full-repo runs leave HYDRA_GATE_BASE_REF unset → ratchet every prop.
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
        # `2>/dev/null || true` discarded both the traceback and the status.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_spt_helper}" "${_spt_files[@]}" >> "${_spt_log}" 2>>"${_spt_log}.err"
        else
            python3 "${_spt_helper}" "${_spt_files[@]}" >> "${_spt_log}" 2>>"${_spt_log}.err"
        fi
        _spt_rc=$?
        if [ "${_spt_rc}" -ne 0 ]; then
            _spt_ran=0
            _skip 51 "schema-property-titles" wiring "check_schema_property_meta.py exited ${_spt_rc} — ${#_spt_files[@]} register file(s) were in scope and no verdict was produced; schema properties missing a human-friendly title/description are UNVERIFIED by this run. See ${_spt_log}.err."
        fi
    else
        _spt_ran=0
        _skip 51 "schema-property-titles" wiring "check_schema_property_meta.py not found at ${_spt_helper} — ${#_spt_files[@]} register file(s) were in scope and NONE were inspected; schema property title/description quality is UNVERIFIED by this run."
    fi
fi
set +e
_spt_fail=$(wc -l < "${_spt_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_spt_fail}" ] && _spt_fail=0
if [ "${#_spt_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    _skip 51 "schema-property-titles" na "scope was empty — 0 lib/Settings/*register*.json (or register.d/*.json) file(s) in this repo or this diff, so NO schema property was inspected; missing human-friendly titles are UNVERIFIED by this run."
elif [ "${_spt_ran}" -eq 1 ]; then
    if [ "${_spt_fail}" -eq 0 ]; then
        _pass 51 "schema-property-titles"
    else
        _fail 51 "schema-property-titles" "${_spt_fail} schema property(ies) missing a human-friendly title/description — see ${_spt_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 52: Custom-widget-ratchet — govern the growth of custom kind:"widget"
# component-registry entries (the ADR-036 five-kind registry passed to
# CnAppRoot) per ADR-049 Decision 1 (built-in-first rule for widgets). Two
# mechanics, both in scripts/lib/check_custom_widget_ratchet.py:
#
#   Justification — any kind:"widget" entry ADDED or MODIFIED in the PR diff
#   without a `_note` field fails with the canonical message from the
#   hydra-gate-custom-widget-ratchet spec. Untouched legacy entries never
#   block a PR (ADR-020) — they are burned down by migrations.
#
#   Ratchet — the app's total custom-widget count on the PR head MUST NOT
#   exceed the count on BASE_REF. Growth fails even when every new entry
#   carries a `_note`, unless an in-scope entry carries the documented
#   exception marker `@custom-widget-ratchet exclude <reason>` (the
#   gate-16/19 exclude-reason convention). Counts (base/head/delta) are
#   always reported so migrations can demonstrate the count shrinking.
#
# Library built-in widget keys (object-table, card-grid, form-renderer,
# map-viewer, chart, stats-block, wiki-renderer) are not custom entries and
# are never counted. The helper receives ALL src/**/*.{js,ts,vue} candidates
# (NOT pre-filtered by _in_scope) because the ratchet count is app-wide; it
# self-scopes the justification check to changed entries via
# HYDRA_GATE_BASE_REF + git diff -U0, exactly like gate-51. When no changed
# file declares a kind:"widget" entry the helper prints nothing and exits 0
# (no-op pass — the ratchet is not computed for that PR). Builder full-repo
# runs leave the env unset: every custom entry needs a `_note`; no ratchet.
#
# Skill: .claude/skills/hydra-gate-custom-widget-ratchet/SKILL.md
# ---------------------------------------------------------------------------
_cwr_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-custom-widget-ratchet.log
: > "${_cwr_log}"
_cwr_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _cwr_files+=("$f")
done < <(find src \( -name '*.js' -o -name '*.ts' -o -name '*.vue' \) \
    -not -path '*/node_modules/*' -not -path '*/dist/*' \
    -not -path '*/build/*' 2>/dev/null)
_cwr_fail=0
_cwr_ran=1
if [ "${#_cwr_files[@]}" -gt 0 ]; then
    _cwr_helper="${SCRIPT_DIR}/lib/check_custom_widget_ratchet.py"
    if [ -f "${_cwr_helper}" ]; then
        # READ THE COUNT OFF STDOUT, NOT THE EXIT BYTE (#209).
        #
        # This was `_cwr_fail=$?` straight after the helper. An exit status
        # carries two different facts on one channel — "how many findings" and
        # "I did not finish" — and the gate could not tell them apart.
        # Measured 2026-08-08 by injecting `raise RuntimeError(...)` into the
        # helper's main(): Python exited 1 and the gate printed
        # `FAIL — 1 custom-widget finding(s)`, a fabricated finding with a
        # plausible message and nothing behind it. The helper's own count was
        # also clamped to 99 to fit in the byte, so 100+ findings under-reported.
        #
        # The helper now prints `[custom-widget-ratchet] findings=N` on every
        # completed run and exits boolean. No such line ⇒ it died ⇒ WIRING.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_cwr_helper}" "${_cwr_files[@]}" >> "${_cwr_log}" 2>&1
        else
            python3 "${_cwr_helper}" "${_cwr_files[@]}" >> "${_cwr_log}" 2>&1
        fi
        _cwr_rc=$?
        _cwr_reported=$(grep -oE '\[custom-widget-ratchet\] findings=[0-9]+' "${_cwr_log}" 2>/dev/null \
            | tail -1 | sed 's/.*findings=//')
        set +e
        case "${_cwr_reported}" in
            ''|*[!0-9]*)
                # The helper produced no count line. It crashed, was killed, or
                # is a version that predates the contract — either way it did
                # NOT judge this repository, and saying "N findings" here would
                # invent them.
                _cwr_ran=0
                _cwr_fail=0
                _skip 52 "custom-widget-ratchet" wiring "check_custom_widget_ratchet.py exited ${_cwr_rc} without printing its \`findings=\` count — it did NOT finish, so ${#_cwr_files[@]} frontend file(s) were left uninspected and custom kind:\"widget\" growth (ADR-049) is UNVERIFIED by this run. This is a broken checker, NOT a finding about the code. See ${_cwr_log}."
                ;;
            *) _cwr_fail="${_cwr_reported}" ;;
        esac
    else
        _cwr_ran=0
        _skip 52 "custom-widget-ratchet" wiring "check_custom_widget_ratchet.py not found at ${_cwr_helper} — ${#_cwr_files[@]} frontend file(s) were in scope and NONE were inspected; custom kind:\"widget\" growth (ADR-049) is UNVERIFIED by this run — no base/head/delta counts were produced."
    fi
fi
# Surface the base/head/delta report on stdout (spec: the counts are always
# reported so migrations can show the number shrinking).
_cwr_counts=$(grep -m1 -o 'base=[0-9]* head=[0-9]* delta=[+-]*[0-9]*.*' "${_cwr_log}" 2>/dev/null || true)
[ -n "${_cwr_counts}" ] && echo "[gate-52] custom-widget-ratchet: ${_cwr_counts}"
if [ "${#_cwr_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    _skip 52 "custom-widget-ratchet" na "no src/**/*.{js,ts,vue} file(s) in this repo, so there is no component registry to hold a custom kind:\"widget\" entry and no ratchet to compute."
elif [ "${_cwr_ran}" -eq 1 ]; then
    if [ "${_cwr_fail}" -eq 0 ]; then
        _pass 52 "custom-widget-ratchet"
    else
        _fail 52 "custom-widget-ratchet" "${_cwr_fail} custom-widget finding(s)${_cwr_counts:+ (${_cwr_counts})} — see ${_cwr_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 53: effective-manifest-crossref — build the EFFECTIVE manifest exactly
# as the lib bootstrap does (base src/manifest.json + src/manifest.d/*.json
# fragments in ascending filename order (ADR-037) + src/menu-layout.json
# relocations → removals → settingsSection (ADR-044)), then:
#   1. validate the ASSEMBLED result through the canonical gate-22 validator
#      (scripts/lib/check_manifest.js — canonical schema + semantic checks);
#      a fragment-introduced violation fails even when the base alone passes;
#   2. run the cross-reference joins JSON Schema cannot express
#      (scripts/lib/check_manifest_crossref.js): menu-route → page-id,
#      open-page action targets (open-modal degrades to WARN — the modal
#      registry is app code), register/schema slug resolution against
#      lib/Settings/*register*.json (+ register.d/*.json; no register JSON
#      in-repo → WARN, runtime-bound registers), deepLink route
#      correspondence, and the ADR-044 no-functionality-loss removals
#      invariant (a removal must never orphan its route).
#
# Why (2026-07-06 audit item 19): gate-22 validates ONLY the base manifest.
# shillinq ships 75+ fragments gate-22 never sees; the 2026-07-06 live e2e
# caught zaakafhandelapp detail widgets referencing besluit/resultaat schemas
# absent from any register declaration — OpenRegister 404s rendered raw
# "Request failed with status code 404" to end users.
#
# Assembly is the hydra-VENDORED buildManifest pipeline
# (scripts/lib/build_effective_manifest.js, sync-noted to
# nextcloud-vue/src/utils/buildManifest.js) — one deterministic merge
# generation fleet-wide, never the app's pinned lib copy.
#
# Diff-scope (ADR-020): with --scope-to-diff, the gate runs only when the PR
# touches src/manifest.json, src/manifest.d/**, src/menu-layout.json, or
# lib/Settings/*register*.json; otherwise it PASSes informationally. Full
# runs (builder, fleet sweep) always run. Tier 0 (no manifest) skips quietly.
#
# Fail-closed (mirrors scripts/fleet-manifest-sweep.sh): a missing helper or
# unresolvable Ajv FAILs the gate — never a silent pass.
#
# NOTE: `set -e` is still enabled at this point in the script (gates 25/26
# leave it on, see the gate-27 comment) — every command below is guarded.
#
# Skill: .claude/skills/hydra-gate-effective-manifest-crossref/SKILL.md
# Spec:  openspec/changes/gate-53-effective-manifest-crossref/specs/gate-effective-manifest-crossref/spec.md
# ---------------------------------------------------------------------------
if [ -f src/manifest.json ]; then
    _em_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-effective-manifest-crossref.log
    : > "${_em_log}"
    _em_builder="${SCRIPT_DIR}/lib/build_effective_manifest.js"
    _em_crossref="${SCRIPT_DIR}/lib/check_manifest_crossref.js"
    _em_validator="${SCRIPT_DIR}/lib/check_manifest.js"
    # Diff-scope trigger set: any manifest input touched → run for real.
    _em_touched=0
    if [ "${SCOPE_TO_DIFF}" = "1" ]; then
        while IFS= read -r _em_f; do
            [ -z "${_em_f}" ] && continue
            case "${_em_f}" in
                src/manifest.json|src/manifest.d/*|src/menu-layout.json|lib/Settings/*register*.json)
                    _em_touched=1 ;;
            esac
        done <<< "${CHANGED_FILES}"
    else
        _em_touched=1
    fi
    if [ "${_em_touched}" -eq 0 ]; then
        # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
        #
        # "Informational pass" is a contradiction: the line printed is
        # `[gate-53] effective-manifest-crossref: PASS`, and no consumer of
        # this runner's stdout — the coverage assertion, the reviewer skill,
        # a human reading the summary — can tell it apart from a manifest that
        # was assembled, validated and cross-referenced. Gates 62 and 63 answer
        # this identical condition ("touched no manifest and no menu-layout")
        # with `na`, and this gate now agrees with them.
        _skip 53 "effective-manifest-crossref" na "the diff against '${BASE_REF}' touched no manifest input (src/manifest.json, src/manifest.d/**, src/menu-layout.json, lib/Settings/*register*.json), so NO effective manifest was assembled or cross-referenced. Diff-scoped out under ADR-020 — not a gap: the manifests in this repo are unchanged from the base branch. This gate runs on the next PR that touches a manifest input."
    elif [ ! -f "${_em_builder}" ] || [ ! -f "${_em_crossref}" ] || [ ! -f "${_em_validator}" ]; then
        # Gate misconfiguration — a vendored helper is missing. Fail-closed.
        _fail 53 "effective-manifest-crossref" "vendored helper missing under ${SCRIPT_DIR}/lib (need build_effective_manifest.js + check_manifest_crossref.js + check_manifest.js) — fail-closed"
    elif ! command -v node >/dev/null 2>&1; then
        # WIRING. Named before the ajv probe below, which is itself a `node -e`
        # — with node absent that probe fails for the wrong reason and reports
        # "ajv not resolvable", sending a reader to install a library when the
        # interpreter is what is missing.
        _skip 53 "effective-manifest-crossref" wiring "a manifest input is in scope but \`node\` is not on PATH — the effective manifest could not be assembled or cross-referenced. This is a missing tool in the runner environment, not a finding about the manifest."
    elif ! node -e "require('ajv/dist/2020')" >/dev/null 2>&1 \
        && ! node -e "require.resolve('ajv/dist/2020', { paths: ['${SCRIPT_DIR}/lib'] })" >/dev/null 2>&1; then
        # Without Ajv the structural stage cannot validate the assembled
        # manifest for real — refuse to run fail-open (fleet-sweep guard).
        _fail 53 "effective-manifest-crossref" "ajv not resolvable from ${SCRIPT_DIR}/lib — refusing to run fail-open (set NODE_PATH or install ajv)"
    else
        # ---------------------------------------------------------------
        # ADR-020 diff scope (fixed 2026-08-03). The trigger above is FILE
        # granularity — and because an app's whole navigation surface lives in
        # one input set, file granularity was indistinguishable from no scoping:
        # a one-line `title` change reproduced the full-repo finding count
        # exactly (pipelinq 24/24, shillinq 246/246, every finding on a page the
        # PR had never touched).
        #
        # The joins are still ANSWERED over the whole assembled manifest — a
        # menu route cannot be resolved to a page id from a diff, and that part
        # of this gate is legitimately whole-repo. What changes is which answers
        # BLOCK: a finding blocks when the PR touched the page / menu entry /
        # top-level block it is about. Findings that address the manifest as a
        # whole keep blocking under every scope and are reported as such.
        # ---------------------------------------------------------------
        _em_scope_file=""
        _em_scoper="${SCRIPT_DIR}/lib/manifest_diff_scope.py"
        if [ "${SCOPE_TO_DIFF}" = "1" ] && [ -f "${_em_scoper}" ]; then
            _em_scope_file=$(mktemp ${HYDRA_GATE_LOG_DIR}/hydra-gate53-scope.XXXXXX 2>/dev/null || true)
            if [ -n "${_em_scope_file}" ]; then
                _em_inputs=()
                while IFS= read -r _em_f; do
                    [ -z "${_em_f}" ] && continue
                    case "${_em_f}" in
                        src/manifest.json|src/manifest.d/*|src/menu-layout.json|lib/Settings/*register*.json)
                            _em_inputs+=("${_em_f}") ;;
                    esac
                done <<< "${CHANGED_FILES}"
                set +e
                if [ "${#_em_inputs[@]}" -gt 0 ]; then
                    HYDRA_GATE_BASE_REF="${BASE_REF}" \
                        python3 "${_em_scoper}" "${_em_inputs[@]}" > "${_em_scope_file}" 2>>"${_em_log}"
                    _em_scope_rc=$?
                else
                    # The trigger said a manifest input changed; we found none.
                    # A disagreement is not a narrow scope.
                    echo "ALL" > "${_em_scope_file}"
                    _em_scope_rc=0
                fi
                set +e
                if [ "${_em_scope_rc}" -ne 0 ]; then
                    # Could not compute a scope → do not narrow. Say so.
                    echo "ALL" > "${_em_scope_file}"
                    echo "[gate-53] scope computation failed (rc=${_em_scope_rc}) — running UNSCOPED (fail toward enforcement)."
                fi
                if grep -qx 'ALL' "${_em_scope_file}" 2>/dev/null; then
                    echo "[gate-53] diff scope is INDETERMINATE for this PR (new/untracked manifest input, or a register JSON changed) — every finding blocks."
                fi
            fi
        fi
        # Built as two plain words rather than an array: `"${arr[@]}"` on an
        # EMPTY array is an unbound-variable error under `set -u` on bash < 4.4,
        # and this runner ships into containers we do not choose the bash of.
        _em_scope_flag=""
        _em_scope_val=""
        if [ -n "${_em_scope_file}" ]; then
            _em_scope_flag="--scope-ids"
            _em_scope_val="${_em_scope_file}"
        fi

        _em_tmp=$(mktemp ${HYDRA_GATE_LOG_DIR}/hydra-gate53-effective.XXXXXX.json 2>/dev/null || true)
        _em_reason=""
        if [ -z "${_em_tmp}" ]; then
            # Temp-file handoff failed — fail-closed, never skipped.
            _em_reason="mktemp failed — cannot write the assembled manifest (fail-closed)"
        elif ! node "${_em_builder}" --app-dir . --out "${_em_tmp}" >> "${_em_log}" 2>&1; then
            _em_reason="effective manifest could not be assembled (bad JSON input?) — see ${_em_log}"
        else
            set +e
            node "${_em_validator}" "${_em_tmp}" ${_em_scope_flag} ${_em_scope_val} >> "${_em_log}" 2>&1
            _em_val_rc=$?
            set +e
            if [ "${_em_val_rc}" -eq 3 ]; then
                _em_reason="SCHEMA VALIDATION DID NOT HAPPEN — the vendored validator degraded to its structural lint (Ajv unresolvable mid-run); see ${_em_log}"
            elif [ "${_em_val_rc}" -ne 0 ]; then
                # Blocking findings only — PRE-EXISTING/WARN lines are excluded
                # by the prefix, so the count is what this PR must actually fix.
                _em_n=$(grep -E '^at ' "${_em_log}" 2>/dev/null | grep -cvE ': (WARN|PRE-EXISTING) ' || true)
                { [ -z "${_em_n}" ] || [ "${_em_n}" -eq 0 ]; } && _em_n=1
                _em_reason="${_em_n} structural violation(s) in the ASSEMBLED manifest (base+fragments+menu-layout) — see ${_em_log}"
            else
                set +e
                node "${_em_crossref}" --app-dir . --manifest "${_em_tmp}" ${_em_scope_flag} ${_em_scope_val} >> "${_em_log}" 2>&1
                _em_cr_rc=$?
                set +e
                if [ "${_em_cr_rc}" -ne 0 ]; then
                    _em_n=$(grep -E '^at ' "${_em_log}" 2>/dev/null | grep -cvE ': (WARN|PRE-EXISTING) ' || true)
                    { [ -z "${_em_n}" ] || [ "${_em_n}" -eq 0 ]; } && _em_n=1
                    _em_reason="${_em_n} cross-reference failure(s) in the effective manifest — see ${_em_log}"
                fi
            fi
        fi
        [ -n "${_em_tmp}" ] && rm -f "${_em_tmp}"
        [ -n "${_em_scope_file}" ] && rm -f "${_em_scope_file}"
        # Surface WARN-severity findings on stdout even on a pass (they never
        # set the exit code, but they must not vanish either).
        _em_warns=$(_count '^at .*: WARN ' "${_em_log}")
        [ "${_em_warns}" -gt 0 ] && echo "[gate-53] effective-manifest-crossref: ${_em_warns} WARN finding(s) (non-blocking) — see ${_em_log}"
        # Same for pre-existing debt suppressed by the diff scope: it is real,
        # it just is not this PR's to fix. Stating the count keeps a scoped
        # green from reading as "the manifest is clean".
        _em_pre=$(_count '^at .*: PRE-EXISTING ' "${_em_log}")
        [ "${_em_pre}" -gt 0 ] && echo "[gate-53] effective-manifest-crossref: ${_em_pre} PRE-EXISTING finding(s) on manifest entries this PR did not touch (ADR-020, not blocking) — see ${_em_log}"

        # -------------------------------------------------------------------
        # ORPHANING A COMPONENT IN THIS PR IS NOT ADVISORY.
        #
        # This gate exists because of larpingapp#286 — `EventRoster` was
        # registered in src/registry.js, resolvable, and named by no manifest
        # position, so the event check-in surface had no entry point at all
        # and a spec task was ticked over unreachable UI. Direction 1 of the
        # registry cross-reference (registered, unreferenced) is reported as
        # WARN, deliberately and correctly: for LEGACY debt the gate genuinely
        # cannot tell "wire it" from "delete it", and forcing a choice
        # fleet-wide is the widening that would make the check useless.
        #
        # But there is one case where it CAN tell, and it is the only case
        # that matters for prevention: THE DIFF ITSELF REMOVED THE LAST
        # REFERENCE. Measured 2026-08-08 — larpingapp#286 was reintroduced
        # exactly (the `checkin` tab deleted from src/manifest.json, the
        # registry entry left in place) and gate-53 reported
        # `[gate-53] effective-manifest-crossref: PASS`. The gate built to
        # catch that defect does not block the commit that creates it.
        #
        # So: a WARN whose component name appears on a REMOVED `"component":`
        # line of this PR's own manifest diff is promoted to blocking. Nothing
        # pre-existing changes severity — an app carrying legacy orphans (and
        # larpingapp carries one today, `ObjectDetail`) is unaffected, so this
        # is prevention rather than a burn-down list nobody can close.
        # -------------------------------------------------------------------
        _em_orphaned=""
        if [ "${SCOPE_TO_DIFF}" = "1" ] && [ -n "${BASE_REF}" ] && [ "${_em_warns}" -gt 0 ]; then
            set +e
            _em_removed_refs=$(git diff -U0 "${BASE_REF}...HEAD" -- \
                'src/manifest.json' 'src/manifest.d/*' 'src/menu-layout.json' 2>/dev/null \
                | grep -E '^-' | grep -vE '^---' || true)
            while IFS= read -r _em_wl; do
                [ -z "${_em_wl}" ] && continue
                _em_name=$(printf '%s' "${_em_wl}" | sed -n "s/.*exports '\\([^']*\\)'.*/\\1/p")
                [ -z "${_em_name}" ] && continue
                # `"component": "<name>"` on a line the PR deleted.
                if printf '%s\n' "${_em_removed_refs}" \
                    | grep -qE "\"component\"[[:space:]]*:[[:space:]]*\"${_em_name}\"" 2>/dev/null; then
                    _em_orphaned="${_em_orphaned}${_em_orphaned:+, }${_em_name}"
                fi
            done <<< "$(grep -E '^at .*: WARN .*registry\.js exports ' "${_em_log}" 2>/dev/null || true)"
            set +e
        fi
        if [ -n "${_em_orphaned}" ]; then
            echo "[gate-53] this PR REMOVED the last manifest reference to: ${_em_orphaned} — promoted from WARN to blocking (larpingapp#286)." >> "${_em_log}"
            _em_reason="${_em_reason:+${_em_reason}; }this PR removed the last manifest reference to ${_em_orphaned}, which src/registry.js still exports — the surface it renders is now unreachable (larpingapp#286). Delete the registry entry too, or keep an entry point (tabs[]/sections[]/page) that names it."
        fi

        if [ -z "${_em_reason}" ]; then
            _pass 53 "effective-manifest-crossref"
        else
            _fail 53 "effective-manifest-crossref" "${_em_reason}"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Gate 54: relation-dialect — enforce the ONE canonical OpenRegister relation
# dialect (ADR-062 rules 6/7/10) across changed register files
# (lib/Settings/*register*.json + lib/Settings/register.d/*.json). A relation
# is a schema PROPERTY carrying type:string (or array items), format:uuid and
# $ref:<schemaKey> (same register set); x-relation-filter rides on that same
# property. Bespoke per-schema dialects are banned. Observed 2026-07-08 across
# the fleet detail-page redesign: decidesk's per-schema x-openregister-
# relations blocks (nothing consumed them — retired) and scholiq's bare-string
# FKs-by-convention (85 converted); procest's case.status proved the rule-10
# lifecycle carve-out.
#
# The helper checks: (a) banned x-openregister-relations dialect; (b) relation-
# shaped property (format:uuid + relation description, no $ref) — property-level
# diff-scoped like gate-51; (c) x-relation-filter misplacement / filter-on-non-
# relation; (d) filter tokens (@objectId / @object.<field>, no two-hop, no
# unknown/nonexistent field); (e) rule-10 frozen-lifecycle readOnly; (f) $ref
# targets resolve to a schema key in the register set (numeric $ref → WARN).
#
# Diff-scoped (ADR-020): only the changed register files are inspected, so
# legacy debt in an untouched register never blocks an unrelated PR. WARN-
# prefixed lines are advisory and never fail the gate.
#
# Skill: .claude/skills/hydra-gate-relation-dialect/SKILL.md
# ---------------------------------------------------------------------------
_rd_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-relation-dialect.log
: > "${_rd_log}"
_rd_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _rd_files+=("$f")
done < <(_enum_tracked '(register[^/]*\.json|/register\.d/[^/]*\.json)$' lib/Settings)
_rd_ran=1
if [ "${#_rd_files[@]}" -gt 0 ]; then
    _rd_helper="${SCRIPT_DIR}/lib/check_relation_dialect.py"
    if [ -f "${_rd_helper}" ]; then
        # Property-level diff-scoping for the relation-shape check when
        # --scope-to-diff is set (mirrors gate-51); other checks scope to the
        # changed file set. Builder full-repo runs leave the env unset.
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
        # `>/dev/null 2>&1 || true` threw away the traceback and the status,
        # and this gate's advisory WARN half reads the same empty log — so a
        # dead helper silenced BOTH halves and printed PASS.
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_rd_helper}" "${_rd_log}" "${_rd_files[@]}" >/dev/null 2>>"${_rd_log}.err"
        else
            python3 "${_rd_helper}" "${_rd_log}" "${_rd_files[@]}" >/dev/null 2>>"${_rd_log}.err"
        fi
        _rd_rc=$?
        if [ "${_rd_rc}" -ne 0 ]; then
            _rd_ran=0
            _skip 54 "relation-dialect" wiring "check_relation_dialect.py exited ${_rd_rc} — ${#_rd_files[@]} register file(s) were in scope and no verdict was produced; non-canonical relation dialects (ADR-062 rules 6/7/10) are UNVERIFIED by this run, and so is its advisory WARN half. See ${_rd_log}.err."
        fi
    else
        _rd_ran=0
        _skip 54 "relation-dialect" wiring "check_relation_dialect.py not found at ${_rd_helper} — ${#_rd_files[@]} register file(s) were in scope and NONE were inspected; non-canonical relation dialects are UNVERIFIED by this run (its advisory WARN half reads the same empty log and is therefore also silent)."
    fi
fi
set +e
_rd_fail=$(grep -cv '^WARN:' "${_rd_log}" 2>/dev/null | tr -d ' ')
_rd_warn=$(grep -c '^WARN:' "${_rd_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_rd_fail}" ] && _rd_fail=0
[ -z "${_rd_warn}" ] && _rd_warn=0
[ "${_rd_warn}" -gt 0 ] && echo "[gate-54] relation-dialect: ${_rd_warn} WARN finding(s) (non-blocking) — see ${_rd_log}"
if [ "${#_rd_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    _skip 54 "relation-dialect" na "scope was empty — 0 lib/Settings/*register*.json (or register.d/*.json) file(s) in this repo or this diff, so NO relation property was inspected; non-canonical relation dialects (ADR-062 rules 6/7/10) are UNVERIFIED by this run."
elif [ "${_rd_ran}" -eq 1 ]; then
    if [ "${_rd_fail}" -eq 0 ]; then
        _pass 54 "relation-dialect"
    else
        _fail 54 "relation-dialect" "${_rd_fail} non-canonical relation dialect finding(s) — see ${_rd_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 55: detail-page-discipline — enforce the manifest side of the ADR-062
# detail-page grid discipline (rules 1/2/5/8/9) on changed manifests
# (src/manifest.json + src/manifest.d/*.json). For every type:"detail" page
# the diff TOUCHES: (a) page-level widgets[] AND config.widgets both present
# (render-path shadowing); (b) config.summaryAggregates present (deprecated,
# rule 2); (c) widgets↔layout integrity (1:1 id↔widgetId + no 12-col overlap);
# (d) sidebar CnAuditTrailTab / audit-trail (use widgets:[{type:'audit'}]);
# (e) widget icons in the shared registry (rule 8); (f) viewAllRoute/rowRoute
# resolve to a page id in the merged manifest.
#
# Diff-scoped (ADR-020): only changed manifest files, and within them only the
# detail pages the diff touches (page object line-span). Complements gate-53
# (menu/deeplink route crossref) — gate-53 does NOT look at widget
# rowRoute/viewAllRoute, so there is no overlap.
#
# Skill: .claude/skills/hydra-gate-detail-page-discipline/SKILL.md
# ---------------------------------------------------------------------------
_dpd_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-detail-page-discipline.log
: > "${_dpd_log}"
_dpd_files=()
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _in_scope "$f" || continue
    _dpd_files+=("$f")
done < <(find src -maxdepth 1 -name 'manifest.json' 2>/dev/null; \
    find src/manifest.d -name '*.json' 2>/dev/null)
_dpd_ran=1
if [ "${#_dpd_files[@]}" -gt 0 ]; then
    _dpd_helper="${SCRIPT_DIR}/lib/check_detail_page_discipline.py"
    if [ -f "${_dpd_helper}" ]; then
        # Page-level diff-scoping when --scope-to-diff is set: only detail pages
        # the PR touches are checked. Builder full-repo runs leave the env unset
        # → every detail page in a changed manifest is checked.
        # A CRASHED CHECKER MUST NOT REPORT PASS (#147 / #249 / #262).
        set +e
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            HYDRA_GATE_BASE_REF="${BASE_REF}" \
                python3 "${_dpd_helper}" "${_dpd_log}" "${_dpd_files[@]}" >/dev/null 2>>"${_dpd_log}.err"
        else
            python3 "${_dpd_helper}" "${_dpd_log}" "${_dpd_files[@]}" >/dev/null 2>>"${_dpd_log}.err"
        fi
        _dpd_rc=$?
        if [ "${_dpd_rc}" -ne 0 ]; then
            _dpd_ran=0
            _skip 55 "detail-page-discipline" wiring "check_detail_page_discipline.py exited ${_dpd_rc} — ${#_dpd_files[@]} manifest file(s) were in scope and no verdict was produced; ADR-062 detail-page grid discipline is UNVERIFIED by this run. See ${_dpd_log}.err."
        fi
    else
        _dpd_ran=0
        _skip 55 "detail-page-discipline" wiring "check_detail_page_discipline.py not found at ${_dpd_helper} — ${#_dpd_files[@]} manifest file(s) were in scope and NONE were inspected; detail-page discipline is UNVERIFIED by this run."
    fi
fi
set +e
_dpd_fail=$(wc -l < "${_dpd_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_dpd_fail}" ] && _dpd_fail=0
if [ "${#_dpd_files[@]}" -eq 0 ]; then
    # AN UNOPENED SCOPE IS NEVER A PASS (#242/#240/#258/#268).
    _skip 55 "detail-page-discipline" na "scope was empty — 0 src/manifest.json or src/manifest.d/*.json file(s) in this repo or this diff, so NO type:\"detail\" page was inspected; ADR-062 grid discipline (render-path shadowing, widgets↔layout integrity, widget icons, row/viewAll routes) is UNVERIFIED by this run."
elif [ "${_dpd_ran}" -eq 1 ]; then
    if [ "${_dpd_fail}" -eq 0 ]; then
        _pass 55 "detail-page-discipline"
    else
        _fail 55 "detail-page-discipline" "${_dpd_fail} detail-page discipline finding(s) — see ${_dpd_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 56: register-handler-resolution — every class/FQCN + method
# referenced from an OpenRegister register JSON (lib/Settings/*register*.json
# + lib/Settings/register.d/*.json) — lifecycle guards (requires/guard/save/
# fallbackGuard/preconditions) and calculation/aggregation/notification
# `handler` entries — MUST resolve to a class that actually exists in the
# repo AND, when a `::method` suffix is present, a method that exists on it.
#
# Observed 2026-07-13 on shillinq (orphan-capability-sweep, issue #425): 17
# guard classes referenced from register.d requires/guard/save/fallbackGuard
# entries did not exist at all, and PeriodCloseGuard::trialBalanceVerifies
# referenced a real class but a method that was never written. OpenRegister's
# LifecycleGuardRegistry::resolve() throws uncaught in
# LifecycleValidationListener, so every one of those lifecycle transitions
# hard-fails (HTTP 500) at runtime while the spec/tests/PHPCS/PHPStan all stay
# green — nothing else in the fleet's tooling ever inspects the JSON STRING.
#
# Diff-scoped (ADR-020): under --scope-to-diff only changed register files
# are inspected — legacy debt in an untouched register never blocks an
# unrelated PR.
#
# Skill: .claude/skills/hydra-gate-register-handler-resolution/SKILL.md
# ---------------------------------------------------------------------------
_rhr_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-register-handler-resolution.log
: > "${_rhr_log}"
_rhr_files=()
_rhr_present=0
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _rhr_present=$((_rhr_present + 1))
    _in_scope "$f" || continue
    _rhr_files+=("$f")
done < <(find lib/Settings -name '*register*.json' \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null; \
    find lib/Settings/register.d -name '*.json' 2>/dev/null)
_rhr_ran=1
# AN UNOPENED SCOPE IS NOT A PASS (.github#276 — the #242/#268 fix, applied to
# the gates it was never applied to). Measured on shillinq with a docs-only
# commit: gates 56, 57, 58, 59, 60 and 61 all printed PASS while 62 and 63 —
# the two that had been fixed — correctly printed NOT APPLICABLE. Six gates
# asserting a verdict about code no run had opened.
if [ "${#_rhr_files[@]}" -eq 0 ]; then
    _rhr_ran=0
    if [ "${_rhr_present}" -eq 0 ]; then
        _skip 56 "register-handler-resolution" na "no lib/Settings register JSON in this repo — this app declares no OpenRegister lifecycle guards or handlers for a class/method reference to dangle from."
    else
        _skip 56 "register-handler-resolution" na "${_rhr_present} register JSON(s) exist here and the diff against '${BASE_REF}' touched none of them, so NONE were inspected. Diff-scoped out under ADR-020 — this gate runs on the next PR that touches a register."
    fi
elif [ "${#_rhr_files[@]}" -gt 0 ]; then
    _rhr_helper="${SCRIPT_DIR}/lib/check_register_handler_resolution.py"
    if [ -f "${_rhr_helper}" ]; then
        # A CHECKER THAT COULD NOT RUN MUST NOT JUDGE THE CODE (.github#276 —
        # the #245/#233 rule, applied to the two gates it had not reached).
        #
        # This was `>> log 2>/dev/null || true`: stderr discarded, exit status
        # discarded, and the verdict then taken from `wc -l` on an empty log,
        # i.e. PASS. Measured on shillinq with a python3 that exits 1:
        # `[gate-56] PASS` and `[gate-57] PASS` — and gate-57 had reported 20
        # real findings over the same 316 services one run earlier. That is
        # gate-40's defect verbatim.
        #
        # The helper ALWAYS returns 0 when it runs, by design (#209 — the
        # count goes to stdout, never into the exit byte). So a non-zero exit
        # here is unambiguously a crash and can never be a finding count.
        _rhr_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-register-handler-resolution.err"
        python3 "${_rhr_helper}" "${_rhr_files[@]}" >> "${_rhr_log}" 2>"${_rhr_err}"
        _rhr_rc=$?
        if [ "${_rhr_rc}" -ne 0 ]; then
            _rhr_ran=0
            _skip 56 "register-handler-resolution" wiring "check_register_handler_resolution.py exited ${_rhr_rc} — ${#_rhr_files[@]} register file(s) were in scope and NONE were judged; whether every referenced handler class/method resolves is UNVERIFIED by this run. This helper always exits 0 when it runs, so this is a crash, not a finding count. See ${_rhr_err}."
        fi
    else
        _rhr_ran=0
        _skip 56 "register-handler-resolution" wiring "check_register_handler_resolution.py not found at ${_rhr_helper} — ${#_rhr_files[@]} register file(s) were in scope and NONE were inspected; whether every referenced handler class/method actually resolves is UNVERIFIED by this run."
    fi
fi
set +e
_rhr_fail=$(wc -l < "${_rhr_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_rhr_fail}" ] && _rhr_fail=0
if [ "${_rhr_ran}" -eq 1 ]; then
    if [ "${_rhr_fail}" -eq 0 ]; then
        _pass 56 "register-handler-resolution"
    else
        _fail 56 "register-handler-resolution" "${_rhr_fail} unresolved register-handler reference(s) — see ${_rhr_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 57: orphaned-write-capability — every PUBLIC side-effecting method on
# lib/Service/** (name starting with post/create/save/emit/dispatch/notify/
# write/export/generate/submit/record/settle/clear/reconcile/seed/publish/
# issue) MUST have at least one non-test production caller, OR be invoked
# through a recognised indirect seam: a register.d handler/guard/requires/
# save/fallbackGuard/preconditions entry, an event listener registered in
# lib/AppInfo/Application.php, a background job registered in
# appinfo/info.xml, or a documented Log*Adapter intentional log-only seam.
#
# Observed 2026-07-13 on shillinq (orphan-capability-sweep, 13 filed
# issues): DisposalJournalEmitter::emit(), IntercompanyJournalService, the
# inventory-cogs-posting.json declarative posting path, five
# Payroll*HandoffService classes, and OssInvoiceRouter::route() were all
# fully implemented and unit-tested by calling the class directly, spec'd
# "done", with ZERO production callers — 100% dead while every prior gate
# and the test suite stayed green.
#
# Diff-scoped (ADR-020): under --scope-to-diff only changed Service files
# are inspected — legacy dead code in an untouched file never blocks an
# unrelated PR.
#
# Skill: .claude/skills/hydra-gate-orphaned-write-capability/SKILL.md
# ---------------------------------------------------------------------------
_owc_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-orphaned-write-capability.log
: > "${_owc_log}"
_owc_files=()
_owc_present=0
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _owc_present=$((_owc_present + 1))
    _in_scope "$f" || continue
    _owc_files+=("$f")
done < <(_enum_tracked '\.php$' lib/Service | grep -v '/tests/')
_owc_ran=1
# An unopened scope is not a PASS — see gate 56 above (.github#276).
if [ "${#_owc_files[@]}" -eq 0 ]; then
    _owc_ran=0
    if [ "${_owc_present}" -eq 0 ]; then
        _skip 57 "orphaned-write-capability" na "no lib/Service PHP in this repo — there is no service layer for a write capability to be orphaned in."
    else
        _skip 57 "orphaned-write-capability" na "${_owc_present} lib/Service file(s) exist here and the diff against '${BASE_REF}' touched none of them, so NONE were inspected. Diff-scoped out under ADR-020 — this gate runs on the next PR that touches a service."
    fi
elif [ "${#_owc_files[@]}" -gt 0 ]; then
    _owc_helper="${SCRIPT_DIR}/lib/check_orphaned_write_capability.py"
    if [ -f "${_owc_helper}" ]; then
        # A crashed checker is not a finding — see gate 56 above (.github#276).
        # Measured on shillinq: with python3 exiting 1 this gate printed PASS
        # over the same 316 services it had reported 20 findings in one run
        # earlier. The helper always returns 0 when it runs (#209).
        _owc_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-orphaned-write-capability.err"
        python3 "${_owc_helper}" "${_owc_files[@]}" >> "${_owc_log}" 2>"${_owc_err}"
        _owc_rc=$?
        if [ "${_owc_rc}" -ne 0 ]; then
            _owc_ran=0
            _skip 57 "orphaned-write-capability" wiring "check_orphaned_write_capability.py exited ${_owc_rc} — ${#_owc_files[@]} service file(s) were in scope and NONE were judged; orphaned (mintable-but-unreachable) write capabilities are UNVERIFIED by this run. This helper always exits 0 when it runs, so this is a crash, not a finding count. See ${_owc_err}."
        fi
    else
        _owc_ran=0
        _skip 57 "orphaned-write-capability" wiring "check_orphaned_write_capability.py not found at ${_owc_helper} — ${#_owc_files[@]} service file(s) were in scope and NONE were inspected; orphaned (mintable-but-unreachable) write capabilities are UNVERIFIED by this run."
    fi
fi
set +e
_owc_fail=$(wc -l < "${_owc_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_owc_fail}" ] && _owc_fail=0
if [ "${_owc_ran}" -eq 1 ]; then
    if [ "${_owc_fail}" -eq 0 ]; then
        _pass 57 "orphaned-write-capability"
    else
        _fail 57 "orphaned-write-capability" "${_owc_fail} orphaned write-capability method(s) — see ${_owc_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 58: e2e-networkidle — `networkidle` NEVER settles on Nextcloud.
# NC's notification poll keeps at least one request in flight for the whole
# session, so `page.waitForLoadState('networkidle')` and
# `goto(..., { waitUntil: 'networkidle' })` can never resolve: each call
# silently burns its ENTIRE timeout budget. The customary
# `.catch(() => {})` swallows the throw while still paying the full cost,
# so the symptom is a bare "Test timeout of Nms exceeded" that is
# indistinguishable from an app outage.
#
# Observed 2026-07-27 on larpingapp: two such waits inside
# `tests/e2e/spec-coverage/index-pages.spec.ts::freshNav()` made EVERY
# index-pages test time out at 90s on a fully idle host. The fleet sweep
# then found 278 call-sites across 128 files in 20 apps (scholiq 26 files,
# docudesk 22, nldesign 17, openconnector 14, larpingapp 9, openbuild 9…)
# — the single largest source of slow/flaky e2e in the fleet.
#
# Canonical fix: `waitUntil: 'domcontentloaded'` + explicit element
# assertions as the readiness signal (openconnector documents this in
# tests/e2e/regression/manifest-pages.spec.ts). ADR-074 rule 4.
#
# Diff-scoped per ADR-020 so the 278-site legacy backlog never blocks an
# unrelated PR — only e2e files the PR touches are checked. Suppress a
# justified single use with an `e2e-networkidle exclude <reason>` comment
# on the same line.
#
# Skill: .claude/skills/hydra-gate-e2e-networkidle/SKILL.md
# ---------------------------------------------------------------------------
#
# A COMMENT WARNING AGAINST IT IS NOT A USE OF IT (#230).
#
# The only filter this gate had was the `exclude` marker, so a comment
# explaining why the last live call was removed counted as a live call.
# Measured on larpingapp@development: FAIL — 1 finding, and the line was
#
#   // live `waitForLoadState('networkidle')` in the suite; every other mention
#
# The repo has ZERO live calls; all ten occurrences under tests/ are comments.
# Doubly perverse: the more carefully a repo documents the ADR-074 rule 4
# removal, the more findings it accrues — and larpingapp is the repo this gate
# was written against, so it has the most such comments in the fleet. It was
# also unfixable in the leaf: the only remedies were deleting the explanation
# or putting an `exclude` marker on a comment.
#
# The naive fix — drop lines starting with `//` — was rejected: it still
# counts a mention after code on the same line and every block-comment
# interior line that begins with a letter, and it LOSES a real call carrying a
# trailing comment. scripts/lib/check_js_call_sites.py blanks the comment
# REGIONS instead, keeps offsets, and reads the `exclude` marker out of the
# ORIGINAL line — the marker lives in a comment, which is precisely what the
# mask removes.
# ---------------------------------------------------------------------------
_nwi_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-e2e-networkidle.log
: > "${_nwi_log}"
_nwi_ran=1
_nwi_helper="${SCRIPT_DIR}/lib/check_js_call_sites.py"
_nwi_files=()
_nwi_present=0
while IFS= read -r f; do
    [ -f "$f" ] || continue
    _nwi_present=$((_nwi_present + 1))
    _in_scope "$f" || continue
    _nwi_files+=("$f")
done < <(find tests/e2e -type f \( -name '*.ts' -o -name '*.js' \) 2>/dev/null)
if [ "${#_nwi_files[@]}" -eq 0 ]; then
    # WAS `: # nothing in scope`, which fell through to _pass — an unopened
    # scope reported as a clean one (.github#276). ADR-020 scoping IS working
    # as designed; the bug was calling its result PASS.
    _nwi_ran=0
    if [ "${_nwi_present}" -eq 0 ]; then
        _skip 58 "e2e-networkidle" na "no tests/e2e/ files in this repo — there is no Playwright suite in which a wait could fail to settle."
    else
        _skip 58 "e2e-networkidle" na "${_nwi_present} tests/e2e file(s) exist here and the diff against '${BASE_REF}' touched none of them, so NONE were inspected. Diff-scoped out under ADR-020 (the fleet carries a 278-site legacy backlog this gate deliberately does not block on) — it runs on the next PR that touches an e2e file."
    fi
elif [ ! -f "${_nwi_helper}" ]; then
    _nwi_ran=0
    _skip 58 "e2e-networkidle" wiring "check_js_call_sites.py not found at ${_nwi_helper} — ${#_nwi_files[@]} e2e file(s) were in scope and NONE were inspected; a wait that never settles is UNVERIFIED by this run."
else
    set +e
    _nwi_err="${HYDRA_GATE_LOG_DIR}/hydra-gate-e2e-networkidle.err"
    python3 "${_nwi_helper}" --rule networkidle "${_nwi_files[@]}" >> "${_nwi_log}" 2>"${_nwi_err}"
    _nwi_rc=$?
    if [ "${_nwi_rc}" -ne 0 ]; then
        _nwi_ran=0
        _skip 58 "e2e-networkidle" wiring "check_js_call_sites.py exited ${_nwi_rc} — ${#_nwi_files[@]} e2e file(s) were in scope and NONE were judged. See ${_nwi_err}."
    fi
fi
set +e
_nwi_fail=$(wc -l < "${_nwi_log}" 2>/dev/null | tr -d ' ')
set +e
[ -z "${_nwi_fail}" ] && _nwi_fail=0
if [ "${_nwi_ran}" -eq 1 ]; then
    if [ "${_nwi_fail}" -eq 0 ]; then
        _pass 58 "e2e-networkidle"
    else
        _fail 58 "e2e-networkidle" "${_nwi_fail} networkidle wait(s) in changed e2e file(s) — never settles on Nextcloud, use waitUntil:'domcontentloaded' (ADR-074 rule 4); see ${_nwi_log}"
    fi
fi

# ---------------------------------------------------------------------------
# Gate 59: unclosable-gate — a version/state config key that is READ but never
# WRITTEN is not a gate. It sits at its default forever, the comparison never
# short-circuits, and the expensive setup it guards (config import, register
# bootstrap, schema seeding) runs on EVERY call. Because these guards live in
# Application::boot() or a service reached from it, that is every request to the
# whole instance.
#
# Observed 2026-07-29 on docudesk: SettingsInitializer::initialize() read
# `configuration_version` to decide whether its OpenRegister configuration was
# imported. Nothing wrote it, so importFromApp() ran every request —
# 354ms -> 255ms median once set (~28% of every request) and 14 schema lookups
# per object create. ADR-076 rule 3.
#
# Diff-scoped per ADR-020: only runs when lib/ changed.
# Suppress with a comment containing `unclosable-gate exclude <reason>`.
# Skill: .claude/skills/hydra-gate-unclosable-gate/SKILL.md
# ---------------------------------------------------------------------------
_ucg_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-unclosable-gate.log
: > "${_ucg_log}"
# DIFF-SCOPE ONLY WHEN THE CALLER ASKED TO (.github#276 — #240's defect, in a
# gate #240 did not reach). `CHANGED_FILES` is populated ONLY under
# --scope-to-diff; on an unscoped run it is the empty string. So the condition
# `grep -qE '^lib/.*\.php$'` was false for EVERY full-tree audit, and this gate
# printed PASS having walked no PHP at all. A full-tree audit was the one mode
# it could never reach — the same sentence #240 wrote about gates 62/63.
_ucg_scoped_out=0
if [ "${SCOPE_TO_DIFF}" = "1" ] && ! printf '%s\n' "${CHANGED_FILES}" | grep -qE '^lib/.*\.php$'; then
    _ucg_scoped_out=1
fi
if [ -d lib ] && [ "${_ucg_scoped_out}" -eq 0 ]; then
    set +e
    python3 "${SCRIPT_DIR}/lib/check_unclosable_gate.py" . > "${_ucg_log}" 2>&1
    _ucg_rc=$?
    set +e
    if [ "${_ucg_rc}" -eq 0 ]; then
        _pass 59 "unclosable-gate"
    elif ! _helper_finished "${_ucg_log}" '^([0-9]+ unclosable gate\(s\)\.|unclosable-gate: OK)$'; then
        # A CRASH IS NOT A FINDING (.github#330) — see _helper_finished. This
        # branch used to be the only alternative to PASS, so a python3 that
        # exited 1 was reported as "config gate(s) read but never written".
        _ucg_why=$(head -3 "${_ucg_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
        _skip 59 "unclosable-gate" wiring "check_unclosable_gate.py exited ${_ucg_rc} without printing its terminal summary line, so NO PHP composition root was judged and read-but-never-written config gates (ADR-076 rule 3) are UNVERIFIED by this run. Checker output: ${_ucg_why:-<empty>}. See ${_ucg_log}."
    else
        # The helper does not prefix its findings with `FAIL`, so the count
        # comes off its own terminal summary line rather than being invented.
        _ucg_n=$(grep -oE '^[0-9]+ unclosable gate\(s\)\.$' "${_ucg_log}" 2>/dev/null | grep -oE '^[0-9]+' | tail -1)
        case "${_ucg_n}" in ''|*[!0-9]*) _ucg_n=1 ;; esac
        _fail 59 "unclosable-gate" "${_ucg_n} config gate(s) read but never written — guarded setup runs on every request (ADR-076 rule 3); see ${_ucg_log}"
    fi
elif [ ! -d lib ]; then
    _skip 59 "unclosable-gate" na "no lib/ in this repo — there is no PHP composition root in which a config gate could be read."
else
    # WAS `_pass 59`. lib/ exists and the diff touched none of it, so nothing
    # was read and nothing was judged — that is `na`, not a pass (.github#276).
    _skip 59 "unclosable-gate" na "lib/ exists here and the diff against '${BASE_REF}' touched no lib/**.php, so NO PHP was inspected. Diff-scoped out under ADR-020 — this gate runs on the next PR that touches lib/."
fi

# ---------------------------------------------------------------------------
# Gate 60: icon-vocabulary — every manifest menu `icon` must come from the
# canonical semantic icon vocabulary (ADR-077), so a glyph reads the same way in
# every Conduction app. Validated against the HYDRA-VENDORED table in
# scripts/schemas/semantic-icons.json, not whatever version the app has pinned
# (same rule as gate 22's schema).
#
# HARD FAILS:
#   - an MDI-style name that exists neither in the vocabulary nor in the app's
#     installed vue-material-design-icons (shillinq shipped `LedgerOutline` and
#     `FileSignOutline` — names with no upstream existence, blank anywhere they
#     are copied)
#   - a Tier A concept on a non-canonical icon (Dashboard / Store / Settings /
#     Documentation / Features & roadmap — the cross-app chrome)
#   - a legacy `icon-*` name with no CSS_ICON_TO_MDI bridge entry, which falls
#     through to the raw NC class and can render invisible on NC34+ light themes
#
# WARNS (non-blocking): any remaining bridged `icon-*` (deprecated by rule 1),
# and a Tier B concept on a non-canonical icon.
#
# Apps without a manifest are skipped. Diff-scoped per ADR-020.
#
# Spec: openspec/architecture/adr-077-semantic-icon-vocabulary.md
# ---------------------------------------------------------------------------
_iv_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-icon-vocabulary.log
: > "${_iv_log}"
if [ -f src/manifest.json ]; then
    _iv_args=""
    if [ "${SCOPE_TO_DIFF}" = "1" ]; then
        # Only manifests this PR touched. No touched manifest → nothing to gate.
        _iv_changed=$(printf '%s\n' "${CHANGED_FILES}" | grep -E '^src/(manifest\.json|manifest\.d/.*\.json)$' || true)
        if [ -z "${_iv_changed}" ]; then
            _iv_changed="__none__"
        fi
        if [ "${_iv_changed}" = "__none__" ]; then
            # WAS `_pass 60`. No manifest in the diff means no icon was read
            # — the same unopened scope gates 62/63 already report as `na`
            # (.github#276).
            _skip 60 "icon-vocabulary" na "src/manifest.json exists here and the diff against '${BASE_REF}' touched no manifest, so NO icon was inspected. Diff-scoped out under ADR-020 — this gate runs on the next PR that touches a manifest."
            _iv_args="__skip__"
        else
            for _f in ${_iv_changed}; do
                _iv_args="${_iv_args} --changed-file ${_f}"
            done
        fi
    fi
    if [ "${_iv_args}" != "__skip__" ]; then
        set +e
        python3 "${SCRIPT_DIR}/lib/check_icon_vocabulary.py" . ${_iv_args} > "${_iv_log}" 2>&1
        _iv_rc=$?
        set +e
        # Surface warnings even on a pass — they are the deprecation pressure.
        grep -E '^(WARN|NOTE)' "${_iv_log}" || true
        if [ "${_iv_rc}" -eq 0 ]; then
            _pass 60 "icon-vocabulary"
        elif [ "${_iv_rc}" -eq 5 ]; then
            # vue-material-design-icons is not installed, so the "does this
            # icon name exist upstream?" rule could not run. This gate has
            # already shipped both dishonest answers to that: 43 confident
            # FAILs when node_modules was absent, then — once those were
            # guarded — a silent PASS over a rule that never executed (#233).
            _skip 60 "icon-vocabulary" wiring "vue-material-design-icons is not installed, so icon names could NOT be verified to exist upstream — an invented MDI name would render blank and this run would not have caught it (ADR-077 rule 1). Every other icon rule passed. Run npm ci to restore full coverage. See ${_iv_log}."
        elif ! _helper_finished "${_iv_log}" '^(checked [0-9]+ manifest\(s\):|no manifest in scope)'; then
            # A CRASH IS NOT A FINDING (.github#330) — see _helper_finished.
            _iv_why=$(head -3 "${_iv_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
            _skip 60 "icon-vocabulary" wiring "check_icon_vocabulary.py exited ${_iv_rc} without printing its terminal 'checked N manifest(s)' summary, so NO icon was judged and the ADR-077 vocabulary is UNVERIFIED by this run. Checker output: ${_iv_why:-<empty>}. See ${_iv_log}."
        else
            _iv_n=$(_count '^FAIL' "${_iv_log}")
            [ "${_iv_n}" -eq 0 ] && _iv_n=1
            _fail 60 "icon-vocabulary" "${_iv_n} icon(s) outside the canonical vocabulary (ADR-077); see ${_iv_log}"
        fi
    fi
else
    _skip 60 "icon-vocabulary" na "no src/manifest.json — this app declares no menu icon for the ADR-077 vocabulary to constrain."
fi

# ---------------------------------------------------------------------------
# Gate 61: listener-work-placement — a listener registered on a POST object
# event (ObjectCreatedEvent / ObjectUpdatedEvent / ObjectDeletedEvent) runs
# INSIDE the user's write. It cannot influence that write, so every millisecond
# it spends is pure latency charged to the request.
#
# The `*ing` / `*ed` suffix already encodes the sync/async line — `*ing`
# listeners may veto or mutate and MUST stay synchronous; `*ed` listeners must
# not do real work on the request path. Nothing enforced that: 134 of the
# fleet's 149 object-lifecycle registrations are on post events, and three
# route through the actor-forwarded deferral contract.
#
# FAILS when a post-event handler does outbound I/O (IClient / IMailer /
# curl_*), a write (saveObject / ->insert( / ->update(), or an UNBOUNDED
# findAll(), and neither routes through ListenerDeferralService nor carries
# a reason-bearing `@listener-placement inline <category> — <reason>` on the
# handler. A BARE annotation with no category, or a category with no reason,
# fails — same shape as gate 16's `@spec exclude` and gate 19's `@e2e exclude`.
#
# Categories are CLOSED (ADR-078 D2): realtime, sapi-memory, cheap-bounded,
# correctness. A fifth needs an ADR amendment, not a new string in a docblock.
#
# ALWAYS diff-scoped per ADR-020, in both full and scoped runs: this gate is
# about NEW debt. The 149-registration backlog is a fleet work-list, not a
# reason to block an unrelated PR. The helper fails closed when the base ref
# does not resolve, so an unscopable run is never reported as a clean one.
#
# NOTE ON PLACEMENT: this block is at TOP LEVEL, deliberately outside any
# `if [ "${_FAILED}" -eq 0 ]` guard. A gate that only runs once everything else
# passed is green-but-dead — its own failures are swallowed exactly when a PR
# is already in trouble.
#
# Spec: openspec/architecture/adr-078-object-event-work-placement.md
# Skill: .claude/skills/hydra-gate-listener-work-placement/SKILL.md
# ---------------------------------------------------------------------------
_lwp_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-listener-work-placement.log
: > "${_lwp_log}"
if [ -d lib/AppInfo ]; then
    set +e
    # `--base` UNCONDITIONALLY, and unlike gates 62/63 that is DELIBERATE:
    # this gate is always diff-scoped in both modes because it is about NEW
    # debt, and the fleet's 149-registration backlog is a work-list rather
    # than a reason to redden every repo (see the header). Switching an
    # unscoped run to `--all` was tried and reverted here: the builder runs
    # unscoped, so it would have surfaced the whole backlog as blocking
    # findings on every build. The helper still fails CLOSED when the base
    # does not resolve, so an unscopable run is never reported as a clean one.
    python3 "${SCRIPT_DIR}/lib/check_listener_placement.py" . --base "${BASE_REF}" > "${_lwp_log}" 2>&1
    _lwp_rc=$?
    set +e
    if [ "${_lwp_rc}" -eq 0 ]; then
        _pass 61 "listener-work-placement"
    elif [ "${_lwp_rc}" -eq 3 ]; then
        # EMPTY SCOPE. This gate is always diff-scoped (ADR-020), so on any PR
        # that touches no listener EVERY registration falls out of scope. That
        # used to print PASS (.github#276).
        #
        # BUT AN EMPTY SCOPE HAS TWO CAUSES AND ONLY ONE OF THEM IS A DIFF
        # (.github#347). `bin/hydra-gates` forwards `--base` only on a
        # diff-scoped run, so on `--full` the runner keeps its own
        # `origin/development` default and hands it over anyway. On
        # `development` itself that diffs the branch against itself, the
        # checker returns 3, and this gate printed
        #
        #   NOT APPLICABLE — the diff against 'origin/development' put every
        #   post-event registration out of scope
        #
        # two lines after the run's own preamble said `Base ref: n/a — --full
        # requested`. THERE WAS NO DIFF. The verdict was defensible; the stated
        # reason named a thing that did not exist, and an unfalsifiable reason
        # is how this stood fleet-wide for weeks. Sibling of `.github#361` —
        # `check_listener_placement.py:555` carries the same non-empty argparse
        # default. Positive control on the same tree, changing only the scope
        # flag: `--all` -> 45 registrations checked, 3 failures.
        #
        # THE VERDICT DOES NOT CHANGE, DELIBERATELY. Sweeping the tree on an
        # unscoped run was tried here before and reverted (see the invocation
        # comment above): the builder runs unscoped, so `--all` would surface
        # the fleet's whole registration backlog as blocking findings on every
        # build. `na` with an HONEST reason is the third answer — it cannot go
        # false-RED, and it stops the run claiming an exclusion nothing
        # performed. What was missing was the SIZE of what went unread, so an
        # advisory sweep supplies it: informational, never a verdict.
        if [ "${SCOPE_TO_DIFF}" = "1" ]; then
            _skip 61 "listener-work-placement" na "the diff against '${BASE_REF}' put every post-event registration out of scope, so NONE were inspected. Diff-scoped out under ADR-020 — the fleet's 149-registration backlog is a work-list, not a reason to block an unrelated PR. This gate runs on the next PR that touches a listener registration. See ${_lwp_log}."
        else
            # ADVISORY ONLY. Its exit status is discarded on purpose: a sweep
            # that finds inherited debt must not become this run's verdict, and
            # a sweep that CRASHES must not either — the count is quoted only
            # when the helper printed its own terminal summary line.
            _lwp_all_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-listener-work-placement.advisory.log
            python3 "${SCRIPT_DIR}/lib/check_listener_placement.py" . --all \
                > "${_lwp_all_log}" 2>&1 || true
            #
            # THE SUMMARY IS RECOMPOSED, NOT QUOTED. The helper's own line ends
            # "…, 0 out of scope: 1 failure(s)", and pasting that in would put
            # the phrase "out of scope" back into a reason on a run that
            # computed no scope — the exact sentence this fix removes, smuggled
            # in as a quotation. The suite asserts against that phrase
            # gate-agnostically and caught it here.
            _lwp_backlog=""
            if grep -qE '^checked [0-9]+ post-event registration' "${_lwp_all_log}" 2>/dev/null; then
                _lwp_all_n=$(grep -oE '^checked [0-9]+ post-event registration' "${_lwp_all_log}" | tail -1 | grep -oE '[0-9]+' | head -1)
                _lwp_all_f=$(grep -oE '[0-9]+ failure\(s\)' "${_lwp_all_log}" | tail -1 | grep -oE '[0-9]+' | head -1)
                _lwp_backlog=" ADVISORY, and it decides nothing here: a whole-tree sweep of this same tree reaches ${_lwp_all_n:-an unreported number of} registration(s) carrying ${_lwp_all_f:-an unreported number of} finding(s), NONE of which this run judged — see ${_lwp_all_log}."
            fi
            _skip 61 "listener-work-placement" na "this run computed NO diff (--full / unscoped), and gate-61 is diff-scoped by design (ADR-078/ADR-020, it is about NEW debt) — so NO post-event registration was inspected and ADR-078 work placement is UNVERIFIED by this run. This is NOT a clean bill of health, and NO diff excluded anything: there was no diff. Re-measure with an explicit base (HYDRA_GATE_BASE_REF=origin/beta) or run with --scope-to-diff.${_lwp_backlog} See ${_lwp_log}."
        fi
    elif [ "${_lwp_rc}" -eq 4 ]; then
        _skip 61 "listener-work-placement" na "this repo registers no post-object-event listener, so there is no work to place on or off the write path (ADR-078). See ${_lwp_log}."
    elif ! _helper_finished "${_lwp_log}" '^checked [0-9]+ (post-event )?registration\(s\)'; then
        # A CRASH IS NOT A FINDING (.github#330) — see _helper_finished.
        _lwp_why=$(head -3 "${_lwp_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
        _skip 61 "listener-work-placement" wiring "check_listener_placement.py exited ${_lwp_rc} without printing its terminal 'checked N ... registration(s)' summary, so NO post-object-event listener was judged and ADR-078 work placement is UNVERIFIED by this run. Checker output: ${_lwp_why:-<empty>}. See ${_lwp_log}."
    else
        _lwp_n=$(_count '^FAIL' "${_lwp_log}")
        [ "${_lwp_n}" -eq 0 ] && _lwp_n=1
        _fail 61 "listener-work-placement" "${_lwp_n} post-event listener(s) doing synchronous work with no deferral and no justification (ADR-078); see ${_lwp_log}"
    fi
else
    _skip 61 "listener-work-placement" na "no lib/AppInfo/ — this repo has no Nextcloud composition root, so it registers no object-event listener."
fi

# ---------------------------------------------------------------------------
# Gate 62 — store-plane (ADR-080)
# ---------------------------------------------------------------------------
_sp_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-store-plane.log
: > "${_sp_log}"
# `--base` ONLY WHEN THE CALLER ASKED TO SCOPE (#240 / #242). It was passed
# unconditionally, so even an unscoped run diff-scoped itself, found no changed
# manifest, and the helper returned 0 — printed as PASS beside a log line
# reading "gate skipped". A full-tree audit was the one mode this gate could
# never reach.
set +e
if [ "${SCOPE_TO_DIFF}" = "1" ]; then
    python3 "${SCRIPT_DIR}/lib/check_store_and_settings_surface.py" . --gate store --base "${BASE_REF}" > "${_sp_log}" 2>&1
else
    python3 "${SCRIPT_DIR}/lib/check_store_and_settings_surface.py" . --gate store > "${_sp_log}" 2>&1
fi
_sp_rc=$?
set +e
if [ "${_sp_rc}" -eq 0 ]; then
    _pass 62 "store-plane"
elif [ "${_sp_rc}" -eq 3 ]; then
    # EMPTY SCOPE — `na`, not structural (#268). See _skip's header. A bugfix
    # PR has no legitimate reason to edit src/manifest.json, so failing it here
    # left the author only two moves: manufacture the gate's input, or switch
    # --require-full-coverage off fleet-wide. Both are worse than the bug.
    _skip 62 "store-plane" na "the diff against '${BASE_REF}' touched no manifest and no menu-layout, so NO manifest was inspected. Diff-scoped out under ADR-020 — not a gap: the manifests in this repo are unchanged from the base branch, so this PR introduces no store-plane naming or discovery decision (ADR-080) to judge. This gate runs on the next PR that touches a manifest. See ${_sp_log}."
elif [ "${_sp_rc}" -eq 4 ]; then
    _skip 62 "store-plane" na "no src/manifest.json — a Tier-0 app declares no store plane for ADR-080 to constrain."
elif ! _helper_finished "${_sp_log}" '^(checked [0-9]+ manifest\(s\):|FAIL )'; then
    # A CRASH IS NOT A FINDING (.github#330) — see _helper_finished. MEASURED:
    # on a fixture with no src/manifest.json at all, where the honest verdict
    # is NOT APPLICABLE, a python3 that exits 1 made this gate report
    # `FAIL — 1 store/templates/catalogue naming or discovery violation(s)`.
    # The `FAIL ` alternative in the marker keeps the helper's early
    # unparseable-JSON return — a real finding printed before the summary —
    # on the failing side where it belongs.
    _sp_why=$(head -3 "${_sp_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
    _skip 62 "store-plane" wiring "check_store_and_settings_surface.py --gate store exited ${_sp_rc} without printing its terminal 'checked N manifest(s)' summary or any FAIL line, so NO manifest was judged and ADR-080 store-plane naming/discovery is UNVERIFIED by this run. Checker output: ${_sp_why:-<empty>}. See ${_sp_log}."
else
    _sp_n=$(_count '^FAIL' "${_sp_log}")
    [ "${_sp_n}" -eq 0 ] && _sp_n=1
    _fail 62 "store-plane" "${_sp_n} store/templates/catalogue naming or discovery violation(s) (ADR-080); see ${_sp_log}"
fi

# ---------------------------------------------------------------------------
# Gate 63 — settings-surface (ADR-079)
# ---------------------------------------------------------------------------
_ss_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-settings-surface.log
: > "${_ss_log}"
# `--base` ONLY WHEN THE CALLER ASKED TO SCOPE — see gate 62 above (#240).
set +e
if [ "${SCOPE_TO_DIFF}" = "1" ]; then
    python3 "${SCRIPT_DIR}/lib/check_store_and_settings_surface.py" . --gate settings --base "${BASE_REF}" > "${_ss_log}" 2>&1
else
    python3 "${SCRIPT_DIR}/lib/check_store_and_settings_surface.py" . --gate settings > "${_ss_log}" 2>&1
fi
_ss_rc=$?
set +e
if [ "${_ss_rc}" -eq 0 ]; then
    _pass 63 "settings-surface"
elif [ "${_ss_rc}" -eq 3 ]; then
    # The log used to say "gate skipped" while the verdict beside it said PASS.
    # Those cannot both be true, and PASS is the one every consumer counted.
    # It is not PASS any more (#242) — and it is `na`, not structural (#268).
    #
    # THE CONTROLLER-ONLY DIFF (.github#276).
    #
    # This gate's rules read src/manifest.json + src/manifest.d/ +
    # src/menu-layout.json. A PR that changes a settings CONTROLLER, or adds
    # or deletes a lib/Settings/*Admin.php section, therefore lands here — and
    # the old reason read "this PR introduces no settings placement (ADR-079)
    # to judge", which on such a diff is an OVERCLAIM. The author changed the
    # settings surface; this gate simply does not adjudicate that half of it.
    #
    # WIDENING IT TO READ THE CONTROLLER WAS TRIED AND REVERTED, and the note
    # is in check_store_and_settings_surface.py: a gate that only RUNS when a
    # manifest changed and then judges code the PR never touched "blocked
    # EVERY manifest-touching PR in that repo, permanently". So the verdict
    # stays `na` and the rules stay manifest-only. What changes is that the
    # line now says WHICH HALF it looked at, and names the PHP half when the
    # diff contains it, so `na` cannot be read as a clearance for the change
    # the author actually made.
    _ss_php=$(printf '%s\n' "${CHANGED_FILES}" | grep -cE '^lib/(Settings/.*\.php|Controller/.*[Ss]ettings.*\.php)$' || true)
    [ -z "${_ss_php}" ] && _ss_php=0
    if [ "${_ss_php}" -gt 0 ]; then
        _ss_extra="⚠️ This diff DOES touch the settings surface in PHP (${_ss_php} lib/Settings or settings-controller file(s)), and this gate did not look at any of it: ADR-079's placement rules are expressed in the manifest, and widening this gate to read controllers was tried and reverted because it blocked every manifest-touching PR in the repo permanently. Read this NOT APPLICABLE as 'no manifest placement decision to judge', never as 'the settings change was checked'."
    else
        _ss_extra="The manifests in this repo are unchanged from the base branch, so this PR introduces no manifest placement decision to judge."
    fi
    _skip 63 "settings-surface" na "the diff against '${BASE_REF}' touched no manifest and no menu-layout, so NO manifest was inspected. Diff-scoped out under ADR-020 — not a gap. This gate adjudicates ADR-079 placement as declared in src/manifest.json, src/manifest.d/ and src/menu-layout.json, and nothing else. ${_ss_extra} It runs on the next PR that touches one of those. See ${_ss_log}."
elif [ "${_ss_rc}" -eq 4 ]; then
    _skip 63 "settings-surface" na "no src/manifest.json — a Tier-0 app declares no settings surface for ADR-079 to place."
elif ! _helper_finished "${_ss_log}" '^(checked [0-9]+ manifest\(s\):|FAIL )'; then
    # A CRASH IS NOT A FINDING (.github#330) — see gate 62 above, same helper.
    _ss_why=$(head -3 "${_ss_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
    _skip 63 "settings-surface" wiring "check_store_and_settings_surface.py --gate settings exited ${_ss_rc} without printing its terminal 'checked N manifest(s)' summary or any FAIL line, so NO manifest was judged and ADR-079 settings placement is UNVERIFIED by this run. Checker output: ${_ss_why:-<empty>}. See ${_ss_log}."
else
    _ss_n=$(_count '^FAIL' "${_ss_log}")
    [ "${_ss_n}" -eq 0 ] && _ss_n=1
    _fail 63 "settings-surface" "${_ss_n} settings-placement violation(s) (ADR-079); see ${_ss_log}"
fi

# ---------------------------------------------------------------------------
# Gate 64: apphost-autoload-prelude — adopting OpenRegister's AppHost without
# first putting OpenRegister's PSR-4 prefix on the autoloader.
#
# OC_App::getEnabledApps() does `sort($apps)`, and Coordinator::registerApps()
# walks THAT sorted list calling OC_App::registerAutoloading($appId) and then
# $application->register() for ONE APP AT A TIME. So every app's register() runs
# BEFORE the PSR-4 prefix of every alphabetically-LATER app exists. Any leaf
# sorting before `openregister` reaches register() while OCA\OpenRegister\ is not
# autoloadable — on a healthy instance, with OpenRegister enabled.
#
# GUARDED, it degrades silently: class_exists() answers FALSE, the generic
# plumbing is skipped, and classes that exist ONLY as Bootstrap DI aliases
# (Controller\HealthController -> AppHost\Controller\GenericHealthController)
# then fail to resolve — those endpoints return 500, not 404.
#
# UNGUARDED, the \Error aborts the ENTIRE register(). Every registerEventListener
# below it never runs. Coordinator catches the Throwable, logs an `emergency` and
# continues, so the app stays enabled and keeps serving: nothing in the UI says
# half its wiring is missing. Measured on doriath — the audit listener recorded
# ZERO dispatched events. Measured independently on openconnector, whose source
# records `class_exists at register(): false` on a clean install.
#
# WHY THIS NEEDS A GATE RATHER THAN A TEST: the failure depends on WHICH APPS
# HAPPEN TO BE INSTALLED. Any app that pulls OpenRegister's autoloader in
# registers the prefix PROCESS-WIDE and masks the defect for every app that
# registers after it. On a dev instance with such an app present everything
# resolves; in CI with a minimal app set it does not. A masking app makes the
# failure vanish exactly where you would test for it — which is why this was
# invisible for as long as it was.
#
# Lazy service closures that merely MENTION an AppHost class are deliberately
# NOT flagged: their bodies run at resolution time, long after every app has
# registered. Only register()-time resolution is the defect.
#
# Spec: openspec/architecture/adr-040-apphost-adoption.md
# ---------------------------------------------------------------------------
# PRIVATE PER-INVOCATION LOG, like every other gate. This was a hardcoded
# `/tmp/hydra-gate-apphost-autoload-prelude.log` — the exact shared-path
# non-determinism the HYDRA_GATE_LOG_DIR block at the top of this file was
# introduced to remove, left behind in one gate (.github#276).
_aap_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-apphost-autoload-prelude.log
: > "${_aap_log}"
if [ -d lib/AppInfo ]; then
    set +e
    python3 "${SCRIPT_DIR}/lib/check_apphost_autoload_prelude.py" . > "${_aap_log}" 2>&1
    _aap_rc=$?
    set +e
    # Surface the non-blocking NOTE lines. A register()-time class_exists() on a
    # non-AppHost OCA\OpenRegister\ class is the SAME autoloader mechanism as
    # the hard rule and silently skips everything it guards, but this gate is
    # not diff-scoped, so failing it would block every PR in the repo on
    # pre-existing code. Printing it is what stops a green gate-64 being read
    # as evidence about it (.github#276).
    grep -E '^NOTE' "${_aap_log}" || true
    if [ "${_aap_rc}" -eq 0 ]; then
        _pass 64 "apphost-autoload-prelude"
    elif ! _helper_finished "${_aap_log}" '^([0-9]+ AppHost adoption\(s\) without the autoload prelude\.|apphost-autoload-prelude: OK)$'; then
        # A CRASH IS NOT A FINDING (.github#330) — see _helper_finished.
        _aap_why=$(head -3 "${_aap_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
        _skip 64 "apphost-autoload-prelude" wiring "check_apphost_autoload_prelude.py exited ${_aap_rc} without printing its terminal summary line, so NO register() body was judged and ADR-040 autoload preludes are UNVERIFIED by this run. Checker output: ${_aap_why:-<empty>}. See ${_aap_log}."
    else
        # The helper does not prefix its findings with `FAIL`, so the count
        # comes off its own terminal summary line rather than being invented.
        _aap_n=$(grep -oE '^[0-9]+ AppHost adoption\(s\) without the autoload prelude\.$' "${_aap_log}" 2>/dev/null | grep -oE '^[0-9]+' | tail -1)
        case "${_aap_n}" in ''|*[!0-9]*) _aap_n=1 ;; esac
        _fail 64 "apphost-autoload-prelude" "${_aap_n} AppHost adoption(s) with no OpenRegister autoload prelude (ADR-040); see ${_aap_log}"
    fi
else
    _skip 64 "apphost-autoload-prelude" na "no lib/AppInfo/ — this repo has no Nextcloud composition root, so there is no register() in which an AppHost reference could resolve too early."
fi

# ---------------------------------------------------------------------------
# Gate 65: coding-standard-adoption — the app must still be on the fleet's
# shared standard, and must not have pinned itself away from it.
#
# WHY THIS EXISTS
# ---------------
# Centralising configuration does not stop it drifting. The 18 mutually
# different psalm.xml files measured on 2026-08-12 were all copies of something
# that had been shared once. What stops recurrence is a check that fails when an
# app walks away from the centre — including by freezing itself against it.
#
# NOT DIFF-SCOPED, deliberately. The subject is the app's standing configuration,
# not the lines a PR touched: an app that never touches phpcs.xml again must not
# thereby become exempt from having one. Every rule is a whole-repo property that
# is either true today or is not.
#
# The checker prints one `FAIL <rule>: <detail>` per violation and a terminal
# `checked N rule(s)`. Its ABSENCE is treated as a wiring failure rather than a
# pass — a crashed checker and a clean repo are otherwise the same silence.
# ---------------------------------------------------------------------------
_csa_log=${HYDRA_GATE_LOG_DIR}/hydra-gate-coding-standard-adoption.log
: > "${_csa_log}"
if [ -f composer.json ] || [ -f phpcs.xml ]; then
    set +e
    python3 "${SCRIPT_DIR}/lib/check_coding_standard_adoption.py" . > "${_csa_log}" 2>&1
    _csa_rc=$?
    set +e
    grep -E '^FAIL ' "${_csa_log}" || true
    if ! _helper_finished "${_csa_log}" '^checked [0-9]+ rule\(s\)$'; then
        # A CRASH IS NOT A FINDING (.github#330).
        _csa_why=$(head -3 "${_csa_log}" 2>/dev/null | tr '\n' ' ' | cut -c1-200)
        _skip 65 "coding-standard-adoption" wiring "check_coding_standard_adoption.py exited ${_csa_rc} without printing its terminal 'checked N rule(s)' summary, so NO rule was evaluated and this repo's adoption of the shared standard is UNVERIFIED by this run. Checker output: ${_csa_why:-<empty>}. See ${_csa_log}."
    elif [ "${_csa_rc}" -eq 0 ]; then
        _pass 65 "coding-standard-adoption"
    else
        # Count comes off the checker's own FAIL lines, not off the exit code,
        # so a future exit-code change cannot silently alter the number.
        _csa_n=$(grep -cE '^FAIL ' "${_csa_log}" 2>/dev/null || true)
        case "${_csa_n}" in ''|*[!0-9]*) _csa_n=1 ;; esac
        _fail 65 "coding-standard-adoption" "${_csa_n} deviation(s) from the shared coding standard; see ${_csa_log}"
    fi
else
    _skip 65 "coding-standard-adoption" na "no composer.json and no phpcs.xml — this repo ships no PHP for a coding standard to apply to."
fi

# ---------------------------------------------------------------------------
# Summary + COVERAGE ACCOUNTING
#
# The banner used to read "ALL 63 GATES GREEN" whenever the failure count was
# zero — whether or not 63 gates had actually run. Every gate in this file is
# wrapped in a prerequisite test (`if [ -d src ]`, `if [ -f
# tests/axe/report.json ]`, …) and a gate whose prerequisite is absent emitted
# NOTHING AT ALL: no line, no count, no trace. Its absence was byte-identical
# to its success.
#
# Measured 2026-08-03 across the fleet: gate-33 (axe-core) has never run in ANY
# repository, because the `tests/axe/report.json` it consumes is produced by a
# `scripts/run-browser-tests.sh` that exists in no app — so every "all gates
# green" this fleet has ever produced excluded accessibility RUNTIME checking
# entirely. gate-24 (integration-parity) is absent in most repos for the same
# structural reason. Neither was visible anywhere in the output.
#
# The inventory is read back out of THIS FILE (number + name of every gate that
# can report), so it cannot go stale against a hardcoded constant, and gates
# that did not run are named — not merely counted.
# ---------------------------------------------------------------------------
_SUMMARY_REACHED=1
echo ""

# Declared inventory: "<n> <name>" per line, first declaration of each number
# wins (a gate may call _pass/_fail/_skip from several branches).
_declared=$(grep -oE '_(pass|fail|skip) [0-9]+ "[^"]+"' "${RUNNER_SELF}" 2>/dev/null \
    | sed -E 's/^_(pass|fail|skip) ([0-9]+) "([^"]+)"$/\2 \3/' \
    | sort -n -k1,1 -s | awk '!seen[$1]++' || true)
_declared_n=$(printf '%s\n' "${_declared}" | grep -c . || true)
_declared_n="${_declared_n:-0}"

# ---------------------------------------------------------------------------
# APPLICABILITY DECLARATIONS
#
# Most gates are wrapped in a bare prerequisite (`if [ -d src ]; then …`) with
# no `else`. When the prerequisite is false the gate emits NOTHING AT ALL — no
# line, no reason, no trace — and its silence is byte-identical to its success.
# Measured on a Tier-0 fixture: 25 of 63 gates vanished this way, which is why
# --require-full-coverage failed a repo that had nothing wrong with it.
#
# Each line below restates ONE gate group's own prerequisite, negated, and names
# the gates it governs. The condition is written to MIRROR the `if` at the gate
# — same test, same operator — so the two cannot mean different things.
#
# Two properties make this safe to state centrally rather than at each gate:
#
#   1. _declare_na REFUSES to touch a gate that already reported anything. A
#      declaration can therefore never un-run a gate that ran, or overwrite a
#      FAIL. It can only ever explain a silence.
#   2. Each condition fires ONLY when the prerequisite is absent. If `src/`
#      exists and a gate in that group still emitted nothing, that gate is a
#      real dead gate and it stays in DID NOT RUN, exactly as before. The
#      declaration cannot mask it, because the declaration did not fire.
#
# So the failure mode this could have introduced — a table drifting away from
# the guards and quietly excusing a live gate — is closed by construction, not
# by keeping the two in step by hand.
# ---------------------------------------------------------------------------
_declare_na() {
    local _reason="$1"; shift
    local _g _name
    for _g in "$@"; do
        # Already reported a verdict of any kind? Leave it entirely alone.
        case " ${_EMITTED_GATES}" in *" ${_g} "*) continue ;; esac
        case " ${_NA_GATES}" in *" ${_g} "*) continue ;; esac
        case " ${_SKIPPED_GATES}" in *" ${_g} "*) continue ;; esac
        _name=$(printf '%s\n' "${_declared}" | awk -v g="${_g}" '$1==g {print $2; exit}')
        _skip "${_g}" "${_name:-gate-${_g}}" na "${_reason}"
    done
}

# `if [ -d src ]` — gates 10 26
[ -d src ] || _declare_na "no src/ directory — this repo ships no frontend, so there is no .vue/.js/.ts source for this gate to inspect." \
    10 26
# `if [ -d src ]` — gates 12 13, with the reason that is actually true of them
# (.github#274, the gate-12 half; #280 closed the gate-45 half above).
#
# The shared line above claimed these two inspect ".vue/.js/.ts source". They
# inspect `.vue` and ONLY `.vue`, because their subjects — `<NcSelect>`,
# `<NcModal>`, `<NcDialog>` — are Vue SFC components. A PHP or HTML template
# cannot instantiate one, so widening them the way #272 widened the a11y family
# would be widening toward markup that cannot contain the defect. gate-40 owns
# the language-agnostic `<input>`/`<select>` label rule for `templates/`.
#
# That is the judgement #274 asked for, written where a reader can disagree with
# it rather than left implicit in a shared reason string. Note these two now
# ALSO report `na` when `src/` EXISTS but holds no `.vue` — see the gates
# themselves; this declaration only covers `src/` being absent.
[ -d src ] || _declare_na "no src/ directory — this repo ships no .vue component, and <NcSelect>/<NcModal>/<NcDialog> are Vue SFC components that a PHP or HTML template cannot instantiate. gate-40 owns the language-agnostic <input>/<select> label rule for templates/." \
    12 13
# `if _a11y_has_markup_dir` — gates 31 32 34 35 36 37 39 40 42 43 44 45
#
# THE TABLE HAD DRIFTED FROM THE GUARDS IT CLAIMS TO MIRROR.
#
# The block above listed the whole accessibility family under `[ -d src ]`,
# and gave them all the reason "this repo ships no frontend, so there is no
# .vue/.js/.ts source for this gate to inspect." After #225/#261 that reason
# is simply untrue: the a11y family reads `_a11y_markup_files`, which is
# .vue AND .php AND .html under src/, templates/ and appinfo/templates/,
# because WCAG does not care which templating language produced the DOM.
#
# Measured 2026-08-08 on an app with a `templates/` full of markup and no
# `src/` — one textbook true positive per gate planted in it:
#
#   gate-34/36/37/38/39/41/43   ran, and 4 of them FAILED on the plants
#   gate-35/40/42/44            NOT APPLICABLE — "this repo ships no frontend"
#
# in the SAME run, over the SAME files. Four gates removed themselves from
# coverage accounting entirely, with a reason contradicted three lines above
# it in their own output. No fleet app is templates-only today, but nldesign
# is one `rm` away: its `src/` holds a single `manifest.json`, which is the
# exact shape that made twelve gates pass over nothing in #225.
#
# GATE-45 IS THE SAME DEFECT, AND #272 LEFT IT BEHIND (.github#274).
#
# It was the twelfth member of the family and stayed in the `[ -d src ]`
# list above, with its own `[ -d src ]` guard. Measured 2026-08-08 on
# nldesign, with a `transition:` and no reduced-motion fallback planted in
# `templates/settings/admin.php`: gate-45 reported NOT APPLICABLE — "this
# repo ships no frontend" — while gate-43, already migrated, FAILED on the
# same tree in the same run. A partial fix to a table like this is the worst
# outcome available: it removes the smell that would have led someone back
# to the entry still carrying the defect.
#
# The condition here is now the SAME FUNCTION the gates call, not a restated
# copy of it, so the two cannot drift again.
_a11y_has_markup_dir || _declare_na "no src/, templates/ or appinfo/templates/ — this repo renders no markup, so there is no DOM for this accessibility gate to inspect." \
    31 32 34 35 36 37 39 40 42 43 44 45
# `if [ -f appinfo/routes.php ]` — gates 5 25
[ -f appinfo/routes.php ] || _declare_na "no appinfo/routes.php — this repo registers no HTTP routes, so there is no endpoint for this gate to inspect." \
    5 25
# `if [ -d lib/Controller ] && [ -f appinfo/routes.php ]` — gate 14
{ [ -d lib/Controller ] && [ -f appinfo/routes.php ]; } || _declare_na "no lib/Controller/ and appinfo/routes.php pair — there is no controller-to-route mapping for this gate to resolve." \
    14
# `if [ -f src/manifest.json ]` — gates 15 22 53
[ -f src/manifest.json ] || _declare_na "no src/manifest.json — this repo declares no manifest, so there are no pages, widgets or handler references for this gate to inspect." \
    15 22 53
# `if [ -d openspec/specs ] || [ -d tests/e2e ]` — gate 19
{ [ -d openspec/specs ] || [ -d tests/e2e ]; } || _declare_na "no openspec/specs/ and no tests/e2e/ — there is neither a spec scenario to trace nor an e2e suite to trace it to." \
    19
# `if [ -d src ] || [ -d templates ] || [ -d appinfo/templates ]` — gate 38.
# That is `_a11y_has_markup_dir` spelled out; the declaration omitted the
# third arm, so it did not mirror the guard either.
_a11y_has_markup_dir || _declare_na "no src/, templates/ or appinfo/templates/ — this repo renders no markup, so there is no document for a skip link to be missing from." \
    38
# `if [ -d templates ] || [ -d appinfo/templates ]` — gate 41
{ [ -d templates ] || [ -d appinfo/templates ]; } || _declare_na "no templates/ and no appinfo/templates/ — this repo ships no server-rendered HTML document to carry a lang attribute." \
    41

_emitted_n=$(printf '%s\n' ${_EMITTED_GATES} | grep -c . || true)
_emitted_n="${_emitted_n:-0}"

# Gates that never reported: declared minus emitted. Membership test rather
# than comm(1) — comm compares in collating order, these lists are numeric,
# and an under-reported coverage gap is exactly what this block exists to stop.
#
# Three-way split, not two. "Did not report" collapsed two facts that a caller
# must act on differently, and collapsing them is why --require-full-coverage
# could not be switched on anywhere:
#
#   NOT APPLICABLE  the gate said, by name and with a category, that its subject
#                   matter does not exist in this repo or this diff. Nothing is
#                   unverified. Listed for the reader; does NOT count against
#                   coverage; does NOT fail the run.
#   DID NOT RUN     everything else — an explicit `structural`/`wiring` skip, OR
#                   total silence from a gate whose prerequisite was false and
#                   which never reached a _skip call at all. Counts, and fails
#                   under --require-full-coverage.
#
# Note the default: silence still counts AGAINST coverage. A gate stops counting
# only by declaring itself not-applicable out loud. That direction matters — the
# opposite default would let any gate disappear by doing nothing, which is the
# failure this accounting was built to catch.
_not_run=""
_na_list=""
while read -r _dn _dname; do
    [ -z "${_dn:-}" ] && continue
    case " ${_EMITTED_GATES}" in
        *" ${_dn} "*) continue ;;
    esac
    case " ${_NA_GATES}" in
        *" ${_dn} "*)
            _na_list="${_na_list}${_na_list:+
}${_dn} ${_dname}"
            continue
            ;;
    esac
    _not_run="${_not_run}${_not_run:+
}${_dn} ${_dname}"
done <<< "${_declared}"
_not_run_n=$(printf '%s\n' "${_not_run}" | grep -c . || true)
_not_run_n="${_not_run_n:-0}"
_na_n=$(printf '%s\n' "${_na_list}" | grep -c . || true)
_na_n="${_na_n:-0}"
_applicable_n=$((_declared_n - _na_n))

echo "[hydra-gates] COVERAGE: ${_emitted_n} of ${_declared_n} declared gates reported a result (${_na_n} not applicable to this repo/diff; ${_emitted_n} of ${_applicable_n} applicable gates ran)."
if [ "${_na_n}" -gt 0 ]; then
    echo "[hydra-gates] NOT APPLICABLE — subject matter absent from this repo or this diff."
    echo "[hydra-gates] These do NOT count against coverage. Each stated its own reason above:"
    while IFS= read -r _na; do
        [ -z "${_na}" ] && continue
        echo "[hydra-gates]   gate-${_na%% *} ${_na#* }"
    done <<< "${_na_list}"
fi
if [ "${_not_run_n}" -gt 0 ]; then
    echo "[hydra-gates] GATES THAT DID NOT RUN — they inspected NOTHING, and their subject"
    echo "[hydra-gates] matter is UNVERIFIED by this run:"
    while IFS= read -r _nr; do
        [ -z "${_nr}" ] && continue
        echo "[hydra-gates]   gate-${_nr%% *} ${_nr#* }"
    done <<< "${_not_run}"
fi

if [ "${_FAILED}" -eq 0 ]; then
    if [ "${_not_run_n}" -eq 0 ]; then
        if [ "${_na_n}" -eq 0 ]; then
            echo "[hydra-gates] ALL ${_declared_n} GATES GREEN — and all ${_declared_n} of them ran."
        else
            echo "[hydra-gates] ALL ${_applicable_n} APPLICABLE GATES GREEN — and all ${_applicable_n} of them ran."
            echo "[hydra-gates] The other ${_na_n} are not applicable to this repo/diff and are named above."
            echo "[hydra-gates] This is NOT 'all ${_declared_n} gates green' — it is 'nothing applicable was left unchecked'."
        fi
    else
        echo "[hydra-gates] ${_emitted_n} GATE(S) GREEN — but ${_not_run_n} of ${_applicable_n} APPLICABLE gates DID NOT RUN (named above)."
        echo "[hydra-gates] This is NOT 'all ${_declared_n} gates green'. It says nothing about the gates that skipped."
        if [ "${REQUIRE_FULL_COVERAGE}" = "1" ]; then
            echo "[hydra-gates] --require-full-coverage was set: treating incomplete coverage as failure."
            echo "[hydra-gates] Only gates whose subject matter EXISTS are counted — the ${_na_n} not-applicable"
            echo "[hydra-gates] gate(s) above were excluded. Every gate named as DID NOT RUN either found its"
            echo "[hydra-gates] input missing (structural) or its own machinery missing (wiring)."
            exit 98
        fi
    fi
else
    echo "[hydra-gates] ${_FAILED} gate(s) failed"
fi
exit "${_FAILED}"
