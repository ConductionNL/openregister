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

/** The shared dev container these suites run against by default. */
export const SHARED_CONTAINER = 'nextcloud'

/**
 * Resolve the name of the Nextcloud CONTAINER the suite may act on.
 *
 * Several api-direct specs shell out to `docker exec -u www-data <c> php occ …`
 * and one to `docker restart <c>`. This used to refuse the shared `nextcloud`
 * container outright, which was too blunt in both directions.
 *
 * 🔑 THE TWO ACTIONS ARE NOT THE SAME RISK, so they no longer share a rule.
 *
 * `'exec'` (the default) DEFAULTS TO the shared container. Running one named
 * `occ` command in it is how the dev box is meant to be exercised, and refusing
 * to do so bought nothing: it made the specs that need a real TimedJob tick —
 * a run parked on `awaiting_consent` and released by a cron sweep — skip
 * everywhere, so the headline behaviour of that subsystem was verified by
 * nothing but a unit test. A skip and a pass look identical in a summary.
 *
 * `'restart'` still REFUSES the shared container without an explicit
 * `NC_ALLOW_SHARED_RESTART=1`. That is the action the original guard was really
 * about: `docker restart nextcloud` bounces an environment that bind-mounts
 * several developers' working trees, mid-session, with no warning to them. A
 * default that runs one job is recoverable; a default that restarts somebody
 * else's instance is not.
 *
 * Returning `null` rather than throwing keeps the `try/catch → skip` behaviour
 * the callers already have.
 *
 * @param  {string} purpose What the caller intends to do with the container.
 * @return {string|null}    The container name, or null when none may be used.
 */
export function resolveContainer(
	purpose: 'exec' | 'restart' = 'exec',
): string | null {
	const name = process.env.NC_CONTAINER || SHARED_CONTAINER

	if (
		purpose === 'restart'
		&& name === SHARED_CONTAINER
		&& process.env.NC_ALLOW_SHARED_RESTART !== '1'
	) {
		// Not an exception: the callers restart opportunistically and a throw
		// here would fail specs that are otherwise perfectly able to run.
		return null
	}

	return name
}

export default resolveBaseUrl
