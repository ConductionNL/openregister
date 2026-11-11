#!/usr/bin/env bash
#
# Prepare a fresh CI instance for the Newman collections in tests/integration.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# The quality workflow runs this as its `newman-seed-command`, from `server/`,
# after openregister is enabled and the PHP server is up. A dev instance does not
# need it: there the real LLM and the real apps are already configured.
#
# 1. An Ollama-compatible stub on 127.0.0.1:11434, and openregister's chat
#    provider pointed at it. chat-streaming and the flow engine's agent case need
#    an answer from an LLM, not a particular one.
# 2. integriq and hermiq, which provide the `openconnector.*` and
#    `hermiq.agent-step` flow nodes that openregister-flow-engine drives.
# 3. The notifications app (a server checkout does not ship it) and a
#    background-job worker for the queued jobs two collections wait for:
#    annotation notifications (platform-annotations) and related destruction
#    dates (dates-follow).

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STUB_PORT=11434
STUB_LOG="${RUNNER_TEMP:-/tmp}/ollama-stub.log"

nohup php -S "127.0.0.1:${STUB_PORT}" "${HERE}/ollama-stub.php" > "${STUB_LOG}" 2>&1 &
for _ in $(seq 1 40); do
	if curl -sf "http://127.0.0.1:${STUB_PORT}/api/tags" > /dev/null; then
		break
	fi
	sleep 0.5
done
if ! curl -sf "http://127.0.0.1:${STUB_PORT}/api/tags" > /dev/null; then
	echo "::error::the LLM stub did not answer on port ${STUB_PORT}; see ${STUB_LOG}" >&2
	exit 1
fi

php occ config:app:set openregister llm \
	--value="{\"enabled\":true,\"chatProvider\":\"ollama\",\"ollamaConfig\":{\"url\":\"http://127.0.0.1:${STUB_PORT}\",\"chatModel\":\"newman-stub\"}}"

# repository and app id. The repository of integriq still carries its old name.
for app in "ConductionNL/openconnector integriq" "ConductionNL/hermiq hermiq"; do
	read -r repo id <<< "${app}"
	git clone --quiet --depth 1 --branch development "https://github.com/${repo}.git" "apps/${id}"
	# soap and xsl are needed only by source types and reports no collection uses.
	composer install --working-dir="apps/${id}" --no-dev --no-progress --prefer-dist \
		--ignore-platform-req=ext-soap --ignore-platform-req=ext-xsl
	php occ app:enable "${id}"
done

# The notifications app branch that matches the server checkout.
SERVER_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "${SERVER_BRANCH}" = "HEAD" ]; then
	SERVER_BRANCH=master
fi
if [ ! -d apps/notifications ]; then
	git clone --quiet --depth 1 --branch "${SERVER_BRANCH}" https://github.com/nextcloud/notifications.git apps/notifications
fi
# Since stable35 the app keeps its runtime dependencies out of git
# (composer.json requires web-push and psr7, vendor/ is ignored), so a bare
# clone fails at app:enable with "Failed opening required
# '.../notifications/composer/../vendor/autoload.php'".
if [ -f apps/notifications/composer.json ] && [ ! -f apps/notifications/vendor/autoload.php ]; then
	composer install --working-dir=apps/notifications --no-dev --no-interaction --no-progress --prefer-dist
fi
php occ app:enable notifications

# Without --stop_after the worker keeps taking jobs of these classes for the rest of the job.
WORKER_LOG="${RUNNER_TEMP:-/tmp}/background-worker.log"
nohup php occ background-job:worker \
	'OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob' \
	'OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob' > "${WORKER_LOG}" 2>&1 &

echo "seeded: LLM stub on ${STUB_PORT}, integriq, hermiq and notifications enabled, background worker running"
