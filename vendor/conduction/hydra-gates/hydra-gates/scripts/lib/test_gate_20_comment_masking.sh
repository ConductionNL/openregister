#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_20_comment_masking.sh — gate-20 must not count a commented-out
# call, and must still count a real one.
#
# WHAT THIS GUARDS (.github#294, and #271 underneath it)
# ------------------------------------------------------
# Gate-20 had NEVER fired in any repo in its entire existence: its pattern
# began with `->`, grep parsed that as OPTIONS and exited 2, and
# `2>/dev/null || true` destroyed both the message and the status. #271
# repaired the grep and anchored the receiver, which took the fleet yield from
# 19 false findings down to one real one.
#
# The very FIRST thing the repaired gate reported was still not a call. It was
# openconnector `lib/Service/SearchService.php:189`:
#
#     // $directory = $this->objectService->findObjects(filters: [...]);
#
# grep has no idea what a comment is. A gate whose first live finding is false
# is a gate people learn to ignore, so this applies the mask gate-5 got in
# #196 — `source_scope.py --mask php`, which blanks `//`, `#` and `/* */`
# while preserving offsets so the reported line number still addresses the
# real file.
#
# ARMS
#   1  the three comment dialects PHP has are all masked      (0 findings)
#   2  a REAL call is still reported                          (the point)
#   3  the reported LINE NUMBER survives masking, and the log shows the
#      original source line, not a row of blanks
#   4  ANTI-MASKING — `#[` opens a PHP 8 ATTRIBUTE, not a comment. Treating
#      it as one swallows the rest of the line, and the line after
#      `#[NoAdminRequired]` is exactly where these calls live.
#   5  receiver anchoring from #271 still holds: `$this->schemaMapper->
#      createFromArray()` is a real method on a different class
#   6  a file with a real call AND a commented-out one reports ONE finding

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_20_comment_masking.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g20.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_mkapp() {  # _mkapp <dir>
    mkdir -p "$1/lib/Service" "$1/lib/Controller"
    (
        cd "$1" || exit 1
        git init -q .
        git add -A
        git -c user.email=t@t -c user.name=t commit -qm init
    ) >/dev/null 2>&1
}

_LAST_LOG_PTR="${_tmp}/last-log-path"
_run20() {  # _run20 <appdir> -> echoes the gate-20 verdict line
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    printf '%s' "${logs}/hydra-gate-or-objectservice-api.log" > "${_LAST_LOG_PTR}"
    (
        cd "$1" || exit 1
        git add -A >/dev/null 2>&1
        git -c user.email=t@t -c user.name=t commit -qm wip >/dev/null 2>&1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" . 2>/dev/null
    ) | grep -E '^\[gate-20\]' || true
}

_assert() {  # _assert <label> <expected-substring> <actual>
    case "$3" in
        *"$2"*) _ok "$1" ;;
        *)      _bad "$1 — got: $3" ;;
    esac
}

# ---------------------------------------------------------------------------
# ARM 1 — openconnector's shape, plus the other two comment dialects.
# ---------------------------------------------------------------------------
_app="${_tmp}/a1"
_mkapp "${_app}"
cat > "${_app}/lib/Service/SearchService.php" <<'PHP'
<?php
namespace OCA\Demo\Service;

class SearchService
{
	public function search(): array
	{
		// $directory = $this->objectService->findObjects(filters: ['_schema' => 'directory']);
		# $legacy = $this->objectService->deleteFromId(1);
		/*
		 * $old = $this->objectService->createFromArray([]);
		 */
		return $this->objectService->findAll();
	}
}
PHP
_assert "//, # and /* */ commented-out calls → PASS" "PASS" "$(_run20 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 2 — the true positive. Masking must not have switched the gate off; that
# is the failure mode #271 spent a whole session on.
# ---------------------------------------------------------------------------
_app="${_tmp}/a2"
_mkapp "${_app}"
cat > "${_app}/lib/Controller/BookingNotificationController.php" <<'PHP'
<?php
namespace OCA\Demo\Controller;

class BookingNotificationController
{
	public function guard(string $id): bool
	{
		$objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
		$booking = $objectService->findObject($id);

		return $booking !== null;
	}
}
PHP
_out="$(_run20 "${_app}")"
_assert "a real \$objectService->findObject() call → FAIL" "FAIL" "${_out}"

# ---------------------------------------------------------------------------
# ARM 3 — the line number must survive masking, and the log must show the
# ORIGINAL source line. A mask that shifted offsets would send readers to the
# wrong line, which is its own kind of blind.
# ---------------------------------------------------------------------------
if grep -q 'BookingNotificationController.php:9:' "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null; then
    _ok "the finding carries the REAL line number (9) despite the mask"
else
    _bad "wrong line number: $(cat "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null)"
fi
# Double-quoted with an escaped `$` rather than single-quoted: ShellCheck's
# SC2016 fires on ANY single-quoted string containing `$`, and it is fatal in
# this repository. `\$` inside double quotes is a literal dollar.
if grep -qF "findObject(\$id)" "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null; then
    _ok "the log shows the ORIGINAL source line, not the blanked mask"
else
    _bad "the log does not carry the original source line"
fi

# ---------------------------------------------------------------------------
# ARM 4 — `#[` is a PHP 8 ATTRIBUTE. If the mask treated it as a `#` comment
# it would blank the rest of that line; this pins that the code AFTER an
# attribute is still searched. `#[NoAdminRequired]` is the most load-bearing
# token in this package and these calls sit directly under it.
# ---------------------------------------------------------------------------
_app="${_tmp}/a4"
_mkapp "${_app}"
cat > "${_app}/lib/Controller/AttrController.php" <<'PHP'
<?php
namespace OCA\Demo\Controller;

class AttrController
{
	#[NoAdminRequired]
	public function show(string $id): array
	{
		return $this->objectService->findObject($id);
	}
}
PHP
_assert "a call under a #[NoAdminRequired] attribute is still found → FAIL" \
    "FAIL" "$(_run20 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 5 — #271's receiver anchoring. `createFromArray()` IS a real method on
# OpenRegister's Mappers. Un-anchoring produced 14 false findings on
# openregister and 5 on shillinq; masking must not have loosened it.
# ---------------------------------------------------------------------------
_app="${_tmp}/a5"
_mkapp "${_app}"
cat > "${_app}/lib/Service/RegisterService.php" <<'PHP'
<?php
namespace OCA\Demo\Service;

/**
 * Uses the ObjectService elsewhere, which is why the old file-level
 * heuristic reported this file.
 */
class RegisterService
{
	public function seed(array $rows): void
	{
		$this->registerMapper->createFromArray($rows);
		$this->schemaMapper->createFromArray($rows);
		$this->objectService->saveObject($rows);
	}
}
PHP
_assert "\$this->schemaMapper->createFromArray() is a different class → PASS" \
    "PASS" "$(_run20 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 6 — one real call next to a commented-out one is exactly ONE finding.
# ---------------------------------------------------------------------------
_app="${_tmp}/a6"
_mkapp "${_app}"
cat > "${_app}/lib/Service/MixedService.php" <<'PHP'
<?php
namespace OCA\Demo\Service;

class MixedService
{
	public function run(string $id): array
	{
		// $old = $this->objectService->findObjects(['id' => $id]);
		return $this->objectService->findObject($id);
	}
}
PHP
_out="$(_run20 "${_app}")"
_assert "one real call beside a commented one → FAIL" "FAIL" "${_out}"
_n=$(wc -l < "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null | tr -d ' ')
if [ "${_n}" = "1" ]; then
    _ok "exactly ONE finding, not two"
else
    _bad "expected 1 finding, got ${_n}"
fi

echo ""
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_20_comment_masking.sh: ALL GREEN"
    exit 0
fi
echo "test_gate_20_comment_masking.sh: ${_failures} FAILURE(S)"
exit 1
