#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# resolve-push-base.sh — resolve the honest diff base for a PUSH event.
#
# WHAT PROBLEM THIS SOLVES
#
# The gates are diff-scoped (ADR-020). The base is the mainline the work will
# merge into. That is exactly right for a pull request and structurally
# impossible for a push TO that mainline: on a push to `development`,
# `origin/development` IS `HEAD`, so the diff is empty by construction.
#
# Both readings of that were useless:
#
#   <= v1.4.0  the empty diff made every gate iterate nothing and report PASS.
#              Measured on shillinq c64e9fe: 52 gates green in 22 seconds;
#              the same commit unscoped fails 18. A permanent GREEN.
#   v1.5.0     the runner refuses (exit 99) rather than pass over nothing.
#              Correct, but it fires on EVERY mainline push. A permanent RED.
#
# A gate that is permanently green and a gate that is permanently red carry the
# same amount of information about the code — none — and both train readers to
# stop looking. The base is not missing on a push; it is simply not the branch
# name. GitHub hands the pusher's previous tip over as `github.event.before`,
# and "what this push changed" is `before...HEAD`.
#
# WHAT THIS FILE IS
#
# One implementation, sourced by BOTH entry points (bin/hydra-gates and
# scripts/run-hydra-gates.sh) so the two cannot drift. It is deliberately
# CONSERVATIVE: it only ever speaks when the base already resolved to HEAD —
# i.e. only in the case that is broken either way. A push to a feature branch,
# where the chain resolves `origin/development` and that is genuinely behind
# HEAD, is left completely untouched, and so is every pull_request run.
#
# CONTRACT
#
#   hydra_resolve_push_base <repo-dir> <head-sha>
#
#   stdout : the resolved base SHA, and nothing else, on success.
#   return : 0  resolved — stdout holds a commit-ish that is NOT head-sha.
#            1  not resolvable — stderr holds the named reason. The caller must
#               then fail closed (exit 99). "Cannot scope" is never a pass.
#
# EDGE CASES, EACH DECIDED EXPLICITLY (none of these falls through to a guess):
#
#   before is 40 zeroes    The branch was CREATED by this push; there is no
#                          previous state to diff against. Everything in the
#                          tree is new, so the only honest scope is the whole
#                          repository — which is the AUDIT mode, not the gating
#                          mode, and would report inherited debt as if this push
#                          introduced it. Refused. (Rare: repo/branch creation.)
#
#   before == HEAD         A push that moved the ref nowhere (a re-run, a
#                          re-push of the same tip). There is no change to
#                          scope to. Refused — this is the very self-comparison
#                          the caller was already rejecting.
#
#   before unreachable     A FORCE-PUSH abandons its old tip. Once no ref
#                          reaches it, GitHub will not serve it (uploadpack
#                          only allows reachable SHAs), so it cannot be fetched
#                          and the diff cannot be computed. We TRY a targeted
#                          fetch first, because the common cause is not a force
#                          push at all but a shallow checkout, and that one is
#                          recoverable. If the fetch fails, refused.
#
#   shallow checkout       `fetch-depth: 1` leaves `before` outside the
#                          truncated history even though it is perfectly
#                          reachable on the server. The targeted fetch below
#                          repairs exactly this case, which is why it is tried
#                          before giving up. (The shared workflow already sets
#                          fetch-depth: 0; other callers may not.)
#
#   merge commit           `before...HEAD` is the three-dot diff, and `before`
#                          is an ancestor of HEAD for any fast-forward or merge
#                          push, so three-dot and two-dot agree: the scope is
#                          everything the merge brought in. For a squash-merge
#                          it is precisely the squashed commit's own diff.
#                          Nothing special is needed and nothing special is
#                          done.
#
#   no shared history      A force-push to an unrelated history. `merge-base`
#                          fails, the diff cannot be computed, and the caller's
#                          own merge-base guard would refuse anyway. We check it
#                          here too so the reason names the push, not the ref.
#
# WHY NOT JUST WIDEN THE SCOPE / SKIP THE GATES ON PUSH
#
# Because both are the blind-pass this programme exists to end. Skipping means
# a mainline never gets gated at all; widening to the full tree means every
# mainline push fails on debt no one in that push wrote, which gets the gates
# switched off within a week. `before...HEAD` is the only scope that answers
# the question actually being asked.
#
# TESTING HOOK
#
# $HYDRA_GATE_PUSH_BEFORE overrides the event payload. It exists so the
# invariant suite can drive every branch above without a GitHub runner, and so
# a human can reproduce a CI run locally with the same scope CI used. It is
# read FIRST and, when set to a non-empty value, the event file is not
# consulted at all.

# Read `before` out of the push event payload. Echoes nothing when there is no
# push event to read, so the caller distinguishes "not a push" from "a push
# whose before is unusable".
_hydra_push_before_raw() {
    if [ -n "${HYDRA_GATE_PUSH_BEFORE:-}" ]; then
        printf '%s' "${HYDRA_GATE_PUSH_BEFORE}"
        return 0
    fi
    [ "${GITHUB_EVENT_NAME:-}" = "push" ] || return 0
    [ -n "${GITHUB_EVENT_PATH:-}" ] || return 0
    [ -r "${GITHUB_EVENT_PATH}" ] || return 0
    command -v python3 > /dev/null 2>&1 || return 0
    # `json.load` and not a grep: the payload is one line of JSON tens of
    # kilobytes long, and a substring match for "before" also finds
    # `head_commit`, `base_ref` and any commit message containing the word.
    python3 - "${GITHUB_EVENT_PATH}" <<'PY' 2>/dev/null || true
import json, sys
try:
    with open(sys.argv[1]) as fh:
        payload = json.load(fh)
except Exception:
    sys.exit(0)
before = payload.get('before')
if isinstance(before, str):
    sys.stdout.write(before.strip())
PY
}

# hydra_resolve_push_base <repo-dir> <head-sha>
hydra_resolve_push_base() {
    local _dir="$1" _head="$2" _before _resolved
    # A function, not a `local -a` array: `safe.directory=*` must reach git
    # LITERALLY, and an unquoted `*` inside an array literal is glob-expanded
    # by the shell against the current directory. When that directory happens
    # to contain files, git receives a filename as its config value and every
    # subsequent call fails with "dubious ownership" — which reads exactly like
    # a missing commit.
    _hg() { git -C "${_dir}" -c safe.directory='*' "$@"; }

    _before="$(_hydra_push_before_raw)"

    if [ -z "${_before}" ]; then
        echo "[hydra-gates] No push event payload to take a base from (GITHUB_EVENT_NAME='${GITHUB_EVENT_NAME:-}')." >&2
        return 1
    fi

    # All-zeroes is git's null SHA: the ref did not exist before this push.
    case "${_before}" in
        *[!0]*) ;;
        *)
            echo "[hydra-gates] github.event.before is the NULL sha — this push CREATED the branch." >&2
            echo "[hydra-gates] There is no previous state to diff against, so every file in the tree" >&2
            echo "[hydra-gates] is 'new' and the only available scope is the whole repository. That is" >&2
            echo "[hydra-gates] the audit mode, not the gating mode: it would report inherited debt as" >&2
            echo "[hydra-gates] if this push had introduced it. Refusing to guess." >&2
            return 1
            ;;
    esac

    # Present locally? A normal fast-forward push leaves `before` in the
    # history of the ref we just checked out, so this succeeds without network.
    if ! _hg cat-file -e "${_before}^{commit}" > /dev/null 2>&1; then
        # Not present. The recoverable cause is a shallow checkout; the
        # unrecoverable one is a force-push that abandoned the commit. Try the
        # cheap targeted fetch and let the outcome decide which it was.
        echo "[hydra-gates] Push base ${_before} is not in this checkout — fetching it directly." >&2
        _hg fetch --no-tags --no-recurse-submodules --quiet origin "${_before}" > /dev/null 2>&1 || true
        if ! _hg cat-file -e "${_before}^{commit}" > /dev/null 2>&1; then
            # Second chance for the shallow case specifically: unshallow.
            if [ -f "$(_hg rev-parse --git-dir 2>/dev/null)/shallow" ]; then
                echo "[hydra-gates] This checkout is SHALLOW — deepening to reach the push base." >&2
                _hg fetch --no-tags --no-recurse-submodules --quiet --unshallow > /dev/null 2>&1 || true
            fi
        fi
    fi

    if ! _hg cat-file -e "${_before}^{commit}" > /dev/null 2>&1; then
        echo "[hydra-gates] ERROR: github.event.before=${_before} cannot be resolved in this repository," >&2
        echo "[hydra-gates] and fetching it directly failed. That is what a FORCE-PUSH looks like: the" >&2
        echo "[hydra-gates] previous tip is unreachable from every ref, so the server will not serve it" >&2
        echo "[hydra-gates] and 'what this push changed' has no answer. Refusing to scope." >&2
        return 1
    fi

    _resolved="$(_hg rev-parse --verify --quiet "${_before}^{commit}" 2>/dev/null || true)"
    if [ -z "${_resolved}" ]; then
        echo "[hydra-gates] ERROR: github.event.before=${_before} exists but does not name a commit." >&2
        return 1
    fi

    if [ "${_resolved}" = "${_head}" ]; then
        echo "[hydra-gates] ERROR: github.event.before is the SAME COMMIT as HEAD (${_head})." >&2
        echo "[hydra-gates] This push moved the ref nowhere, so there is nothing to scope to. That is" >&2
        echo "[hydra-gates] the self-comparison this guard exists to reject, not a clean tree." >&2
        return 1
    fi

    if ! _hg merge-base "${_resolved}" "${_head}" > /dev/null 2>&1; then
        echo "[hydra-gates] ERROR: github.event.before=${_resolved} shares NO history with HEAD." >&2
        echo "[hydra-gates] The push replaced the branch with an unrelated history; there is no diff" >&2
        echo "[hydra-gates] between them to gate. Refusing to scope." >&2
        return 1
    fi

    printf '%s' "${_resolved}"
    return 0
}
