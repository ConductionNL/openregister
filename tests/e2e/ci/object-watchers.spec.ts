import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OBJECT WATCHERS — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/object-watchers/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e object-interactions::following-leaves-the-object-untouched
 * @e2e object-interactions::a-user-without-read-cannot-watch
 * @e2e object-interactions::a-team-lead-lists-who-follows-a-case
 * @e2e notificatie-engine::the-block-is-validated-at-schema-save
 *
 * WHAT THIS FILE CAN PROVE, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is REACHABLE: the routes are registered, the auth
 * attributes let a non-admin through, the controller reaches the service, the
 * markers come back on an ordinary read, and the lens narrows a list. Those are
 * exactly the failures a green unit suite hides — a route missing from
 * `appinfo/routes.php` is a 404 no PHPUnit test would notice.
 *
 * It does NOT assert that a notification is DELIVERED to a watcher. Delivery
 * runs through the queue and a background job, so an assertion here would be a
 * timing race dressed up as coverage, and a spec that polls until it gives up
 * is worse than no spec: it is red for reasons that have nothing to do with
 * watchers. Recipient resolution, the read check at dispatch and the healing of
 * the list are asserted in
 * tests/Unit/Service/Notification/NotificationRecipientResolverWatchersTest.php,
 * named here so nobody has to take this comment's word for it. What this file
 * does assert about notifications is the half that IS synchronous and
 * HTTP-visible: the schema refuses to save a malformed watchers block.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and object and
 * removes all three. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same fixed uids `object-sharing.spec.ts` uses, provisioned by the
 * workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh). Fixed names are
 * safe because the config pins `workers: 1` and `fullyParallel: false`.
 */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

const API = '/index.php/apps/openregister/api'

/** Build an API context authenticated as one user. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
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
 * Assert a seeded account is actually usable before any test leans on it, so a
 * seed that silently did not run says so here rather than surfacing later as a
 * confusing authorization error inside a watcher assertion.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('object watchers over HTTP', () => {
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

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e watch register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e watch schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				// A non-empty authorization block fails closed for any action it
				// does not list, so all four verbs are named. `update` is what
				// lets the follower see the watcher list and the count.
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				// The recipient block this change adds, declared on the schema
				// rather than dispatched by an app (ADR-031).
				'x-openregister-notifications': {
					keyChanged: {
						trigger: { type: 'updated' },
						recipients: [{ watchers: true }],
						channels: ['nc-notification'],
						subject: 'The object you follow changed',
					},
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key: 'followed-object' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	test.afterAll(async () => {
		if (objectUuid) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${objectUuid}`)
			await admin.delete(`${API}/deleted/${objectUuid}`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a schema may declare a rule addressed to the watchers, and a malformed one is refused', async () => {
		// The schema in beforeAll already carries `{"watchers": true}` and saved,
		// which is half the scenario. The other half is that the validator is
		// actually looking: a rule addressed to the string "yes" addresses
		// nobody, silently, every time it fires.
		const bad = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e watch bad schema ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key' } },
				'x-openregister-notifications': {
					keyChanged: {
						trigger: { type: 'updated' },
						recipients: [{ watchers: 'yes' }],
						channels: ['nc-notification'],
						subject: 'nope',
					},
				},
			},
		})

		expect(
			bad.status(),
			`a watchers block spelled "yes" must be refused, got ${bad.status()}: ${await bad.text()}`,
		).toBe(422)
	})

	test('following an object sets the marker and leaves the object where it was', async () => {
		const before = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(before.ok(), 'the object should start out readable').toBeTruthy()
		const beforeSelf = (await before.json())['@self']
		expect(beforeSelf.watching, 'nobody follows it yet').toBe(false)

		const follow = await other.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watch`,
		)
		expect(follow.ok(), `following failed: ${await follow.text()}`).toBeTruthy()

		const after = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		const afterBody = await after.json()
		expect(afterBody['@self'].watching, 'the follower sees their own marker').toBe(true)
		expect(afterBody['@self'].watcherCount, 'an editor sees the audience size').toBe(1)
		// The object itself is untouched: following is stored beside it, so the
		// stored data is the same data.
		expect(afterBody.key, 'following must not rewrite the object').toBe('followed-object')

		// And it is somebody ELSE's marker, not a global flag: the owner does not
		// follow the object and must not be told that they do.
		const asOwner = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(
			(await asOwner.json())['@self'].watching,
			'the marker is per user, not per object',
		).toBe(false)
	})

	test('following twice is following once', async () => {
		const again = await other.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watch`,
		)
		expect(again.ok(), `the repeat failed: ${await again.text()}`).toBeTruthy()

		const list = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watchers`,
		)
		expect(list.ok(), `the watcher list failed: ${await list.text()}`).toBeTruthy()
		expect((await list.json()).total, 'the second follow wrote no second row').toBe(1)
	})

	test('an editor lists who follows the object, with the time each subscribed', async () => {
		const list = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watchers`,
		)
		expect(list.ok(), `the watcher list failed: ${await list.text()}`).toBeTruthy()

		const body = await list.json()
		expect(body.results.map((w: { userId: string }) => w.userId)).toContain(OTHER)
		expect(
			body.results[0].created,
			'the list says when, not only who',
		).toBeTruthy()
	})

	test('the watching lens returns the followed object, and stops once it is unfollowed', async () => {
		const lens = await other.get(
			`${API}/objects/${registerId}/${schemaId}?_watching=true&_limit=100`,
		)
		expect(lens.ok(), `the lens failed: ${await lens.text()}`).toBeTruthy()
		const ids = (await lens.json()).results.map(
			(o: Record<string, { id?: string }> & { id?: string }) =>
				o['@self']?.id ?? o.id,
		)
		expect(ids, 'a followed object is in the lens').toContain(objectUuid)

		const unfollow = await other.delete(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watch`,
		)
		expect(unfollow.status(), 'unfollowing answers 204').toBe(204)

		const afterLens = await other.get(
			`${API}/objects/${registerId}/${schemaId}?_watching=true&_limit=100`,
		)
		const afterIds = (await afterLens.json()).results.map(
			(o: Record<string, { id?: string }> & { id?: string }) =>
				o['@self']?.id ?? o.id,
		)
		expect(afterIds, 'an unfollowed object leaves the lens').not.toContain(objectUuid)

		const read = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(
			(await read.json())['@self'].watching,
			'the marker is cleared on the next read',
		).toBe(false)
	})

	test('a user who cannot read the object cannot follow it, and is told nothing about it', async () => {
		const priv = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(priv.ok(), `could not make the object private: ${await priv.text()}`).toBeTruthy()

		try {
			const attempt = await other.put(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watch`,
			)
			// 404, not 403: the endpoint must not confirm that the id exists.
			expect(
				attempt.status(),
				'an unreadable object answers 404, never 403',
			).toBe(404)

			// And the refusal wrote nothing: the owner's list is still empty.
			const list = await owner.get(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/watchers`,
			)
			expect((await list.json()).total, 'a refused follow writes no row').toBe(0)
		} finally {
			await owner.put(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/scope`,
				{ data: { scope: 'organisation' } },
			)
		}
	})
})
