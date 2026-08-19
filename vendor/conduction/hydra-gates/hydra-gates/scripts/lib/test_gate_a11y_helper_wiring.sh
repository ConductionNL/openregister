#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_a11y_helper_wiring.sh — a broken a11y helper must report SKIPPED,
# never PASS, and must never take the rest of the run down with it.
#
# WHY THIS EXISTS
# ---------------
# gates 37 and 43 were moved out of the runner into python helpers. Both call
# sites started life as:
#
#     python3 "${helper}" "${files[@]}" >> "${log}" 2>/dev/null || true
#
# which discards the traceback AND the failure. A helper that crashes leaves an
# empty findings log, and an empty findings log is how these gates spell PASS —
# a falsely-green gate manufactured by its own plumbing, which is exactly the
# defect #147 was filed for and gate-19 (#249) was re-plumbed for.
#
# The second failure mode is worse and less obvious. gate-19's block turns
# `set -e` ON and leaves it on for every gate after it, though this script's
# header sets only `set -u`. With errexit live, a non-zero helper does not
# reach its own `_skip` — it kills the entire runner mid-sweep. When this was
# first measured on gate-38, 21 later gates went silently unreported and the
# run ended on the abort guard. So each case below asserts BOTH that the gate
# says SKIPPED and that the run still reached its COVERAGE summary.
#
# Run: bash scripts/lib/test_gate_a11y_helper_wiring.sh   (exit 0 = green)
set -uo pipefail

PKG_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/../.." && pwd)"

_fail_n=0
_pass_n=0
_ok()  { _pass_n=$((_pass_n + 1)); printf 'PASS — %s\n' "$1"; }
_bad() { _fail_n=$((_fail_n + 1)); printf 'FAIL — %s\n' "$1"; }

# A minimal app tree with something for each gate to find, so "no finding" can
# never be confused with "nothing to look at".
_APP="$(mktemp -d "${TMPDIR:-/tmp}/hydra-a11y-app.XXXXXXXX")" || exit 1
mkdir -p "${_APP}/src" "${_APP}/lib" "${_APP}/appinfo" \
         "${_APP}/lib/BackgroundJob" "${_APP}/templates" "${_APP}/tests/e2e"
cat > "${_APP}/src/Thing.vue" <<'VUE'
<template>
	<div>
		<div tabindex="0" aria-hidden="true">unreachable-but-tabbable</div>
		<img src="/no-alt.png">
		<div @click="go()">click-only</div>
		<NcSelect :options="opts" />
		<button type="button"><span class="icon-delete" /></button>
		<a href="/docs">click here</a>
		<input type="text" name="email">
		<NcCheckboxRadioSwitch :checked="on" @update:checked="v => on = v" />
		<table>
			<tr><th>Name</th><th>Size</th></tr>
			<tr><td>a</td><td>1</td></tr>
		</table>
	</div>
</template>

<script>
export default { methods: { go() { window.confirm('really?') } } }
</script>
VUE

# One true positive per gate whose helper wiring is asserted below (#191,
# #196, #220, #224, #226, #230, #235, #236, #266). "No finding" must never be
# confusable with "nothing to look at" — that confusion IS the defect this
# suite exists to catch.
cat > "${_APP}/lib/BackgroundJob/EmptyJob.php" <<'PHP'
<?php
namespace OCA\Fixture\BackgroundJob;
class EmptyJob extends \OCP\BackgroundJob\QueuedJob
{
    protected function run($argument): void
    {
        $this->logger->info('not implemented yet');
    }
}
PHP

cat > "${_APP}/templates/page.php" <<'PHP'
<?php // a standalone page that really does own the document ?>
<html>
<body><div id="x"></div></body>
</html>
PHP

cat > "${_APP}/tests/e2e/thing.spec.ts" <<'TS'
import { test } from '@playwright/test'
test('thing', async ({ page }) => {
	await page.waitForLoadState('networkidle')
})
TS

_STAGE=""
_stage() {   # copy the package so a helper can be broken without touching it
    _STAGE="$(mktemp -d "${TMPDIR:-/tmp}/hydra-a11y-pkg.XXXXXXXX")" || return 1
    cp -r "${PKG_ROOT}/scripts" "${_STAGE}/scripts" || return 1
    return 0
}

_verdict() {  # <gate-n> -> echoes the gate's verdict line
    local _logdir _out
    _logdir="$(mktemp -d "${TMPDIR:-/tmp}/hydra-a11y-log.XXXXXXXX")"
    _out="$(HYDRA_GATE_LOG_DIR="${_logdir}" bash "${_STAGE}/scripts/run-hydra-gates.sh" "${_APP}" 2>&1 || true)"
    printf '%s' "${_out}"
    rm -rf "${_logdir}"
}

_check() {  # <gate-n> <gate-name> <how-broken> <out>
    local _n="$1" _name="$2" _how="$3" _out="$4" _line
    _line="$(printf '%s' "${_out}" | grep -E "^\[gate-${_n}\] " | head -1)"
    case "${_line}" in
        *"SKIPPED"*) _ok "gate-${_n} ${_name}: a ${_how} helper reports SKIPPED" ;;
        *": PASS"*)  _bad "gate-${_n} ${_name}: a ${_how} helper reported PASS having inspected NOTHING — ${_line}" ;;
        "")          _bad "gate-${_n} ${_name}: a ${_how} helper produced NO verdict line at all (did the run abort?)" ;;
        *)           _bad "gate-${_n} ${_name}: wanted SKIPPED, got — ${_line}" ;;
    esac
    # The run must still finish. An aborted run's PASS lines read exactly like
    # a clean run's, so "did not abort" is a separate assertion from "SKIPPED".
    if printf '%s' "${_out}" | grep -q '^\[hydra-gates\] COVERAGE:'; then
        _ok "gate-${_n} ${_name}: the run still reached its summary — later gates were not lost"
    else
        _bad "gate-${_n} ${_name}: the run ABORTED — a ${_how} helper took the whole sweep down"
    fi
}

echo "== a11y / prose-family helper wiring =="
echo

# The gates whose findings now come from a helper, and the helper each one
# depends on. Nine of these moved out of the runner on 2026-08-08 when their
# raw-text matching was replaced (#191, #196, #220, #224, #226, #230, #235,
# #236, #266); every one of them can now go falsely green by losing its
# helper, which is what this table exists to prevent.
_WIRED=(
    "37:aria-hidden-focusable:check_aria_hidden_focusable.py"
    "43:table-headers:check_table_headers.py"
    "3:stub-scan:check_stub_run_body.py"
    "12:nc-input-labels:check_nc_select_labels.py"
    "31:img-alt:check_markup_a11y.py"
    "32:semantic-controls:check_markup_a11y.py"
    "34:window-confirm:check_js_call_sites.py"
    "41:html-lang:php_template_scope.py"
    "58:e2e-networkidle:check_js_call_sites.py"
    # Added 2026-08-08. Gates 40, 42 and 44 were the last three a11y gates
    # whose checker ran without a return-code guard: 40 as
    # `python3 helper … 2>/dev/null || true`, 42 and 44 as per-file inline
    # heredocs. Measured on opencatalogi with a `python3` that exits 1 on
    # every call, all three reported PASS — gate-40 over the 13 real findings
    # it had reported one run earlier — while every gate already in this table
    # reported SKIPPED (wiring). gate-39 was wired correctly but never listed
    # here, so nothing held it to that.
    "39:button-name:check_button_name.py"
    "40:form-label-association:check_form_labels.py"
    "42:link-text-quality:check_link_text.py"
    "44:autocomplete-attr:check_autocomplete.py"
)

# ---------------------------------------------------------------------------
# 0. POSITIVE CONTROL. With both helpers intact the fixture app must FAIL both
#    gates. Every assertion below is only meaningful because these fire.
# ---------------------------------------------------------------------------
if _stage; then
    _out="$(_verdict)"
    for _case in "${_WIRED[@]}"; do
        _g="${_case%%:*}"
        _line="$(printf '%s' "${_out}" | grep -E "^\[gate-${_g}\] " | head -1)"
        case "${_line}" in
            *": FAIL"*) _ok "positive control: gate-${_g} FAILS on the fixture app — ${_line%%—*}" ;;
            *) _bad "positive control: gate-${_g} did not fail on a fixture built to fail it — ${_line:-<none>}" ;;
        esac
    done
    rm -rf "${_STAGE}"
else
    _bad "could not stage the package — nothing below ran"
fi

# ---------------------------------------------------------------------------
# 1. MISSING helper (#147).
# ---------------------------------------------------------------------------
for _case in "${_WIRED[@]}"; do
    _n="${_case%%:*}"; _rest="${_case#*:}"; _name="${_rest%%:*}"; _file="${_rest#*:}"
    if _stage; then
        rm -f "${_STAGE}/scripts/lib/${_file}"
        _check "${_n}" "${_name}" "MISSING" "$(_verdict)"
        rm -rf "${_STAGE}"
    fi
done

# ---------------------------------------------------------------------------
# 2. CRASHING helper (#249). Present, importable path, dies on invocation.
#    This is the case `2>/dev/null || true` could not tell from "no findings".
# ---------------------------------------------------------------------------
for _case in "${_WIRED[@]}"; do
    _n="${_case%%:*}"; _rest="${_case#*:}"; _name="${_rest%%:*}"; _file="${_rest#*:}"
    if _stage; then
        printf 'raise SystemExit("boom")\n' > "${_STAGE}/scripts/lib/${_file}"
        _check "${_n}" "${_name}" "CRASHING" "$(_verdict)"
        rm -rf "${_STAGE}"
    fi
done

# ---------------------------------------------------------------------------
# 3. THE SHARED MASK (#196). gate-5's attribute test runs over a comment-masked
#    copy of the controller. If source_scope.py is gone — or is present but
#    returns its input unchanged, which no file-existence check can see — the
#    gate falls straight back into the false NEGATIVE it was fixed for, and a
#    false negative leaves NO log to notice. So the gate runs a positive
#    control on the mask first and must decline rather than pass.
#
#    The "silently identity" case is the one worth having: it is the shape
#    #184 shipped (a checker that matched everything and nothing), and it is
#    invisible to `[ -f helper ]`.
# ---------------------------------------------------------------------------
mkdir -p "${_APP}/lib/Controller"
cat > "${_APP}/appinfo/routes.php" <<'PHP'
<?php
return ['routes' => [
    ['name' => 'widget#update', 'url' => '/api/widgets/{id}', 'verb' => 'PUT'],
]];
PHP
cat > "${_APP}/lib/Controller/WidgetController.php" <<'PHP'
<?php
namespace OCA\Fixture\Controller;
class WidgetController extends \OCP\AppFramework\Controller
{
    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    public function update(string $id): \OCP\AppFramework\Http\JSONResponse
    {
        return new \OCP\AppFramework\Http\JSONResponse(['id' => $id]);
    }
}
PHP

if _stage; then
    _line="$(printf '%s' "$(_verdict)" | grep -E '^\[gate-5\] ' | head -1)"
    case "${_line}" in
        *": PASS"*) _ok "positive control: gate-5 PASSes on a correctly attributed method — ${_line}" ;;
        *) _bad "positive control: gate-5 did not pass a method carrying #[NoAdminRequired] — ${_line:-<none>}" ;;
    esac
    rm -rf "${_STAGE}"
fi

if _stage; then
    rm -f "${_STAGE}/scripts/lib/source_scope.py"
    _check 5 "route-auth" "MISSING mask" "$(_verdict)"
    rm -rf "${_STAGE}"
fi

if _stage; then
    # Present, exits 0, and echoes its input unchanged: a mask that masks
    # nothing. `[ -f ]` cannot tell this from a working helper.
    cat > "${_STAGE}/scripts/lib/source_scope.py" <<'PY'
import sys
if len(sys.argv) >= 4 and sys.argv[1] == "--mask":
    t = sys.argv[3]
    sys.stdout.write(sys.stdin.read() if t == "-" else open(t).read())
    raise SystemExit(0)
raise SystemExit(2)
PY
    _check 5 "route-auth" "IDENTITY (masks nothing)" "$(_verdict)"
    rm -rf "${_STAGE}"
fi

rm -rf "${_APP}"

echo
echo "== summary =="
printf '   passed: %d\n   failed: %d\n' "${_pass_n}" "${_fail_n}"
[ "${_fail_n}" -eq 0 ] || exit 1
echo
echo "ALL wiring assertions held."
