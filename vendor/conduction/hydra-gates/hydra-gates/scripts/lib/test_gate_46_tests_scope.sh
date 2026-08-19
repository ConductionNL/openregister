#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_46_tests_scope.sh — gate-46 must resolve `@spec` targets wherever
# the tag is WRITTEN, and `tests/` is one of those places.
#
# WHAT THIS GUARDS (.github#322)
# ------------------------------
# Gate-46's enumerator was `find lib src`. It had never opened a test file,
# and `tests/` is where a large share of the fleet's `@spec` tags live —
# a test is the natural place to name the requirement it proves.
#
# Measured 2026-08-09 over the 21 apps carrying an `openspec/`: 272 unresolved
# targets under `tests/`, across 16 repos, that no run had ever reported. The
# textbook case is procest — `tests/Unit/BackgroundJob/DsoDeadlineJobTest.php`
# annotates `@spec openspec/changes/dso-omgevingsloket/tasks.md#T14` against a
# tasks.md numbering T01–T08 and V01–V10. There is no T14. The identical tag
# in `lib/` would have failed this gate since #246.
#
# THE PLANTS ARE THE SHAPES THE REPOS ACTUALLY CONTAIN, not the minimal case:
#   * `#T14` — the `T<n>` shorthand, against a real archived tasks.md, which is
#     the exact procest finding. A minimal `#nonsense` plant would also fail,
#     and would NOT have proven that the shorthand resolver treats a
#     nine-task file honestly.
#   * a target under `openspec/changes/<name>/` whose directory was ARCHIVED
#     under a date prefix — the case #322 believed was unchecked. It resolves,
#     and this suite pins that it resolves, so nobody "fixes" it into a
#     false positive.
#
# BOTH DIRECTIONS. Arm 2 is the one that keeps this honest: a tag in `tests/`
# whose target and anchor are real must still PASS, or widening the scope has
# simply moved the blindness into noise.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_46_tests_scope.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g46-tests.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

# An app whose change directory has been ARCHIVED under a date prefix — the
# normal end state of an OpenSpec change, and the state #322 mistook for a
# missing file. `openspec/changes/dso-omgevingsloket/` no longer exists;
# `openspec/changes/archive/2026-06-13-dso-omgevingsloket/` does.
_mkapp() {  # _mkapp <dir>
    mkdir -p "$1/lib" "$1/src" "$1/tests/Unit" \
             "$1/openspec/changes/archive/2026-06-13-dso-omgevingsloket"
    printf '{"name":"fx","menu":[]}\n' > "$1/src/manifest.json"
    cat > "$1/openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md" <<'MD'
# Tasks: dso-omgevingsloket

## Implementation Tasks

- [x] **T01**: Seed an omgevingsvergunning case type.
- [x] **T02**: Create DsoCaseService.
- [x] **T03**: Create BeschikkingGenerationService.

## Verification Tasks

- [x] **V01**: Config keys populated after install.
MD
    (
        cd "$1" || exit 1
        git init -q .
        git add -A
        git -c user.email=t@t -c user.name=t commit -qm init
    ) >/dev/null 2>&1
}

_LAST_LOG_PTR="${_tmp}/last-log-path"
_run46() {  # _run46 <appdir> -> echoes the gate-46 verdict line
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    printf '%s' "${logs}/hydra-gate-spec-anchor-existence.log" > "${_LAST_LOG_PTR}"
    (
        cd "$1" || exit 1
        git add -A >/dev/null 2>&1
        git -c user.email=t@t -c user.name=t commit -qm wip >/dev/null 2>&1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" . 2>/dev/null
    ) | grep -E '^\[gate-46\]' || true
}

_assert() {  # _assert <label> <expected-substring> <actual>
    case "$3" in
        *"$2"*) _ok "$1" ;;
        *)      _bad "$1 — got: $3" ;;
    esac
}

# ---------------------------------------------------------------------------
# ARM 1 — a dangling anchor in a TEST file is a finding.
#
# This is procest's shape verbatim: `#T14` against a tasks.md that stops at
# T03/V01. `T<n>` resolves against HEADINGS and task ids only — deliberately
# NOT positionally — so a nine-task file must not answer for a fourteenth.
# ---------------------------------------------------------------------------
_app="${_tmp}/a1"
_mkapp "${_app}"
cat > "${_app}/tests/Unit/DsoDeadlineJobTest.php" <<'PHP'
<?php
class DsoDeadlineJobTest {
	/** @spec openspec/changes/dso-omgevingsloket/tasks.md#T14 */
	public function testDeadlineWarning(): void {}
}
PHP
_out="$(_run46 "${_app}")"
_assert "a dangling #T14 anchor in tests/ → FAIL" "FAIL" "${_out}"
if grep -q 'DsoDeadlineJobTest.php' "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null; then
    _ok "the finding NAMES the test file"
else
    _bad "the finding does not name the test file"
fi

# ---------------------------------------------------------------------------
# ARM 2 — ANTI-WIDENING. A tag in tests/ whose target and anchor are REAL must
# PASS, and it must pass THROUGH THE ARCHIVE INDEX: the change directory the
# tag names was archived under a date prefix and no longer exists at the
# literal path. This is the resolution #322 believed was absent; pinning it
# here stops a future "fix" from converting every archived tag in the fleet
# into a false positive.
# ---------------------------------------------------------------------------
_app="${_tmp}/a2"
_mkapp "${_app}"
cat > "${_app}/tests/Unit/DsoDeadlineJobTest.php" <<'PHP'
<?php
class DsoDeadlineJobTest {
	/** @spec openspec/changes/dso-omgevingsloket/tasks.md#T02 */
	public function testDeadlineWarning(): void {}
}
PHP
_assert "a REAL #T02 anchor, resolved through the date-prefixed archive → PASS" \
    "PASS" "$(_run46 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 3 — a target FILE that never existed is reported from tests/ too, not
# only from lib/. The two failure modes are separate code paths in the helper.
# ---------------------------------------------------------------------------
_app="${_tmp}/a3"
_mkapp "${_app}"
cat > "${_app}/tests/Unit/GhostTest.php" <<'PHP'
<?php
class GhostTest {
	/** @spec openspec/changes/does-not-exist-at-all/tasks.md#task-1 */
	public function testGhost(): void {}
}
PHP
_out="$(_run46 "${_app}")"
_assert "a target file that never existed, tagged in tests/ → FAIL" "FAIL" "${_out}"
if grep -q 'target file not found' "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null; then
    _ok "reported as 'target file not found', not as a bad anchor"
else
    _bad "the finding is not classified as 'target file not found'"
fi

# ---------------------------------------------------------------------------
# ARM 4 — the original lib/ scope still works. Widening must not displace it.
# ---------------------------------------------------------------------------
_app="${_tmp}/a4"
_mkapp "${_app}"
cat > "${_app}/lib/Job.php" <<'PHP'
<?php
class Job {
	/** @spec openspec/changes/dso-omgevingsloket/tasks.md#T99 */
	public function run(): void {}
}
PHP
_assert "a dangling anchor in lib/ is still a FAIL" "FAIL" "$(_run46 "${_app}")"

echo ""
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_46_tests_scope.sh: ALL GREEN"
    exit 0
fi
echo "test_gate_46_tests_scope.sh: ${_failures} FAILURE(S)"
exit 1
