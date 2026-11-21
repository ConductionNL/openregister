#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Make lib/-only checkouts of the fleet apps' development branches, for tools/sig/fleet_surface.py.
# Usage: tools/sig/fleet_checkout.sh DIR   (an existing checkout is updated in place)
set -euo pipefail
dir="${1:?usage: fleet_checkout.sh DIR}"
mkdir -p "${dir}"
apps=(opencatalogi integriq filinq thematiq launchpad stackiq larpinq dossiq pipelinq shillinq learniq
	portaliq decidiq buildiq keepiq hermiq humaniq versioniq planninq)
for app in "${apps[@]}"; do
	if [ -d "${dir}/${app}/.git" ]; then
		git -C "${dir}/${app}" fetch -q --depth 1 origin development
		git -C "${dir}/${app}" reset -q --hard FETCH_HEAD
	else
		git clone -q --depth 1 --filter=blob:none --sparse --branch development "https://github.com/ConductionNL/${app}.git" "${dir}/${app}"
		git -C "${dir}/${app}" sparse-checkout set lib
	fi
	echo "${app} $(git -C "${dir}/${app}" rev-parse --short HEAD)"
done
