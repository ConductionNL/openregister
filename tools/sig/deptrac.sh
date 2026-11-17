#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Run deptrac on lib/ with one of the configs in tools/sig/deptrac/:
#
#   layers   the layering rules (layers.yaml)
#   areas    the functional areas of sigrid.components.yaml (areas.yaml, written by
#            tools/sig/deptrac_areas.py); every area may use Kernel and nothing else
#
# Usage: tools/sig/deptrac.sh layers|areas [--rev REV] [--out DIR]
#   --rev REV   analyse lib/ of that commit (exported with git archive) instead of the working tree
#   --out DIR   write deptrac-<config>.json to DIR: the violations per layer pair, summarised by
#               tools/sig/deptrac_summary.py
#
# Prints the violations per layer pair. Exits 0 whether or not deptrac finds violations; the
# numbers are the result, not a pass or fail.
#
# deptrac itself is installed in vendor-bin/deptrac: composer --working-dir=vendor-bin/deptrac install

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "${here}/../.." && pwd)"
deptrac="${repo}/vendor-bin/deptrac/vendor/bin/deptrac"
config="${1:?usage: deptrac.sh layers|areas [--rev REV] [--out DIR]}"
shift
rev=""
out=""
while [ "$#" -gt 0 ]; do
	case "$1" in
		--rev) rev="$2"; shift 2 ;;
		--out) out="$2"; shift 2 ;;
		*) echo "unknown argument: $1" >&2; exit 2 ;;
	esac
done

[ -x "${deptrac}" ] || { echo "deptrac is not installed: composer --working-dir=vendor-bin/deptrac install" >&2; exit 2; }
[ -f "${here}/deptrac/${config}.yaml" ] || { echo "no config ${here}/deptrac/${config}.yaml" >&2; exit 2; }

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT
if [ -n "${rev}" ]; then
	git -C "${repo}" archive "${rev}" lib | tar -x -C "${work}"
else
	ln -s "${repo}/lib" "${work}/lib"
fi
cp "${here}/deptrac/${config}.yaml" "${work}/deptrac.yaml"

"${deptrac}" analyse --config-file="${work}/deptrac.yaml" --cache-file="${work}/.deptrac.cache" --no-progress \
	--formatter=json --output="${work}/report.json" >/dev/null || true
[ -s "${work}/report.json" ] || { echo "deptrac wrote no report" >&2; exit 1; }
summary=()
if [ -n "${out}" ]; then
	mkdir -p "${out}"
	summary=(--out "${out}/deptrac-${config}.json")
fi
python3 "${here}/deptrac_summary.py" "${work}/report.json" "${summary[@]}"
