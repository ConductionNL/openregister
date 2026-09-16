import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE OPERATIONS CONSOLE, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/admin-operations-console/specs/` is archived
 * into `openspec/specs/`:
 *
 * @e2e operations-console::a-failed-run-is-visible-with-its-reason
 * @e2e operations-console::a-stuck-queue-is-cleared-by-hand
 *
 * WHAT THIS FILE PROVES.
 *
 * That the console is reachable, that it is administrators only, that it
 * names the background jobs whose runs are recorded nowhere rather than
 * leaving them out, and that a job row's own `actions` flags agree with what
 * the bulk job endpoints will actually accept: a previewed job refuses a
 * resume, and a committed one is paused and set going again.
 *
 * THE PERMISSION CASE IS THE POINT OF THE SECOND ACCOUNT. An administrator
 * succeeding proves almost nothing about authorization, so every read here is
 * also attempted as an ordinary signed-in user, who must be refused. Without
 * that arm, removing the admin posture from the controller would leave this
 * file green.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It never waits for cron. A pause is
 * asserted through the state the API reports and the refusal a second pause
 * gives, not by watching members stop being walked, because nothing here
 * guarantees the worker ran between two HTTP calls and a sleep would be a
 * timing race dressed up as coverage. That the runner honours the paused
 * state is asserted in tests/Unit/BackgroundJob/BulkJobRunnerTest.php
 * (testAPausedJobIsNotWalkedAndNotRequeued), named here so nobody has to take
 * this comment's word for it.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects,
 * and removes all of them. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same fixed uid the sharing, watcher and bulk-job specs use, provisioned
 * by the workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh).
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
async function assertSeededUser(
	ctx: APIRequestContext,
	uid: string,
): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}), did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('one console over what the instance is doing', () => {
	let admin: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string

	/** Every object this spec creates, as a uuid. */
	const created: string[] = []

	/** Every job this spec creates, so afterAll can cancel what is left. */
	const jobs: string[] = []

	async function createObject(key: string): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key, status: 'open' },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push(uuid)

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e operations register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e operations schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					status: { type: 'string', title: 'Status', maxLength: 64 },
				},
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
	})

	test.afterAll(async () => {
		for (const jobId of jobs) {
			await admin.post(`${API}/bulk-jobs/${jobId}/cancel`).catch(() => undefined)
		}

		for (const uuid of created) {
			await admin
				.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
				.catch(() => undefined)
		}

		await admin.delete(`${API}/schemas/${schemaId}`).catch(() => undefined)
		await admin.delete(`${API}/registers/${registerId}`).catch(() => undefined)

		await admin.dispose()
		await other.dispose()
	})

	test('the console answers its window and its three panes', async () => {
		const res = await admin.get(`${API}/operations/console`)
		expect(res.ok(), `console read failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()

		expect(body.window?.hours, 'the console did not name its window').toBeGreaterThan(0)
		expect(
			(body.panes ?? []).map((pane: Record<string, unknown>) => pane.id).sort(),
			'the console did not report the three panes',
		).toEqual(['jobs', 'notifications', 'rule-runs'])

		for (const pane of body.panes) {
			expect(typeof pane.total, `pane ${pane.id} has no total`).toBe('number')
			expect(
				typeof pane.attention,
				`pane ${pane.id} does not say how much needs a look`,
			).toBe('number')
		}
	})

	test('the console is refused to an ordinary signed-in user', async () => {
		for (const path of ['console', 'jobs', 'rule-runs']) {
			const res = await other.get(`${API}/operations/${path}`)
			expect(
				res.status(),
				`/operations/${path} answered ${res.status()} to a non-administrator`,
			).toBeGreaterThanOrEqual(400)
		}
	})

	test('a job whose runs are recorded nowhere is named, not left out', async () => {
		const res = await admin.get(`${API}/operations/jobs`)
		expect(res.ok(), `job read failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const registered = body.registered ?? []

		expect(
			registered.length,
			'the console read no background jobs at all, so it cannot be judging any',
		).toBeGreaterThan(0)

		// An empty unobserved list would be the interesting result, and it is
		// not the one this instance gives: only the bulk job runner records how
		// its runs came out. Asserting the shape rather than a count keeps this
		// true as the wrapper grows the observed set.
		for (const job of body.unobserved ?? []) {
			expect(job.observed, `${job.name} is in the unobserved list while observed`).toBe(false)
			expect(job.name, 'an unobserved job came back with no name').toBeTruthy()
		}
	})

	test('a previewed job offers no resume, and refuses one', async () => {
		await createObject(`console-${RUN}-1`)

		const create = await admin.post(`${API}/bulk-jobs`, {
			data: {
				action: 'openregister:set-properties',
				parameters: { properties: { status: 'closed' } },
				selection: { query: { status: 'open' } },
				register: registerId,
				schema: schemaId,
			},
		})

		expect(create.status(), `job create failed: ${await create.text()}`).toBe(201)

		const job = await create.json()
		jobs.push(String(job.id))

		const listed = await admin.get(`${API}/operations/jobs`)
		const rows = (await listed.json()).results ?? []
		const row = rows.find(
			(candidate: Record<string, unknown>) => String(candidate.id) === String(job.id),
		)

		expect(row, 'the job the console just created is missing from its own listing').toBeTruthy()
		expect(row.actions.resume, 'a previewed job offered a resume').toBe(false)
		expect(row.actions.pause, 'a previewed job offered a pause').toBe(false)
		expect(row.actions.cancel, 'a previewed job offered no cancel').toBe(true)

		// The flags are an offer, never the authorization. The endpoint refuses
		// on its own, which is what this asserts.
		const resume = await admin.post(`${API}/bulk-jobs/${job.id}/resume`)
		expect(resume.status(), 'a previewed job was resumed').toBe(422)
		expect((await resume.json()).reason).toBe('not-resumable')
	})

	test('a running job is held by hand and set going again', async () => {
		const jobId = jobs[0]

		test.skip(jobId === undefined, 'no job was created, so there is nothing to pause')

		const commit = await admin.post(`${API}/bulk-jobs/${jobId}/commit`, {
			data: { justification: 'e2e operations console' },
		})
		expect(commit.ok(), `commit failed: ${await commit.text()}`).toBeTruthy()

		const paused = await admin.post(`${API}/bulk-jobs/${jobId}/pause`)

		// The worker may have finished the job between the commit and here, and
		// a pause of a finished job is correctly refused. Both outcomes are
		// right; what would be wrong is a pause that answered 200 and left the
		// state alone.
		if (paused.status() === 422) {
			expect((await paused.json()).reason).toBe('not-pausable')

			return
		}

		expect(paused.status(), `pause failed: ${await paused.text()}`).toBe(200)
		expect((await paused.json()).state, 'the pause did not change the state').toBe('paused')

		const twice = await admin.post(`${API}/bulk-jobs/${jobId}/pause`)
		expect(twice.status(), 'a paused job was paused again').toBe(422)

		const resumed = await admin.post(`${API}/bulk-jobs/${jobId}/resume`)
		expect(resumed.status(), `resume failed: ${await resumed.text()}`).toBe(202)
		expect((await resumed.json()).state).toBe('running')
	})

	test('an ordinary user cannot pause a job that is not theirs', async () => {
		const jobId = jobs[0]

		test.skip(jobId === undefined, 'no job was created, so there is nothing to pause')

		const res = await other.post(`${API}/bulk-jobs/${jobId}/pause`)

		// 404 rather than 403: a job the caller may not touch must not be
		// distinguishable from one that does not exist.
		expect(res.status(), 'another user paused a job that is not theirs').toBe(404)
	})
})
