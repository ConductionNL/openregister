#!/usr/bin/env bash
#
# check-integration-parity.sh — ADR-019 integration parity gate (wrapper).
#
# ADR-019 (pluggable integration registry) names this path as the
# cross-repo parity gate. The canonical check is the Node script that
# ships in @conduction/nextcloud-vue:
#
#     nextcloud-vue/scripts/check-integration-parity.js
#
# It asserts that every registered integration declares BOTH a sidebar
# `tab` AND a `widget` component (AD-11/AD-13). This wrapper lets the
# ADR-019 path resolve inside the openregister repo (and hydra's quality
# gate) by locating and invoking that JS check.
#
# Resolution order for the JS check:
#   1. $NEXTCLOUD_VUE_DIR/scripts/check-integration-parity.js   (override)
#   2. node_modules/@conduction/nextcloud-vue/scripts/...        (installed dep)
#   3. ../nextcloud-vue/scripts/...                              (sibling checkout)
#
# The npm-published @conduction/nextcloud-vue package does not ship the
# `scripts/` dir, so the installed-dep path only works against a linked /
# source checkout. When no copy of the JS check can be found this wrapper
# SKIPS (exit 0) with a clear message rather than failing the build —
# the authoritative gate runs in the nextcloud-vue repo's own CI, and
# openregister ships no integration descriptors of its own (the leaves
# live in nextcloud-vue/src/integrations/).
#
# Exit codes (mirror the JS check when it runs):
#   0 — parity OK, or the JS check could not be located (skipped)
#   1 — at least one integration is missing a `tab` or `widget`
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

REL_JS="scripts/check-integration-parity.js"

candidates=()
if [ -n "${NEXTCLOUD_VUE_DIR:-}" ]; then
	candidates+=("${NEXTCLOUD_VUE_DIR}/${REL_JS}")
fi
candidates+=("${REPO_ROOT}/node_modules/@conduction/nextcloud-vue/${REL_JS}")
candidates+=("${REPO_ROOT}/../nextcloud-vue/${REL_JS}")

JS_CHECK=""
for c in "${candidates[@]}"; do
	if [ -f "${c}" ]; then
		JS_CHECK="${c}"
		break
	fi
done

if [ -z "${JS_CHECK}" ]; then
	echo "i integration parity: canonical JS check not found locally — skipping."
	echo "  (looked for ${REL_JS} under \$NEXTCLOUD_VUE_DIR, node_modules/@conduction/nextcloud-vue, and ../nextcloud-vue)"
	echo "  The authoritative gate runs in the nextcloud-vue repo CI; openregister ships no integration descriptors."
	exit 0
fi

if ! command -v node >/dev/null 2>&1; then
	echo "✗ integration parity: node is required to run ${JS_CHECK} but was not found on PATH." >&2
	exit 1
fi

echo "→ running integration parity check via ${JS_CHECK}"
exec node "${JS_CHECK}"
