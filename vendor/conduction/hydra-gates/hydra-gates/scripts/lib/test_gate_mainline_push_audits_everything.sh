#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_mainline_push_audits_everything.sh — a push to a mainline branch
# must gate the code, not exit 99 having gated nothing.
#
# WHAT THIS GUARDS (.github#183)
# ------------------------------
# On a push, the diff base the caller supplies IS the branch being pushed, so
# `origin/development...HEAD` is empty by construction. The runner already
# handles the common case by re-scoping to `github.event.before` — the push's
# own previous tip.
#
# When that tip cannot be resolved — a branch created by this push, a
# force-push, a freshly-cloned mirror — the runner used to `exit 99`. That
# reasoning was right about the evidence and wrong about the remedy: the run
# then reported NO gate at all, zero `[gate-N]` lines, which carries exactly as
# much information as a green over an empty diff. None.
#
# The file says so itself, two screens above the block: "a permanently-red gate
# and a permanently-green gate say the same thing about the code, and both get
# filtered out by the people reading them."
#
# There is a correct scope for "I cannot tell what this push changed":
# everything. Diffing against the EMPTY TREE puts every tracked file in scope,
# needs no root commit, and is therefore unambiguous in a repository with
# several roots or a grafted history.
#
# Measured before the fix: exit 99, 0 gate lines. After: 60 gates report.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_mainline_push_audits_everything.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-mainline.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

# A repo shaped like a mainline push: the branch named as the diff base points
# at HEAD, and nothing tells us what the push started from.
_app="${_tmp}/app"
mkdir -p "${_app}/src" "${_app}/lib/Controller" "${_app}/appinfo"
printf '{"name":"fx","menu":[]}\n' > "${_app}/src/manifest.json"
# appinfo/routes.php is REQUIRED, not decoration: gate-25 reports NOT APPLICABLE
# without it, and the control below would then pass by never reaching the gate
# it claims to exercise.
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
    printf 'more\n' > src/extra.txt
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm second
    git branch -f development HEAD
) >/dev/null 2>&1

_logs="${_tmp}/logs"
mkdir -p "${_logs}"
_out="${_tmp}/run.txt"
(
    cd "${_app}" || exit 1
    # HYDRA_GATE_PUSH_BEFORE deliberately unset — this is the unresolvable case.
    unset HYDRA_GATE_PUSH_BEFORE
    HYDRA_GATE_LOG_DIR="${_logs}" bash "${_runner}" \
        --scope-to-diff --base development . > "${_out}" 2>&1
)
_rc=$?

_lines=$(grep -cE '^\[gate-[0-9]+\]' "${_out}" 2>/dev/null || echo 0)

if [ "${_rc}" -eq 99 ]; then
    _bad "the runner exited 99 on a mainline push — it gated nothing (#183)"
else
    _ok "the runner did not refuse a mainline push (exit ${_rc})"
fi

if [ "${_lines}" -gt 0 ]; then
    _ok "${_lines} gate(s) reported a verdict on a mainline push"
else
    _bad "no gate reported at all — a mainline push still gates nothing"
fi

if grep -q 'FULL-TREE AUDIT' "${_out}"; then
    _ok "the fallback states out loud that everything is in scope"
else
    _bad "the run did not announce the full-tree fallback — a silent re-scope is how a scope change goes unnoticed"
fi

# THE CONTROL. "Audit everything" must mean everything: a repo whose only
# controller is in the first commit must still have that controller in scope,
# or the fallback has quietly become another empty diff.
if grep -qE '^\[hydra-gates\] COVERAGE:' "${_out}"; then
    _ok "the run reached its coverage summary"
else
    _bad "the run never reached the coverage summary"
fi

# A gate whose subject matter exists must not report NOT APPLICABLE under the
# fallback — that would mean the empty-tree diff did not actually list the files.
if grep -qE '^\[gate-25\][^:]*: (PASS|FAIL)' "${_out}"; then
    _ok "gate-25 saw the controller — the empty-tree diff really does list every tracked file"
else
    _v=$(grep -oE '^\[gate-25\] [^:]+: [A-Z]+( \([a-z]+\))?' "${_out}" | head -1 | sed 's/^[^:]*: //')
    _bad "gate-25 reported '${_v:-nothing}' — the fallback scope is not reaching tracked files"
fi

# A NARROWLY SCOPED push must still be scoped narrowly: the fallback is for the
# unresolvable case only, and must not swallow a caller who told us the base.
_out2="${_tmp}/run2.txt"
_logs2="${_tmp}/logs2"
mkdir -p "${_logs2}"
(
    cd "${_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_logs2}" bash "${_runner}" \
        --scope-to-diff --base HEAD~1 . > "${_out2}" 2>&1
)
if grep -q 'FULL-TREE AUDIT' "${_out2}"; then
    _bad "a resolvable base was overridden by the full-tree fallback — scoping is now unusable"
else
    _ok "a resolvable base is still scoped narrowly (the fallback did not swallow it)"
fi

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_mainline_push_audits_everything.sh: ALL PASS"
    exit 0
fi
echo "test_gate_mainline_push_audits_everything.sh: ${_failures} FAILURE(S)"
exit 1
