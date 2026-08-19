#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_glob_recursion_fixtures.sh — regression proof that every gate whose
# file enumeration was widened by the gate-glob-recursion-audit change actually
# REACHES code that sits in a nested directory.
#
# Why this test exists
# --------------------
# Gate-8 (unsafe-auth-resolver) missed a live CWE-863 fail-open in
# openregister's lib/Service/Object/PermissionHandler.php for months. The
# detection logic was correct. The gate simply enumerated `lib/Service/*.php`
# with a NON-RECURSIVE shell glob and never opened the file. Measured: 227 of
# 607 Service+Controller files scanned (37%). The deeper a security-critical
# class sat, the less likely it was checked — exactly backwards.
#
# A gate's fixtures must not encode the same assumption the gate makes. Every
# fixture below therefore lives at DEPTH, and there is deliberately NO
# top-level equivalent — a gate that re-acquires a non-recursive glob will find
# nothing and this test will fail. `_assert_no_toplevel` enforces that
# invariant so a future edit cannot quietly "fix" a failure by moving a fixture
# up to the root.
#
# Runs the REAL run-hydra-gates.sh against the checked-in fixture app under
# scripts/test-fixtures/glob-recursion/, exactly the way CI invokes it.
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
GATES="${SCRIPT_DIR}/../run-hydra-gates.sh"
FIXTURE="$(cd "${SCRIPT_DIR}/../test-fixtures/glob-recursion" && pwd)"

_fails=0

_assert_found() { # <log-name> <expected-substring> <label>
    local want="$2" label="$3" log
    # Some gates mktemp their log (hydra-gate-<name>.XXXXXX.log) so parallel
    # runs across repos cannot clobber each other; others still use the fixed
    # name. Accept BOTH, newest first — asserting only the fixed path made this
    # test report "gate did not run" for a gate that had run and failed
    # correctly, which is a worse failure mode than the one it was guarding.
    # `ls -t` is used deliberately: the point is to pick the MOST RECENT of the
    # two possible log names, and ordering by mtime is exactly what ls -t does.
    # The paths are gate-name-derived and contain no whitespace, so SC2012's
    # concern (non-alphanumeric filenames) does not apply.
    # shellcheck disable=SC2012
    log="$(ls -t "/tmp/hydra-gate-$1.log" /tmp/hydra-gate-"$1".*.log 2>/dev/null | head -1)"
    if [ -z "${log}" ] || [ ! -f "${log}" ]; then
        echo "FAIL: ${label} — no /tmp/hydra-gate-$1[.XXXXXX].log written (gate did not run)"
        _fails=$((_fails + 1)); return
    fi
    if grep -qF -- "${want}" "${log}"; then
        echo "PASS: ${label}"
    else
        echo "FAIL: ${label} — '${want}' not in ${log}"
        echo "      log contents:"; sed 's/^/        /' "${log}"
        _fails=$((_fails + 1))
    fi
}

# Guard the guard: assert the fixture has NO shallow twin, so the nested file
# is genuinely the only way for the gate to produce the finding.
_assert_no_toplevel() { # <dir> <glob> <label>
    local n
    n=$(find "${FIXTURE}/$1" -maxdepth 1 -name "$2" -type f 2>/dev/null | wc -l)
    if [ "${n}" -eq 0 ]; then
        echo "PASS: ${3} (no top-level twin — nested reach is the only path)"
    else
        echo "FAIL: ${3} — found ${n} top-level file(s) in $1; the fixture now"
        echo "      encodes the same assumption the gate makes and proves nothing."
        _fails=$((_fails + 1))
    fi
}

echo "=== fixture shape invariants ==="
_assert_no_toplevel "lib/Service"       '*.php'            "gate-6/8 fixture is nested-only"
_assert_no_toplevel "lib/Controller"    '*.php'            "gate-7/9 fixture is nested-only"
_assert_no_toplevel "lib/BackgroundJob" '*.php'            "gate-3 fixture is nested-only"
_assert_no_toplevel "lib/Settings"      '*register*.json'  "gate-18 fixture is fragment-only"

echo
echo "=== running real gates against ${FIXTURE} ==="
rm -f /tmp/hydra-gate-*.log
bash "${GATES}" "${FIXTURE}" > /tmp/hydra-glob-recursion-fixture.out 2>&1 || true

echo
echo "=== nested-reach assertions ==="
_assert_found unsafe-auth-resolver \
    "lib/Service/Object/PermissionHandler.php" \
    "gate-8 reaches lib/Service/Object/ (the real CWE-863 shape)"
_assert_found orphan-auth \
    "lib/Service/Object/NestedOrphanHandler.php" \
    "gate-6 reaches lib/Service/Object/"
_assert_found no-admin-idor \
    "lib/Controller/Api/V1/NestedThingController.php" \
    "gate-7 reaches lib/Controller/Api/V1/"
_assert_found semantic-auth \
    "lib/Controller/Api/V1/NestedThingController.php" \
    "gate-9 reaches lib/Controller/Api/V1/"
_assert_found stub-scan \
    "lib/BackgroundJob/Nested/NestedStubJob.php" \
    "gate-3 reaches lib/BackgroundJob/Nested/"
_assert_found notification-dialect \
    "lib/Settings/register.d/10-nested-notifications.json" \
    "gate-18 reaches lib/Settings/register.d/ fragments"
_assert_found skip-link \
    "src/views/deep/deeper/deepest/NestedRoot.vue" \
    "gate-38 reaches src/ depth 5 (past the old -maxdepth 4)"

echo
if [ "${_fails}" -eq 0 ]; then
    echo "test_gate_glob_recursion_fixtures.sh: ALL PASS"
    exit 0
fi
echo "test_gate_glob_recursion_fixtures.sh: ${_fails} FAILURE(S)"
echo "Full gate output: /tmp/hydra-glob-recursion-fixture.out"
exit 1
