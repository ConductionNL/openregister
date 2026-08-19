#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_45_to_55_acceptance.sh — the acceptance test for gates 45–55:
# each one must FAIL on a planted true positive, PASS on the clean fixture,
# and say NOT APPLICABLE (never PASS) when it inspected nothing.
#
# WHY THIS EXISTS
# ---------------
# On 2026-08-08 one textbook true positive was planted per a11y gate in
# nldesign and ALL THIRTEEN reported PASS: eleven globbed `src/**/*.vue` and
# nldesign ships zero `.vue` files. Every green was a green over nothing, and
# no open issue named any of them — because nobody had ever asked a gate to
# fail on purpose. "No open issue" means nobody looked.
#
# So every arm below plants a defect FIRST and asserts the gate names it.
# An arm that only ever sees clean input proves nothing: a checker that has
# been widened until it catches nothing passes it identically.
#
# THREE FAMILIES OF ARM
# ---------------------
#   A. UNOPENED SCOPE IS NEVER PASS (#242/#240/#258/#268). All eleven gates
#      printed PASS on a README-only diff — a run in which not one of them
#      opened a file. Measured on larpingapp: 11 PASS lines, and the summary
#      then read "53 of 53 applicable gates ran".
#   B. GATE-45's GUARD MUST MIRROR ITS ENUMERATOR (#225/#261). It reads
#      src/ + templates/ + appinfo/templates/ but was gated on `[ -d src ]`,
#      so a templates-only app got NOT APPLICABLE over live markup. A false
#      `na` is worse than the PASS it replaced: `na` removes the gate from the
#      coverage arithmetic, so the run reports "all applicable gates green"
#      with the defect inside it.
#   C. GATE-50 WAS WRONG IN BOTH DIRECTIONS AT ONCE. It could not see a config
#      read whose app id is a class constant (the fleet-standard idiom), and
#      it rejected a correct compound `if ($a === '' || $b === '')` guard.
#      Both arms are here, plus the opencatalogi#86 shape that mixes them:
#      a guarded read and an unguarded one two lines apart.
#
# Run: bash hydra-gates/scripts/lib/test_gate_45_to_55_acceptance.sh
#
# ShellCheck: SC2016 is suppressed for this file only. The PHP and JS fixtures
# below are written as single-quoted heredocs on purpose — `$reg`, `$sch`,
# `$this->appConfig` are PHP variables that must reach the fixture VERBATIM.
# Letting the shell expand them would write `  = ->appConfig->...` into the
# file and silently turn every arm into a test of an empty fixture, which is
# the exact "green over nothing" failure this suite exists to catch.
# shellcheck disable=SC2016

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

# ---------------------------------------------------------------------------
# PREFLIGHT — ajv must be resolvable, or this suite reports GATE DEFECTS that
# are really a WIRING fault, and one of them is a false pass.
#
# Measured 2026-08-09 on a fresh clone of main (c51a225, zero drift): with ajv
# unresolvable, gate-53 fails closed ("ajv not resolvable ... refusing to run
# fail-open"), and FOUR arms of FAMILY D then read as gate defects —
#   D2  removing component + registry entry together : expected PASS, got FAIL
#   D3  a pre-existing orphan stays advisory         : expected PASS, got FAIL
#   D1b the finding NAMES EventRoster                : name never appears
#   D3b the pre-existing orphan surfaces as a WARN   : WARN never appears
# and this was reported upstream as "the suite expects blocking where the gate
# was deliberately made advisory". It does not. The gate is correct.
#
# 🔑 Worse, arm D1 still printed `ok`. It expects FAIL and got FAIL — for a
# completely unrelated reason. That is a FALSE PASS inside the very suite
# built to catch false passes, and it is why this preflight aborts instead of
# letting the run continue with a warning.
#
# ⚠️ `node -e "require('ajv')"` is NOT a valid check on its own: Node resolves
# UPWARD from the cwd, so it can succeed against a node_modules belonging to
# some ancestor directory rather than to the gates package. Resolve it from
# the helpers' own directory — the same place the runner resolves it from —
# and print the ABSOLUTE PATH, because the path is the evidence and the exit
# code is not.
# ---------------------------------------------------------------------------
if ! command -v node >/dev/null 2>&1; then
    echo "WIRING: node is not on PATH." >&2
    echo "        Every gate in this band that shells out to a JS helper would fail" >&2
    echo "        closed, and this suite would report that as a gate defect." >&2
    exit 2
fi
_ajv_at="$(cd "${_scripts}/lib" && node -e "process.stdout.write(require.resolve('ajv'))" 2>/dev/null || true)"
if [ -z "${_ajv_at}" ]; then
    echo "WIRING: ajv is not resolvable from ${_scripts}/lib." >&2
    echo "        gates 22 and 53 fail CLOSED without it, so this suite would report" >&2
    echo "        four gate-53 defects that do not exist — and arm D1 would print 'ok'" >&2
    echo "        for the wrong reason. Refusing to run rather than emit a false verdict." >&2
    echo "        Fix: NODE_PATH=<dir containing ajv> bash ${BASH_SOURCE[0]}" >&2
    exit 2
fi

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_45_to_55_acceptance.sh"
echo "  preflight — ajv resolves to ${_ajv_at}"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g4555.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_run() {  # _run <appdir> <outfile> [runner args...]
    local app="$1" out="$2"; shift 2
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "${app}" || exit 1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" "$@" . > "${out}" 2>&1
    )
    return $?
}

# The verdict word is not always one token — "NOT APPLICABLE" is two.
_verdict() { grep -oE "^\[gate-$2\] [^:]+: [A-Z]+( [A-Z]+)*( \([a-z]+\))?" "$1" | head -1 | sed 's/^[^:]*: //'; }

_expect() {  # _expect <outfile> <gate> <expected-verdict> <what>
    local got; got="$(_verdict "$1" "$2")"
    if [ "${got}" = "$3" ]; then
        _ok "gate-$2 $4 → $3"
    else
        _bad "gate-$2 $4 → expected '$3', got '${got:-<no line at all>}'"
    fi
}

_commit() { git -C "$1" add -A >/dev/null 2>&1; git -C "$1" -c user.email=t@t -c user.name=t commit -qm "$2" >/dev/null 2>&1; }

# ===========================================================================
# FAMILY A — an unopened scope must render NOT APPLICABLE, never PASS.
# ===========================================================================
_appA="${_tmp}/appA"
mkdir -p "${_appA}/src" "${_appA}/lib/Controller" "${_appA}/lib/Settings"
cat > "${_appA}/src/manifest.json" <<'JSON'
{ "$schema": "https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json", "version": "0.1.0", "menu": [], "pages": [] }
JSON
printf 'export default {}\n' > "${_appA}/src/registry.js"
cat > "${_appA}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;
class ThingController {
    public function index() { return 1; }
}
PHP
printf '{"components":{"schemas":{}}}\n' > "${_appA}/lib/Settings/fx_register.json"
git -C "${_appA}" init -q .
_commit "${_appA}" init
printf 'docs only\n' > "${_appA}/README.md"
_commit "${_appA}" docs

_base="$(git -C "${_appA}" rev-parse HEAD~1)"
_outA="${_tmp}/a.txt"
_run "${_appA}" "${_outA}" --scope-to-diff --base "${_base}"

# The README-only diff opens nothing for any of these. Every one of them used
# to print PASS.
for _g in 45 46 49 50 51 53 54 55; do
    _expect "${_outA}" "${_g}" "NOT APPLICABLE" "on a README-only diff (nothing inspected)"
done

# Gates 47/48 legitimately RAN here — they classified the diff and found no
# security change — so PASS is the correct verdict and the arm below is the
# anti-widening pair for family A: the fix must not turn every gate into a
# permanent skip.
_expect "${_outA}" 47 "PASS" "classified a real (non-security) diff"
_expect "${_outA}" 48 "PASS" "examined a real (no-removal) diff"

# ...and on a run with NO diff at all, 47/48 cannot form a verdict.
_outAfull="${_tmp}/a-full.txt"
_run "${_appA}" "${_outAfull}"
_expect "${_outAfull}" 47 "NOT APPLICABLE" "on a whole-repository run (no change set)"
_expect "${_outAfull}" 48 "NOT APPLICABLE" "on a whole-repository run (no change set)"

# ===========================================================================
# FAMILY B — gate-45 on an app that renders from PHP templates and has no src/.
# ===========================================================================
_appB="${_tmp}/appB"
mkdir -p "${_appB}/templates/settings"
cat > "${_appB}/templates/settings/admin.php" <<'PHP'
<div id="fx-admin">
	<p>Settings</p>
</div>
<style>
.fx-banner { transition: opacity 0.4s ease; }
</style>
PHP
git -C "${_appB}" init -q .
_commit "${_appB}" init

_outB="${_tmp}/b.txt"
_run "${_appB}" "${_outB}"
_expect "${_outB}" 45 "FAIL" "sees a <style> transition in a PHP template with no src/"

# Anti-widening: the same tree with the fallback present must go green, and a
# tree with no motion at all must go green too.
cat > "${_appB}/templates/settings/admin.php" <<'PHP'
<div id="fx-admin">
	<p>Settings</p>
</div>
<style>
.fx-banner { transition: opacity 0.4s ease; }
@media (prefers-reduced-motion: reduce) {
	.fx-banner { transition: none; }
}
</style>
PHP
_commit "${_appB}" "add the reduced-motion fallback"
_outB2="${_tmp}/b2.txt"
_run "${_appB}" "${_outB2}"
_expect "${_outB2}" 45 "PASS" "accepts a template that ships the fallback"

# And a repo that truly renders no markup at all is still NOT APPLICABLE — the
# category must not become unreachable.
_appB3="${_tmp}/appB3"
mkdir -p "${_appB3}/lib"
printf '<?php\n' > "${_appB3}/lib/Nothing.php"
git -C "${_appB3}" init -q .
_commit "${_appB3}" init
_outB3="${_tmp}/b3.txt"
_run "${_appB3}" "${_outB3}"
_expect "${_outB3}" 45 "NOT APPLICABLE" "on a repo with no src/, templates/ or appinfo/templates/"

# ===========================================================================
# FAMILY C — gate-50, both directions.
# ===========================================================================
_appC="${_tmp}/appC"
mkdir -p "${_appC}/lib/Service"

_write_service() {  # _write_service <body>
    cat > "${_appC}/lib/Service/ListingService.php" <<PHP
<?php
namespace OCA\\Fx\\Service;
class ListingService {
$1
}
PHP
}

# C1 — the leak, written with a CLASS CONSTANT app id. This is the shape the
# gate could not see at all: identical code with a quoted 'fx' failed.
_write_service '    public function scope(): string
    {
        $reg = $this->appConfig->getValueString(Application::APP_ID, '"'"'listing_register'"'"', '"'"''"'"');
        return $reg;
    }'
git -C "${_appC}" init -q . 2>/dev/null
_commit "${_appC}" init
_outC1="${_tmp}/c1.txt"
_run "${_appC}" "${_outC1}"
_expect "${_outC1}" 50 "FAIL" "sees an unguarded read whose app id is a class constant"

# C2 — the same leak with a quoted app id. Must still fail (no regression).
_write_service '    public function scope(): string
    {
        $reg = $this->appConfig->getValueString('"'"'fx'"'"', '"'"'listing_register'"'"', '"'"''"'"');
        return $reg;
    }'
_commit "${_appC}" "quoted app id"
_outC2="${_tmp}/c2.txt"
_run "${_appC}" "${_outC2}"
_expect "${_outC2}" 50 "FAIL" "still sees an unguarded read whose app id is a literal"

# C3 — ANTI-WIDENING. A correct COMPOUND guard. The pre-fix regex required a
# closing paren immediately after the empty string, so this shipped as two
# findings and zero defects — and the "fix" it suggested (split into two
# single-key ifs) changes nothing about the code.
_write_service '    public function scope(): array
    {
        $reg = $this->appConfig->getValueString('"'"'fx'"'"', '"'"'listing_register'"'"', '"'"''"'"');
        $sch = $this->appConfig->getValueString('"'"'fx'"'"', '"'"'listing_schema'"'"', '"'"''"'"');
        if ($reg === '"'"''"'"' || $sch === '"'"''"'"') {
            return [];
        }
        return [$reg, $sch];
    }'
_commit "${_appC}" "compound guard"
_outC3="${_tmp}/c3.txt"
_run "${_appC}" "${_outC3}"
_expect "${_outC3}" 50 "PASS" "accepts a correct compound empty-check guard"

# C4 — ANTI-WIDENING. A guard that is not an `if` at all. Verbatim shape from
# larpingapp's SetupController::isProvisioned(); it fails closed.
_write_service '    public function isProvisioned(): bool
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, '"'"'register'"'"', '"'"''"'"');
        $schemaMarker = $this->appConfig->getValueString(Application::APP_ID, '"'"'schema_marker'"'"', '"'"''"'"');

        return $registerId !== '"'"''"'"' && $schemaMarker !== '"'"''"'"';
    }'
_commit "${_appC}" "boolean-return guard"
_outC4="${_tmp}/c4.txt"
_run "${_appC}" "${_outC4}"
_expect "${_outC4}" 50 "PASS" "accepts a boolean-return empty-check guard"

# C5 — the opencatalogi#86 shape: one read guarded, the next one two lines
# later NOT. The gate must report the second and only the second.
_write_service '    public function scope(): array
    {
        $reg = $this->appConfig->getValueString('"'"'fx'"'"', '"'"'listing_register'"'"', '"'"''"'"');
        if ($reg === '"'"''"'"') {
            return [];
        }
        $sch = $this->appConfig->getValueString('"'"'fx'"'"', '"'"'listing_schema'"'"', '"'"''"'"');
        return [$reg, $sch];
    }'
_commit "${_appC}" "guarded read + leak"
_outC5="${_tmp}/c5.txt"
_run "${_appC}" "${_outC5}"
if grep -qE "^\[gate-50\][^:]*: FAIL — 1 unsafe" "${_outC5}"; then
    _ok "gate-50 reports exactly the unguarded read of the pair, not both"
else
    _bad "gate-50 on a guarded+unguarded pair → $(grep -E '^\[gate-50\]' "${_outC5}" | head -1)"
fi

# C6 — ANTI-WIDENING. A PHPCS-FORMATTED MULTI-LINE READ.
#
# Verbatim shape from procest lib/Service/AiService.php:580 and :967. Two
# five-line calls plus a blank line put the guard on the ELEVENTH line, one
# outside a window that counted from where the call BEGAN. The constant-app-id
# fix (C1) is what made these reads visible at all, so the window bug arrived
# with it: 3 findings on procest, all three textbook `empty()` guards.
_write_service '    public function writeAudit(): void
    {
        $registerId = $this->appConfig->getValueString(
            Application::APP_ID,
            '"'"'register'"'"',
            '"'"''"'"'
        );
        $schemaId   = $this->appConfig->getValueString(
            Application::APP_ID,
            '"'"'ai_audit_entry_schema'"'"',
            '"'"''"'"'
        );

        if (empty($registerId) === true || empty($schemaId) === true) {
            $this->logger->warning('"'"'AI audit: register or schema ID not configured'"'"');
            return;
        }
    }'
_commit "${_appC}" "multi-line reads with a guard below them"
_outC6="${_tmp}/c6.txt"
_run "${_appC}" "${_outC6}"
_expect "${_outC6}" 50 "PASS" "accepts a PHPCS-formatted multi-line read whose guard follows it"

# C7 — ANTI-WIDENING. The guard on the SAME LINE as the read (procest:710).
_write_service '    public function settings(): array
    {
        return [
            '"'"'ai_api_key_set'"'"' => $this->appConfig->getValueString(Application::APP_ID, '"'"'ai_api_key'"'"', '"'"''"'"') !== '"'"''"'"',
        ];
    }'
_commit "${_appC}" "same-line emptiness check"
_outC7="${_tmp}/c7.txt"
_run "${_appC}" "${_outC7}"
_expect "${_outC7}" 50 "PASS" "accepts an emptiness check written on the read's own line"

# C8 — the reverse control for C6/C7: the SAME multi-line shape with the guard
# DELETED must still fail. Without this, C6 and C7 could be satisfied by a
# window so wide the gate can no longer find anything.
_write_service '    public function writeAudit(): void
    {
        $registerId = $this->appConfig->getValueString(
            Application::APP_ID,
            '"'"'register'"'"',
            '"'"''"'"'
        );

        $this->objectService->saveObject($registerId, []);
    }'
_commit "${_appC}" "multi-line read with no guard at all"
_outC8="${_tmp}/c8.txt"
_run "${_appC}" "${_outC8}"
_expect "${_outC8}" 50 "FAIL" "still fails a multi-line read with no guard anywhere"

# C9 — A DELIBERATE, MEASURED BLIND SPOT, PINNED SO IT CANNOT DRIFT SILENTLY.
#
# The first draft of the app-id fix accepted ANY expression up to the comma,
# which also takes `$app` and `$this->appName`. A 12-repo before/after sweep
# priced that: softwarecatalog went 23 -> 64 findings and 47 of the new ones
# are entries in an array literal that assembles the admin settings payload —
#
#     'sendgridApiKey' => $this->config->getValueString($app, 'email_sendgrid_api_key', ''),
#
# There is no defense being deactivated in a settings read-out and nothing to
# guard, so the finding has no legitimate end state (#252). The accepted app-id
# shapes are therefore the ones actually measured as blind: a quoted literal
# and a class constant.
#
# This arm asserts the CURRENT boundary rather than an ideal one. If someone
# later teaches this gate to tell a scope decision from a read-out, this is the
# arm to change — deliberately, with the number in front of them.
_write_service '    public function readouts(): array
    {
        $app = '"'"'fx'"'"';
        return [
            '"'"'sendgridApiKey'"'"' => $this->config->getValueString($app, '"'"'email_sendgrid_api_key'"'"', '"'"''"'"'),
            '"'"'mailgunApiKey'"'"'  => $this->config->getValueString($this->appName, '"'"'email_mailgun_api_key'"'"', '"'"''"'"'),
        ];
    }'
_commit "${_appC}" "settings read-outs with a variable app id"
_outC9="${_tmp}/c9.txt"
_run "${_appC}" "${_outC9}"
_expect "${_outC9}" 50 "PASS" "does not report settings read-outs whose app id is a plain variable (measured blind spot, not a safe shape)"

# C10 — and the constant form of the SAME read is still caught, so C9 is a
# boundary and not a hole the gate fell through.
_write_service '    public function readouts(): array
    {
        return [
            '"'"'sendgridApiKey'"'"' => $this->config->getValueString(Application::APP_ID, '"'"'email_sendgrid_api_key'"'"', '"'"''"'"'),
        ];
    }'
_commit "${_appC}" "same read-out with a class-constant app id"
_outC10="${_tmp}/c10.txt"
_run "${_appC}" "${_outC10}"
_expect "${_outC10}" 50 "FAIL" "still catches the same read written with a class-constant app id"

# ===========================================================================
# FAMILY D — gate-53 must block the PR that CREATES larpingapp#286.
#
# `EventRoster` was registered in src/registry.js, resolvable, and named by no
# manifest position, so the event check-in surface had no entry point. When
# that defect was reintroduced exactly, gate-53 printed PASS. Direction 1 of
# the registry cross-reference stays advisory for LEGACY orphans — the gate
# cannot tell "wire it" from "delete it" — but when the DIFF ITSELF removed
# the last reference, it can, and that is the case worth blocking.
# ===========================================================================
_appD="${_tmp}/appD"
mkdir -p "${_appD}/src"
cat > "${_appD}/src/manifest.json" <<'JSON'
{
  "$schema": "https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json",
  "version": "0.1.0",
  "menu": [{ "id": "EventDetail", "label": "Events", "icon": "Calendar", "route": "EventDetail", "order": 10 }],
  "pages": [
    {
      "id": "EventDetail",
      "type": "detail",
      "route": "/events/:id",
      "title": "Event",
      "config": { "sidebar": { "tabs": [
        { "id": "checkin", "label": "Check-in", "icon": "AccountCheck", "component": "EventRoster" }
      ] } }
    }
  ]
}
JSON
cat > "${_appD}/src/registry.js" <<'JS'
import EventRoster from './views/EventRoster.vue'

export default {
	EventRoster: { kind: 'section', component: EventRoster },
}
JS
mkdir -p "${_appD}/src/views"
printf '<template><div /></template>\n' > "${_appD}/src/views/EventRoster.vue"
git -C "${_appD}" init -q .
_commit "${_appD}" init
_baseD="$(git -C "${_appD}" rev-parse HEAD)"

# D1 — remove the ONLY reference, keep the registry entry. This is #286.
python3 - "${_appD}/src/manifest.json" <<'PY'
import json, sys
p = sys.argv[1]
raw = open(p).read()
old = '        { "id": "checkin", "label": "Check-in", "icon": "AccountCheck", "component": "EventRoster" }\n'
assert old in raw, "PLANT ANCHOR MISSING — the fixture changed, fix the test not the anchor"
open(p, 'w').write(raw.replace(old, '', 1))
json.load(open(p))
PY
_commit "${_appD}" "drop the check-in tab"
_outD1="${_tmp}/d1.txt"
_run "${_appD}" "${_outD1}" --scope-to-diff --base "${_baseD}"
_expect "${_outD1}" 53 "FAIL" "blocks the PR that removes the last reference to a registered component"
if grep -q "EventRoster" "${_outD1}"; then
    _ok "gate-53 NAMES the orphaned component"
else
    _bad "gate-53 failed without naming EventRoster"
fi

# D2 — ANTI-WIDENING. Removing BOTH sides is a legitimate retirement.
git -C "${_appD}" checkout -q -B d2 "${_baseD}"
python3 - "${_appD}/src/manifest.json" "${_appD}/src/registry.js" <<'PY'
import json, sys
m, r = sys.argv[1], sys.argv[2]
raw = open(m).read()
old = '        { "id": "checkin", "label": "Check-in", "icon": "AccountCheck", "component": "EventRoster" }\n'
assert old in raw, "PLANT ANCHOR MISSING (manifest)"
open(m, 'w').write(raw.replace(old, '', 1))
json.load(open(m))
js = open(r).read()
oldj = "\tEventRoster: { kind: 'section', component: EventRoster },\n"
assert oldj in js, "PLANT ANCHOR MISSING (registry)"
open(r, 'w').write(js.replace(oldj, '', 1))
PY
_commit "${_appD}" "retire the check-in surface entirely"
_outD2="${_tmp}/d2.txt"
_run "${_appD}" "${_outD2}" --scope-to-diff --base "${_baseD}"
_expect "${_outD2}" 53 "PASS" "accepts removing the component and its registry entry together"

# D3 — ANTI-WIDENING. A pre-existing orphan is still advisory, not blocking.
# Without this arm the fix would be indistinguishable from promoting
# direction 1 wholesale, which would light up every app carrying legacy debt.
_appD3="${_tmp}/appD3"
mkdir -p "${_appD3}/src/views"
cat > "${_appD3}/src/manifest.json" <<'JSON'
{ "$schema": "https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json", "version": "0.1.0", "menu": [], "pages": [] }
JSON
cat > "${_appD3}/src/registry.js" <<'JS'
import Orphan from './views/Orphan.vue'

export default {
	Orphan: { kind: 'section', component: Orphan },
}
JS
printf '<template><div /></template>\n' > "${_appD3}/src/views/Orphan.vue"
git -C "${_appD3}" init -q .
_commit "${_appD3}" init
_outD3="${_tmp}/d3.txt"
_run "${_appD3}" "${_outD3}"
_expect "${_outD3}" 53 "PASS" "leaves a PRE-EXISTING orphan advisory (WARN), not blocking"
if grep -qE '^\[gate-53\].*WARN finding' "${_outD3}"; then
    _ok "gate-53 still SURFACES the pre-existing orphan as a WARN"
else
    _bad "gate-53 swallowed the pre-existing orphan entirely"
fi

# ===========================================================================
# FAMILY E — gate-52: a crashed helper is WIRING, never a finding.
#
# The runner read `_cwr_fail=$?` straight off the helper. An exit status is one
# byte and it is also how Python reports a traceback, so a dead checker
# reported `FAIL — 1 custom-widget finding(s)` — an actionable-looking finding
# with nothing behind it, pointing at a widget that does not exist. Same
# lossy-channel shape as #209, where 266 findings were reported as 10.
#
# Driven by copying the package and injecting a `raise` into the helper's
# main(), then pointing the runner-under-test at the copy. The copy is why
# this arm can exist at all: the shipped helper must not be edited to test it.
# ===========================================================================
_pkg="${_tmp}/pkg"
mkdir -p "${_pkg}"
cp -R "${_scripts}" "${_pkg}/scripts"
_broken="${_pkg}/scripts/lib/check_custom_widget_ratchet.py"
python3 - "${_broken}" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
old = "def main(argv):"
assert old in s, "MUTATION ANCHOR MISSING — check_custom_widget_ratchet.py no longer defines main(argv)"
s = s.replace(old, 'def main(argv):\n    raise RuntimeError("simulated helper crash")\n\n\ndef _unreachable_main(argv):', 1)
open(p, "w").write(s)
PY

_appE="${_tmp}/appE"
mkdir -p "${_appE}/src"
printf "export default {\n\tThing: { kind: 'widget', component: 1 },\n}\n" > "${_appE}/src/registry.js"
git -C "${_appE}" init -q .
_commit "${_appE}" init

_outE="${_tmp}/e.txt"
_saved_runner="${_runner}"
_runner="${_pkg}/scripts/run-hydra-gates.sh"
_run "${_appE}" "${_outE}"
_runner="${_saved_runner}"

_expect "${_outE}" 52 "SKIPPED (wiring)" "reports a crashed helper as wiring, not as a finding"
if grep -qE '^\[gate-52\][^:]*: FAIL' "${_outE}"; then
    _bad "gate-52 turned a helper crash into a FAIL with a fabricated finding count"
else
    _ok "gate-52 invents no finding count when the helper did not finish"
fi

# ANTI-WIDENING for family E: the SAME fixture with the real helper must still
# catch its planted true positive (a kind:"widget" entry with no _note).
_outE2="${_tmp}/e2.txt"
_run "${_appE}" "${_outE2}"
_expect "${_outE2}" 52 "FAIL" "still catches a kind:\"widget\" entry with no _note"

# ===========================================================================
# FAMILY F — A CRASHED INTERPRETER MUST NOT PRODUCE A VERDICT.
#
# A planted true positive only fires WHEN THE GATE RUNS, so no arm above can
# see this. Measured 2026-08-08 on a tree carrying real findings, with a
# `python3` on PATH that exits 1 on every call: EIGHT of these eleven gates
# printed PASS. The worst was gate-46, which reported PASS over the 277
# unresolved @spec findings — 104 distinct targets — it had reported one run
# earlier on the same files. Gates 45/49/50 discarded the status with
# `2>/dev/null`; gates 47/51/54/55 with `|| true`; gate-52 read the count off
# the exit byte, so a traceback became `FAIL — 1 custom-widget finding(s)`.
#
# `_a` is a fake `python3` earlier on PATH. Both directions are asserted: the
# same fixture with a working interpreter must produce real verdicts, or this
# family would be satisfied by a runner that skipped everything always.
# ===========================================================================
_appF="${_tmp}/appF"
mkdir -p "${_appF}/src/manifest.d" "${_appF}/lib/Controller" "${_appF}/lib/Service" \
         "${_appF}/lib/Settings" "${_appF}/templates"
cat > "${_appF}/src/manifest.json" <<'JSON'
{ "$schema": "https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json",
  "version": "0.1.0", "menu": [], "pages": [] }
JSON
printf "export default {}\n" > "${_appF}/src/registry.js"
printf '<template><div /></template>\n<style scoped>\n.x { transition: all .3s; }\n</style>\n' \
    > "${_appF}/src/Thing.vue"
cat > "${_appF}/lib/Controller/ThingController.php" <<'PHP'
<?php
namespace OCA\Fx\Controller;
class ThingController {
    /** @spec openspec/specs/no-such-thing/spec.md#requirement-nope */
    public function destroy(string $id) {
        $this->objectService->deleteObject($id);
        return 1;
    }
}
PHP
cat > "${_appF}/lib/Service/ListingService.php" <<'PHP'
<?php
namespace OCA\Fx\Service;
class ListingService {
    public function scope(): string
    {
        return $this->appConfig->getValueString('fx', 'listing_register', '');
    }
}
PHP
cat > "${_appF}/lib/Settings/fx_register.json" <<'JSON'
{ "components": { "schemas": { "thing": { "properties": {
  "bare": { "type": "string" },
  "rel": { "type": "string", "format": "uuid", "title": "Rel", "description": "Reference to the related thing object" }
} } } } }
JSON
git -C "${_appF}" init -q .
_commit "${_appF}" init

# Working interpreter: these gates must produce REAL verdicts on this tree.
_outF="${_tmp}/f.txt"
_run "${_appF}" "${_outF}"
_real=0
for _g in 45 46 49 50 51 54; do
    grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_outF}" && _real=$((_real + 1))
done
if [ "${_real}" -ge 5 ]; then
    _ok "with a working interpreter the fixture yields ${_real} real FAIL verdicts (the thing a crash must not erase)"
else
    _bad "fixture produced only ${_real} FAIL verdicts — family F would prove nothing"
fi

# Broken interpreter: every one of them must say WIRING, and none may PASS.
_fakebin="${_tmp}/fakebin"
mkdir -p "${_fakebin}"
printf '#!/bin/sh\necho "python3: simulated interpreter failure" >&2\nexit 1\n' > "${_fakebin}/python3"
chmod +x "${_fakebin}/python3"
_outF2="${_tmp}/f2.txt"
(
    cd "${_appF}" || exit 1
    _l="${_tmp}/logs.crash"; mkdir -p "${_l}"
    PATH="${_fakebin}:${PATH}" HYDRA_GATE_LOG_DIR="${_l}" bash "${_runner}" . > "${_outF2}" 2>&1
)
_green_over_crash=""
for _g in 45 46 47 48 49 50 51 52 54 55; do
    if grep -qE "^\[gate-${_g}\][^:]*: (PASS|FAIL)" "${_outF2}"; then
        _green_over_crash="${_green_over_crash} ${_g}"
    fi
done
if [ -z "${_green_over_crash}" ]; then
    _ok "with python3 exiting 1, no gate in the band produced a verdict — all reported wiring or na"
else
    _bad "gate(s)${_green_over_crash} produced a PASS/FAIL verdict although their checker never ran"
fi
if grep -qE "^\[gate-46\][^:]*: SKIPPED \(wiring\)" "${_outF2}"; then
    _ok "gate-46 says SKIPPED (wiring) rather than PASS over findings it cannot see"
else
    _bad "gate-46 on a dead interpreter → $(grep -E '^\[gate-46\]' "${_outF2}" | head -1)"
fi

echo ""
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_45_to_55_acceptance.sh: ALL GREEN"
    exit 0
fi
echo "test_gate_45_to_55_acceptance.sh: ${_failures} failure(s)"
exit 1
