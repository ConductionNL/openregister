#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_orphaned_capability_fixtures.sh — end-to-end fixture proof for
# gate-56 (register-handler-resolution) and gate-57
# (orphaned-write-capability).
#
# Unlike test_check_register_handler_resolution.py / _orphaned_write_
# capability.py (which exercise the Python detection logic against inline
# synthetic sources), this script runs the REAL helper against REAL,
# checked-in fixture repos under scripts/test-fixtures/ — proving the
# gates work end-to-end exactly the way the bash wrapper in
# run-hydra-gates.sh invokes them (cwd = app root, file list as argv).
#
# Four fixture trees:
#   register-handler-resolution-fail  — MUST produce exactly 2 findings
#                                        (a missing guard class, and a
#                                        method missing on an existing
#                                        class — the two shapes named in
#                                        the orphan-capability-sweep).
#   register-handler-resolution-pass  — MUST produce 0 findings (every
#                                        reference resolves; also proves
#                                        the exclude-annotation and
#                                        declarative-CEL-guard-object
#                                        false-positive guards).
#   orphaned-write-capability-fail    — MUST produce exactly 1 finding
#                                        (a write method with zero
#                                        callers anywhere).
#   orphaned-write-capability-pass    — MUST produce 0 findings (direct
#                                        caller, register.d handler seam,
#                                        event-listener seam, background-
#                                        job seam, Log*Adapter seam, and
#                                        the exclude annotation all
#                                        correctly recognised as NOT
#                                        orphans).
#   orphaned-write-crossapp           — a FOUNDATION repo (openregister) plus
#                                        the sibling that consumes it
#                                        (openconnector). MUST produce
#                                        exactly 1 finding: the genuinely
#                                        dead generateNeverCalledReport(),
#                                        and NOT clearCurrents(), which is
#                                        live but called only across the app
#                                        boundary (hydra#106).
#
# NB the crossapp assertion runs from the fixture PARENT, not from the app
# root, and passes a repo-qualified path — the way the fleet sweep really
# invokes the gate. The other assertions cd into the app root; if every
# assertion did that, a cwd-derived app_root would pass all of them while
# being broken in production. That is precisely how gate-56 shipped its cwd
# bug behind a green suite (hydra#108/#109, 242 false positives).
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
FIX_ROOT="$(cd "${SCRIPT_DIR}/../test-fixtures" && pwd)"

_fails=0

_assert_count() { # <fixture-dir> <helper> <file-ext> <search-path> <expected-count> <label>
    local fixture="$1" helper="$2" ext="$3" search_path="$4" want="$5" label="$6"
    local out n
    out=$(
        cd "${FIX_ROOT}/${fixture}" || exit 1
        # shellcheck disable=SC2046
        python3 "${SCRIPT_DIR}/${helper}" $(find "${search_path}" -name "*.${ext}" 2>/dev/null)
    )
    n=$(printf '%s\n' "${out}" | grep -c . || true)
    if [ "${n}" -eq "${want}" ]; then
        echo "PASS — ${label} (${n} finding(s))"
    else
        echo "FAIL — ${label}: expected ${want} finding(s), got ${n}"
        printf '%s\n' "${out}" | sed 's/^/    /'
        _fails=$((_fails + 1))
    fi
}

echo "== gate-56 register-handler-resolution =="
_assert_count "register-handler-resolution-fail" "check_register_handler_resolution.py" \
    "json" "lib/Settings" 2 "fail fixture: missing class + missing method"
_assert_count "register-handler-resolution-pass" "check_register_handler_resolution.py" \
    "json" "lib/Settings" 0 "pass fixture: all resolvable + exclude annotation + CEL guard object"

echo
echo "== gate-57 orphaned-write-capability =="
_assert_count "orphaned-write-capability-fail" "check_orphaned_write_capability.py" \
    "php" "lib/Service" 1 "fail fixture: zero-caller write method"
_assert_count "orphaned-write-capability-pass" "check_orphaned_write_capability.py" \
    "php" "lib/Service" 0 "pass fixture: direct caller / register.d handler / event listener / background job / Log*Adapter / exclude annotation seams"


# Cross-app: run from the fixture PARENT with a repo-qualified path, so the
# gate must derive the app root from the FILE, discover the sibling repo, and
# index its call sites. Asserts on the exact method names, not just a count:
# "1 finding" would also be satisfied by the catastrophic inversion (flagging
# the live clearCurrents while missing the dead method).
_crossapp_out=$(
    cd "${FIX_ROOT}/orphaned-write-crossapp" || exit 1
    python3 "${SCRIPT_DIR}/check_orphaned_write_capability.py" \
        openregister/lib/Service/ObjectService.php 2>/dev/null
)
if printf '%s\n' "${_crossapp_out}" | grep -q 'clearCurrents'; then
    echo "FAIL — crossapp fixture: LIVE clearCurrents() reported dead (hydra#106 regression)"
    printf '%s\n' "${_crossapp_out}" | sed 's/^/    /'
    _fails=$((_fails + 1))
elif ! printf '%s\n' "${_crossapp_out}" | grep -q 'method=generateNeverCalledReport'; then
    echo "FAIL — crossapp fixture: genuinely dead generateNeverCalledReport() NOT flagged"
    printf '%s\n' "${_crossapp_out}" | sed 's/^/    /'
    _fails=$((_fails + 1))
else
    echo "PASS — crossapp fixture: sibling-only caller keeps clearCurrents() live, true positive survives"
fi

echo
if [ "${_fails}" -eq 0 ]; then
    echo "ALL gate-56 / gate-57 fixture assertions PASSED"
    exit 0
fi
echo "${_fails} fixture assertion(s) FAILED"
exit 1
