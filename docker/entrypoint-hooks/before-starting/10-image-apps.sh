#!/bin/sh
#
# Runs as www-data on every container start, after Nextcloud is installed or
# upgraded. Brings the apps baked into the image into the persistent
# custom_apps directory and enables them, in the order NEXTCLOUD_IMAGE_APPS
# lists them. Rendered from ConductionNL/.github templates/app-deploy.
#
# The official entrypoint copies custom_apps from the image only into an empty
# volume, so without this a newer image would keep running the old app code.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -eu

baked=/usr/src/nextcloud/custom_apps
live=/var/www/html/custom_apps
occ="php /var/www/html/occ"

version_of() {
	[ -f "$1/appinfo/info.xml" ] || return 0
	sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1/appinfo/info.xml" | head -n 1
}

if ! $occ status --output=json | grep -q '"installed":true'; then
	echo "==> Nextcloud is not installed yet, leaving the image apps alone"
	exit 0
fi

synced=false
for app in ${NEXTCLOUD_IMAGE_APPS:-}; do
	[ -d "$baked/$app" ] || continue
	from="$(version_of "$baked/$app")"
	to="$(version_of "$live/$app")"
	if [ "$from" != "$to" ]; then
		echo "==> $app: ${to:-absent} -> $from"
		rsync -rlD --delete "$baked/$app/" "$live/$app/"
		synced=true
	fi
done

if [ "$synced" = true ] && $occ status --output=json | grep -q '"needsDbUpgrade":true'; then
	$occ upgrade
fi

enabled="$($occ app:list --enabled)"
for app in ${NEXTCLOUD_IMAGE_APPS:-}; do
	if printf '%s\n' "$enabled" | grep -q "^  - $app:"; then
		continue
	fi
	echo "==> enabling $app"
	$occ app:enable "$app"
	enabled="$(printf '%s\n  - %s:' "$enabled" "$app")"
done
