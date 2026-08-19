#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_crashed_checker_is_not_a_finding.sh — a checker that could not run
# must not produce a verdict about the code.
#
# WHAT THIS GUARDS (.github#245, #233)
# ------------------------------------
# Two gates turned an ENVIRONMENT failure into a statement about the source:
#
#   gate-17  passed the scope list as ONE argv string,
#            `--changed-files=<404 KB>`. A single argument is capped at
#            MAX_ARG_STRLEN — 128 KiB on Linux — regardless of ARG_MAX being
#            2 MB. The exec raised E2BIG, python3 never started, and the log
#            held the shell's "Argument list too long". `grep -c '^lib/'` then
#            counted zero findings in that error text and the gate reported
#            `FAIL — 0 pass-through method(s)`: a crashed checker wearing a
#            finding count, and a count of zero at that.
#
#            Measured on openregister: root-scoped CHANGED_FILES is 404,828
#            bytes across 7,224 files — over 3x the limit — and the pre-fix
#            baseline reports exactly that FAIL — 0 line.
#
#   gate-60   reported 43 confident FAILs ("Calendar does not exist") when
#            node_modules was absent. That was fixed by guarding the existence
#            check — which swapped it for the OPPOSITE error: the rule silently
#            stops running and the gate returns 0, so the runner prints PASS
#            over a check that never executed.
#
# Both directions are the same mistake. "I could not look" is a THIRD state:
# never PASS, never a finding count that was never measured.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_crashed_checker_is_not_a_finding.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-crashed.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

# ---------------------------------------------------------------------------
# THE MECHANISM ITSELF — a single argv string over 128 KiB is unusable.
#
# Asserted directly, because this is the fact the gate-17 fix depends on and it
# is not obvious: ARG_MAX says 2 MB, and the limit that bites is a different,
# per-argument one.
# ---------------------------------------------------------------------------
if python3 - <<'PY'
import subprocess, sys
big = "x" * (200 * 1024)
try:
    subprocess.run(["/bin/true", "--changed-files=" + big], check=True)
except OSError:
    sys.exit(0)   # E2BIG — the limit is real
sys.exit(1)       # no error: this platform does not have the limit
PY
then
    _ok "a single 200 KiB argv string raises E2BIG (the limit gate-17 tripped over)"
    _argv_limited=1
else
    echo "  note — this platform accepts a 200 KiB single argument; the file-based"
    echo "         scope path is still asserted below, but E2BIG cannot be provoked here."
    _argv_limited=0
fi

# ---------------------------------------------------------------------------
# gate-17 — a scope list far larger than MAX_ARG_STRLEN must still be inspected,
# and must NOT come back as "FAIL — 0 pass-through method(s)".
# ---------------------------------------------------------------------------
_app="${_tmp}/app"
mkdir -p "${_app}/lib/Controller" "${_app}/src"
printf '{"name":"fx","menu":[]}\n' > "${_app}/src/manifest.json"
cat > "${_app}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;
class ThingController {
    public function index() { return 1; }
}
PHP
# TWO commits: the diff has to be root..HEAD, and a repo whose root IS its HEAD
# has an empty diff — the runner refuses that outright (base == HEAD), so no
# gate would report and this arm would pass for the wrong reason.
(
    cd "${_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

# Enough files that the newline-joined path list clears 128 KiB comfortably.
mkdir -p "${_app}/src/filler"
_i=0
while [ "${_i}" -lt 3000 ]; do
    printf 'export const x%s = 1\n' "${_i}" \
        > "${_app}/src/filler/a-file-with-a-deliberately-long-name-${_i}.ts"
    _i=$((_i + 1))
done
(
    cd "${_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm "bulk"
) >/dev/null 2>&1

_root=$(cd "${_app}" && git rev-list --max-parents=0 HEAD | tail -1)
_scope_bytes=$(cd "${_app}" && git diff --name-only --diff-filter=ACMR "${_root}...HEAD" | wc -c)
echo "  (scope list is ${_scope_bytes} bytes; MAX_ARG_STRLEN is 131072)"

_logs="${_tmp}/logs"
mkdir -p "${_logs}"
_out="${_tmp}/run.txt"
(
    cd "${_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_logs}" bash "${_runner}" \
        --scope-to-diff --base "${_root}" . > "${_out}" 2>&1
)

_v17=$(grep -oE '^\[gate-17\] [^:]+: [A-Z]+( \([a-z]+\))?' "${_out}" | head -1 | sed 's/^[^:]*: //')
if grep -qE '^\[gate-17\][^:]*: FAIL — 0 ' "${_out}"; then
    _bad "gate-17 reported 'FAIL — 0 pass-through method(s)' — a crash rendered as a finding count"
elif [ "${_v17}" = "PASS" ] || [ "${_v17}" = "FAIL" ]; then
    _ok "gate-17 produced a real verdict (${_v17}) over a ${_scope_bytes}-byte scope list"
elif [ "${_v17}" = "SKIPPED (wiring)" ]; then
    _ok "gate-17 reported SKIPPED (wiring) — honest, though the scope file should have avoided the crash"
else
    _bad "gate-17 verdict is '${_v17:-none emitted}' — expected a real verdict"
fi

# The mechanism: the scope must travel via a FILE, not argv.
if [ -s "${_logs}/hydra-gate-17-scope.txt" ]; then
    _ok "the scope list travelled in a file, not in argv"
else
    _bad "no scope file was written — the list is still going through argv and will E2BIG again"
fi

# ---------------------------------------------------------------------------
# gate-60 — node_modules absent. Not a pass, and not 43 findings.
# ---------------------------------------------------------------------------
_icon_app="${_tmp}/icons"
mkdir -p "${_icon_app}/src"
cat > "${_icon_app}/src/manifest.json" <<'JSON'
{"name":"fx","menu":[{"label":"Calendar","icon":"Calendar","route":"/cal"}]}
JSON
(
    cd "${_icon_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_ilogs="${_tmp}/ilogs"
mkdir -p "${_ilogs}"
_iout="${_tmp}/icons.txt"
(
    cd "${_icon_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_ilogs}" bash "${_runner}" . > "${_iout}" 2>&1
)

_v60=$(grep -oE '^\[gate-60\] [^:]+: [A-Z]+( \([a-z]+\))?' "${_iout}" | head -1 | sed 's/^[^:]*: //')
case "${_v60}" in
    "SKIPPED (wiring)")
        _ok "gate-60 reports SKIPPED (wiring) when vue-material-design-icons is absent"
        ;;
    PASS)
        _bad "gate-60 reported PASS while the icon-existence rule never ran — the #233 regression, inverted"
        ;;
    FAIL)
        _bad "gate-60 reported FAIL from a missing dependency — an environment failure rendered as findings (#233)"
        ;;
    *)
        _bad "gate-60 verdict is '${_v60:-none emitted}' — expected SKIPPED (wiring)"
        ;;
esac

# It must say WHAT could not be checked, not merely that something was skipped.
if grep -qE '^\[gate-60\][^:]*: SKIPPED \(wiring\) — .*vue-material-design-icons' "${_iout}"; then
    _ok "gate-60's skip names the missing dependency and what it left unverified"
else
    _bad "gate-60's skip does not name the missing dependency"
fi

# THE CONTROL: the rules that CAN run without node_modules must still run, so
# this is not "skip the gate whenever a dependency is missing".
_bad_icon_app="${_tmp}/icons-bad"
mkdir -p "${_bad_icon_app}/src"
cat > "${_bad_icon_app}/src/manifest.json" <<'JSON'
{"name":"fx","menu":[{"label":"Dashboard","icon":"CarSportOutline","route":"/d"}]}
JSON
(
    cd "${_bad_icon_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1
_blogs="${_tmp}/blogs"
mkdir -p "${_blogs}"
_bout="${_tmp}/icons-bad.txt"
(
    cd "${_bad_icon_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_blogs}" bash "${_runner}" . > "${_bout}" 2>&1
)
if grep -qE '^\[gate-60\][^:]*: FAIL' "${_bout}"; then
    _ok "a Tier-A concept on a non-canonical icon is STILL caught without node_modules"
else
    _v=$(grep -oE '^\[gate-60\] [^:]+: [A-Z]+( \([a-z]+\))?' "${_bout}" | head -1 | sed 's/^[^:]*: //')
    _bad "gate-60 returned '${_v}' for a real violation it can detect offline — the missing dependency is now suppressing rules that DO work"
fi

# ---------------------------------------------------------------------------
# gates 56 + 57 — a DEAD INTERPRETER must not read as a clean tree (.github#276)
#
# Both invoked their helper as `>> log 2>/dev/null || true` and then derived
# the verdict from `wc -l` on the log. Stderr discarded, exit status
# discarded, empty log — so a checker that never started reported PASS.
#
# Measured on shillinq against gate package 48c88ba, with a python3 shim that
# exits 1 for exactly these two helpers:
#
#     [gate-56] register-handler-resolution: PASS      <- 153 registers
#     [gate-57] orphaned-write-capability:   PASS      <- 316 services
#
# and gate-57 had reported 20 real findings over that same tree on the
# previous run. That is gate-40's defect verbatim.
#
# Both helpers ALWAYS exit 0 when they run, by design (#209 — the count goes
# to stdout, never into the exit byte), so a non-zero exit here can only be a
# crash and never a finding count. TWO ARMS: the crash must be visible, and
# the same tree with a working interpreter must still produce real verdicts —
# otherwise "always skip" would pass this test.
# ---------------------------------------------------------------------------
_crash_app="${_tmp}/crash-5657"
mkdir -p "${_crash_app}/lib/Settings/register.d" "${_crash_app}/lib/Service" \
         "${_crash_app}/appinfo"
printf '<?xml version="1.0"?>\n<info>\n <id>leaf</id>\n</info>\n' \
    > "${_crash_app}/appinfo/info.xml"
# The SECOND commit has to carry the subjects, or ADR-020 scopes them out and
# both gates answer `na` — which would satisfy the crash arm for the wrong
# reason. (Measured while writing this: with the plants in commit 1 and a
# docs-only commit 2, all four assertions read NOT APPLICABLE.)
(
    cd "${_crash_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1
# One unresolvable guard (gate-56) and one caller-less write method (gate-57).
cat > "${_crash_app}/lib/Settings/register.d/10-thing.json" <<'JSON'
{"components":{"schemas":{"T":{"x-openregister-lifecycle":{"states":{
  "s":{"guard":"OCA\\Leaf\\Lifecycle\\NoSuchGuard::may"}}}}}}}
JSON
# The body has to DO something: an empty `{}` is a stub, and gate-57
# deliberately does not call a stub an orphaned write capability.
cat > "${_crash_app}/lib/Service/ThingService.php" <<'PHP'
<?php
namespace OCA\Leaf\Service;
class ThingService {
    public function publishScore(string $id, float $score): void {
        file_put_contents('/dev/null', $id . $score);
    }
}
PHP
(
    cd "${_crash_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm "plant one TP per gate"
) >/dev/null 2>&1
_croot=$(cd "${_crash_app}" && git rev-parse HEAD~1)

# ARM A — with a working interpreter, both gates must FAIL on the plants.
_clogs_ok="${_tmp}/clogs-ok"; mkdir -p "${_clogs_ok}"
_cout_ok="${_tmp}/crash-5657-ok.txt"
(
    cd "${_crash_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_clogs_ok}" bash "${_runner}" \
        --scope-to-diff --base "${_croot}" . > "${_cout_ok}" 2>&1
)
for _g in 56 57; do
    if grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_cout_ok}"; then
        _ok "gate-${_g} FAILs its planted true positive with a working interpreter"
    else
        _v=$(grep -oE "^\[gate-${_g}\] [^:]+: [A-Z]+( [A-Z]+)?( \([a-z]+\))?" "${_cout_ok}" | head -1 | sed 's/^[^:]*: //')
        _bad "gate-${_g} returned '${_v:-none}' for a planted true positive — the crash arm below would then prove nothing"
    fi
done

# ARM B — the same tree with a python3 that dies for these two helpers only.
_shim="${_tmp}/shim"; mkdir -p "${_shim}"
cat > "${_shim}/python3" <<'SHIM'
#!/bin/bash
for a in "$@"; do
  case "$a" in
    *check_register_handler_resolution.py|*check_orphaned_write_capability.py)
      echo "Traceback (most recent call last): ModuleNotFoundError" >&2
      exit 1 ;;
  esac
done
exec /usr/bin/python3 "$@"
SHIM
chmod +x "${_shim}/python3"
_clogs="${_tmp}/clogs"; mkdir -p "${_clogs}"
_cout="${_tmp}/crash-5657.txt"
(
    cd "${_crash_app}" || exit 1
    PATH="${_shim}:${PATH}" HYDRA_GATE_LOG_DIR="${_clogs}" bash "${_runner}" \
        --scope-to-diff --base "${_croot}" . > "${_cout}" 2>&1
)
for _g in 56 57; do
    _v=$(grep -oE "^\[gate-${_g}\] [^:]+: [A-Z]+( [A-Z]+)?( \([a-z]+\))?" "${_cout}" | head -1 | sed 's/^[^:]*: //')
    case "${_v}" in
        "SKIPPED (wiring)")
            _ok "gate-${_g} reports SKIPPED (wiring) when its checker cannot run"
            ;;
        PASS)
            _bad "gate-${_g} reported PASS over a checker that exited 1 — this is gate-40's defect: it FAILED the same tree one run earlier"
            ;;
        FAIL)
            _bad "gate-${_g} reported FAIL from a crashed checker — a crash wearing a finding count (#209/#245)"
            ;;
        *)
            _bad "gate-${_g} verdict is '${_v:-none emitted}' — expected SKIPPED (wiring)"
            ;;
    esac
done

# EVERY python-backed gate, with a python3 that cannot run (.github#271)
#
# The two arms above each test ONE gate against ONE way of failing. This arm
# breaks the interpreter for the whole suite at once and asks the only question
# that matters: which gates notice?
#
# MEASURED 2026-08-08 on pipelinq at gate package cdfbd7ab, with
# `python3` shadowed by a stub that prints a traceback and exits 1 — so NOT ONE
# file was inspected by ANY python-backed gate:
#
#     gate-12 nc-input-labels        SKIPPED (wiring)   <- honest
#     gate-15 dashboard-antipattern  PASS               <- green over nothing
#     gate-16 spec-coverage          PASS               <- green over nothing
#     gate-17 redundant-controller   SKIPPED (wiring)   <- honest
#     gate-18 notification-dialect   PASS               <- green over nothing
#     gate-19 e2e-coverage           FAIL — "an unreported number of scenario(s)"
#
# Three greens and one blocking failure whose count nobody measured, from a run
# in which nothing was read. 12 and 17 got this right because they check the
# helper's exit status; 15, 16 and 18 wrote `2>/dev/null || true` and counted
# lines in an empty log.
#
# This arm keeps that from coming back. It is deliberately GENERIC: any future
# python-backed gate that lands with the `|| true` idiom fails here on its first
# run, without anyone remembering to add it to a list.
# ---------------------------------------------------------------------------
_fakebin="${_tmp}/fakebin"
mkdir -p "${_fakebin}"
cat > "${_fakebin}/python3" <<'SH'
#!/bin/sh
echo "Traceback (most recent call last): simulated interpreter failure" >&2
exit 1
SH
chmod +x "${_fakebin}/python3"

_pyapp="${_tmp}/pyapp"
mkdir -p "${_pyapp}/lib/Controller" "${_pyapp}/lib/Settings" "${_pyapp}/src/views" "${_pyapp}/openspec/specs/thing"
cat > "${_pyapp}/src/manifest.json" <<'JSON'
{"name":"fx","pages":[{"id":"Dash","route":"/","type":"dashboard","title":"Dash","config":{"widgets":[{"id":"kpis","type":"custom"}]}}]}
JSON
cat > "${_pyapp}/src/views/Dash.vue" <<'VUE'
<template><div><NcSelect :options="o" /></div></template>
VUE
cat > "${_pyapp}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;
class ThingController {
    public function index() { return 1; }
}
PHP
cat > "${_pyapp}/lib/Settings/thing_register.json" <<'JSON'
{"components":{"schemas":{"Thing":{"x-openregister-notifications":{"r":{"channel":"nc","recipient":"@self.owner"}}}}}}
JSON
cat > "${_pyapp}/openspec/specs/thing/spec.md" <<'MD'
# Thing

## Purpose

Thing.

### Requirement: The system SHALL thing

#### Scenario: A thing happens

- **GIVEN** a thing
- **WHEN** it happens
- **THEN** it happened
MD
(
    cd "${_pyapp}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

_pylogs="${_tmp}/pylogs"
mkdir -p "${_pylogs}"
_pyout="${_tmp}/pyrun.txt"
(
    cd "${_pyapp}" || exit 1
    PATH="${_fakebin}:${PATH}" HYDRA_GATE_LOG_DIR="${_pylogs}" \
        bash "${_runner}" . > "${_pyout}" 2>&1
)

# A control first: the stub really is in play. If python3 still works, every
# assertion below would pass for the wrong reason — the exact "a check that did
# not run looks like one that passed" shape this file is about.
if grep -q 'simulated interpreter failure' "${_pyout}" \
    || grep -rq 'simulated interpreter failure' "${_pylogs}" 2>/dev/null; then
    _ok "control: the python3 stub was actually used (its traceback reached the run)"
else
    _bad "control FAILED: no evidence the python3 stub ran — the assertions below prove nothing"
fi

# Gates 6, 7 and 9 joined this list in .github#330. All three invoked their
# helper as `>> log 2>/dev/null || true` — the gate-20 construct verbatim,
# discarding the message AND the status — and then took the verdict from
# `wc -l` on a log nothing had written to.
#
# MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
#
#     working python3              python3 that exits 1
#     [gate-6] orphan-auth: FAIL     [gate-6] orphan-auth: PASS
#     [gate-7] no-admin-idor: FAIL   [gate-7] no-admin-idor: PASS
#     [gate-9] semantic-auth: FAIL   [gate-9] semantic-auth: PASS
#
# Three SECURITY gates — dead authorization code, IDOR, and auth-attribute
# mismatch — reporting a tree clean that they had not opened. gate-7 is the
# gate a missing helper had already blinded once, over 11 real unguarded
# endpoints (.github#147); this was the second route to the same false green.
for _g in 6:orphan-auth 7:no-admin-idor 9:semantic-auth \
          12:nc-input-labels 15:dashboard-antipattern 16:spec-coverage \
          17:redundant-controller 18:notification-dialect 19:e2e-coverage; do
    _num="${_g%%:*}"
    _name="${_g#*:}"
    _v=$(grep -oE "^\[gate-${_num}\] [^:]+: [A-Z]+( \([a-z]+\))?" "${_pyout}" | head -1 | sed 's/^[^:]*: //')
    case "${_v}" in
        "SKIPPED (wiring)")
            _ok "gate-${_num} ${_name}: SKIPPED (wiring) when python3 cannot run"
            ;;
        PASS)
            _bad "gate-${_num} ${_name}: PASS — its checker never executed and it reported the code clean anyway"
            ;;
        FAIL)
            _bad "gate-${_num} ${_name}: FAIL — an environment failure rendered as findings about the source"
            ;;
        "")
            # A gate whose SUBJECT is absent may legitimately emit nothing;
            # this fixture gives all six a subject, so silence is a defect.
            _bad "gate-${_num} ${_name}: emitted NO verdict line at all, though this fixture gives it a subject"
            ;;
        *)
            _bad "gate-${_num} ${_name}: verdict is '${_v}' — expected SKIPPED (wiring)"
            ;;
    esac
done

# ...and the crash must be RECOVERABLE, not silently discarded to /dev/null.
if [ -s "${_clogs}/hydra-gate-register-handler-resolution.err" ] \
   && [ -s "${_clogs}/hydra-gate-orphaned-write-capability.err" ]; then
    _ok "both crashes left their stderr on disk for the reader"
else
    _bad "a crashed checker's stderr was discarded — the reason it died is unrecoverable"
fi

# Each skip must say WHAT went unchecked — a bare "skipped" is unactionable and
# is how a wiring failure gets filed under "known noise".
for _num in 15 16 18; do
    if grep -qE "^\[gate-${_num}\][^:]*: SKIPPED \(wiring\) — .*(UNVERIFIED|did not complete|exited)" "${_pyout}"; then
        _ok "gate-${_num}'s skip names what it left unverified"
    else
        _bad "gate-${_num}'s skip does not say what went unchecked"
    fi
done

# THE ANTI-WIDENING CONTROL. With a WORKING python3 the same fixture must
# produce real verdicts — this must not become "skip whenever anything looks
# odd". The fixture's register file carries the legacy dialect, so gate-18 has
# something to find and MUST find it.
_oklogs="${_tmp}/oklogs"
mkdir -p "${_oklogs}"
_okout="${_tmp}/pyrun-ok.txt"
(
    cd "${_pyapp}" || exit 1
    HYDRA_GATE_LOG_DIR="${_oklogs}" bash "${_runner}" . > "${_okout}" 2>&1
)
if grep -qE '^\[gate-18\][^:]*: FAIL' "${_okout}"; then
    _ok "control: with a working python3, gate-18 still FAILS on the planted legacy dialect"
else
    _v=$(grep -oE '^\[gate-18\] [^:]+: [A-Z]+( \([a-z]+\))?' "${_okout}" | head -1 | sed 's/^[^:]*: //')
    _bad "control FAILED: gate-18 returned '${_v}' on a register file carrying channel/recipient/@self. — the skip logic is suppressing a real finding"
fi
for _num in 15 16; do
    _v=$(grep -oE "^\[gate-${_num}\] [^:]+: [A-Z]+( \([a-z]+\))?" "${_okout}" | head -1 | sed 's/^[^:]*: //')
    if [ "${_v}" = "SKIPPED (wiring)" ]; then
        _bad "control FAILED: gate-${_num} still reports SKIPPED (wiring) with a working python3 — the marker check is broken, not the interpreter"
    else
        _ok "control: gate-${_num} produces a real verdict (${_v:-none}) with a working python3"
    fi
done

# ---------------------------------------------------------------------------
# GATES 59-64 — A CRASH MUST NOT WEAR A FINDING COUNT (.github#330)
#
# The mirror image of everything above. These six gates take a NUMERIC EXIT
# PROTOCOL from their helper (0 pass / 1 findings / 3 empty scope / 4 n-a /
# 5 tooling missing) and sent every unrecognised code to `_fail`. A crash also
# exits 1, so it arrived at the findings branch — and because a traceback
# contains no `FAIL` lines, `_count '^FAIL'` returned 0 and the runner then ran
# `[ "${_n}" -eq 0 ] && _n=1`, INVENTING a finding count of one for a check
# that had measured nothing.
#
# MEASURED 2026-08-10 at package 71e01bf, one fixture, two runs:
#
#     working python3                    python3 that exits 1
#     [gate-59] unclosable-gate: PASS      FAIL — config gate(s) read but never written
#     [gate-62] store-plane: NOT APPLICABLE FAIL — 1 naming or discovery violation(s)
#     [gate-63] settings-surface: NOT APPL. FAIL — 1 naming or discovery violation(s)
#
# 62 and 63 blocked a repo that does not even HAVE the subject matter. A gate
# that is wrong in the RED direction burns the same credit as one that is wrong
# in the green direction, and it is the reason a suite gets switched off.
#
# TWO ARMS, as everywhere in this file: the planted true positives must still
# be caught with a working interpreter, or "always skip" would satisfy the
# crash arm.
# ---------------------------------------------------------------------------
_hi_app="${_tmp}/highgates"
mkdir -p "${_hi_app}/lib/AppInfo" "${_hi_app}/lib/Service" "${_hi_app}/src" \
         "${_hi_app}/appinfo"
printf '<?xml version="1.0"?>\n<info>\n <id>hg</id>\n</info>\n' \
    > "${_hi_app}/appinfo/info.xml"
# gate-59: a config key READ but never WRITTEN (the docudesk shape, ADR-076).
cat > "${_hi_app}/lib/Service/SettingsInitializer.php" <<'PHP'
<?php
namespace OCA\Hg\Service;
class SettingsInitializer {
    public function initialize(): void {
        $v = $this->config->getAppValue('hg', 'configuration_version', '');
        if ($v === '') {
            $this->importFromApp();
        }
    }
}
PHP
# gate-64: an AppHost adoption with no OpenRegister autoload prelude (ADR-040).
cat > "${_hi_app}/lib/AppInfo/Application.php" <<'PHP'
<?php
namespace OCA\Hg\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
class Application extends App {
    public function register(IRegistrationContext $context): void {
        \OCA\OpenRegister\AppHost\Bootstrap::register($context, 'hg');
    }
}
PHP
# gate-60: an icon outside the canonical vocabulary (ADR-077).
cat > "${_hi_app}/src/manifest.json" <<'JSON'
{"name":"hg","menu":[{"label":"Dashboard","icon":"CarSportOutline","route":"/d"}]}
JSON
(
    cd "${_hi_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
) >/dev/null 2>&1

# ARM A — working interpreter. The plants must be caught.
_hilogs_ok="${_tmp}/hilogs-ok"; mkdir -p "${_hilogs_ok}"
_hiout_ok="${_tmp}/highgates-ok.txt"
(
    cd "${_hi_app}" || exit 1
    HYDRA_GATE_LOG_DIR="${_hilogs_ok}" bash "${_runner}" . > "${_hiout_ok}" 2>&1
)
for _g in 59:unclosable-gate 60:icon-vocabulary 64:apphost-autoload-prelude; do
    _num="${_g%%:*}"; _name="${_g#*:}"
    if grep -qE "^\[gate-${_num}\][^:]*: FAIL" "${_hiout_ok}"; then
        _ok "gate-${_num} ${_name}: FAILs its planted true positive with a working interpreter"
    else
        _v=$(grep -oE "^\[gate-${_num}\] [^:]+: [A-Z]+( [A-Z]+)?( \([a-z]+\))?" "${_hiout_ok}" | head -1 | sed 's/^[^:]*: //')
        _bad "gate-${_num} ${_name}: returned '${_v:-none}' for a planted true positive — the crash arm below would prove nothing"
    fi
done

# The count must be MEASURED, not invented. Gates 59 and 64 print no `FAIL`-
# prefixed lines at all, so `_count '^FAIL'` was always 0 and the fallback
# `_n=1` fired on EVERY real failure — a number nobody had counted.
if grep -qE '^\[gate-59\][^:]*: FAIL — [0-9]+ config gate\(s\)' "${_hiout_ok}"; then
    _ok "gate-59's failure carries a measured count, not a bare verdict"
else
    _bad "gate-59's failure line has no measured count"
fi

# ARM B — the same tree, interpreter dead. No FAIL, no PASS: a named wiring skip.
_hilogs="${_tmp}/hilogs"; mkdir -p "${_hilogs}"
_hiout="${_tmp}/highgates-crash.txt"
(
    cd "${_hi_app}" || exit 1
    PATH="${_fakebin}:${PATH}" HYDRA_GATE_LOG_DIR="${_hilogs}" \
        bash "${_runner}" . > "${_hiout}" 2>&1
)
for _g in 59:unclosable-gate 60:icon-vocabulary 61:listener-work-placement \
          62:store-plane 63:settings-surface 64:apphost-autoload-prelude; do
    _num="${_g%%:*}"; _name="${_g#*:}"
    _v=$(grep -oE "^\[gate-${_num}\] [^:]+: [A-Z]+( [A-Z]+)?( \([a-z]+\))?" "${_hiout}" | head -1 | sed 's/^[^:]*: //')
    case "${_v}" in
        "SKIPPED (wiring)")
            _ok "gate-${_num} ${_name}: SKIPPED (wiring) when its checker cannot run"
            ;;
        FAIL)
            _bad "gate-${_num} ${_name}: FAIL from a crashed checker — an environment failure rendered as findings about the source (#233/#245/#330)"
            ;;
        PASS)
            _bad "gate-${_num} ${_name}: PASS over a checker that never executed"
            ;;
        *)
            _bad "gate-${_num} ${_name}: verdict is '${_v:-none emitted}' — expected SKIPPED (wiring)"
            ;;
    esac
done

# ---------------------------------------------------------------------------
# THE CONSTRUCT ITSELF — `2>/dev/null || true` on a helper invocation.
#
# Every specific assertion above is a statement about the gates that exist
# today. This one is about the SHAPE, so the next gate to land with it fails
# here on its first run rather than after a fleet-wide false green.
#
# `2>/dev/null` on a helper discards the reason it died; `|| true` discards the
# fact that it died. Together they are why gate-20 had never fired in any repo
# in its entire existence: its grep pattern began with `->`, grep parsed that as
# options and exited 2, and both halves of the evidence went in the bin.
# ---------------------------------------------------------------------------
_offenders=$(grep -nE '(python3|node|npx|bash)[^|]*2>/dev/null[[:space:]]*\|\|[[:space:]]*true' "${_scripts}/run-hydra-gates.sh" \
    | grep -vE '^[0-9]+:[[:space:]]*#' || true)
# Multi-line invocations put the redirect on a continuation line, so also look
# for a `2>/dev/null || true` whose PREVIOUS line ends in a backslash after an
# interpreter name.
_offenders2=$(awk '
    /(python3|node |npx |bash )/ && /\\$/ { prev=NR; next }
    /2>\/dev\/null[[:space:]]*\|\|[[:space:]]*true/ && NR == prev+1 { print NR": "$0 }
' "${_scripts}/run-hydra-gates.sh")
if [ -z "${_offenders}${_offenders2}" ]; then
    _ok "no helper in run-hydra-gates.sh is invoked with '2>/dev/null || true' (the gate-20 construct)"
else
    _bad "a helper is still invoked with '2>/dev/null || true' — message and status both discarded:"
    printf '%s\n%s\n' "${_offenders}" "${_offenders2}" | sed '/^$/d' | sed 's/^/         /'
fi

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_crashed_checker_is_not_a_finding.sh: ALL PASS"
    exit 0
fi
echo "test_gate_crashed_checker_is_not_a_finding.sh: ${_failures} FAILURE(S)"
exit 1
