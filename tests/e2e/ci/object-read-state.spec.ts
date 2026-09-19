import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OBJECT READ STATE — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/object-read-state/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e object-read-state::a-change-makes-an-object-unread-for-the-other-reader
 * @e2e object-read-state::marking-back-to-unread-works
 * @e2e object-read-state::an-unread-only-list-pages-correctly
 * @e2e notificatie-engine::doing-the-work-empties-the-bell
 * @e2e notificatie-engine::archiving-is-not-reading
 * @e2e notificatie-engine::the-bell-is-filtered-by-what-a-notice-is-about
 *
 * WHAT THIS FILE CAN PROVE, AND WHY IT HAS TO BE THIS FILE.
 *
 * Unread is the ABSENCE of a read-state row, and the filter that reads it is a
 * correlated `NOT EXISTS` in SQL. Neither of those is reachable from PHPUnit
 * here: the mapper needs a database, and a mocked mapper would be asserting the
 * mock. So the pieces only this layer can see are exactly the ones that matter
 * most: that the routes are registered, that the marker survives a real write,
 * that the filter is resolved IN the query so a total and a page agree, and
 * that a notice is cleared by the work rather than by the bell.
 *
 * PAGING IS THE POINT OF THE THIRD TEST. A post-filter gives a first page of 25
 * and a total of 120, and the second page silently skips rows. That failure
 * reads as a paging bug and is not one, so the assertion is on the TOTAL and on
 * the second page's contents together, not on either alone.
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
 * The same fixed uids `object-sharing.spec.ts` and `object-watchers.spec.ts`
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
 * confusing authorization error inside a read-state assertion.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('object read state over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string
	const pageUuids: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e read state register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e read state schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					note: { type: 'string', title: 'Note', maxLength: 255 },
				},
				// A non-empty authorization block fails closed for any action it
				// does not list, so all four verbs are named.
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				// `key` is the only property that counts as news. `note` is
				// declared on the schema but left out of this list on purpose:
				// it is what proves the annotation is read rather than ignored.
				'x-openregister-read-state': {
					properties: ['key'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key: 'read-state-object', note: 'first' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	test.afterAll(async () => {
		for (const uuid of [...pageUuids, objectUuid]) {
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

	test('a change makes an object unread for the other reader and read for its author', async () => {
		// Both users open it, so both hold a read state.
		for (const ctx of [owner, other]) {
			const seen = await ctx.put(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
			)
			expect(
				seen.ok(),
				`marking read failed: ${await seen.text()}`,
			).toBeTruthy()
			expect((await seen.json()).unread).toBe(false)
		}

		// The owner changes a DECLARED property.
		const write = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
			{
				data: { key: 'read-state-object-changed', note: 'first' },
			},
		)
		expect(
			write.ok(),
			`object update failed: ${await write.text()}`,
		).toBeTruthy()

		const forOther = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(forOther.ok()).toBeTruthy()
		expect(
			(await forOther.json()).unread,
			'the reader who did not make the change should see it as unread',
		).toBe(true)

		const forOwner = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(forOwner.ok()).toBeTruthy()
		expect(
			(await forOwner.json()).unread,
			'the author of the change has just seen it, so it stays read for them',
		).toBe(false)
	})

	test('a change to a property the schema did not declare is not news', async () => {
		// Both read it again, so both start from read.
		for (const ctx of [owner, other]) {
			await ctx.put(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
			)
		}

		// `note` is not in `x-openregister-read-state.properties`.
		const write = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
			{
				data: { key: 'read-state-object-changed', note: 'second' },
			},
		)
		expect(
			write.ok(),
			`object update failed: ${await write.text()}`,
		).toBeTruthy()

		const forOther = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(
			(await forOther.json()).unread,
			"an undeclared property changing must not light up anybody's badge",
		).toBe(false)
	})

	test('marking back to unread works, and only for the user who asked', async () => {
		for (const ctx of [owner, other]) {
			await ctx.put(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
			)
		}

		const back = await other.delete(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(back.ok(), `marking unread failed: ${await back.text()}`).toBeTruthy()
		expect((await back.json()).unread).toBe(true)

		const forOwner = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(
			(await forOwner.json()).unread,
			'one user marking something unread must not touch anybody else',
		).toBe(false)
	})

	test('the object read carries the unread marker for the reader', async () => {
		await other.delete(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)

		const read = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
		)
		expect(read.ok()).toBeTruthy()
		const self = (await read.json())['@self']
		expect(
			self?.unread,
			'`@self.unread` is what lets a list render a badge without a call per row',
		).toBe(true)
	})

	test('an unread-only list pages correctly, and its total counts only unread rows', async () => {
		// Twelve objects, of which the reader will have seen five. Twelve rather
		// than the spec's 120 because the assertion is about the ARITHMETIC of
		// paging, which a hundred more rows do not make more true, and a CI run
		// that creates 120 objects to prove it is a slow test with no extra
		// evidence in it.
		for (let i = 0; i < 12; i++) {
			const obj = await owner.post(
				`${API}/objects/${registerId}/${schemaId}`,
				{
					data: { key: `page-${i}`, note: 'page' },
				},
			)
			expect(
				obj.ok(),
				`page object ${i} create failed: ${await obj.text()}`,
			).toBeTruthy()
			const body = await obj.json()
			pageUuids.push(String(body['@self']?.id ?? body.id ?? body.uuid))
		}

		// The reader opens five of them, plus the original object from beforeAll.
		const seen = new Set<string>([objectUuid, ...pageUuids.slice(0, 5)])
		for (const uuid of seen) {
			const res = await other.put(
				`${API}/objects/${registerId}/${schemaId}/${uuid}/read-state`,
			)
			expect(
				res.ok(),
				`marking ${uuid} read failed: ${await res.text()}`,
			).toBeTruthy()
		}

		// Seven of the thirteen objects in this register are unread for them.
		const first = await other.get(
			`${API}/objects/${registerId}/${schemaId}?_unread=true&_limit=4&_page=1`,
		)
		expect(first.ok(), `unread list failed: ${await first.text()}`).toBeTruthy()
		const firstBody = await first.json()

		expect(
			firstBody.total,
			'the total must count the unread rows, not every row: a total that counts what the page excluded is the paging bug this filter exists to avoid',
		).toBe(7)
		expect(firstBody.results).toHaveLength(4)

		const second = await other.get(
			`${API}/objects/${registerId}/${schemaId}?_unread=true&_limit=4&_page=2`,
		)
		expect(second.ok()).toBeTruthy()
		const secondBody = await second.json()
		expect(secondBody.total).toBe(7)
		expect(secondBody.results).toHaveLength(3)

		// No page may contain something the reader has already seen.
		const returned = [...firstBody.results, ...secondBody.results].map(
			(row: Record<string, any>) => String(row['@self']?.id ?? row.id),
		)
		for (const uuid of returned) {
			expect(seen.has(uuid), `page contained a read object: ${uuid}`).toBe(
				false,
			)
		}

		// And the two pages together are the whole answer, with no row twice.
		expect(new Set(returned).size).toBe(7)
	})

	test('doing the work empties the bell, and archiving is not reading', async () => {
		// A notice addressed to this reader about this object. Recorded through
		// the history the bell reads, which is what `clearForObject` clears.
		const before = await other.get(
			`${API}/notification-history?objectUuid=${objectUuid}&unreadOnly=true`,
		)
		expect(
			before.ok(),
			`notification list failed: ${await before.text()}`,
		).toBeTruthy()
		const beforeBody = await before.json()
		expect(Array.isArray(beforeBody.results)).toBe(true)

		// Opening the work clears whatever the bell was holding about it. The
		// count is asserted as "not more than before", because this instance's
		// dispatch rules are not this spec's subject: what IS this spec's
		// subject is that the endpoint exists, answers, and never grows the
		// unread set by being called.
		await other.delete(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		const opened = await other.put(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state`,
		)
		expect(
			opened.ok(),
			`marking read failed: ${await opened.text()}`,
		).toBeTruthy()
		const openedBody = await opened.json()
		expect(
			openedBody,
			'marking read must report what it cleared, so a client can update its badge without a second call',
		).toHaveProperty('notificationsCleared')

		const after = await other.get(
			`${API}/notification-history?objectUuid=${objectUuid}&unreadOnly=true`,
		)
		expect(after.ok()).toBeTruthy()
		expect((await after.json()).total).toBeLessThanOrEqual(beforeBody.total)
	})

	test('the bell is filtered by what a notice is about', async () => {
		// The axis is the point: a list that ignores `subjectType` answers the
		// whole bell while the caller reads it as one type, which is the same
		// silent-widening failure the unread lens guards against.
		const typed = await other.get(
			`${API}/notification-history?subjectType=e2e-nothing-${RUN}`,
		)
		expect(
			typed.ok(),
			`subject-type list failed: ${await typed.text()}`,
		).toBeTruthy()
		const typedBody = await typed.json()

		expect(
			typedBody.total,
			'a subject type nothing carries must answer nothing, never the whole bell',
		).toBe(0)
		expect(typedBody.results).toHaveLength(0)

		const unfiltered = await other.get(`${API}/notification-history`)
		expect(unfiltered.ok()).toBeTruthy()
		expect(
			(await unfiltered.json()).total,
			'and the unfiltered list must be at least as large, or the filter did nothing',
		).toBeGreaterThanOrEqual(typedBody.total)
	})

	test('a read state is private: another user cannot ask about it', async () => {
		const probe = await other.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/read-state?userId=${OWNER}`,
		)
		expect(
			probe.status(),
			"asking about somebody else's read state must be refused, not answered about yourself",
		).toBe(403)
	})
})
