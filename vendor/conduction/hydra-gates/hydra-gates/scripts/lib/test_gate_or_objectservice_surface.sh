#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_or_objectservice_surface.sh — gate-20 (or-objectservice-api) and
# gate-17 (redundant-controller) both reason about OpenRegister's ObjectService.
# Both were wrong about it, in opposite directions, and both reported PASS.
#
# WHAT THIS GUARDS (.github#271)
# ------------------------------
# gate-20 HAD NEVER FIRED. Not rarely — never, in any repo, since it was
# written. Its search was
#
#     grep -nE "->${_pat//(/\\(}" "${_file}"
#
# and the expanded pattern is `->findObjects\(`, which begins with `-`. grep
# reads that as OPTIONS:
#
#     $ grep -nE "->findObjects\(" file.php
#     grep: invalid option -- '>'      (exit 2)
#
# `2>/dev/null || true` swallowed the message and the status, every file came
# back with zero hits, and the gate printed PASS. Proven 2026-08-08 by planting
# `$this->objectService->findObjects(['limit' => 1])` in openregister's
# ActionsController: gate-20 reported PASS over it.
#
# Repairing the grep ALONE is not the fix. The old receiver test was "the FILE
# mentions ObjectService somewhere", which is not a claim about the call. With
# the grep repaired and that heuristic left alone the fleet measurement is 14
# findings on openregister and 5 on shillinq, ALL FALSE — `createFromArray()`
# is a real method on OpenRegister's *Mappers*
# (`$this->schemaMapper->createFromArray(...)`), and those files mention
# ObjectService elsewhere. A gate that cries wolf gets switched off, so the
# receiver is now part of the pattern.
#
# gate-17 was wrong the other way. Four of the six names in its
# OBJECT_SERVICE_CRUD tuple — findObjects / createFromArray / updateFromArray /
# deleteFromId — DO NOT EXIST on ObjectService; they are precisely what gate-20
# exists to flag as fabricated. The real surface (openregister
# lib/Service/ObjectService.php) is find / findAll / saveObject / createObject /
# updateObject / deleteObject, and only `find` and `saveObject` were listed. So
# a textbook ADR-022 pass-through written against the REAL API was not
# recognised as an ObjectService call at all, fell through to RESCUE_PATTERNS,
# and was matched by `\$this->\w+Service->\w+\(` ("any non-objectService call →
# escape"). The gate did not merely miss the modern shape — it rescued it.
#
# Every arm below has an anti-widening sibling: the thing that MUST still be
# silent, differing from the finding by the one property the rule keys on.
#
# Run: bash scripts/lib/test_gate_or_objectservice_surface.sh   (exit 0 = green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"
RUNNER="${HYDRA_GATES_RUNNER_UNDER_TEST:-${PKG_ROOT}/scripts/run-hydra-gates.sh}"

_fail_n=0
_ok()  { printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-or-surface.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

echo "== gate-20 / gate-17: the OpenRegister ObjectService surface =="
echo

# ---------------------------------------------------------------------------
# THE MECHANISM ITSELF. Asserted directly, because the whole gate-20 defect is
# this one fact and it is invisible in a log that discards stderr.
# ---------------------------------------------------------------------------
printf 'x->findObjects(1);\n' > "${_tmp}/probe.txt"
grep -nE "->findObjects\(" "${_tmp}/probe.txt" >/dev/null 2>&1
_grep_rc=$?
if [ "${_grep_rc}" -ge 2 ]; then
    _ok "a grep pattern beginning with '-' is parsed as OPTIONS (rc=${_grep_rc}) — the defect is real on this platform"
else
    echo "  note — this grep tolerates a leading '-' in the pattern (rc=${_grep_rc});"
    echo "         the defect cannot be provoked here, but the '--' guard is asserted below."
fi
if grep -nE -- "->findObjects\(" "${_tmp}/probe.txt" >/dev/null 2>&1; then
    _ok "the same pattern after '--' matches — '--' is the whole difference between dead and alive"
else
    _bad "'--' did not make the pattern usable; the gate-20 repair rests on this"
fi

# ---------------------------------------------------------------------------
# The fixture app. One TRUE positive per gate, each with its silent sibling.
# ---------------------------------------------------------------------------
_app="${_tmp}/app"
mkdir -p "${_app}/lib/Controller" "${_app}/lib/Service"

# gate-20 TRUE POSITIVE: a fabricated method, called on the ObjectService.
cat > "${_app}/lib/Service/SeedService.php" <<'PHP'
<?php
namespace OCA\Fx\Service;

use OCA\OpenRegister\Service\ObjectService;

class SeedService
{
    public function __construct(private ObjectService $objectService) {}

    public function seed(): array
    {
        // `findObjects` does not exist on ObjectService. Real API: findAll().
        return $this->objectService->findObjects(['limit' => 1]);
    }
}
PHP

# gate-20 ANTI-WIDENING SIBLING: `createFromArray` IS a real method — on the
# mappers. The file mentions ObjectService, which is exactly what the old
# file-level heuristic keyed on, so this is the false positive that repairing
# the grep alone produces.
cat > "${_app}/lib/Service/ImportService.php" <<'PHP'
<?php
namespace OCA\Fx\Service;

use OCA\OpenRegister\Service\ObjectService;

class ImportService
{
    public function __construct(
        private ObjectService $objectService,
        private $schemaMapper,
    ) {}

    public function import(array $data): mixed
    {
        // A REAL method on a DIFFERENT class. Must never be reported.
        return $this->schemaMapper->createFromArray($data);
    }
}
PHP

# gate-17 TRUE POSITIVE: CRUD-named, body is one call to the REAL ObjectService
# API. This is the shape the old fabricated-name tuple could not see.
cat > "${_app}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;

class ThingController
{
    public function fetchAll(): JSONResponse
    {
        return new JSONResponse($this->objectService->findAll([]));
    }

    // ANTI-WIDENING SIBLING: identical body, DOMAIN name. The name is the
    // signal ADR-022 keys on, and it must still exempt this.
    public function publishThing(): JSONResponse
    {
        return new JSONResponse($this->objectService->findAll([]));
    }
}
PHP

(
    cd "${_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_logs="${_tmp}/logs"
mkdir -p "${_logs}"
_out="${_tmp}/run.txt"
(
    cd "${_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_logs}" bash "${RUNNER}" . > "${_out}" 2>&1
)

_verdict() { grep -oE "^\[gate-$1\] [^:]+: [A-Z]+( \([a-z]+\))?" "${_out}" | head -1 | sed 's/^[^:]*: //'; }

# ---- gate-20 ---------------------------------------------------------------
_v20="$(_verdict 20)"
_log20="${_logs}/hydra-gate-or-objectservice-api.log"
if [ "${_v20}" = "FAIL" ]; then
    _ok "gate-20 FAILS on a fabricated ObjectService method (was PASS for the gate's entire life)"
else
    _bad "gate-20 returned '${_v20:-none}' over \$this->objectService->findObjects( — the gate is still dead"
fi
if grep -q 'SeedService.php' "${_log20}" 2>/dev/null; then
    _ok "gate-20 NAMES the offending file"
else
    _bad "gate-20 did not name SeedService.php — a verdict that does not say where is not actionable"
fi
if grep -q 'ImportService.php' "${_log20}" 2>/dev/null; then
    _bad "gate-20 flagged \$this->schemaMapper->createFromArray() — a REAL method on a different class. This is the 14-finding openregister false positive."
else
    _ok "gate-20 does NOT flag a mapper's real createFromArray() in a file that merely mentions ObjectService"
fi
if [ "$(grep -c . "${_log20}" 2>/dev/null || echo 0)" -eq 1 ]; then
    _ok "exactly ONE gate-20 finding — the receiver is part of the pattern, not the file"
else
    _bad "expected exactly 1 gate-20 finding, got: $(tr '\n' '|' < "${_log20}" 2>/dev/null)"
fi

# ---- gate-17 ---------------------------------------------------------------
_v17="$(_verdict 17)"
_log17="${_logs}/hydra-gate-redundant-controller.log"
if [ "${_v17}" = "FAIL" ]; then
    _ok "gate-17 FAILS on a CRUD-named pass-through written against the REAL ObjectService API"
else
    _bad "gate-17 returned '${_v17:-none}' over fetchAll() { return new JSONResponse(\$this->objectService->findAll([])); }"
fi
if grep -q 'method=fetchAll' "${_log17}" 2>/dev/null; then
    _ok "gate-17 NAMES fetchAll"
else
    _bad "gate-17 did not name fetchAll"
fi
if grep -q 'method=publishThing' "${_log17}" 2>/dev/null; then
    _bad "gate-17 flagged publishThing — a domain-named method. The CRUD-name filter is the whole false-positive defence."
else
    _ok "gate-17 does NOT flag the identically-bodied domain-named sibling"
fi

# The checker must have finished — its terminal marker is the evidence.
if grep -q '^# count=' "${_log17}" 2>/dev/null; then
    _ok "gate-17's checker printed its terminal '# count=' marker (it reached its own summary)"
else
    _bad "no '# count=' marker in gate-17's log — the checker did not complete and any verdict is unmeasured"
fi

echo
if [ "${_fail_n}" -eq 0 ]; then
    echo "ALL gate-20 / gate-17 ObjectService-surface assertions PASSED"
    exit 0
fi
echo "${_fail_n} assertion(s) FAILED"
exit 1
