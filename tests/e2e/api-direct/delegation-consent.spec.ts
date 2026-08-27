/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Delegation consent e2e — the loop that makes a refusal recoverable.
 *
 * WHAT THIS SUITE IS GUARDING AGAINST
 *
 * `delegated-identity.spec.ts` proves the refusals. On its own that is only half
 * a feature: a grant store with no way to answer is a store that only ever says
 * no, and a security control whose sole outcome is "denied" gets removed by
 * whoever is next blocked by it at four in the afternoon.
 *
 * So the assertion that matters here is not any single endpoint. It is the
 * TRANSITION: the same flow save that was refused must succeed once, and only
 * once, a grant exists — and must go back to being refused the moment it is
 * withdrawn. A test that only checked "POST /delegations returns 201" would pass
 * against a store nothing consults.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const RUN_ID = `e2e-consent-${Date.now().toString(36)}`

/** The uid every fixture runs as. Present on any dev instance. */
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || 'admin'

/** A real account that is NOT the caller — the delegation has to be to someone. */
const OTHER = process.env.NEXTCLOUD_OTHER_USER || 'ddauth-alice'

interface Grant {
	uuid: string
	status: string
	principal: string
	actingAs: string
	statedReason?: string | null
	summary?: string
	grantedBy?: string | null
	revokedAt?: string | null
}

/** Post a flow whose schedule trigger names OTHER, and return the raw response. */
async function saveDelegatingFlow(request: APIRequestContext, label: string) {
	return request.post('/apps/openregister/api/flows', {
		data: {
			name: `${RUN_ID} ${label}`,
			description: 'Created by the delegation-consent e2e suite.',
			trigger: 'manual',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-schedule',
					config: { cron: '*/5 * * * *', runAs: OTHER },
					position: { x: 0, y: 0 },
				},
				{
					id: 'done',
					type: 'openregister.end',
					config: { message: 'The flow completed.' },
					position: { x: 200, y: 0 },
				},
			],
			edges: [{ id: 'e1', from: 'start', to: 'done' }],
		},
	})
}

test.describe.configure({ mode: 'serial' })

test.describe('delegation-consent — a refusal becomes recoverable', () => {
	let grantUuid = ''

	test.afterAll(async ({ request }) => {
		// Leave the instance as we found it. A live grant left behind would make
		// the NEXT run of delegated-identity.spec.ts pass its refusal assertion
		// for the wrong reason — or rather fail it, which is the good outcome;
		// the bad one is a later suite silently inheriting a delegation nobody
		// declared. Cleanup here is test hygiene AND a correctness dependency.
		if (grantUuid !== '') {
			await request.post(
				`/apps/openregister/api/delegations/${grantUuid}/revoke`,
			)
		}
	})

	test('BASELINE: the flow is refused before any grant exists', async ({
		request,
	}) => {
		const response = await saveDelegatingFlow(request, 'before the grant')

		expect(
			response.status(),
			'without this baseline the "after" assertion proves nothing — the save might always have worked',
		).toBeGreaterThanOrEqual(400)
		expect((await response.text()).toLowerCase()).toContain(OTHER.toLowerCase())
	})

	test('a request is raised, pending, and names the caller as principal', async ({
		request,
	}) => {
		const response = await request.post('/apps/openregister/api/delegations', {
			data: { actingAs: OTHER, reason: `${RUN_ID} covering leave` },
		})

		expect(response.status(), await response.text()).toBe(201)

		const grant = (await response.json()) as Grant
		grantUuid = grant.uuid

		expect(grant.uuid, 'a request with no id cannot be answered').toBeTruthy()
		expect(grant.status).toBe('pending')
		// 🔴 The principal is the SESSION USER, never the body. An endpoint that
		// took it from the payload would let anyone raise a request in somebody
		// else's name, and the person prompted would read it as that party asking.
		expect(grant.principal).toBe(ADMIN)
		expect(grant.actingAs).toBe(OTHER)
	})

	test('the stated reason is carried ATTRIBUTED, never as the system’s own words', async ({
		request,
	}) => {
		const response = await request.get('/apps/openregister/api/delegations')
		expect(response.status()).toBe(200)

		const body = await response.json()
		const mine = (body.heldByMe ?? []).find((g: Grant) => g.uuid === grantUuid)

		expect(mine, 'the request must be listable by its requester').toBeTruthy()
		expect(mine.statedReason).toContain('covering leave')
		expect(
			mine.summary,
			'the summary is the sentence the system speaks in its own voice, so no requester text may appear in it',
		).not.toContain('covering leave')
	})

	test('asking again REUSES the outstanding request', async ({ request }) => {
		// N blocked units of work must produce ONE pending request, and one answer
		// must release all N. Consent fatigue is not caused by asking; it is
		// caused by asking again, and the eleventh identical prompt is accepted
		// by reflex rather than by decision.
		const response = await request.post('/apps/openregister/api/delegations', {
			data: { actingAs: OTHER, reason: 'a completely different reason' },
		})

		expect(response.status()).toBe(201)

		const grant = (await response.json()) as Grant
		expect(grant.uuid, 'a second request must not be created').toBe(grantUuid)
		expect(
			grant.statedReason,
			'the reuse must return the ORIGINAL request, not a rewritten one',
		).toContain('covering leave')
	})

	test('answering allows it, and records who answered', async ({ request }) => {
		const response = await request.post(
			`/apps/openregister/api/delegations/${grantUuid}/answer`,
			{ data: { allow: true } },
		)

		expect(response.status(), await response.text()).toBe(200)

		const grant = (await response.json()) as Grant
		expect(grant.status).toBe('granted')
		expect(
			grant.grantedBy,
			'a grant that does not record who gave it is not auditable',
		).toBeTruthy()
	})

	test('🔴 THE PAYOFF: the same flow now saves, and is stamped', async ({
		request,
	}) => {
		const response = await saveDelegatingFlow(request, 'after the grant')

		expect(response.status(), await response.text()).toBe(201)

		const flow = await response.json()
		const trigger = (flow.nodes ?? []).find(
			(n: Record<string, unknown>) =>
				n.type === 'openregister.trigger-schedule',
		)

		expect(trigger.config.runAs).toBe(OTHER)
		// The stamp is what the fire path re-resolves against. Its absence would
		// leave this schedule permanently authorised by a check that ran once.
		expect(
			trigger.config.runAsDeclaredBy,
			'a permitted delegation must record WHO asserted it',
		).toBe(ADMIN)
	})

	test('🔴 revoking makes the same save refused again, and says so', async ({
		request,
	}) => {
		const revoked = await request.post(
			`/apps/openregister/api/delegations/${grantUuid}/revoke`,
		)
		expect(revoked.status(), await revoked.text()).toBe(200)
		expect(((await revoked.json()) as Grant).status).toBe('revoked')

		const response = await saveDelegatingFlow(request, 'after the revocation')

		expect(
			response.status(),
			'withdrawing a grant must stop what it permitted',
		).toBeGreaterThanOrEqual(400)

		// The REASON, not just the refusal. "Revoked" and "never granted" send
		// the author to different places — one asks again, the other does not.
		expect((await response.text()).toLowerCase()).toContain('revoked')
	})

	test('a request naming a non-existent account is refused before it is stored', async ({
		request,
	}) => {
		// A pending request naming nobody can never be answered, so it would sit
		// in the store until it expired while its requester waited for a prompt
		// that no account could ever receive.
		const response = await request.post('/apps/openregister/api/delegations', {
			data: { actingAs: 'e2e-no-such-user-9f3a1c', reason: 'why' },
		})

		expect(response.status()).toBe(404)
	})

	test('asking to act as YOURSELF is refused as meaningless', async ({
		request,
	}) => {
		const response = await request.post('/apps/openregister/api/delegations', {
			data: { actingAs: ADMIN, reason: 'why' },
		})

		expect(response.status()).toBe(400)
		expect((await response.text()).toLowerCase()).toContain('not delegation')
	})
})
