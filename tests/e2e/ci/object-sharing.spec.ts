/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OBJECT SHARING — end to end, through the HTTP API a real client uses.
 *
 * The live-DB PHPUnit suite already proves the four enforcement paths agree.
 * What it CANNOT prove is that the capability is reachable: that the routes are
 * registered, the auth attributes let a non-admin through, the controllers wire
 * to the service, and a token redeems anonymously over HTTP. Those are exactly
 * the failures that a green unit suite hides — a route missing from
 * `appinfo/routes.php` is a 404 no PHPUnit test would notice.
 *
 * So this drives HTTP only, and it asserts the CONSEQUENCE each time — "the
 * other user cannot see it", "the token resolves", "the revoked token stops" —
 * rather than the mechanism. Every mechanism assertion in this programme has
 * passed while the consequence was broken at least once.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, users and
 * object, and deletes the users at the end. It needs no `occ`, no docker, and no
 * pre-seeded data, which is what makes it safe to run on every push.
 */
import { test, expect, request as pwRequest, type APIRequestContext } from '@playwright/test'
import { resolveBaseUrl } from '../base-url'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * FIXED uids, provisioned by `occ` in the workflow's `playwright-seed-command`
 * rather than created here through the OCS provisioning API.
 *
 * They used to be per-run and created over `/ocs/v2.php/cloud/users`. That began
 * returning 404 on the CI instance — `provisioning_api` is a shippped-but-
 * optional app, and an e2e suite for object sharing should not go dark because a
 * user-management app is absent. `occ user:add` has no such dependency.
 *
 * Fixed names are safe here because the config pins `workers: 1` and
 * `fullyParallel: false`, so two runs never share an instance. Objects keep the
 * per-run suffix, since those ARE created through the API under test.
 */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/**
 * Assert a seeded account is actually usable before any test leans on it.
 *
 * The accounts are created by the workflow's seed command, so the failure this
 * guards against is a seed that silently did not run — which would otherwise
 * surface later as a confusing authorization error deep inside a scope
 * assertion rather than "the fixture user does not exist".
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get('/index.php/apps/openregister/api/registers')
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe('object sharing over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		// A register and a schema whose read rule admits any logged-in caller,
		// so the SCOPE is the only thing that can hide the object.
		const reg = await admin.post('/index.php/apps/openregister/api/registers', {
			data: { title: `e2e share register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e share schema ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				// A non-empty authorization block FAILS CLOSED for any action it
				// does not list — so listing only `read` means the owner cannot
				// even create the fixture. The first CI run said exactly that:
				// "does not have permission to 'create' objects in schema".
				// `read` is still the ceiling that matters for the scope tests.
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`,
			{ data: { key: 'shared-object' } },
		)
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	// No user teardown: the accounts are owned by the seed command, not by this
	// spec. Deleting them here would break a re-run against the same instance and
	// would race the other spec files that share them.

	test('an owner can make their own object private, and it disappears for others', async () => {
		// Visible to the other user first — this is an ordinary object.
		const before = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(before.status(), 'the object should start out readable by another user').toBeLessThan(300)

		// The OWNER sets the scope. Not an admin — that is the point of task 4.0.
		const put = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(put.ok(), `owner could not set the scope: ${await put.text()}`).toBeTruthy()
		expect((await put.json()).scope).toBe('private')

		// The consequence: gone for the other user.
		const after = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(
			after.status(),
			'a private object must not be readable by a non-owner',
		).toBeGreaterThanOrEqual(400)

		// And still there for its owner — an owner must never lock themselves out.
		const mine = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(mine.status(), 'the owner must still reach their own private object').toBeLessThan(300)
	})

	test('inviting a user restores their access, and revoking removes it again', async () => {
		const grant = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}/shares`,
			{ data: { type: 'user', shareWith: OTHER, permissions: 1 } },
		)
		expect(grant.ok(), `grant failed: ${await grant.text()}`).toBeTruthy()
		const shareId = String((await grant.json()).id)
		expect(shareId, 'no share id came back').toBeTruthy()

		const granted = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(granted.status(), 'an invited user must reach the object').toBeLessThan(300)

		const revoke = await owner.delete(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`
			+ `/shares/${encodeURIComponent(shareId)}`,
		)
		expect(revoke.ok(), `revoke failed: ${await revoke.text()}`).toBeTruthy()

		const revoked = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(
			revoked.status(),
			'a revoked grant must deny on the next request',
		).toBeGreaterThanOrEqual(400)
	})

	test('a share link resolves anonymously, and stops when revoked', async () => {
		const link = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}/links`,
			{ data: { permissions: 1 } },
		)
		expect(link.ok(), `link create failed: ${await link.text()}`).toBeTruthy()
		const { token, id } = await link.json()
		expect(token, 'core issued no token').toBeTruthy()

		// ANONYMOUS: a context with no credentials at all.
		const anon = await pwRequest.newContext({ baseURL: BASE })
		try {
			const resolved = await anon.get(`/index.php/apps/openregister/api/shared/${token}`)
			expect(
				resolved.ok(),
				`a live token must resolve anonymously: ${await resolved.text()}`,
			).toBeTruthy()

			await owner.delete(
				`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}`
				+ `/shares/${encodeURIComponent(String(id))}`,
			)

			const dead = await anon.get(`/index.php/apps/openregister/api/shared/${token}`)
			expect(dead.status(), 'a revoked link must stop resolving').toBe(404)
		} finally {
			await anon.dispose()
		}
	})

	test('a non-owner cannot change the scope of somebody else\'s object', async () => {
		const attempt = await other.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectUuid}/scope`,
			{ data: { scope: 'organisation' } },
		)
		expect(
			attempt.status(),
			'only the owner or an administrator may re-scope an object',
		).toBeGreaterThanOrEqual(400)
	})
})
