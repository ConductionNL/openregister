#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_a11y_markup_scope.sh — the accessibility gates must read the markup
# the app actually ships, not only the markup written in Vue.
#
# WHAT THIS GUARDS (.github#225)
# ------------------------------
# Gates 31, 32, 34, 35, 36, 37, 39, 40, 42, 43, 44 and 45 enumerated
# `find src -name '*.vue'` and nothing else. An app that renders its UI from PHP
# templates had every one of those gates iterate an empty list and report PASS.
#
# The `[ -d src ]` guard did not save it: nldesign HAS a `src/`, containing only
# `manifest.json`. The directory existed, the glob matched nothing, the loop ran
# zero times, and twelve gates printed PASS.
#
# Measured 2026-08-08 on nldesign — one textbook true positive planted per gate
# into `templates/settings/`:
#
#   before the fix   0 of 12 gates caught their planted true positive
#   after  the fix  12 of 12
#
# and removing the plants surfaced 8 GENUINE pre-existing findings in nldesign's
# real admin template (gates 40, 43, 44) that no run had ever reported.
#
# TWO ARMS. The second is the one that keeps this honest:
#
#   ARM 1  planted true positives in a PHP template ARE caught
#   ARM 2  ANTI-WIDENING CONTROL — a CLEAN PHP template still PASSes, and a
#          clean .vue still PASSes. A checker that flags everything is not a
#          checker, and "widen the glob" is exactly the change that could turn
#          these gates into noise generators.

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_a11y_markup_scope.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-a11y-scope.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_mkapp() {  # _mkapp <dir>  — a PHP-template app whose src/ holds only a manifest
    mkdir -p "$1/src" "$1/templates/settings"
    printf '{"name":"fx","menu":[]}\n' > "$1/src/manifest.json"
    (
        cd "$1" || exit 1
        git init -q .
        git add -A
        git -c user.email=t@t -c user.name=t commit -qm init
    ) >/dev/null 2>&1
}

_run() {  # _run <appdir> <outfile>
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "$1" || exit 1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" . > "$2" 2>&1
    )
}

# ---------------------------------------------------------------------------
# ARM 1 — planted true positives in a PHP template are caught.
#
# One violation per gate, each the textbook example from that gate's own
# docblock. If a gate stops catching its own documented example, this goes red.
# ---------------------------------------------------------------------------
_bad_app="${_tmp}/bad"
_mkapp "${_bad_app}"
cat > "${_bad_app}/templates/settings/admin.php" <<'PHP'
<?php // planted true positives — one per accessibility gate ?>
<div id="planted">
	<img src="logo.png">
	<img src="/img/avatar.png" alt="">
	<div @click="doThing()">Click me</div>
	<span tabindex="3">focus trap</span>
	<a href="/settings" aria-hidden="true">Settings</a>
	<button><span class="icon-delete"></span></button>
	<input type="text" name="email_address" id="pl-email">
	<a href="/docs">click here</a>
	<table><tr><td>a</td><td>b</td></tr></table>
</div>
<script>window.confirm('really?');</script>
<style>.planted { transition: all .3s; }</style>
PHP
(
    cd "${_bad_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm plant
) >/dev/null 2>&1

_bad_out="${_tmp}/bad.txt"
_run "${_bad_app}" "${_bad_out}"

# gate -> what it should have found in the template above
_expect="31:img without alt
32:click handler on a non-semantic element
34:window.confirm in an inline script
35:empty alt on a semantically-named src
36:positive tabindex
37:aria-hidden=true on a focusable element
39:icon-only button with no accessible name
40:input with no associated label
42:non-descriptive link text
43:table with no th scope
44:semantic input with no autocomplete
45:style block with motion and no prefers-reduced-motion"

_caught=0
_total=0
while IFS=: read -r _g _what; do
    [ -z "${_g}" ] && continue
    _total=$((_total + 1))
    if grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_bad_out}"; then
        _caught=$((_caught + 1))
    else
        _v=$(grep -oE "^\[gate-${_g}\] [^:]+: [A-Z]+( \([a-z]+\))?" "${_bad_out}" | head -1 | sed 's/^[^:]*: //')
        _bad "gate-${_g} did not catch: ${_what} (verdict: ${_v:-none emitted})"
    fi
done <<< "${_expect}"

if [ "${_caught}" -eq "${_total}" ]; then
    _ok "all ${_total} accessibility gates caught their planted true positive in a PHP template"
fi

# ---------------------------------------------------------------------------
# ARM 2 — ANTI-WIDENING CONTROL. A clean template must still pass.
# ---------------------------------------------------------------------------
_good_app="${_tmp}/good"
_mkapp "${_good_app}"
cat > "${_good_app}/templates/settings/admin.php" <<'PHP'
<?php // the same surfaces, done correctly ?>
<div id="clean">
	<img src="logo.png" alt="Company logo">
	<button type="button" aria-label="Delete item"><span class="icon-delete"></span></button>
	<label for="cl-email">Email address</label>
	<input type="text" name="email_address" id="cl-email" autocomplete="email">
	<a href="/docs">Read the configuration guide</a>
	<table>
		<tr><th scope="col">Name</th><th scope="col">Value</th></tr>
		<tr><td>a</td><td>b</td></tr>
	</table>
</div>
<style>
.clean { transition: all .3s; }
@media (prefers-reduced-motion: reduce) { .clean { transition: none; } }
</style>
PHP
(
    cd "${_good_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm clean
) >/dev/null 2>&1

_good_out="${_tmp}/good.txt"
_run "${_good_app}" "${_good_out}"

_noisy=""
for _g in 31 32 34 35 36 37 39 40 42 43 44 45; do
    if grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_good_out}"; then
        _noisy="${_noisy} ${_g}"
    fi
done
if [ -z "${_noisy}" ]; then
    _ok "a CLEAN PHP template raises no accessibility finding (no false positives from the widening)"
else
    _bad "clean PHP template wrongly flagged by gate(s):${_noisy} — the widened glob is manufacturing findings"
fi

# The widening must not have cost the .vue coverage it already had.
_vue_app="${_tmp}/vue"
_mkapp "${_vue_app}"
mkdir -p "${_vue_app}/src/views"
printf '<template>\n  <div><img src="x.png"></div>\n</template>\n' \
    > "${_vue_app}/src/views/Thing.vue"
(
    cd "${_vue_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm vue
) >/dev/null 2>&1
_vue_out="${_tmp}/vue.txt"
_run "${_vue_app}" "${_vue_out}"
if grep -qE '^\[gate-31\][^:]*: FAIL' "${_vue_out}"; then
    _ok "a .vue violation is still caught — widening did not displace the original scope"
else
    _bad "gate-31 stopped catching a .vue <img> without alt — the widening REPLACED the Vue scope instead of adding to it"
fi

# ---------------------------------------------------------------------------
# ARM 4 — A REPO WITH NO src/ AT ALL MUST NOT SILENCE HALF THE FAMILY.
#
# ARM 1 above passes with `src/` present but empty of markup, which is
# nldesign's real shape. Delete that directory — a templates-only app — and
# the guards diverge. Measured 2026-08-08 at package sha cdfbd7a, same files,
# same run:
#
#   gate-34/36/37/38/39/41/43   ran; four of them FAILED on the plants
#   gate-35/40/42/44            NOT APPLICABLE — "this repo ships no frontend,
#                               so there is no .vue/.js/.ts source for this
#                               gate to inspect"
#
# Those four still guarded on `[ -d src ]` while their siblings had moved to
# `_a11y_has_markup_dir`, and the central applicability table listed the whole
# family under `[ -d src ]`. `na` is the one verdict that removes a gate from
# coverage accounting — so four accessibility gates excused themselves from a
# repo full of markup, giving a reason the same run's own output contradicted.
#
# The assertion is deliberately about NOT-APPLICABLE rather than about the
# findings: a gate that has become blind can still be caught by ARM 1, but a
# gate that has declared itself irrelevant is invisible to every arm above.
# ---------------------------------------------------------------------------
_nosrc_app="${_tmp}/nosrc"
_mkapp "${_nosrc_app}"
cp "${_bad_app}/templates/settings/admin.php" "${_nosrc_app}/templates/settings/admin.php"
rm -rf "${_nosrc_app}/src"
(
    cd "${_nosrc_app}" || exit 1
    git add -A
    git -c user.email=t@t -c user.name=t commit -qm nosrc
) >/dev/null 2>&1
_nosrc_out="${_tmp}/nosrc.txt"
_run "${_nosrc_app}" "${_nosrc_out}"

_excused=""
_still=""
for _g in 31 32 34 35 36 37 39 40 42 43 44 45; do
    if grep -qE "^\[gate-${_g}\][^:]*: NOT APPLICABLE" "${_nosrc_out}"; then
        _excused="${_excused} ${_g}"
    elif ! grep -qE "^\[gate-${_g}\]" "${_nosrc_out}"; then
        _still="${_still} ${_g}"
    fi
done
if [ -n "${_excused}" ]; then
    _bad "templates-only repo: gate(s)${_excused} declared NOT APPLICABLE over a templates/ full of markup — an accessibility gate excused itself from a DOM it can read"
elif [ -n "${_still}" ]; then
    _bad "templates-only repo: gate(s)${_still} emitted no verdict line at all — silence is byte-identical to success"
else
    _ok "a templates-only repo (no src/) keeps every accessibility gate applicable"
fi

# ...and the plants in it are still CAUGHT, not merely "not excused". A gate
# that reports PASS over markup it never opened is the #225 defect itself.
_nosrc_caught=0
_nosrc_total=0
while IFS=: read -r _g _what; do
    [ -z "${_g}" ] && continue
    # gate-38 is not in _expect (it is a whole-document rule, not a per-element
    # one) so it has nothing to be counted against here.
    #
    # gate-45 USED TO BE EXCLUDED HERE TOO, AND THE EXCLUSION WAS THE BUG
    # (.github#274). #272 migrated 35/40/42/44 off `[ -d src ]` and left the
    # twelfth member of the family behind — still `[ -d src ]`-guarded, still
    # listed under `[ -d src ]` in the applicability table — so on this exact
    # templates-only fixture gate-45 reported NOT APPLICABLE ("this repo ships
    # no frontend") over a `<style>` block with `transition: all .3s` and no
    # reduced-motion fallback, sitting in the same file the arm above reads.
    # The skip carried a comment saying so, which is the shape to distrust: a
    # test that documents the defect it declines to assert on. Removing the
    # name from this list IS the regression test.
    case "${_g}" in 38) continue ;; esac
    _nosrc_total=$((_nosrc_total + 1))
    if grep -qE "^\[gate-${_g}\][^:]*: FAIL" "${_nosrc_out}"; then
        _nosrc_caught=$((_nosrc_caught + 1))
    else
        _v=$(grep -oE "^\[gate-${_g}\] [^:]+: [A-Z ]+" "${_nosrc_out}" | head -1 | sed 's/^[^:]*: //')
        _bad "templates-only repo: gate-${_g} did not catch: ${_what} (verdict: ${_v:-none emitted})"
    fi
done <<< "${_expect}"
if [ "${_nosrc_caught}" -eq "${_nosrc_total}" ]; then
    _ok "all ${_nosrc_total} accessibility gates caught their planted true positive with NO src/ directory at all"
fi

echo
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_a11y_markup_scope.sh: ALL PASS"
    exit 0
fi
echo "test_gate_a11y_markup_scope.sh: ${_failures} FAILURE(S)"
exit 1
