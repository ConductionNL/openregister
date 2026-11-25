#!/usr/bin/env bash
#
# Give a CI instance a working malware scanner: files_antivirus over a ClamAV daemon.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Every OpenRegister upload is refused with 503 when files_antivirus is not
# installed with a working scanner (upload-malware-scan, REQ-UMS-001, decision
# D6). The Newman and Playwright jobs upload files, so both seed scripts call
# this before anything uploads. Run from `server/`, like the seed scripts.
#
# 1. A clamav/clamav container on 127.0.0.1:3310. It loads its signatures before
#    it answers, which takes a minute or two; the wait below is bounded and a
#    daemon that never answers fails the job rather than letting every upload
#    test fail with 503 for a reason nobody reads.
# 2. files_antivirus from the app store, in daemon mode against that container.

set -euo pipefail

CLAMAV_IMAGE="${CLAMAV_IMAGE:-clamav/clamav:stable}"
CLAMAV_PORT="${CLAMAV_PORT:-3310}"
CLAMAV_WAIT_SECONDS="${CLAMAV_WAIT_SECONDS:-300}"

if ! docker ps --format '{{.Names}}' | grep -qx or-clamav; then
	docker run -d --name or-clamav -p "127.0.0.1:${CLAMAV_PORT}:3310" "${CLAMAV_IMAGE}" > /dev/null
fi

clamd_answers() {
	local reply
	exec 3<> "/dev/tcp/127.0.0.1/${CLAMAV_PORT}" || return 1
	printf 'zPING\0' >&3
	reply="$(timeout 5 head -c 4 <&3 || true)"
	exec 3>&-
	[ "${reply}" = "PONG" ]
}

deadline=$(( SECONDS + CLAMAV_WAIT_SECONDS ))
until clamd_answers 2> /dev/null; do
	if [ "${SECONDS}" -ge "${deadline}" ]; then
		echo "::error::ClamAV did not answer on port ${CLAMAV_PORT} within ${CLAMAV_WAIT_SECONDS}s" >&2
		docker logs --tail 40 or-clamav >&2 || true
		exit 1
	fi
	sleep 5
done

if ! php occ app:list --output=json | grep -q '"files_antivirus"'; then
	php occ app:install files_antivirus
fi
php occ app:enable files_antivirus
php occ config:app:set files_antivirus av_mode --value=daemon
php occ config:app:set files_antivirus av_host --value=127.0.0.1
php occ config:app:set files_antivirus av_port --value="${CLAMAV_PORT}"
php occ config:app:set files_antivirus av_infected_action --value=only_log

echo "files_antivirus enabled in daemon mode, ClamAV answering on ${CLAMAV_PORT}"
