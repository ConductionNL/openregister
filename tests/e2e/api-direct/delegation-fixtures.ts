/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared preconditions for the delegation suites: who to delegate to, and a
 * known-empty grant table to start from.
 *
 * WHY THIS EXISTS
 *
 * A delegation needs somebody to delegate TO, and the delegation specs used to
 * name one: `NEXTCLOUD_OTHER_USER || 'ddauth-alice'`. That uid existed on the dev
 * instance when the specs were written and did not exist a day later, after the
 * instance was rebuilt — so two specs failed with
 * `"ddauth-alice" resolves to no account you may ask`, which reads exactly like
 * the delegation guard refusing a real person and is in fact a rotted fixture.
 *
 * A hardcoded uid is a fixture with an expiry date nobody wrote down.
 *
 * 🔴 IT DOES NOT CREATE ONE. Minting a fixture user would leave an account behind
 * on every run, on a shared instance, forever — and an account is not a register
 * a teardown can drop without thinking about who else is looking at it.
 *
 * 🔴 AND THE SKIP NAMES WHAT WENT UNVERIFIED. "Skipped" on its own cannot tell
 * "this instance has one account" from "the delegation loop is broken", and
 * reporting the second as the first is how a defect hides. Callers pass the skip
 * reason straight through to `test.skip()`.
 */
import type { APIRequestContext } from '@playwright/test'

/** Where the grant endpoints live. */
export const DELEGATIONS_API = '/index.php/apps/openregister/api/delegations'

/** The uid every fixture runs as. */
export const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || 'admin'

/**
 * The uid of some account that is not the caller, or null when there is none.
 *
 * `NEXTCLOUD_OTHER_USER` still wins when set, so a CI job that provisions a
 * known partner account keeps naming it. Absent that, the instance is asked.
 */
export async function findSecondAccount(
	request: APIRequestContext,
): Promise<string | null> {
	const named = process.env.NEXTCLOUD_OTHER_USER
	if (named) {
		return named
	}

	// 🔴 NOTHING IS SWALLOWED HERE. An earlier draft wrapped this in
	// `catch { return null }`, and the result was worse than the bug it hid: when
	// the probe came back as Nextcloud's LOGIN PAGE — 200, HTML, `.json()`
	// throws — the catch reported "no second account", eleven specs skipped
	// citing a single-account instance, and the run said `0 failed`. An auth
	// failure wearing a fixture's words is the exact shape of a suite that cannot
	// fail. A probe that cannot answer must SAY so.
	const resp = await request.get('/ocs/v2.php/cloud/users?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	const body = await resp.text()

	if (!resp.ok()) {
		throw new Error(
			`cannot list accounts (HTTP ${resp.status()}): ${body.slice(0, 200)}`,
		)
	}

	let users: string[]
	try {
		users = JSON.parse(body)?.ocs?.data?.users ?? []
	} catch {
		// HTML here means the request was served unauthenticated — the login page
		// is what Nextcloud returns to a session it does not recognise.
		throw new Error(
			`the account list is not JSON, so this request was not authenticated: ${body.slice(0, 200)}`,
		)
	}

	return users.find((uid) => uid !== ADMIN) ?? null
}

/** The sentence a spec skips with when the instance has nobody to delegate to. */
export const NO_SECOND_ACCOUNT =
	'this instance has no account besides the caller, so the delegation path — '
	+ 'request, grant, the save it unblocks, and the refusal a revocation restores — '
	+ 'is UNVERIFIED by this run. Provision a second account, or set '
	+ 'NEXTCLOUD_OTHER_USER, to exercise it.'

/**
 * Revoke every LIVE grant the caller holds over `actingAs`.
 *
 * 🔴 WHY A REFUSAL TEST MUST DO THIS ITSELF
 *
 * Every delegation suite opens by asserting that the save is REFUSED — the
 * baseline the later "now it saves" assertion is measured against. That baseline
 * is only meaningful on an instance where no grant is live, and grants outlive a
 * test run: a suite killed mid-way (a `head` on the reporter closing the pipe is
 * enough) leaves its granted row behind, and the next run's baseline gets a
 * cheerful 201 where it demanded a 4xx.
 *
 * That happened. Three runs in a row disagreed with each other — 18 passed, then
 * 8 failed, then 2 failed — over identical code, because a `granted` row from an
 * aborted run was still live. The failing direction was the lucky one: had the
 * leak been a REVOKED grant rather than a granted one, the refusal baseline would
 * have passed for the wrong reason and the suite would have proved nothing.
 *
 * A precondition a test needs is a precondition that test must establish.
 */
export async function revokeGrantsOver(
	request: APIRequestContext,
	actingAs: string,
): Promise<number> {
	if (actingAs === '') {
		return 0
	}

	const resp = await request.get(`${DELEGATIONS_API}`)
	if (!resp.ok()) {
		throw new Error(`cannot list delegations (HTTP ${resp.status()})`)
	}

	const held: Array<Record<string, unknown>> = (await resp.json())?.heldByMe ?? []
	const live = held.filter((g) => g.actingAs === actingAs && g.revokedAt === null)

	for (const grant of live) {
		await request.post(`${DELEGATIONS_API}/${String(grant.uuid)}/revoke`)
	}

	return live.length
}
