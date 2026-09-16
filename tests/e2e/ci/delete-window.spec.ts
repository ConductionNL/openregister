import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DELETE WINDOW AND RECORDED DESTRUCTION — end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/delete-window-and-recorded-destruction/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e deletion-audit-trail::a-caseworker-sees-how-long-they-have
 * @e2e deletion-audit-trail::the-refusal-says-where-the-object-went
 * @e2e deletion-audit-trail::a-caseworker-cannot-destroy
 * @e2e deletion-audit-trail::the-record-outlives-the-object
 * @e2e deletion-audit-trail::what-hangs-off-the-object-goes-with-it
 * @e2e deletion-audit-trail::the-two-dates-are-readable-and-separate
 *
 * WHAT THIS FILE CAN PROVE, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is REACHABLE over HTTP: the two new routes are
 * registered, the window comes back on the trash listing and in the refusal a
 * reader gets, a caller without the `destroy` right is refused by name, the
 * window itself refuses a premature destroy, and the destruction record is
 * readable AFTER the object it describes is gone. Those are exactly the
 * failures a green unit suite hides: a route missing from `appinfo/routes.php`
 * is a 404 no PHPUnit test would notice.
 *
 * It does NOT drive the AVG retention pass or place a legal hold. Both run in a
 * background job against catalogue rows this spec cannot seed over the API, so
 * an assertion here would be a timing race dressed up as coverage. The spec
 * delta marks those three scenarios `@e2e exclude` for that reason, and they
 * are asserted in
 * tests/Unit/Service/Deletion/RetentionClockServiceTest.php — named here so
 * nobody has to take this comment's word for it.
 *
 * What it DOES assert about the two clocks is the half that is synchronous and
 * HTTP-visible: both clocks come back, separately, each naming the rule that
 * produced it, including when a rule produced no date. A single merged date, or
 * a silent absence, is the failure this change exists to prevent.
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
 * The same fixed uids the sharing and watcher specs use, provisioned by the
 * workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh).
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
 * confusing authorization error inside a destruction assertion.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('the delete window and the recorded destruction over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string

	/** Every object this spec creates, so afterAll can clear the trash. */
	const created: string[] = []

	/** Create an object and remember it for cleanup. */
	async function createObject(key: string): Promise<string> {
		const res = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push(uuid)

		return uuid
	}

	/** Find one object's row in the trash listing. */
	async function trashRowFor(uuid: string): Promise<Record<string, unknown>> {
		const res = await admin.get(`${API}/deleted?limit=200`)
		expect(res.ok(), `trash listing failed: ${await res.text()}`).toBeTruthy()

		const rows = (await res.json()).results as Array<Record<string, unknown>>
		const row = rows.find((entry) => {
			const self = entry['@self'] as Record<string, unknown> | undefined
			return String(self?.id ?? entry.uuid ?? '') === uuid
		})

		expect(row, `object ${uuid} is not in the trash listing`).toBeTruthy()

		return row as Record<string, unknown>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e delete window register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e delete window schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				// `destroy` is granted to the admin group only, which is what
				// makes "only the record manager may delete a document"
				// expressible at all. `e2e-other` holds delete and not destroy,
				// which is exactly the caller the refusal is written for.
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
					destroy: ['admin'],
				},
				// A 30-day recovery window, stated on the schema rather than
				// left to the instance default.
				archive: {
					deleteRetention: 30,
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)
	})

	test.afterAll(async () => {
		for (const uuid of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('the trash names the destroyable-from date and the days remaining', async () => {
		const uuid = await createObject('window-row')

		const del = await owner.delete(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
		)
		expect(del.ok(), `delete failed: ${await del.text()}`).toBeTruthy()

		const row = await trashRowFor(uuid)
		const window = row.deletionWindow as Record<string, unknown>

		expect(window, 'the trash row carries no window at all').toBeTruthy()
		expect(
			String(window.destroyableFrom ?? ''),
			'the destroyable-from date is missing from the trash listing',
		).toMatch(/^\d{4}-\d{2}-\d{2}/)

		// The schema declares 30 days, so the row deleted a moment ago has 30
		// left. A hard-coded window would say 31, which is what this used to do.
		expect(
			window.daysRemaining,
			'the window is not the one the schema declared',
		).toBe(30)
		expect(window.retentionDays).toBe(30)
		expect(window.retentionSource).toBe('schema')
		expect(window.lapsed).toBe(false)
	})

	test('reading a deleted object says it is deleted and until when', async () => {
		const uuid = await createObject('refusal-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const read = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
		)
		expect(read.status(), 'a deleted object should not read as present').toBe(
			404,
		)

		const body = await read.json()
		expect(
			body.code,
			`the refusal does not say the object is deleted: ${JSON.stringify(body)}`,
		).toBe('OBJECT_DELETED')
		expect(String(body.message)).toContain('restored until')
		expect(String(body.deleted?.destroyableFrom ?? '')).toMatch(
			/^\d{4}-\d{2}-\d{2}/,
		)
	})

	test('a restore inside the window brings the object back in one act', async () => {
		const uuid = await createObject('restore-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const restore = await owner.post(`${API}/deleted/${uuid}/restore`)
		expect(restore.ok(), `restore failed: ${await restore.text()}`).toBeTruthy()

		const restored = await restore.json()
		expect(restored.success).toBe(true)
		expect(
			restored.restoredWithin?.daysRemaining,
			'the restore does not say which window it happened inside',
		).toBe(30)

		const read = await owner.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
		)
		expect(
			read.ok(),
			'the restored object should read as present again',
		).toBeTruthy()
	})

	test('a caseworker without the destroy right is refused by name', async () => {
		const uuid = await createObject('refused-destroy-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const attempt = await other.delete(`${API}/deleted/${uuid}?force=true`)
		expect(
			attempt.status(),
			'a caller without the destroy right must be refused',
		).toBe(403)

		const body = await attempt.json()
		expect(
			String(body.rule ?? ''),
			`the refusal does not name the rule: ${JSON.stringify(body)}`,
		).toContain('destroy-right')

		// And the object is unchanged: it is still in the trash.
		await trashRowFor(uuid)
	})

	test('destroying inside the window is refused until the window is waived', async () => {
		const uuid = await createObject('premature-destroy-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const premature = await admin.delete(`${API}/deleted/${uuid}`)
		expect(
			premature.status(),
			'destroying inside an open recovery window must be refused',
		).toBe(409)

		const body = await premature.json()
		expect(body.rule).toBe('recovery-window-open')
		expect(body.deletionWindow?.daysRemaining).toBe(30)

		// Still there.
		await trashRowFor(uuid)
	})

	test('the preview names what goes, and both clocks come back separately', async () => {
		const uuid = await createObject('preview-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const preview = await admin.get(`${API}/deleted/${uuid}/destruction-preview`)
		expect(preview.ok(), `preview failed: ${await preview.text()}`).toBeTruthy()

		const body = await preview.json()

		// This schema declares no destruction scope, so nothing extra goes with
		// the object and the preview says so rather than staying silent.
		expect(body.preview?.scope, 'the preview carries no scope at all').toEqual(
			[],
		)
		expect(body.preview?.total).toBe(0)
		expect(body.preview?.destroyable).toBe(true)

		// Two clocks, never one merged date. Both are present, and each names
		// its rule even when that rule produced no date.
		expect(body.clocks?.avg, 'the AVG clock is missing entirely').toBeTruthy()
		expect(
			body.clocks?.archive,
			'the Archiefwet clock is missing entirely',
		).toBeTruthy()
		expect(
			String(body.clocks.avg.rule ?? ''),
			'the AVG clock names no rule',
		).not.toBe('')
		expect(
			String(body.clocks.archive.rule ?? ''),
			'the Archiefwet clock names no rule',
		).not.toBe('')
		expect(body.clocks.legalHold).toBe(false)
	})

	test('the destruction record outlives the object it describes', async () => {
		const uuid = await createObject('destroyed-row')
		await owner.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)

		const destroy = await admin.delete(`${API}/deleted/${uuid}?force=true`)
		expect(destroy.ok(), `destroy failed: ${await destroy.text()}`).toBeTruthy()

		const destroyed = await destroy.json()
		expect(
			destroyed.destruction?.destroyedBy,
			'the destruction names no actor',
		).toBeTruthy()
		expect(
			destroyed.destruction?.destroyedAt,
			'the destruction names no time',
		).toBeTruthy()
		expect(destroyed.destruction?.rule).toBe('destroy-right-granted')

		// The object itself is gone.
		const read = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
		)
		expect(read.status(), 'the destroyed object still resolves').toBe(404)

		// And the record is still readable, through a door that does not go
		// through the object.
		const record = await admin.get(`${API}/deleted/${uuid}/destruction`)
		expect(
			record.ok(),
			`destruction record read failed: ${await record.text()}`,
		).toBeTruthy()

		const body = await record.json()
		expect(
			body.total,
			`no destruction record survived for ${uuid}: ${JSON.stringify(body)}`,
		).toBeGreaterThan(0)
		expect(String(body.results[0].action)).toBe('object.destroyed')
	})
})
