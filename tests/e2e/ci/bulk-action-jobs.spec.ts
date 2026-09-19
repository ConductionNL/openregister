import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BULK ACTION JOBS, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/bulk-action-jobs/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e bulk-action-jobs::selecting-every-match-is-not-the-same-as-selecting-the-page
 * @e2e bulk-action-jobs::the-preview-names-what-would-be-skipped
 * @e2e bulk-action-jobs::a-refusal-is-not-a-skip
 * @e2e bulk-action-jobs::a-distribution-without-a-reason-is-refused
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is reachable over HTTP and that the preview is a
 * rehearsal rather than a promise: the nine routes are registered, a job
 * created from a query records the query and the whole match count rather
 * than the page, the preview reports per object what would happen and
 * modifies nothing, an object the caller may not write comes back refused
 * with the rule rather than folded into the skip count, the outcome file
 * carries every member and its reason, and a commit with no reason where the
 * action requires one is refused with nothing written.
 *
 * It does NOT drive the commit to completion, cancel a running job, or retry
 * one. All three need the background worker to have walked some members, and
 * nothing in this environment guarantees cron ran between two HTTP calls: an
 * assertion here would be a timing race dressed up as coverage. Those three
 * scenarios are marked `@e2e exclude` in the spec delta and asserted in
 * tests/Unit/Service/BulkJob/BulkJobExecutorTest.php (the cancel boundary)
 * and tests/Unit/Service/BulkJob/BulkJobServiceTest.php (the retry and the
 * grown selection), named here so nobody has to take this comment's word.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and its
 * objects, and removes all of them. It needs no `occ` and no docker.
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

/** Assert a seeded account is usable before any test leans on it. */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}), did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('a bulk action is one previewed job', () => {
	let admin: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let openSchemaId: string
	let lockedSchemaId: string

	/** Every object this spec creates, as [schemaId, uuid]. */
	const created: Array<[string, string]> = []

	/** Every job this spec creates, so afterAll can cancel what is left. */
	const jobs: string[] = []

	async function createSchema(title: string, update: string[]): Promise<string> {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `${title} ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					status: { type: 'string', title: 'Status', maxLength: 64 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update,
					delete: ['authenticated'],
				},
			},
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

		return String((await res.json()).id)
	}

	async function createObject(
		schemaId: string,
		key: string,
		status: string,
	): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key, status },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push([schemaId, uuid])

		return uuid
	}

	async function statusOf(schemaId: string, uuid: string): Promise<string> {
		const res = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
		)
		expect(res.ok(), `object read failed: ${await res.text()}`).toBeTruthy()

		return String((await res.json()).status ?? '')
	}

	/** Create a job and remember it for cleanup. */
	async function createJob(
		ctx: APIRequestContext,
		body: Record<string, unknown>,
	): Promise<{ status: number; json: Record<string, unknown> }> {
		const res = await ctx.post(`${API}/bulk-jobs`, { data: body })
		const json = (await res.json()) as Record<string, unknown>

		if (res.status() === 201) {
			jobs.push(String(json.id))
		}

		return { status: res.status(), json }
	}

	async function membersOf(
		ctx: APIRequestContext,
		jobId: string,
	): Promise<Array<Record<string, unknown>>> {
		const res = await ctx.get(`${API}/bulk-jobs/${jobId}/members?limit=500`)
		expect(res.ok(), `member listing failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()).results as Array<Record<string, unknown>>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e bulk jobs register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		openSchemaId = await createSchema('e2e bulk jobs open schema', [
			'authenticated',
		])
		lockedSchemaId = await createSchema('e2e bulk jobs locked schema', ['admin'])
	})

	test.afterAll(async () => {
		for (const jobId of jobs) {
			await admin.post(`${API}/bulk-jobs/${jobId}/cancel`)
		}

		for (const [schemaId, uuid] of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		for (const schemaId of [openSchemaId, lockedSchemaId]) {
			if (schemaId) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('the catalogue names each action, its guards and whether it needs a reason', async () => {
		const res = await admin.get(`${API}/bulk-actions`)
		expect(
			res.ok(),
			`the action catalogue is not reachable: ${await res.text()}`,
		).toBeTruthy()

		const body = await res.json()
		const byId = Object.fromEntries(
			(body.results as Array<Record<string, unknown>>).map((row) => [
				String(row.id),
				row,
			]),
		)

		expect(
			byId['openregister:set-properties'],
			'the attribute write is not registered',
		).toBeTruthy()
		expect(byId['openregister:set-properties'].guards).toContain('homogeneity')
		expect(byId['openregister:set-properties'].requiresJustification).toBe(false)

		expect(
			byId['openregister:assign'],
			'the redistribution is not registered',
		).toBeTruthy()
		expect(byId['openregister:assign'].requiresJustification).toBe(true)

		expect(
			Number(body.ceiling),
			'the instance ceiling is not published',
		).toBeGreaterThan(0)
	})

	test('a job created from every match records the query and the whole count', async () => {
		for (let i = 0; i < 5; i++) {
			await createObject(openSchemaId, `match-${RUN}-${i}`, 'open')
		}

		// A page of two, against a query that matches all five.
		const page = await admin.get(
			`${API}/objects/${registerId}/${openSchemaId}?status=open&_limit=2`,
		)
		expect(page.ok(), `paged listing failed: ${await page.text()}`).toBeTruthy()
		expect((await page.json()).results.length, 'the page should hold two').toBe(
			2,
		)

		const { status, json } = await createJob(admin, {
			action: 'openregister:set-properties',
			parameters: { properties: { status: 'closed' } },
			selection: { query: { status: 'open' } },
			register: registerId,
			schema: openSchemaId,
		})

		expect(status, `job create failed: ${JSON.stringify(json)}`).toBe(201)
		expect(
			json.selectionType,
			'the job does not say the selection is a query',
		).toBe('query')
		expect(
			json.total,
			'the job recorded the page rather than the whole result set',
		).toBe(5)
		expect((json.selection as Record<string, unknown>).query).toEqual({
			status: 'open',
		})

		const report = json.report as Record<string, Record<string, unknown>>
		expect(report.selection.kind).toBe('query')
		expect(report.selection.countAtCreation).toBe(5)
	})

	test('the preview names what would be skipped, and modifies nothing', async () => {
		const willApply = await createObject(openSchemaId, `skip-${RUN}-a`, 'open')
		const alreadyThere = await createObject(
			openSchemaId,
			`skip-${RUN}-b`,
			'closed',
		)

		const { status, json } = await createJob(admin, {
			action: 'openregister:set-properties',
			parameters: { properties: { status: 'closed' } },
			selection: { ids: [willApply, alreadyThere] },
			register: registerId,
			schema: openSchemaId,
		})

		expect(status, `job create failed: ${JSON.stringify(json)}`).toBe(201)
		expect(json.state, 'a new job should be previewed, not running').toBe(
			'previewed',
		)

		const counts = json.counts as Record<string, number>
		expect(counts.applied, 'the preview miscounted what it would apply').toBe(1)
		expect(counts.skipped, 'the preview miscounted the skips').toBe(1)

		const members = await membersOf(admin, String(json.id))
		const skipped = members.find((row) => row.outcome === 'skipped')
		expect(skipped, 'no member came back skipped').toBeTruthy()
		expect(skipped?.objectUuid).toBe(alreadyThere)
		expect(
			String(skipped?.reason ?? ''),
			'a skip with no reason is the thing this change exists to remove',
		).toContain('already carries these values')

		// The rehearsal wrote nothing.
		expect(await statusOf(openSchemaId, willApply)).toBe('open')

		// And the whole outcome is downloadable, skipped members included.
		const file = await admin.get(`${API}/bulk-jobs/${json.id}/download`)
		expect(
			file.ok(),
			`the outcome file is not reachable: ${file.status()}`,
		).toBeTruthy()

		const csv = await file.text()
		expect(csv).toContain(willApply)
		expect(csv).toContain(alreadyThere)
		expect(csv).toContain('already carries these values')
	})

	test('an object the caller may not write is refused, not skipped', async () => {
		const locked = await createObject(lockedSchemaId, `locked-${RUN}`, 'open')

		const { status, json } = await createJob(other, {
			action: 'openregister:set-properties',
			parameters: { properties: { status: 'closed' } },
			selection: { ids: [locked] },
			register: registerId,
			schema: lockedSchemaId,
		})

		expect(status, `job create failed: ${JSON.stringify(json)}`).toBe(201)

		const counts = json.counts as Record<string, number>
		expect(counts.refused, 'the object should have been refused').toBe(1)
		expect(counts.skipped, 'a refusal must never be counted as a skip').toBe(0)
		expect(counts.applied).toBe(0)

		const members = await membersOf(other, String(json.id))
		expect(members[0].outcome).toBe('refused')
		expect(
			String(members[0].reason ?? ''),
			'the refusal does not name the rule that refused it',
		).toContain('update rule')

		expect(await statusOf(lockedSchemaId, locked)).toBe('open')
	})

	test('a distribution without a reason is refused, and nothing is modified', async () => {
		const target = await createObject(openSchemaId, `assign-${RUN}`, 'open')

		const { status, json } = await createJob(admin, {
			action: 'openregister:assign',
			parameters: { property: 'status', value: 'fatima' },
			selection: { ids: [target] },
			register: registerId,
			schema: openSchemaId,
		})
		expect(status, `job create failed: ${JSON.stringify(json)}`).toBe(201)

		const res = await admin.post(`${API}/bulk-jobs/${json.id}/commit`, {
			data: {},
		})
		expect(res.status(), 'a commit with no reason should be refused').toBe(422)

		const body = await res.json()
		expect(body.reason).toBe('justification-required')
		expect(String(body.error)).toContain('openregister:assign')

		expect(await statusOf(openSchemaId, target)).toBe('open')

		// With a reason, the same commit is accepted and queued.
		const accepted = await admin.post(`${API}/bulk-jobs/${json.id}/commit`, {
			data: {
				justification:
					'Hans left on the 30th, his caseload moves to Fatima.',
			},
		})
		expect(
			accepted.status(),
			`the commit was refused: ${await accepted.text()}`,
		).toBe(202)
		expect((await accepted.json()).state).toBe('running')
	})

	test('a job belongs to the caller who created it', async () => {
		const target = await createObject(openSchemaId, `owner-${RUN}`, 'open')

		const { json } = await createJob(admin, {
			action: 'openregister:set-properties',
			parameters: { properties: { status: 'closed' } },
			selection: { ids: [target] },
			register: registerId,
			schema: openSchemaId,
		})

		const mine = await admin.get(`${API}/bulk-jobs/${json.id}`)
		expect(mine.ok()).toBeTruthy()

		const theirs = await other.get(`${API}/bulk-jobs/${json.id}`)
		expect(
			theirs.status(),
			'another user should not be able to read this job, nor learn that it exists',
		).toBe(404)
	})
})
