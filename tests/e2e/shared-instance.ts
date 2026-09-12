/*
 * SPDX-FileCopyrightText: 2026 Openregister Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether this e2e run is allowed to touch the instance it is aimed at.
 *
 * GENERATED FROM hydra/templates/e2e/shared-instance.ts.tmpl. The only thing
 * that differs per app is `APP_ID` below. Edit the template, not the copies.
 *
 * The problem
 * -----------
 * Every Conduction dev box runs one shared Nextcloud on port 8080 (or 80 when
 * it is published without a port). It bind-mounts the host checkouts under
 * `apps-extra/`, so it serves whatever everybody on the box is editing, and it
 * carries data colleagues are working on. An e2e suite seeds OpenRegister
 * objects and then deletes them again. Pointed at that instance it is not
 * running tests, it is editing someone else's environment. Two apps were
 * caught doing exactly this, by default, because their base URL fell back to
 * `http://localhost:8080` when nothing was set.
 *
 * The rule
 * --------
 * Aiming at a shared instance is allowed. Aiming at one BY ACCIDENT is not.
 * So the target has to be named twice: once as the base URL, and once in an
 * opt-in variable that holds the origin you permit.
 *
 *     E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 \
 *     npx playwright test
 *
 * The flag holds an ORIGIN, not `1`. A bare `1` left in a shell profile goes
 * on permitting every shared instance the suite ever meets. An origin permits
 * the one you typed and nothing else, so aiming somewhere new makes you type
 * the new one.
 *
 * Two spellings are accepted and they mean the same thing:
 *
 *   E2E_ALLOW_SHARED_INSTANCE              fleet-wide, permits any app's suite
 *   OPENREGISTER_E2E_ALLOW_SHARED_INSTANCE  this app only
 *
 * The app-specific spelling is the safer one to leave in a profile, because it
 * stops at this app. The fleet-wide one is for a session that deliberately
 * drives several suites at one instance.
 *
 * CI is exempt
 * ------------
 * On a GitHub runner `localhost:8080` is the runner's own throwaway Nextcloud,
 * started by the shared ConductionNL/.github workflow and destroyed with the
 * job. Nothing there is shared with anybody. Treating it as shared would break
 * every e2e run in the fleet, so `isCI()` short-circuits the whole rule.
 *
 * This is one of three guards, and they do not overlap
 * ---------------------------------------------------
 *  1. An age bound on the cross-run residue sweep stops one session deleting
 *     another session's LIVE fixtures.
 *  2. A run ledger stops a run's own teardown reaching rows it never created,
 *     at any age. Content matching (`JSON.stringify(row).includes(prefix)`) is
 *     not ownership: it deletes anything that happens to carry the string.
 *  3. This flag stops the cross-run sweep deleting anything at all on a shared
 *     box, because there it cannot tell your leftovers from a colleague's.
 *
 * Guard 3 is the only portable one, which is why it is the one in this
 * template. Guard 2 needs a single create funnel and is written per app.
 */

/** The app this copy belongs to. Substituted when the template is rendered. */
export const APP_ID = 'openregister'

/** The app-specific opt-in variable. Holds an origin, not a boolean. */
export const SHARED_INSTANCE_FLAG = 'OPENREGISTER_E2E_ALLOW_SHARED_INSTANCE'

/** The fleet-wide opt-in variable. Same contract, wider blast radius. */
export const FLEET_INSTANCE_FLAG = 'E2E_ALLOW_SHARED_INSTANCE'

/**
 * Both spellings, app-specific first so it wins a disagreement.
 */
export const SHARED_INSTANCE_FLAGS = [
	SHARED_INSTANCE_FLAG,
	FLEET_INSTANCE_FLAG,
] as const

/**
 * Origins that belong to the shared Conduction development stack.
 *
 * Port 8080 is the `nextcloud` container every dev box runs, and port 80 is
 * the same stack published without a port. A disposable rig gets its own high
 * port (8095, 8614, 8731 and so on), never matches this list, and needs no
 * flag.
 */
const SHARED_PORTS = new Set(['80', '8080'])

/** Loopback spellings that all name the same host. */
const LOOPBACK = new Set(['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'])

/** A URL split into the three things this module compares. */
interface OriginParts {
	/** `http:` or `https:`, including the colon. */
	protocol: string
	/** Hostname, with every loopback spelling folded onto `localhost`. */
	host: string
	/** Port as a string, with the protocol default made explicit. */
	port: string
}

/**
 * Split a URL into protocol, folded host and explicit port.
 *
 * The explicit port is the whole point. `new URL('http://127.0.0.1').port` is
 * the empty string, not `80`, so a comparison against a port list silently
 * misses every shared origin written without one. That is the failure this
 * helper exists to make impossible, by being the only place a port is read.
 *
 * @param value A base URL.
 * @return The parts, or null when the value will not parse.
 */
function splitOrigin(value: string): OriginParts | null {
	let url: URL
	try {
		url = new URL(value)
	} catch {
		return null
	}
	return {
		protocol: url.protocol,
		host: LOOPBACK.has(url.hostname) ? 'localhost' : url.hostname,
		port: url.port !== '' ? url.port : url.protocol === 'https:' ? '443' : '80',
	}
}

/**
 * Reduce a URL to `scheme://host:port`, with loopback spellings folded onto
 * `localhost` and the default port made explicit.
 *
 * Returns the trimmed input when it will not parse, so a malformed value fails
 * later on its own HTTP probe with a message about the real problem rather
 * than here.
 *
 * @param value A base URL.
 * @return The normalised origin.
 */
export function normaliseOrigin(value: string): string {
	const parts = splitOrigin(value)
	if (parts === null) return value.trim().replace(/\/+$/, '')
	return `${parts.protocol}//${parts.host}:${parts.port}`
}

/**
 * Whether this URL names an instance shared with other people.
 *
 * Shared means loopback on port 80 or 8080. A remote host is somebody's
 * deployment and is out of scope here: this guard is about the box you are
 * sitting at.
 *
 * @param value A base URL.
 * @return True when the origin is the shared development stack.
 */
export function isSharedOrigin(value: string): boolean {
	const parts = splitOrigin(value)
	if (parts === null) return false
	return parts.host === 'localhost' && SHARED_PORTS.has(parts.port)
}

/**
 * Whether this process runs on a CI runner.
 *
 * @return True on GitHub Actions, or any CI that exports `CI`.
 */
export function isCI(): boolean {
	return Boolean(process.env.CI) || Boolean(process.env.GITHUB_ACTIONS)
}

/**
 * The flag value that permits this origin, if any flag does.
 *
 * @param target The base URL being aimed at.
 * @param env    The environment to read.
 * @return The variable name and its value, or null when none matches.
 */
export function permittingFlag(
	target: string,
	env: NodeJS.ProcessEnv = process.env,
): { name: string; value: string } | null {
	const wanted = normaliseOrigin(target)
	for (const name of SHARED_INSTANCE_FLAGS) {
		const value = (env[name] ?? '').trim()
		if (value === '') continue
		if (normaliseOrigin(value) === wanted) return { name, value }
	}
	return null
}

/**
 * Whether this run deliberately targets an instance shared with other people.
 *
 * False on CI even at `localhost:8080`, and false for a rig on its own port.
 * Every safety rule in a suite keys off this one answer: teardown deletes only
 * recorded ids, the cross-run residue sweep reports instead of deleting, and
 * specs that would change instance-wide settings refuse to run.
 *
 * @param target The base URL under test.
 * @return True when the target is shared and this run said so.
 */
export function isSharedInstance(target: string): boolean {
	return isCI() === false && isSharedOrigin(target)
}

/**
 * The message an operator reads when they aimed at a shared instance without
 * saying so.
 *
 * @param target The base URL they asked for.
 * @param env    The environment to read.
 * @return The full error text.
 */
function refusalMessage(
	target: string,
	env: NodeJS.ProcessEnv = process.env,
): string {
	const origin = normaliseOrigin(target)
	const setButWrong = SHARED_INSTANCE_FLAGS.map((name) => {
		const value = (env[name] ?? '').trim()
		if (value === '') return ''
		return (
			`${name} is set to "${value}", which normalises to `
			+ `${normaliseOrigin(value)} and does not match ${origin}.\n`
		)
	}).join('')

	return (
		`[${APP_ID} e2e] ${target} is the SHARED development instance, and this run `
		+ 'did not say it meant to go there.\n'
		+ setButWrong
		+ 'That instance bind-mounts host checkouts and holds data your colleagues '
		+ 'are working on. This suite seeds and deletes objects.\n\n'
		+ 'Point the suite at your own disposable rig:\n\n'
		+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
		+ 'Or aim at the shared instance on purpose, naming the origin you permit:\n\n'
		+ `    ${SHARED_INSTANCE_FLAG}=${origin} \\\n`
		+ `    PLAYWRIGHT_BASE_URL=${target} \\\n`
		+ '    npx playwright test\n\n'
		+ `Read the header of tests/e2e/shared-instance.ts first. The flag changes `
		+ 'what teardown may delete and what the residue sweep may remove.'
	)
}

/**
 * Refuse the run when it aims at a shared instance without the opt-in.
 *
 * Call this from the module that resolves the base URL, on the resolved value,
 * so there is one place a target can enter the suite.
 *
 * @param target The base URL under test.
 * @param env    The environment to read.
 * @return The target, unchanged, so the call can be inlined.
 * @throws when the target is shared and no flag names it.
 */
export function assertInstancePermitted(
	target: string,
	env: NodeJS.ProcessEnv = process.env,
): string {
	if (isCI()) return target
	if (isSharedOrigin(target) === false) return target
	if (permittingFlag(target, env) !== null) return target
	throw new Error(refusalMessage(target, env))
}

/**
 * Refuse one capability on a shared instance, even when the flag permitted the
 * suite itself.
 *
 * For work that changes instance-wide state: enabling a workflow engine,
 * flipping an app setting, purging a register. Call it from `test.beforeAll`
 * so the refusal is reported as a failure with its reason. A skip reads as
 * "nothing to see here", which is the opposite of the message.
 *
 * @param target The base URL under test.
 * @param what   The spec or capability being refused.
 * @param reason Why it must not run on a shared instance.
 * @throws when this run targets a shared instance.
 */
export function refuseOnSharedInstance(
	target: string,
	what: string,
	reason: string,
): void {
	if (isSharedInstance(target) === false) return
	throw new Error(
		`[${APP_ID} e2e] ${what} must not run on ${target}.\n`
		+ `${reason}\n`
		+ `${SHARED_INSTANCE_FLAG} permits the suite on a shared instance. It does `
		+ 'not permit this.\n'
		+ 'Start a disposable rig and point the suite at that instead:\n\n'
		+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n',
	)
}

/**
 * The occ invocation for the instance under test, as a command prefix.
 *
 * The binding matters on a shared box: `php occ` run from this checkout talks
 * to whatever server root sits two directories up, which on CI is the instance
 * under test and on a dev box may be a different one entirely. Naming the
 * container is how the two are tied together.
 *
 * Resolution order:
 *  1. `OPENREGISTER_E2E_OCC`, a complete prefix, for any rig shape the guesses
 *     below do not cover.
 *  2. `OPENREGISTER_E2E_CONTAINER` or `NEXTCLOUD_CONTAINER`, a container name,
 *     turned into `docker exec -u www-data <name> php occ`.
 *  3. `php occ` from the server root, which is the CI case.
 *
 * @param env The environment to read.
 * @return The argv prefix, already split, for `execFile` rather than a shell.
 */
export function occPrefix(env: NodeJS.ProcessEnv = process.env): string[] {
	const explicit = (env.OPENREGISTER_E2E_OCC ?? '').trim()
	if (explicit !== '') return explicit.split(/\s+/).filter((p) => p !== '')

	const container = (
		env.OPENREGISTER_E2E_CONTAINER ?? env.NEXTCLOUD_CONTAINER ?? ''
	).trim()
	if (container !== '') {
		return ['docker', 'exec', '-u', 'www-data', container, 'php', 'occ']
	}

	return ['php', 'occ']
}
