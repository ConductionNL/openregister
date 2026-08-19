#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_1_11_honest_verdicts.sh — gates 1–11 must not report PASS over a
# scope they never opened, and must still catch a planted defect when they do.
#
# WHAT THIS GUARDS (measured 2026-08-08, gate package cdfbd7a)
# ------------------------------------------------------------
# The sibling suite test_gate_empty_scope_never_passes.sh pinned this behaviour
# for gates 19/25/62/63. The SAME hole was open across the whole 1–11 band and
# nothing was watching it. Run against larpingapp with `--scope-to-diff` over a
# README-only commit, the runner printed:
#
#     [gate-1]  spdx-headers:          PASS
#     [gate-2]  forbidden-patterns:    PASS
#     [gate-3]  stub-scan:             PASS
#     [gate-5]  route-auth:            PASS      <- a SECURITY gate
#     [gate-8]  unsafe-auth-resolver:  PASS      <- a SECURITY gate
#     [gate-9]  semantic-auth:         PASS      <- a SECURITY gate
#     [gate-10] initial-state:         PASS
#     [gate-11] admin-router:          PASS      <- a SECURITY gate
#
# Eight greens over zero bytes, four of them on authorization surfaces. Gates
# 4, 6 and 7 already said NOT APPLICABLE for the identical situation, which is
# what made the other eight readable as a real result rather than an absence.
#
# `na`, not `structural`: per #268 an empty ADR-020 diff scope is subject matter
# absent from THIS DIFF, and no change the author could make would put a PHP
# file into a diff that touches none. Categorising it `structural` would exit 98
# on PRs that have nothing to judge.
#
# ARM 2 is the control that keeps ARM 1 honest. A gate can always be made to say
# `na` by never looking at anything; these gates must still FAIL a planted true
# positive when the file IS in scope. Without that arm, "reports na" is
# satisfiable by a gate that has been skipped into uselessness.
#
# Run: bash scripts/lib/test_gate_1_11_honest_verdicts.sh   (exit 0 = green)

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_1_11_honest_verdicts.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-1-11-scope.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_app="${_tmp}/app"
mkdir -p "${_app}/src" "${_app}/appinfo" "${_app}/lib/Controller" "${_app}/lib/Service"
printf '{"name":"fx","menu":[],"pages":[]}\n' > "${_app}/src/manifest.json"
printf "import { createRouter } from 'vue-router'\nconst router = createRouter({ routes: [] })\nexport default router\n" \
    > "${_app}/src/main.js"
printf "<?php\nreturn ['routes'=>[['name'=>'thing#index','url'=>'/api/thing','verb'=>'GET']]];\n" \
    > "${_app}/appinfo/routes.php"

# A CLEAN controller: correct header, declared auth posture, guarded body.
cat > "${_app}/lib/Controller/ThingController.php" <<'PHP'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\Controller;

class ThingController
{
    /**
     * Read one thing.
     *
     * @NoAdminRequired
     */
    public function index(string $id)
    {
        if ($this->owns($id) === false) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse([]);

    }//end index()
}//end class
PHP

(
    cd "${_app}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm init
    # A docs-only second commit — the ordinary shape of a PR that touches no
    # PHP and no frontend. This is the EMPTY SCOPE case.
    printf 'docs only\n' > README.md
    git add README.md
    git -c user.email=t@t -c user.name=t commit -qm docs
) >/dev/null 2>&1

_run() {  # _run <outfile> [runner args...]
    local out="$1"; shift
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "${_app}" || exit 1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" "$@" . > "${out}" 2>&1
    )
    return $?
}

_verdict() { grep -oE "^\[gate-$2\] [^:]+: [A-Z]+( [A-Z]+)*( \([a-z]+\))?" "$1" | head -1 | sed 's/^[^:]*: //'; }

# ---------------------------------------------------------------------------
# ARM 1 — an empty diff scope is NOT APPLICABLE for every gate in the band.
# ---------------------------------------------------------------------------
echo "  -- ARM 1: empty diff scope"
_scoped="${_tmp}/scoped.txt"
_run "${_scoped}" --scope-to-diff --base HEAD~1
for _g in 1 2 3 4 5 6 7 8 9 10 11; do
    _v="$(_verdict "${_scoped}" "${_g}")"
    case "${_v}" in
        "NOT APPLICABLE")
            _ok "gate-${_g} reports NOT APPLICABLE over an empty diff scope" ;;
        PASS)
            _bad "gate-${_g} reported PASS over a scope it never opened" ;;
        "")
            _bad "gate-${_g} emitted NO verdict line at all over an empty diff scope" ;;
        *)
            _bad "gate-${_g} empty-scope verdict is '${_v}' — expected NOT APPLICABLE" ;;
    esac
done

# Every NOT APPLICABLE must carry a reason. A bare category is a skip nobody
# can audit.
for _g in 1 2 3 5 8 9 10 11; do
    if grep -qE "^\[gate-${_g}\][^:]*: NOT APPLICABLE — .{40,}" "${_scoped}"; then
        _ok "gate-${_g} states WHY it was not applicable"
    else
        _bad "gate-${_g}'s NOT APPLICABLE line carries no substantive reason"
    fi
done

# ---------------------------------------------------------------------------
# ARM 2 — THE CONTROL. Saying `na` is free; catching the defect is not.
# Each gate gets one textbook true positive, full-tree, and must FAIL on it.
# ---------------------------------------------------------------------------
echo "  -- ARM 2: planted true positives, full tree"

_full="${_tmp}/full-clean.txt"
_run "${_full}"
for _g in 1 2 3 5 8 9 10 11; do
    _v="$(_verdict "${_full}" "${_g}")"
    if [ "${_v}" = "PASS" ]; then
        _ok "gate-${_g} PASSes the clean fixture (anti-widening control)"
    else
        _bad "gate-${_g} returned '${_v}' on a CLEAN fixture — expected PASS"
    fi
done

# Gates enumerate their surface with `git ls-files` (_enum_tracked), so an
# UNTRACKED plant is invisible and the gate reports PASS — a green that says
# nothing about the gate. Stage every plant before judging it.
_plant_and_check() {  # <gate> <label> <file>
    local _g="$1" _label="$2" _file="$3"
    local _out="${_tmp}/plant-${_g}.txt"
    ( cd "${_app}" && git add -A >/dev/null 2>&1 )
    if ! ( cd "${_app}" && git ls-files --error-unmatch "${_file#"${_app}"/}" >/dev/null 2>&1 ); then
        _bad "gate-${_g}: the ${_label} plant was NOT staged — the assertion below would prove nothing"
        return
    fi
    _run "${_out}"
    local _v; _v="$(_verdict "${_out}" "${_g}")"
    if [ "${_v}" = "FAIL" ]; then
        _ok "gate-${_g} FAILs its planted true positive (${_label})"
    else
        _bad "gate-${_g} returned '${_v}' for a planted ${_label} — expected FAIL"
    fi
    rm -f "${_file}"
    _restore
}

# Restore the fixture to its COMMITTED state. `git checkout -- .` restores from
# the INDEX, and the plants were staged, so it would hand the plant straight
# back — which is how gate-11 reported residue that did not exist. Each planted
# path is removed by name (never `git clean`, which would take the fixture's
# untracked work with it).
_restore() {
    ( cd "${_app}" \
        && git reset -q HEAD -- . >/dev/null 2>&1 \
        && git checkout -q HEAD -- . >/dev/null 2>&1 )
}

# gate-1: a lib/ PHP file with no @license / @copyright.
printf '<?php\nnamespace OCA\\Fx\\Service;\nclass NoHeader { public function a() { return 1; } }\n' \
    > "${_app}/lib/Service/NoHeader.php"
_plant_and_check 1 "PHP file with no SPDX header" "${_app}/lib/Service/NoHeader.php"

# gate-2: a debug helper that the OLD grep could not see.
cat > "${_app}/lib/Service/Dbg.php" <<'PHP'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\Service;

class Dbg
{
    public function a(): void
    {
        var_dump ($x);

    }//end a()
}//end class
PHP
_plant_and_check 2 "var_dump WITH A SPACE — invisible to the old grep" "${_app}/lib/Service/Dbg.php"

# gate-3: a service method that accepts a caller identity and ignores it.
cat > "${_app}/lib/Service/Stub.php" <<'PHP'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\Service;

class Stub
{
    public function authorizeRead(string $uid, string $objectId): bool
    {
        $result = true;
        $note = 'not wired yet';
        return $result;

    }//end authorizeRead()
}//end class
PHP
_plant_and_check 3 "caller-identity parameter ignored" "${_app}/lib/Service/Stub.php"

# gate-8: the decidesk#45 fail-open, TAB indented (the shape the old awk
# mis-extracted in both directions).
# shellcheck disable=SC2016  # `$this->` is PHP source, not a shell expansion.
printf '<?php\n\n/**\n * @copyright 2026 Conduction B.V.\n * @license   EUPL-1.2 x\n */\n\nnamespace OCA\\Fx\\Service;\n\nclass Res\n{\n\tpublic function getAuthorizationService(): ?object\n\t{\n\t\ttry {\n\t\t\treturn $this->c->get("A");\n\t\t} catch (\\Throwable $e) {\n\t\t\treturn null;\n\t\t}\n\t}\n}\n' \
    > "${_app}/lib/Service/Res.php"
_plant_and_check 8 "tab-indented catch(Throwable){return null}" "${_app}/lib/Service/Res.php"

# gate-10: the TWO-STEP DOM read — the form the old single-line grep missed.
printf "const el = document.getElementById('fx-settings')\nexport const v = el.dataset.version\n" \
    > "${_app}/src/probe.js"
_plant_and_check 10 "two-step getElementById -> .dataset read" "${_app}/src/probe.js"

# gate-11: the doriath c7c72e9 defect, in the router the app ACTUALLY uses.
printf "import { createRouter } from 'vue-router'\nimport AdminRoot from './views/AdminRoot.vue'\nconst routes = []\nroutes.push({ path: '/settings', component: AdminRoot })\nconst router = createRouter({ routes })\nexport default router\n" \
    > "${_app}/src/main.js"
( cd "${_app}" && git add -A >/dev/null 2>&1 )
_out="${_tmp}/plant-11.txt"
_run "${_out}"
_v="$(_verdict "${_out}" 11)"
if [ "${_v}" = "FAIL" ]; then
    _ok "gate-11 FAILs its planted true positive (doriath /settings -> AdminRoot in src/main.js)"
else
    _bad "gate-11 returned '${_v}' for the doriath defect in src/main.js — expected FAIL. This is the DEAD-GATE case: fourteen of fifteen fleet apps build their router there."
fi
_restore

# ---------------------------------------------------------------------------
# ARM 3 — removing the plant restores the prior verdict (no residue).
# ---------------------------------------------------------------------------
echo "  -- ARM 3: no residue after the plants are removed"
_after="${_tmp}/after.txt"
_run "${_after}"
for _g in 1 2 3 5 8 9 10 11; do
    _v="$(_verdict "${_after}" "${_g}")"
    if [ "${_v}" = "PASS" ]; then
        _ok "gate-${_g} returned to PASS after its plant was removed"
    else
        _bad "gate-${_g} is '${_v}' after the plants were removed — residue"
    fi
done

# ---------------------------------------------------------------------------
# ARM 4 — AN ATTRIBUTE-ONLY CHANGE IS A CHANGE.
#
# `_filter_preexisting` compares a method against the base ref and moves
# unchanged entries into `<log>.preexisting`, i.e. OUT of the verdict. In this
# band gates 6, 7 and 8 route through it. Until #276 the comparison began at the
# `function NAME(` line, so ADDING `#[NoAdminRequired]` above an existing
# unguarded method left the body byte-identical and the finding was suppressed.
#
# That is a security-gate bypass by construction: the single edit that changes a
# method's auth posture is the one the filter could not see. Measured on this
# exact fixture — package cdfbd7a gave `[gate-7] no-admin-idor: PASS` with the
# finding sitting in `hydra-gate-no-admin-idor.log.preexisting`; package
# 34370f6 (#276, `_annotation_prefix`) gives FAIL.
#
# #276 repaired the shared helper. This arm keeps it repaired FOR GATE-7, where
# it costs the most: gate-7's entire scope is decided by that attribute, so a
# filter blind to it is blind to every endpoint the PR newly exposed.
# ---------------------------------------------------------------------------
echo "  -- ARM 4: an attribute-only change must not be filtered as pre-existing"
_att="${_tmp}/attr"
mkdir -p "${_att}/lib/Controller"
cat > "${_att}/lib/Controller/WidgetController.php" <<'PHPEOF'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\Controller;

class WidgetController
{
    /**
     * Fetch a widget.
     */
    public function fetch(string $id): JSONResponse
    {
        $obj = $this->objectService->find(id: $id);
        return new JSONResponse(data: $obj);

    }//end fetch()
}//end class
PHPEOF
(
    cd "${_att}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm base
) >/dev/null 2>&1
_att_base="$(git -C "${_att}" rev-parse HEAD)"

# ASSERT THE MUTATION APPLIES BEFORE TRUSTING ANYTHING IT PROVES. A mutation
# that silently did not apply looks exactly like a gate that correctly found
# nothing.
# shellcheck disable=SC2016  # `$id` is a PHP variable in the fixture, not a shell expansion.
if grep -q '^    public function fetch(string \$id): JSONResponse$' "${_att}/lib/Controller/WidgetController.php"; then
    # shellcheck disable=SC2016  # `$id` is a PHP variable in the fixture, not a shell expansion.
    sed -i 's|^    public function fetch(string \$id): JSONResponse$|    #[NoAdminRequired]\n    public function fetch(string $id): JSONResponse|' \
        "${_att}/lib/Controller/WidgetController.php"
    ( cd "${_att}" && git add -A && git -c user.email=t@t -c user.name=t commit -qm expose ) >/dev/null 2>&1

    _changed="$(git -C "${_att}" diff --numstat HEAD~1 HEAD | awk '{print $1"/"$2}')"
    if [ "${_changed}" = "1/0" ]; then
        _ok "ARM 4: the diff is one ADDED line and zero removed (the body is byte-identical)"
    else
        _bad "ARM 4: expected a 1-insertion/0-deletion diff, got '${_changed}' — the fixture is not testing what it claims"
    fi

    _attout="${_tmp}/attr.txt"
    _attlogs="${_tmp}/attrlogs"
    mkdir -p "${_attlogs}"
    (
        cd "${_att}" || exit 1
        HYDRA_GATE_LOG_DIR="${_attlogs}" bash "${_runner}" \
            --scope-to-diff --base "${_att_base}" . > "${_attout}" 2>&1
    )
    _v="$(_verdict "${_attout}" 7)"
    if [ "${_v}" = "FAIL" ]; then
        _ok "gate-7 FAILs an attribute-only change that exposes an unguarded method"
    else
        _bad "gate-7 returned '${_v}' for an attribute-only exposure — expected FAIL. If the finding sits in hydra-gate-no-admin-idor.log.preexisting, _filter_preexisting is comparing bodies without their annotation head again (#276)."
    fi
    if [ -s "${_attlogs}/hydra-gate-no-admin-idor.log.preexisting" ]; then
        _bad "gate-7's finding was moved to .preexisting — the attribute-only bypass is back"
    else
        _ok "gate-7's finding was NOT filtered away as pre-existing"
    fi
else
    _bad "ARM 4: the fixture signature was not found — the mutation could not be applied, so nothing here would prove anything"
fi

# ---------------------------------------------------------------------------
# ARM 5 — A BROKEN INTERPRETER IS `SKIPPED (wiring)`, NEVER `PASS`.
#
# A planted true positive cannot reveal this: a plant only fires when the gate
# RUNS. A gate that reports PASS because its checker crashed is the most
# expensive shape in the package — the verdict is green, the log is empty, and
# nothing on stdout says the check did not happen. gate-40 was caught printing
# PASS over the 13 real findings it had reported one run earlier (#272).
#
# The control below is what makes this arm mean something: on this same fixture
# gate-3 FAILS with a real finding when python3 works, so `SKIPPED (wiring)`
# here is the gate losing a verdict it demonstrably HAD.
# ---------------------------------------------------------------------------
echo "  -- ARM 5: a broken interpreter is SKIPPED (wiring), never PASS"
_brk="${_tmp}/brk"
mkdir -p "${_brk}/bin" "${_brk}/app/lib/BackgroundJob" "${_brk}/app/src" "${_brk}/app/appinfo"
printf '#!/bin/sh\nexit 1\n' > "${_brk}/bin/python3"
chmod +x "${_brk}/bin/python3"
cat > "${_brk}/app/lib/BackgroundJob/J.php" <<'PHPEOF'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\BackgroundJob;

class J extends TimedJob
{
    protected function run($argument): void
    {
    }
}
PHPEOF
printf '{"name":"fx","menu":[],"pages":[]}\n' > "${_brk}/app/src/manifest.json"
printf "import { createRouter } from 'vue-router'\nconst r = createRouter({ routes: [] })\n" \
    > "${_brk}/app/src/main.js"
printf '<?php\nreturn ["routes"=>[]];\n' > "${_brk}/app/appinfo/routes.php"
(
    cd "${_brk}/app" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm base
) >/dev/null 2>&1

# The runner REFUSES to run when HYDRA_GATE_LOG_DIR does not already exist
# (exit 97) — shared log paths make verdicts non-deterministic. Create both
# directories up front, or every verdict below reads as the empty string.
mkdir -p "${_tmp}/brklogs1" "${_tmp}/brklogs2"
_ctl="${_tmp}/brk-control.txt"
(
    cd "${_brk}/app" || exit 1
    HYDRA_GATE_LOG_DIR="${_tmp}/brklogs1" bash "${_runner}" . > "${_ctl}" 2>&1
)
if [ "$(_verdict "${_ctl}" 3)" = "FAIL" ]; then
    _ok "ARM 5 control: gate-3 FAILS the empty run() body when python3 works"
else
    _bad "ARM 5 control: gate-3 did not FAIL the stub run() with a working python3 — got '$(_verdict "${_ctl}" 3)'. The arm below would prove nothing."
fi

_brkout="${_tmp}/brk.txt"
(
    cd "${_brk}/app" || exit 1
    PATH="${_brk}/bin:${PATH}" HYDRA_GATE_LOG_DIR="${_tmp}/brklogs2" \
        bash "${_runner}" . > "${_brkout}" 2>&1
)
for _g in 2 3 10 11; do
    _v="$(_verdict "${_brkout}" "${_g}")"
    case "${_v}" in
        "SKIPPED (wiring)")
            _ok "gate-${_g} reports SKIPPED (wiring) when its helper cannot run" ;;
        PASS)
            _bad "gate-${_g} reported PASS with a BROKEN interpreter — a crashed checker is not a clean tree" ;;
        "")
            _bad "gate-${_g} emitted NO verdict line at all with a broken interpreter — the run itself failed, so this arm tested nothing" ;;
        *)
            _bad "gate-${_g} returned '${_v}' with a broken interpreter — expected SKIPPED (wiring)" ;;
    esac
done

# ---------------------------------------------------------------------------
# ARM 6 — A GATE MUST NOT DECLARE `na` OVER A REPO FULL OF ITS OWN SUBJECT.
#
# `na` REMOVES A GATE FROM COVERAGE ACCOUNTING, so a wrong `na` leaves the
# denominator silently. gate-10 enumerated `src/` only, and nldesign's `src/`
# holds exactly one file — `manifest.json`. Its entire hand-written frontend
# lives in `js/`, so gate-10 announced
#
#     [gate-10] initial-state: NOT APPLICABLE — ... this repo ships no frontend
#
# over a repo whose `js/admin.js` does precisely what the gate exists to catch:
#
#     var settingsEl = document.getElementById('nldesign-settings');
#     var tokenSets  = JSON.parse(settingsEl.getAttribute('data-token-sets'));
#
# — the doriath AdminRoot defect, two-step form, in an ADMIN settings script.
# Four real findings, invisible behind a verdict that said the subject did not
# exist. This fixture is that shape: `src/` with no frontend, `js/` with the
# defect. `*.min.js` stays out — a committed bundle is not authored code.
# ---------------------------------------------------------------------------
echo "  -- ARM 6: the nldesign shape — frontend in js/, not src/"
_nld="${_tmp}/nld"
mkdir -p "${_nld}/js" "${_nld}/src" "${_nld}/lib"
printf '{"name":"nld","menu":[],"pages":[]}\n' > "${_nld}/src/manifest.json"
cat > "${_nld}/js/admin.js" <<'JSEOF'
(function () {
	var settingsEl = document.getElementById('nld-settings');
	if (!settingsEl) { return }
	var tokenSets = JSON.parse(settingsEl.getAttribute('data-token-sets') || '[]');
	console.log(tokenSets);
})();
JSEOF
printf 'var a=1;var b=document.getElementById("x").dataset.v;\n' > "${_nld}/js/vendor.min.js"
(
    cd "${_nld}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm base
) >/dev/null 2>&1
_nldout="${_tmp}/nld.txt"
mkdir -p "${_tmp}/nldlogs"
(
    cd "${_nld}" || exit 1
    HYDRA_GATE_LOG_DIR="${_tmp}/nldlogs" bash "${_runner}" . > "${_nldout}" 2>&1
)
_v="$(_verdict "${_nldout}" 10)"
case "${_v}" in
    FAIL)
        _ok "gate-10 FAILs the js/-only frontend (it did not declare itself away)" ;;
    "NOT APPLICABLE")
        _bad "gate-10 declared NOT APPLICABLE over a repo whose js/ carries its defect — this is the na blackout: the gate left the coverage denominator while its subject was present" ;;
    *)
        _bad "gate-10 returned '${_v}' for a js/-only frontend carrying a real finding — expected FAIL" ;;
esac
if grep -q 'js/admin.js' "${_tmp}/nldlogs/hydra-gate-initial-state.log" 2>/dev/null; then
    _ok "gate-10 NAMES js/admin.js"
else
    _bad "gate-10 did not name js/admin.js in its findings log"
fi
if grep -q 'vendor.min.js' "${_tmp}/nldlogs/hydra-gate-initial-state.log" 2>/dev/null; then
    _bad "gate-10 judged a MINIFIED bundle — committed build output is not authored code"
else
    _ok "gate-10 leaves *.min.js alone (anti-widening)"
fi

# ---------------------------------------------------------------------------
# ARM 7 — A DERIVED CONTROLLER PATH IS A GUESS, AND BOTH ROOTS MUST BE TRIED.
#
# Nextcloud resolves a route name `A\B\C` against `OCA\<App>\A\B\C`, which PSR-4
# maps to `lib/A/B/CController.php`. The resolver rooted every namespaced name
# at `lib/Controller/`, which is right for `Settings\FileSettings` and WRONG for
# `AppHost\Controller\GenericHealth`.
#
# Measured on openregister — the repository that SHIPS
# `lib/AppHost/Controller/GenericHealthController.php`. The derived path did not
# exist, `_apphost_serves` then matched the name, and gate-5 filed the entry as
# "its auth attribute lives in the openregister package and is NOT visible from
# this repository" — inside openregister. The gate punted to another package
# while standing in it, so two routed methods were judged by NOBODY.
#
# The second fixture is the control: on an app that CONSUMES the AppHost
# generics the file really is absent, and the ADR-040 classification must stay.
# ---------------------------------------------------------------------------
echo "  -- ARM 7: namespaced route names resolve under lib/ as well as lib/Controller/"
_ns="${_tmp}/nsroot"
mkdir -p "${_ns}/lib/AppHost/Controller" "${_ns}/lib/Controller/Settings" "${_ns}/appinfo" "${_ns}/lib/AppInfo"
cat > "${_ns}/appinfo/routes.php" <<'PHPEOF'
<?php

return [
	'routes' => [
		['name' => 'AppHost\Controller\GenericHealth#index', 'url' => '/health', 'verb' => 'GET'],
		['name' => 'Settings\FileSettings#stats', 'url' => '/api/file-stats', 'verb' => 'GET'],
	],
];
PHPEOF
cat > "${_ns}/lib/AppHost/Controller/GenericHealthController.php" <<'PHPEOF'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\AppHost\Controller;

class GenericHealthController
{
    /**
     * Health probe. NOTE: no auth attribute — this is the planted defect.
     *
     * @return JSONResponse Status.
     */
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);

    }//end index()
}//end class
PHPEOF
cat > "${_ns}/lib/Controller/Settings/FileSettingsController.php" <<'PHPEOF'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\Controller\Settings;

class FileSettingsController
{
    /**
     * Extraction stats.
     *
     * @return JSONResponse Stats.
     *
     * @NoAdminRequired
     */
    public function stats(): JSONResponse
    {
        return new JSONResponse([]);

    }//end stats()
}//end class
PHPEOF
(
    cd "${_ns}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm base
) >/dev/null 2>&1
_nsout="${_tmp}/nsroot.txt"
mkdir -p "${_tmp}/nslogs"
(
    cd "${_ns}" || exit 1
    HYDRA_GATE_LOG_DIR="${_tmp}/nslogs" bash "${_runner}" . > "${_nsout}" 2>&1
)
if grep -q 'GenericHealthController.php' "${_tmp}/nslogs/hydra-gate-route-auth.log" 2>/dev/null; then
    _ok "gate-5 RESOLVED lib/AppHost/Controller/GenericHealthController.php and judged it"
else
    _bad "gate-5 did not judge lib/AppHost/Controller/GenericHealthController.php — a file this fixture SHIPS. If it is in hydra-gate-route-auth-unresolved.log, the resolver is rooting every namespaced name at lib/Controller/ again."
fi
if grep -q 'GenericHealth' "${_tmp}/nslogs/hydra-gate-route-auth-unresolved.log" 2>/dev/null; then
    _bad "gate-5 called GenericHealthController 'not present in this repository' inside the repository containing it"
else
    _ok "gate-5 makes no false-absence claim about a file the repo ships"
fi
if grep -q 'FileSettingsController' "${_tmp}/nslogs/hydra-gate-route-auth.log" 2>/dev/null; then
    _bad "gate-5 reported the correctly-annotated Settings\\FileSettings#stats — the lib/Controller/ root regressed"
else
    _ok "gate-5 still resolves Settings\\FileSettings under lib/Controller/ (both roots work)"
fi

# The control: when the class genuinely is NOT in this repo, ADR-040 classification stands.
_ah="${_tmp}/apphost-consumer"
mkdir -p "${_ah}/appinfo" "${_ah}/lib/AppInfo" "${_ah}/lib/Controller"
cat > "${_ah}/appinfo/routes.php" <<'PHPEOF'
<?php

return [
	'routes' => [
		['name' => 'health#index', 'url' => '/health', 'verb' => 'GET'],
	],
];
PHPEOF
cat > "${_ah}/lib/AppInfo/Application.php" <<'PHPEOF'
<?php

/**
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\Fx\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;

class Application
{
    public function register($context): void
    {
        Bootstrap::register($context, appId: 'fx');

    }//end register()
}//end class
PHPEOF
(
    cd "${_ah}" || exit 1
    git init -q .
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm base
) >/dev/null 2>&1
_ahout="${_tmp}/apphost.txt"
mkdir -p "${_tmp}/ahlogs"
(
    cd "${_ah}" || exit 1
    HYDRA_GATE_LOG_DIR="${_tmp}/ahlogs" bash "${_runner}" . > "${_ahout}" 2>&1
)
if grep -q 'ADR-040' "${_tmp}/ahlogs/hydra-gate-route-auth-unresolved.log" 2>/dev/null; then
    _ok "ARM 7 control: an AppHost CONSUMER still classifies the generic as ADR-040-unresolved"
else
    _bad "ARM 7 control: the AppHost consumer lost its ADR-040 classification — an absent generic must not become a finding"
fi
if [ -s "${_tmp}/ahlogs/hydra-gate-route-auth.log" ]; then
    _bad "ARM 7 control: gate-5 reported a FINDING for a generic controller this repo does not ship"
else
    _ok "ARM 7 control: no finding raised for a controller the consumer does not ship"
fi

echo
if [ "${_failures}" -gt 0 ]; then
    echo "FAILED — ${_failures} assertion(s)"
    exit 1
fi
echo "ALL test_gate_1_11_honest_verdicts assertions passed"
