#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate7_verb_object_guards.sh — gate-7 had 86 unit tests and was wrong.
#
# WHY THIS EXISTS
# ---------------
# This is the load-bearing argument for the whole repo-shaped suite, so it gets
# its own file. `_GUARD_HELPER_NAME_RE` required the auth token to be the FINAL
# CamelCase segment. Every one of gate-7's 86 unit tests passed. The regex was
# self-consistent — it matched the strings its author had thought of. What no
# unit test could contain was a real repository that spells its predicates
# VERB-OBJECT: hermiq's `canUserAccessAgent()`. All three of hermiq's gate-7
# findings were false positives, and each one accused a genuinely-guarded
# endpoint of being an unguarded IDOR.
#
# Fixed and merged as `.github#353`. This suite exists so it STAYS fixed.
#
# THE ANTI-DEAD-TEST CONTROL (the reason this file is not just an expect.conf row)
# -------------------------------------------------------------------------------
# The author of #353 hit this exact trap: their first "now passes" fixture
# passed IDENTICALLY under the old regex, so it asserted nothing about the fix.
# They caught it only by reverting the relaxation and counting reds.
#
# A fixture that cannot distinguish the fixed code from the broken code is a
# dead test that looks like coverage — the same disease this whole suite
# diagnoses, one level up. So this file does the revert ITSELF, every run: it
# substitutes the pre-#353 regex and requires the clean arm to go RED. If it
# does not, the fixture has stopped proving anything and that is a hard failure.
#
# Run: bash scripts/lib/test_gate7_verb_object_guards.sh
set -uo pipefail

GF_PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
FIX="${GF_PKG_ROOT}/scripts/test-fixtures/gate-acceptance/auth-guards"
CHECKER="${GF_PKG_ROOT}/scripts/lib/check_no_admin_idor.py"
TARGET="lib/Controller/AgentController.php"

_fail_n=0; _pass_n=0; _defect_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

for _a in planted clean; do
    [ -f "${FIX}/${_a}/${TARGET}" ] || { echo "FAIL — fixture arm ${_a} missing at ${FIX}"; exit 1; }
done

_findings() {  # <arm> [checker-path]
    local _arm="$1"
    local _ck="${2:-${CHECKER}}"
    ( cd "${FIX}/${_arm}" && python3 "${_ck}" "${TARGET}" 2>/dev/null )
}

# ===========================================================================
echo "== the fixed regex: clean is clean, planted names exactly the two plants =="
# ===========================================================================
_clean_out="$(_findings clean)"
_clean_n="$(printf '%s' "${_clean_out}" | grep -c . || true)"
if [ "${_clean_n}" -eq 0 ]; then
    _ok "clean/ produces 0 findings — verb-object guards are recognised (#353)"
else
    _bad "clean/ produced ${_clean_n} finding(s); every method there carries a real per-object guard: ${_clean_out}"
fi

_planted_out="$(_findings planted)"
_planted_n="$(printf '%s' "${_planted_out}" | grep -c . || true)"
if [ "${_planted_n}" -eq 2 ]; then
    _ok "planted/ produces exactly 2 findings"
else
    _bad "planted/ produced ${_planted_n} finding(s), wanted 2: ${_planted_out}"
fi
# NAME the plants — a count alone would be satisfied by flagging everything.
for _m in show update; do
    if printf '%s' "${_planted_out}" | grep -q "method=${_m}\b"; then
        _ok "planted/ names method=${_m}"
    else
        _bad "planted/ never named method=${_m} — a bare count is not a finding"
    fi
done
# ...and must NOT flag the one method that kept its guard, or the gate is just
# reporting every #[NoAdminRequired] method it can see.
if printf '%s' "${_planted_out}" | grep -q 'method=diff\b'; then
    _bad "planted/ flagged method=diff, which KEEPS its verb-object guard through loadAccessibleAgent() — the gate is flagging attributes, not missing guards"
else
    _ok "planted/ leaves method=diff alone — the guarded transitive path is still recognised"
fi

# ===========================================================================
echo
echo "== ANTI-DEAD-TEST: revert the #353 relaxation and count the reds =="
# ===========================================================================
# If clean/ passes under the OLD regex too, this fixture proves nothing about
# #353 and must not be counted as coverage for it.
_WORK="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g7.XXXXXXXX")"
trap 'rm -rf "${_WORK}"' EXIT
_OLDCK="${_WORK}/check_no_admin_idor_OLD.py"
cp "${CHECKER}" "${_OLDCK}"

# The pre-#353 first alternative: the auth token had to be the FINAL segment.
# Restored by deleting the trailing optional-segment group that #353 added.
python3 - "${_OLDCK}" <<'PYREVERT'
import re, sys
p = sys.argv[1]
s = open(p).read()
# The CURRENT shape (post-#360: the middle segment is repeatable and optional,
# so the auth token may lead). Updated when #360 reshaped it — the suite's own
# guard below demanded exactly that rather than letting the arm rot.
new_alt = (
    'r"^(?:is|has|can|may)(?:[A-Z][a-z0-9_]*)*?"\n'
    '    r"(?:Admin|Access|Permission|Permitted|Owner|Allowed|Authori[sz]ed)"\n'
    '    r"(?:[A-Z][A-Za-z0-9_]*)?$"'
)
old_alt = (
    'r"^(?:is|has|can|may)[A-Z]\\w*"\n'
    '    r"(?:Admin|Access|Permission|Permitted|Owner|Allowed|Authori[sz]ed)$"'
)
if new_alt not in s:
    sys.stderr.write("REVERT-FAILED: the post-#353 regex shape was not found\n")
    sys.exit(2)
open(p, "w").write(s.replace(new_alt, old_alt, 1))
PYREVERT
_revert_rc=$?

if [ "${_revert_rc}" -ne 0 ]; then
    _bad "could not reconstruct the pre-#353 regex — _GUARD_HELPER_NAME_RE has been reshaped, so this control no longer proves the fixture is live. Re-derive the revert from \`git show 3c8da4c\` and update it here rather than deleting this arm."
else
    _old_out="$(_findings clean "${_OLDCK}")"
    _old_n="$(printf '%s' "${_old_out}" | grep -c . || true)"
    if [ "${_old_n}" -gt 0 ]; then
        _ok "clean/ goes RED (${_old_n} finding(s)) under the pre-#353 regex — the fixture genuinely discriminates fixed from broken"
    else
        _bad "clean/ passes under the OLD regex too (${_old_n} findings). This fixture is a DEAD TEST: it would have been green before #353 and asserts nothing about the fix. Add a method whose guard the old regex rejects."
    fi
    # And the old regex must still catch the real plants, or the revert broke
    # something else and the red above is not the red we think it is.
    _old_planted_n="$(_findings planted "${_OLDCK}" | grep -c . || true)"
    if [ "${_old_planted_n}" -ge 2 ]; then
        _ok "the reverted checker still catches the genuine plants (${_old_planted_n}) — the revert changed guard RECOGNITION, not the gate's ability to run"
    else
        _bad "the reverted checker found only ${_old_planted_n} planted IDOR(s); the revert broke the checker rather than narrowing it, so the control above is not measuring what it claims"
    fi
fi

# ===========================================================================
echo
echo "== abuse controls: an auth token is still REQUIRED =="
# ===========================================================================
# #353 relaxed the token's POSITION, never the requirement. If `canRender`
# started counting as a guard, the fix would have widened gate-7 into silence.
_ABUSE="${_WORK}/abuse"
mkdir -p "${_ABUSE}/lib/Controller"
cat > "${_ABUSE}/${TARGET}" <<'PHPABUSE'
<?php
namespace OCA\ScopeFixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

class AgentController extends Controller {
	#[NoAdminRequired]
	public function render(int $id): JSONResponse {
		$agent = $this->access->findAgent($id);
		if (!$this->canRender($agent)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($agent);
	}

	private function canRender(array $agent): bool {
		return $agent['template'] !== null;
	}
}
PHPABUSE
( cd "${_ABUSE}" && git init -q . && git config user.email f@example.invalid \
    && git config user.name F && git add -f . >/dev/null && git commit -qm abuse >/dev/null )
_abuse_n="$( cd "${_ABUSE}" && python3 "${CHECKER}" "${TARGET}" 2>/dev/null | grep -c . || true )"
if [ "${_abuse_n}" -ge 1 ]; then
    _ok "canRender() is still NOT accepted as a guard — the token requirement survived #353"
else
    _bad "canRender() was accepted as an authorization guard. #353 relaxed the token's POSITION; if it has been widened to accept any is/has/can/may method, gate-7 now clears real IDORs."
fi

# ===========================================================================
echo
echo "== idiomatic SHORT guard names: the auth token may come first (#360) =="
# ===========================================================================
# Measured end-to-end, not inferred from the regex. This arm was a live KNOWN
# DEFECT until `.github#360`: a controller guarded by `hasPermission()` or
# `canAccess()` was reported as TWO unguarded IDORs, before AND after #353,
# because the middle `[A-Z][A-Za-z0-9_]*` segment was mandatory and had to
# precede the token — so the token could never be the FIRST segment after the
# prefix. #360 made that segment repeatable and optional.
_FP="${_WORK}/fp"
mkdir -p "${_FP}/lib/Controller"
cat > "${_FP}/${TARGET}" <<'PHPFP'
<?php
namespace OCA\ScopeFixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

class AgentController extends Controller {
	#[NoAdminRequired]
	public function a(int $id): JSONResponse {
		$o = $this->access->findAgent($id);
		if (!$this->hasPermission($o, $this->userId)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($o);
	}

	#[NoAdminRequired]
	public function b(int $id): JSONResponse {
		$o = $this->access->findAgent($id);
		if (!$this->canAccess($o, $this->userId)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($o);
	}

	private function hasPermission(array $o, string $u): bool { return $o['ownerId'] === $u; }
	private function canAccess(array $o, string $u): bool { return $o['ownerId'] === $u; }
}
PHPFP
( cd "${_FP}" && git init -q . && git config user.email f@example.invalid \
    && git config user.name F && git add -f . >/dev/null && git commit -qm fp >/dev/null )
_fp_out="$( cd "${_FP}" && python3 "${CHECKER}" "${TARGET}" 2>/dev/null )"
_fp_n="$( printf '%s' "${_fp_out}" | grep -c . || true )"
if [ "${_fp_n}" -eq 0 ]; then
    _ok "hasPermission() and canAccess() are recognised as per-object guards (#360) — 0 findings"
else
    _bad "gate-7 reports ${_fp_n} finding(s) against a controller guarded by hasPermission() and canAccess(). Both are genuine per-object guards. This is .github#360 returning: _GUARD_HELPER_NAME_RE must let the auth token be the FIRST segment after the is/has/can/may prefix. Findings: ${_fp_out}"
fi

# ANTI-DEAD-TEST for #360, the same treatment #353 gets above: substitute the
# pre-#360 regex and require this arm to go RED. A fixture that passes under the
# broken regex too would assert nothing about the fix.
_OLD360="${_WORK}/check_no_admin_idor_PRE360.py"
cp "${CHECKER}" "${_OLD360}"
if ! python3 - "${_OLD360}" <<'PYREVERT360'
import sys
p = sys.argv[1]
s = open(p).read()
new_alt = 'r"^(?:is|has|can|may)(?:[A-Z][a-z0-9_]*)*?"'
old_alt = 'r"^(?:is|has|can|may)[A-Z][A-Za-z0-9_]*"'
if new_alt not in s:
    sys.stderr.write("REVERT-FAILED: the post-#360 regex shape was not found\n")
    sys.exit(2)
open(p, "w").write(s.replace(new_alt, old_alt, 1))
PYREVERT360
then
    _bad "could not reconstruct the pre-#360 regex — _GUARD_HELPER_NAME_RE has been reshaped, so this control no longer proves the fixture is live. Re-derive the revert and update it here rather than deleting this arm."
else
    _old360_n="$( cd "${_FP}" && python3 "${_OLD360}" "${TARGET}" 2>/dev/null | grep -c . || true )"
    if [ "${_old360_n}" -eq 2 ]; then
        _ok "the short-guard arm goes RED (2 findings) under the pre-#360 regex — it genuinely discriminates fixed from broken"
    else
        _bad "the short-guard arm produced ${_old360_n} finding(s) under the pre-#360 regex, wanted 2. Either the revert did not land or this fixture is a DEAD TEST that would have passed before #360."
    fi
fi

echo
echo "== summary =="
echo "   passed:        ${_pass_n}"
echo "   failed:        ${_fail_n}"
echo "   known defects: ${_defect_n} (live, each named above)"
[ "${_fail_n}" -eq 0 ] || exit 1
[ "${_pass_n}" -gt 0 ] || { echo "FAIL — zero assertions ran; an empty suite is not a green one."; exit 1; }
echo
echo "ALL gate-7 verb-object controls PASSED (${_defect_n} known defect(s) still live)"
exit 0
