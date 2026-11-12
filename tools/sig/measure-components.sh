#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Component independence and entanglement of this repository, in two views (ADR-012, ADR-013):
#
#   sigrid view      the components Sigrid rates today: sigrid.yaml as it is in the tree
#                    (`component_base_dirs`, or its `components:` block once that is switched);
#   functional view  the ADR-012 `components:` block in sigrid.components.yaml, read through
#                    SIG_COMPONENTS_YAML; skipped when that file does not exist.
#
# Both runs are the `report` mode of tools/sig/check_sig_maintainability.py (gate 122 with the
# pattern-components patch). Prints, per view, the PHP exposed LOC (module LOC minus the LOC whose
# every referrer sits in its own component), its share, the estimated independence rating, the
# communication lines and the violation degree with its estimated entanglement rating.
#
# Usage: tools/sig/measure-components.sh [--components FILE] [APP_DIR]
#   --components FILE   use FILE as the functional block instead of sigrid.components.yaml
#   APP_DIR             the repository to measure (default: the repository holding this script)
#
# Each report takes one to two minutes on openregister; lizard and jscpd are fetched into the
# hydra-gates tool cache on first use (HYDRA_GATE_SIG_NO_INSTALL=1 forbids that).

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
tool="${here}/check_sig_maintainability.py"
components=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --components)
            components="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '5,21p' "$0"
            exit 0
            ;;
        *)
            break
            ;;
    esac
done

app="$(cd "${1:-${here}/../..}" && pwd)"
if [ -z "${components}" ] && [ -f "${app}/sigrid.components.yaml" ]; then
    components="${app}/sigrid.components.yaml"
fi
if [ -n "${components}" ]; then
    components="$(cd "$(dirname "${components}")" && pwd)/$(basename "${components}")"
fi

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

# run_view LABEL SOURCE COMPONENTS_FILE: one report, then its two component lines.
run_view() {
    local label="$1" source="$2" file="$3" rc=0
    if [ -n "${file}" ]; then
        SIG_COMPONENTS_YAML="${file}" python3 "${tool}" report "${app}" --json "${work}/${label}.json" \
            >"${work}/${label}.log" 2>&1 || rc=$?
    else
        env -u SIG_COMPONENTS_YAML python3 "${tool}" report "${app}" --json "${work}/${label}.json" \
            >"${work}/${label}.log" 2>&1 || rc=$?
    fi
    if [ "${rc}" -ne 0 ] || [ ! -s "${work}/${label}.json" ]; then
        echo "${label} view: the report failed (exit ${rc}):"
        tail -n 5 "${work}/${label}.log"
        return 1
    fi
    python3 - "${work}/${label}.json" "${label}" "${source}" <<'PY'
import json
import sys

path, label, source = sys.argv[1:4]
report = json.load(open(path))
php = (report.get("structure") or {}).get("php")
if not php:
    print(f"{label} view ({source}): no PHP structure in the report")
    sys.exit(1)
props = (report.get("rating") or {}).get("properties") or {}


def rating(name):
    value = (props.get(name) or {}).get("rating")
    return "?" if value is None else f"{value:.2f}"


total = php["module_loc"]
hidden = php["component_independence"]["hidden_loc"]
exposed = total - hidden
ent = php["component_entanglement"]
comps = php.get("components") or {}
print(f"{label.upper()} VIEW ({source}): {len(comps)} components, root {php.get('component_root')}")
print(f"  component independence: exposed {exposed:,} of {total:,} LOC ({exposed / max(total, 1):.2%}), "
      f"independence {rating('component_independence')}")
print(f"  component entanglement: {ent['communication_lines']} lines between {ent['connected_components']} "
      f"components, violation degree {ent['violation_degree']:.4f}, entanglement {rating('component_entanglement')}")
PY
}

echo "tree ${app} at $(git -C "${app}" rev-parse --short=12 HEAD 2>/dev/null || echo '?')"
status=0
run_view sigrid "sigrid.yaml as in the tree" "" || status=1
if [ -n "${components}" ]; then
    run_view functional "${components#"${app}"/}" "${components}" || status=1
else
    echo "FUNCTIONAL VIEW: skipped, no sigrid.components.yaml in ${app} (pass --components FILE)"
fi
exit "${status}"
