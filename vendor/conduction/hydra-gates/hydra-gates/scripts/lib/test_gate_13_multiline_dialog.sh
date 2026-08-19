#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_13_multiline_dialog.sh — gate-13 must see a dialog tag opened
# across several lines, which is how Vue components are actually written.
#
# WHAT THIS GUARDS (.github#321)
# ------------------------------
# The test was `grep -qE '<NcModal[ \t>/]|<NcDialog[ \t>/]'`. `grep` matches
# line by line, so a tag with its props on following lines —
#
#     <NcDialog
#         :open="showConfirm"
#         name="Delete lead">
#
# has NOTHING after `<NcDialog` on its own line, the character class cannot
# match end-of-line, and the tag was invisible.
#
# Measured 2026-08-09 on pipelinq: 0 of 9 real violations seen, while the gate
# passed its own planted true positive the entire time — because a plant is
# written on one line and a real dialog is not. THAT is why the arms below are
# built from the shapes the repos contain rather than the minimal case: a
# minimal plant and a real defect can differ in precisely the feature the
# regex depends on, and here they did.
#
# ARMS
#   1  a multi-line-opened <NcDialog> is caught          (the real shape)
#   2  a multi-line-opened <NcModal> is caught too
#   3  the single-line form still caught                 (no displacement)
#   4  ANTI-WIDENING — <NcDialogHeader> / <NcModalFooter> are NOT dialogs;
#      the delimiter must still be required, only widened to end-of-line
#   5  ANTI-WIDENING — a commented-out dialog is not a dialog
#   6  ANTI-WIDENING — a component with no dialog at all still PASSes
#   7  a dialog living correctly under src/dialogs/ is not a finding

set -u

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_scripts="$(cd "${_here}/.." && pwd)"
_runner="${HYDRA_GATES_RUNNER_UNDER_TEST:-${_scripts}/run-hydra-gates.sh}"

_failures=0
_ok()  { echo "  ok   — $1"; }
_bad() { echo "  FAIL — $1"; _failures=$((_failures + 1)); }

echo "test_gate_13_multiline_dialog.sh"

_tmp="$(mktemp -d "${TMPDIR:-/tmp}/hydra-g13.XXXXXX")"
trap 'rm -rf "${_tmp}"' EXIT

_mkapp() {  # _mkapp <dir>
    mkdir -p "$1/src/components" "$1/src/dialogs"
    printf '{"name":"fx","menu":[]}\n' > "$1/src/manifest.json"
    (
        cd "$1" || exit 1
        git init -q .
        git add -A
        git -c user.email=t@t -c user.name=t commit -qm init
    ) >/dev/null 2>&1
}

_run13() {  # _run13 <appdir> -> echoes the gate-13 verdict line
    local logs="${_tmp}/logs.$$.${RANDOM}"
    mkdir -p "${logs}"
    (
        cd "$1" || exit 1
        git add -A >/dev/null 2>&1
        git -c user.email=t@t -c user.name=t commit -qm wip >/dev/null 2>&1
        HYDRA_GATE_LOG_DIR="${logs}" bash "${_runner}" . 2>/dev/null
    ) | grep -E '^\[gate-13\]' || true
}

_assert() {  # _assert <label> <expected-substring> <actual>
    case "$3" in
        *"$2"*) _ok "$1" ;;
        *)      _bad "$1 — got: $3" ;;
    esac
}

# ---------------------------------------------------------------------------
# ARM 1 — pipelinq's shape verbatim.
# ---------------------------------------------------------------------------
_app="${_tmp}/a1"
_mkapp "${_app}"
cat > "${_app}/src/components/LeadList.vue" <<'VUE'
<template>
	<div class="lead-list">
		<NcDialog
			:open="showConfirm"
			name="Delete lead"
			@closing="showConfirm = false">
			<p>Are you sure?</p>
		</NcDialog>
	</div>
</template>
<script>
export default { name: 'LeadList' }
</script>
VUE
_assert "a multi-line-opened <NcDialog> in a parent component → FAIL" \
    "FAIL" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 2 — the NcModal half of the pattern had the identical defect.
# ---------------------------------------------------------------------------
_app="${_tmp}/a2"
_mkapp "${_app}"
cat > "${_app}/src/components/QueueList.vue" <<'VUE'
<template>
	<NcModal
		v-if="open"
		size="large"
		@close="open = false">
		<div>body</div>
	</NcModal>
</template>
<script>
export default { name: 'QueueList' }
</script>
VUE
_assert "a multi-line-opened <NcModal> → FAIL" "FAIL" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 3 — the single-line form the gate already caught must STILL be caught.
# This is the plant that passed while all nine real violations were missed;
# keeping it proves the widening did not displace the original rule.
# ---------------------------------------------------------------------------
_app="${_tmp}/a3"
_mkapp "${_app}"
cat > "${_app}/src/components/Inline.vue" <<'VUE'
<template>
	<div><NcDialog :open="x" name="y" /></div>
</template>
<script>
export default { name: 'Inline' }
</script>
VUE
_assert "the single-line form is still caught → FAIL" "FAIL" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 4 — ANTI-WIDENING. The delimiter is widened to include end-of-line, NOT
# dropped. A component whose NAME merely starts with NcDialog/NcModal is a
# different component and must not be reported — otherwise the fix trades one
# blind gate for a noisy one.
# ---------------------------------------------------------------------------
_app="${_tmp}/a4"
_mkapp "${_app}"
cat > "${_app}/src/components/Header.vue" <<'VUE'
<template>
	<div>
		<NcDialogHeader
			:title="title" />
		<NcModalFooter>
			<slot />
		</NcModalFooter>
	</div>
</template>
<script>
export default { name: 'Header' }
</script>
VUE
_assert "<NcDialogHeader> / <NcModalFooter> are NOT dialogs → PASS" \
    "PASS" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 5 — ANTI-WIDENING. A commented-out dialog is not a dialog. Both the HTML
# comment in the template and the block/line comments in <script>, and a URL
# containing `//` must survive the line-comment mask.
# ---------------------------------------------------------------------------
_app="${_tmp}/a5"
_mkapp "${_app}"
cat > "${_app}/src/components/Commented.vue" <<'VUE'
<template>
	<div>
		<!-- TODO: bring this back
		<NcDialog
			:open="x" />
		-->
		<p>nothing here</p>
	</div>
</template>
<script>
/*
 * <NcModal> was removed in favour of the shared dialog.
 */
// see https://example.test/docs#NcDialog for the migration note
export default { name: 'Commented' }
</script>
VUE
_assert "a commented-out <NcDialog>/<NcModal>, plus a https:// URL → PASS" \
    "PASS" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 6 — a plain component with no dialog at all.
# ---------------------------------------------------------------------------
_app="${_tmp}/a6"
_mkapp "${_app}"
cat > "${_app}/src/components/Plain.vue" <<'VUE'
<template>
	<div class="plain">
		<NcButton @click="go">Go</NcButton>
	</div>
</template>
<script>
export default { name: 'Plain', methods: { go() {} } }
</script>
VUE
_assert "a component with no dialog → PASS" "PASS" "$(_run13 "${_app}")"

# ---------------------------------------------------------------------------
# ARM 7 — a dialog in its correct home is the thing the rule ASKS for.
# ---------------------------------------------------------------------------
_app="${_tmp}/a7"
_mkapp "${_app}"
cat > "${_app}/src/dialogs/ConfirmDelete.vue" <<'VUE'
<template>
	<NcDialog
		:open="open"
		name="Confirm delete"
		@closing="$emit('close')">
		<p>Are you sure?</p>
	</NcDialog>
</template>
<script>
export default { name: 'ConfirmDelete' }
</script>
VUE
cat > "${_app}/src/components/Uses.vue" <<'VUE'
<template>
	<div><ConfirmDelete :open="open" @close="open = false" /></div>
</template>
<script>
import ConfirmDelete from '../dialogs/ConfirmDelete.vue'
export default { name: 'Uses', components: { ConfirmDelete } }
</script>
VUE
_assert "a dialog under src/dialogs/, used by import → PASS" "PASS" "$(_run13 "${_app}")"

echo ""
if [ "${_failures}" -eq 0 ]; then
    echo "test_gate_13_multiline_dialog.sh: ALL GREEN"
    exit 0
fi
echo "test_gate_13_multiline_dialog.sh: ${_failures} FAILURE(S)"
exit 1
