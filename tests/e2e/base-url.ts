/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Single source of truth for the Nextcloud base URL the e2e suite targets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two apps in this fleet were found running their e2e suites against the
 * SHARED dev container on :8080. That container bind-mounts real host
 * checkouts, so the suites' WRITE paths created fixture registers, schemas and
 * apps inside other people's working environments, and one app's login specs
 * fired failed logins into it until brute-force protection tripped.
 *
 * The mechanism in both cases was a default: `process.env.X || 'http://localhost:8080'`.
 * A default that points at a shared instance is not a convenience — it is the
 * failure mode. When the variable is unset the suite must FAIL, loudly, not
 * quietly retarget.
 *
 * ⚠️ BUT the shared Code Quality workflow exports the target as `BASE_URL`,
 * not `PLAYWRIGHT_BASE_URL`. openconnector adopted a `PLAYWRIGHT_BASE_URL`-only
 * resolver during its own Vue 3 migration and its E2E job has hard-failed on
 * every run since with `Error: PLAYWRIGHT_BASE_URL is not set.` So accept CI's
 * name too — just never a localhost literal.
 *
 * `NEXTCLOUD_URL` is accepted as well because this repo's existing specs and
 * `global-setup.ts` already use it.
 *
 * This helper belongs in @conduction/nextcloud-vue as a shared testing export;
 * every app in the fleet needs the identical resolver.
 */

/**
 * Resolve the base URL of the Nextcloud instance under test.
 *
 * @throws {Error} When none of the accepted environment variables is set.
 * @return {string} The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	const url =
		process.env.PLAYWRIGHT_BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.BASE_URL

	if (!url) {
		throw new Error(
			'No base URL configured for the e2e suite. Set PLAYWRIGHT_BASE_URL '
				+ '(local) or BASE_URL (CI). There is deliberately no default — a '
				+ 'default would silently target the shared dev instance on :8080 and '
				+ "write fixtures into other people's working trees.",
		)
	}

	return url.replace(/\/+$/, '')
}

/**
 * Resolve the name of the Nextcloud CONTAINER the suite may run `occ` in.
 *
 * Several api-direct specs shell out to `docker exec -u www-data <c> php occ …`
 * and one to `docker restart <c>`. Every one of them defaulted to the literal
 * `'nextcloud'` — the SHARED dev container, which bind-mounts several
 * developers' real working trees. A spec run without `NC_CONTAINER` set
 * therefore wrote appconfig into, and restarted, somebody else's environment.
 *
 * Returning `null` rather than throwing keeps the existing `try/catch → skip`
 * behaviour those specs already have: with no container configured they now
 * skip instead of silently retargeting.
 *
 * `NC_ALLOW_SHARED_CONTAINER=1` opts back in DELIBERATELY. The guard exists to
 * stop an accidental default, not to make the shared box untestable: some paths
 * — a run parked on `awaiting_consent` and released by a cron sweep — only exist
 * once a real TimedJob ticks, and skipping them leaves the headline behaviour of
 * that subsystem unverified by anything but a unit test. Setting the flag is a
 * person saying they know whose environment they are ticking. It does not make
 * `docker restart` safe there, so keep it to the narrow
 * `background-job:execute <id> --force-execute` shape.
 *
 * @return {string|null} The container name, or null when none is configured.
 */
export function resolveContainer(): string | null {
	const name = process.env.NC_CONTAINER
	if (!name) {
		return null
	}
	if (name === 'nextcloud' && process.env.NC_ALLOW_SHARED_CONTAINER !== '1') {
		throw new Error(
			'Refusing to target the shared dev container "nextcloud". Point '
				+ 'NC_CONTAINER at a disposable instance, or set '
				+ 'NC_ALLOW_SHARED_CONTAINER=1 to say you meant it.',
		)
	}
	return name
}

export default resolveBaseUrl
