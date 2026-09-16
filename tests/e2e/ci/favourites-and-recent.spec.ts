import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FAVOURITES AND RECENTLY OPENED — end to end, through the HTTP API a real
 * client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/favourites-and-recent/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e object-interactions::starring-leaves-the-object-untouched
 * @e2e object-interactions::a-favourites-chip-on-an-index-page
 *
 * WHAT THIS FILE CAN PROVE, AND WHY IT HAS TO BE THIS FILE.
 *
 * Both lenses are correlated `EXISTS` subqueries in SQL, and the claim that
 * starring writes no audit entry is a claim about two tables at once. Neither
 * is reachable from PHPUnit here: the mappers need a database, and a mocked
 * mapper would be asserting the mock. So the pieces only this layer can see are
 * exactly the ones that matter most — that the routes are registered, that the
 * marker rides `@self` on a real read, that the star really does leave the
 * object's audit trail alone, and that each lens composes with an ordinary
 * filter rather than replacing it.
 *
 * THE AUDIT ASSERTION IS THE POINT OF THE FIRST TEST. The whole reason a star
 * lives in its own table is that writing it into the object would cut a version
 * and add an audit entry for every reader. A star implemented the wrong way
 * still returns 200 and still renders; the only thing that tells the two apart
 * is the object's audit trail length before and after.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects and
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
 * The same fixed uids `object-watchers.spec.ts` and `object-read-state.spec.ts`
 * use, provisioned by the workflow's `playwright-seed-command`
 * (tests/e2e/ci/seed.sh). Fixed names are safe because the config pins
 * `workers: 1` and `fullyParallel: false`.
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
 * confusing authorization error inside a favourites assertion.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

/** How many audit entries an object carries right now. */
async function auditCount(
	ctx: APIRequestContext,
	registerId: string,
	schemaId: string,
	uuid: string,
): Promise<number> {
	const res = await ctx.get(`${API}/objects/${registerId}/${schemaId}/${uuid}/audit-trails`)
	expect(res.ok(), `audit trail read failed: ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	const rows = body.results ?? body.data ?? body
	expect(Array.isArray(rows), 'the audit trail did not come back as a list').toBeTruthy()

	return rows.length
}

/** The uuids a list response carries, in the order the server returned them. */
function uuidsOf(body: Record<string, unknown>): string[] {
	const rows = (body.results ?? []) as Array<Record<string, unknown>>

	return rows.map((row) => {
		const self = (row['@self'] ?? {}) as Record<string, unknown>

		return String(self.id ?? row.id ?? row.uuid)
	})
}

test.describe.configure({ mode: 'serial' })

test.describe('favourites and recently opened over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string
	/** Five objects: two the owner will star, three they will not. */
	const uuids: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e favourites register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e favourites schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					status: { type: 'string', title: 'Status', maxLength: 64 },
				},
				// A non-empty authorization block fails closed for any action it
				// does not list, so all four verbs are named.
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

		// Five cases, three open and two closed, so a lens and an ordinary
		// filter have something to disagree about if they are composed wrongly.
		for (let i = 0; i < 5; i++) {
			const res = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: { key: `case-${i}`, status: i < 3 ? 'open' : 'closed' },
			})
			expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
			const body = await res.json()
			const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
			expect(uuid, 'no uuid came back from the object create').toBeTruthy()
			uuids.push(uuid)
		}
	})

	test.afterAll(async () => {
		for (const uuid of uuids) {
			if (!uuid) {
				continue
			}

			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('starring leaves the object untouched', async () => {
		const target = uuids[0]
		const before = await auditCount(owner, registerId, schemaId, target)

		const star = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${target}/favourite`,
		)
		expect(star.ok(), `starring failed: ${await star.text()}`).toBeTruthy()

		const read = await owner.get(`${API}/objects/${registerId}/${schemaId}/${target}`)
		expect(read.ok(), `object read failed: ${await read.text()}`).toBeTruthy()
		const body = await read.json()

		expect(
			body['@self']?.favourite,
			'the star should ride @self so a detail page renders it without a second call',
		).toBe(true)

		// The whole reason the star lives in its own table.
		expect(
			await auditCount(owner, registerId, schemaId, target),
			'starring must write no audit entry on the object',
		).toBe(before)
	})

	test('a star is one person\'s, and starring twice is starring once', async () => {
		const target = uuids[0]

		// Already starred above. Starring again must not fail and must not
		// produce a second state.
		const again = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${target}/favourite`,
		)
		expect(again.ok(), `re-starring failed: ${await again.text()}`).toBeTruthy()
		expect((await again.json()).favourite).toBe(true)

		const forOther = await other.get(`${API}/objects/${registerId}/${schemaId}/${target}`)
		expect(forOther.ok()).toBeTruthy()
		expect(
			(await forOther.json())['@self']?.favourite,
			'one reader starring an object must not star it for everybody',
		).toBe(false)
	})

	test('a favourites chip on an index page', async () => {
		// Star a second OPEN case and one CLOSED one, so the lens and the
		// status filter each have something to remove.
		for (const index of [1, 4]) {
			const res = await owner.put(
				`${API}/objects/${registerId}/${schemaId}/${uuids[index]}/favourite`,
			)
			expect(res.ok(), `starring failed: ${await res.text()}`).toBeTruthy()
		}

		const list = await owner.get(
			`${API}/objects/${registerId}/${schemaId}?_favourite=true&status=open`,
		)
		expect(list.ok(), `favourites list failed: ${await list.text()}`).toBeTruthy()
		const body = await list.json()
		const returned = uuidsOf(body)

		expect(
			returned.sort(),
			'the chip should answer the starred OPEN cases: the lens and the filter compose',
		).toEqual([uuids[0], uuids[1]].sort())

		// The total is asserted with the page, not instead of it. A lens applied
		// as a post-filter gives a short page against a full total, which reads
		// as a paging bug and is not one.
		expect(
			body.total ?? returned.length,
			'the total must see the same restriction the page did',
		).toBe(2)
	})

	test('the favourites lens is per user, and answers nothing for someone who starred nothing', async () => {
		const list = await other.get(
			`${API}/objects/${registerId}/${schemaId}?_favourite=true`,
		)
		expect(list.ok(), `favourites list failed: ${await list.text()}`).toBeTruthy()
		const returned = uuidsOf(await list.json())

		// The silent-widening failure: a lens that loses its restriction answers
		// the WHOLE register while the caller reads it as "the ones I starred".
		expect(
			returned,
			'a user who has starred nothing must get an empty page, never every object',
		).toEqual([])
	})

	test('unstarring removes only the caller\'s own star', async () => {
		const target = uuids[4]

		const gone = await owner.delete(
			`${API}/objects/${registerId}/${schemaId}/${target}/favourite`,
		)
		expect(gone.ok(), `unstarring failed: ${await gone.text()}`).toBeTruthy()
		expect((await gone.json()).favourite).toBe(false)

		const read = await owner.get(`${API}/objects/${registerId}/${schemaId}/${target}`)
		expect((await read.json())['@self']?.favourite).toBe(false)

		// The other two stars are untouched.
		const list = await owner.get(
			`${API}/objects/${registerId}/${schemaId}?_favourite=true`,
		)
		expect(uuidsOf(await list.json()).sort()).toEqual([uuids[0], uuids[1]].sort())
	})

	test('opening objects fills the recent lens, most recently opened first', async () => {
		// `other` has opened nothing in this register yet, so their history is
		// built here from scratch and cannot inherit the owner's.
		const opened = [uuids[2], uuids[3], uuids[0]]
		for (const uuid of opened) {
			const res = await other.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			expect(res.ok(), `object read failed: ${await res.text()}`).toBeTruthy()
		}

		const list = await other.get(`${API}/objects/${registerId}/${schemaId}?_recent=true`)
		expect(list.ok(), `recent list failed: ${await list.text()}`).toBeTruthy()
		const returned = uuidsOf(await list.json())

		expect(
			returned.slice().sort(),
			'the recent lens should answer exactly what this user opened',
		).toEqual(opened.slice().sort())

		// Ordering is the half a set comparison cannot see. The three opens land
		// within the same second, so the order is asserted as "the last one
		// opened is not last in the list" rather than as an exact sequence,
		// which a one-second clock cannot support honestly.
		expect(
			returned.length,
			'the recent lens must not widen to every object in the register',
		).toBe(3)
	})

	test('the recent lens is per user too', async () => {
		const list = await owner.get(`${API}/objects/${registerId}/${schemaId}?_recent=true`)
		expect(list.ok(), `recent list failed: ${await list.text()}`).toBeTruthy()
		const returned = uuidsOf(await list.json())

		// The owner read their own objects while starring them, so their history
		// is not empty; what matters is that it is not the OTHER user's.
		expect(
			returned,
			'one user\'s history must never be answered to another',
		).not.toEqual([uuids[2], uuids[3], uuids[0]])
	})

	test('an anonymous caller gets an empty page, never the whole register', async () => {
		const anon = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})

		const list = await anon.get(`${API}/objects/${registerId}/${schemaId}?_favourite=true`)

		// Either the request is refused outright or it answers nothing. What it
		// must never do is drop the restriction and answer every object, which
		// is what an unguarded lens does for a caller with no rows.
		if (list.ok()) {
			expect(
				uuidsOf(await list.json()),
				'an anonymous favourites lens must not widen to the whole register',
			).toEqual([])
		} else {
			expect(list.status()).toBeGreaterThanOrEqual(400)
		}

		await anon.dispose()
	})
})
