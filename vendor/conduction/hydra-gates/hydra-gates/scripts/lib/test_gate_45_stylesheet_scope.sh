#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_45_stylesheet_scope.sh — gate-45 must read STYLESHEETS, not only
# `<style>` blocks written inside markup.
#
# WHAT THIS GUARDS (.github#287)
# ------------------------------
# Gate-45 (prefers-reduced-motion) had never opened a `.css` file in its entire
# existence. It scanned `<style>` blocks in `.vue`/`.php`/`.html` and nothing
# else — so every green it ever produced was a statement about markup, not
# about CSS. In a Nextcloud app the app-wide motion lives in `css/`, because
# that is what `Util::addStyle()` loads.
#
# Measured 2026-08-09, before the fix:
#   nldesign      3 stylesheets with motion, 0 reduced-motion guards → gate-45 PASS
#   openregister  css/main.css, 7 motion declarations, 0 guards      → gate-45 PASS
#
# BOTH DIRECTIONS, and the second half is the one that matters. Un-blinding a
# gate fleet-wide is exactly the change that turns it into a noise generator,
# so the anti-widening arms below encode the shapes the fleet's stylesheets
# ACTUALLY contain, not the minimal case:
#
#   ARM 1  a stylesheet with unguarded motion FAILS
#   ARM 2  `@media screen and (prefers-reduced-motion: reduce)` — a full media
#          prelude, which the old regex could not have matched — PASSES
#   ARM 3  a commented-out declaration is not a declaration
#   ARM 4  `transition: none` is how a fallback is WRITTEN, not motion
#   ARM 5  SCSS `//` comments, without eating the `//` of a `url(https://…)`
#   ARM 6  a repo-wide UNIVERSAL reset in one file guards every other file —
#          the gate must accept the fix people will actually write
#   ARM 7  minified/generated output (webpack `*.chunk.css`, no `.min` in the
#          name) is not audited: the fix belongs in the source it compiled
#   ARM 8  the markup arm still works — widening did not displace it

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_45_stylesheet_scope.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g45-css.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_mkapp() {  # _mkapp <dir> — an app with a css/ and a CLEAN .vue
    mkdir -p "$1/css" "$1/src"
    printf '{"name":"fx","menu":[]}\n' > "$1/src/manifest.json"
    cat > "$1/src/Clean.vue" <<'VUE'
<template>
	<div class="x">hi</div>
</template>
<script>
export default { name: 'Clean' }
</script>
<style scoped>
.x { color: red; }
</style>
VUE
    (
        cd "$1" || exit 1
        git init -q .
        git add -A
        git -c user.email=t@t -c user.name=t commit -qm init
    ) >/dev/null 2>&1
}

# The log path is written to a FILE rather than a shell variable on purpose:
# `$(_run45 …)` is a command substitution, i.e. a subshell, so an assignment
# made inside it never reaches the caller. That is the same class of mistake
# this whole suite exists to catch — a check reading a value that was never
# actually set.
_LAST_LOG_PTR="${_tmp}/last-log-path"
_run45() {  # _run45 <appdir> -> echoes the gate-45 verdict line
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    printf '%s' "${logs}/hydra-gate-prefers-reduced-motion.log" > "${_LAST_LOG_PTR}"
    (
        cd "$1" || exit 1
        git add -A >/dev/null 2>&1
        git -c user.email=t@t -c user.name=t commit -qm wip >/dev/null 2>&1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" . 2>/dev/null
    ) | grep -E '^\[gate-45\]' || true
}

_assert() {  # _assert <label> <expected-substring> <actual>
    case "$3" in
        *"$2"*) _ok "$1" ;;
        *)      _bad "$1 — got: $3" ;;
    esac
}

# ---------------------------------------------------------------------------
# ARM 1 — a stylesheet with unguarded motion is a FAIL.
# This is the exact shape measured on openregister's css/main.css.
# ---------------------------------------------------------------------------
_app="${_tmp}/a1"
_mkapp "${_app}"
cat > "${_app}/css/main.css" <<'CSS'
.spinner {
	animation: spin 1s linear infinite;
}
.btn {
	transition: background-color 0.3s ease;
}
CSS
_out="$(_run45 "${_app}")"
_assert "a css/ stylesheet with motion and no guard → FAIL" "FAIL" "${_out}"
if grep -q 'css/main.css' "$(cat "${_LAST_LOG_PTR}")" 2>/dev/null; then
    _ok "the finding NAMES the stylesheet"
else
    _bad "the finding does not name css/main.css"
fi

# ---------------------------------------------------------------------------
# ARM 2 — a full media prelude is a guard.
#
# The pre-#287 regex demanded `@media` followed IMMEDIATELY by `(`, so
# `@media screen and (prefers-reduced-motion: reduce)` — valid, common, and
# correct — would have been reported as a finding the moment stylesheets came
# into scope. That is a false-positive engine, not an un-blinding.
# ---------------------------------------------------------------------------
_app="${_tmp}/a2"
_mkapp "${_app}"
cat > "${_app}/css/main.css" <<'CSS'
.spinner {
	animation: spin 1s linear infinite;
}
@media screen and (prefers-reduced-motion: reduce) {
	.spinner { animation: none; }
}
CSS
_assert "'@media screen and (prefers-reduced-motion: reduce)' counts as a guard → PASS" \
    "PASS" "$(_run45 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 3 + 4 + 5 — a commented-out declaration is not a declaration, a
# `transition: none` is not motion, and SCSS `//` must not eat a URL.
# ---------------------------------------------------------------------------
_app="${_tmp}/a3"
_mkapp "${_app}"
cat > "${_app}/css/commented.css" <<'CSS'
/* .dead { transition: all 2s ease; } */
.live { color: blue; }
CSS
cat > "${_app}/css/fallback.css" <<'CSS'
.b { transition: none; }
.c { animation: none; }
CSS
mkdir -p "${_app}/src/styles"
cat > "${_app}/src/styles/a.scss" <<'SCSS'
// .commented { animation: pulse 1s infinite; }
.y { background: url(https://example.test/y.png); }
SCSS
_assert "commented-out motion + 'transition: none' + SCSS // and a url(https://) → PASS" \
    "PASS" "$(_run45 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 6 — a repo-wide UNIVERSAL reset guards every stylesheet.
#
# This is the remedy people write: one `@media (prefers-reduced-motion: reduce)
# { *, *::before, *::after { …duration: 0.01ms !important } }`, once. A
# strictly per-file rule would report every OTHER stylesheet as a finding the
# day that reset lands — the gate would punish the correct fix.
# ---------------------------------------------------------------------------
_app="${_tmp}/a6"
_mkapp "${_app}"
cat > "${_app}/css/main.css" <<'CSS'
.spinner { animation: spin 1s linear infinite; }
CSS
_assert "control: without the reset, that file is a finding → FAIL" "FAIL" "$(_run45 "${_app}")"
cat > "${_app}/css/a11y.css" <<'CSS'
@media (prefers-reduced-motion: reduce) {
	*, *::before, *::after {
		animation-duration: 0.01ms !important;
		transition-duration: 0.01ms !important;
	}
}
CSS
_assert "a universal reset in ANOTHER file guards the whole repo → PASS" "PASS" "$(_run45 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 7 — generated/minified output is not audited.
#
# app-versions ships five `css/main-<hash>.chunk.css` files, each a single
# 3 kB line of webpack output with `data-v-` scoped rules and no `.min` in the
# name. A finding there is unactionable in the file it is reported against.
# Detected by CONTENT (a 500-character line), not by guessing at filenames.
# ---------------------------------------------------------------------------
_app="${_tmp}/a7"
_mkapp "${_app}"
{
    printf '.a[data-v-06ad9b25]{animation:spin 1s linear infinite}'
    i=0
    while [ "${i}" -lt 60 ]; do printf '.p%s{color:red}' "${i}"; i=$((i + 1)); done
    printf '\n'
} > "${_app}/css/main-C-zpA6Y.chunk.css"
_out="$(_run45 "${_app}")"
_assert "a minified webpack chunk with unguarded motion is NOT audited → PASS" "PASS" "${_out}"

# ---------------------------------------------------------------------------
# ARM 8 — the ORIGINAL markup arm still works. Widening a gate's scope must
# not displace what it already caught.
# ---------------------------------------------------------------------------
_app="${_tmp}/a8"
_mkapp "${_app}"
cat > "${_app}/src/Motion.vue" <<'VUE'
<template>
	<div class="m">hi</div>
</template>
<script>
export default { name: 'Motion' }
</script>
<style scoped>
.m { transition: opacity 0.4s ease; }
</style>
VUE
_assert "a <style> block with unguarded motion is still a FAIL" "FAIL" "$(_run45 "${_app}")"

echo ""
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_45_stylesheet_scope.sh: ALL GREEN"
    exit 0
fi
echo "test_gate_45_stylesheet_scope.sh: ${_failures} FAILURE(S)"
exit 1
