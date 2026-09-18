import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE OPERATIONS CONSOLE, end to end, over the HTTP API.
 *
 * THE SCENARIOS THIS FILE ANCHORS, now that the run log, run now and
 * maintenance mode have landed:
 *
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-failed-run-is-visible-with-its-reason
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-stuck-queue-is-cleared-by-hand
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-running-job-is-not-started-twice
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-rebuild-is-as-observable-as-any-other-job
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-the-check-changes-nothing
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-the-repair-is-authorised-and-recorded
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-user-is-told-why-the-instance-is-closed
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-the-administrator-can-still-leave-the-mode
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-a-job-is-disabled-and-stops-being-due
 * @e2e openspec/changes/admin-operations-console/specs/operations-console/spec.md#scenario-the-bundle-carries-no-secret
 *
 * MAINTENANCE MODE IS THE DANGEROUS TEST IN THIS FILE. It closes the whole
 * app for every session on the instance while it holds, so the case that
 * enters it leaves it in the same test, and `afterAll` leaves it again
 * unconditionally. A run killed between the two would otherwise leave the
 * instance shut for the next lane.
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
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
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
		// Unconditional, and first: a run killed mid-way through the
		// maintenance case would otherwise leave the whole app shut for the
		// next lane on this instance.
		await admin
			.delete(`${API}/operations/maintenance`)
			.catch(() => undefined)

		for (const jobId of jobs) {
			await admin
				.post(`${API}/bulk-jobs/${jobId}/cancel`)
				.catch(() => undefined)
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

		expect(
			body.window?.hours,
			'the console did not name its window',
		).toBeGreaterThan(0)
		expect(
			(body.panes ?? [])
				.map((pane: Record<string, unknown>) => pane.id)
				.sort(),
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
			expect(
				job.observed,
				`${job.name} is in the unobserved list while observed`,
			).toBe(false)
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

		expect(create.status(), `job create failed: ${await create.text()}`).toBe(
			201,
		)

		const job = await create.json()
		jobs.push(String(job.id))

		const listed = await admin.get(`${API}/operations/jobs`)
		const rows = (await listed.json()).results ?? []
		const row = rows.find(
			(candidate: Record<string, unknown>) =>
				String(candidate.id) === String(job.id),
		)

		expect(
			row,
			'the job the console just created is missing from its own listing',
		).toBeTruthy()
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

		test.skip(
			jobId === undefined,
			'no job was created, so there is nothing to pause',
		)

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
		expect(
			(await paused.json()).state,
			'the pause did not change the state',
		).toBe('paused')

		const twice = await admin.post(`${API}/bulk-jobs/${jobId}/pause`)
		expect(twice.status(), 'a paused job was paused again').toBe(422)

		const resumed = await admin.post(`${API}/bulk-jobs/${jobId}/resume`)
		expect(resumed.status(), `resume failed: ${await resumed.text()}`).toBe(202)
		expect((await resumed.json()).state).toBe('running')
	})

	test('an ordinary user cannot pause a job that is not theirs', async () => {
		const jobId = jobs[0]

		test.skip(
			jobId === undefined,
			'no job was created, so there is nothing to pause',
		)

		const res = await other.post(`${API}/bulk-jobs/${jobId}/pause`)

		// 404 rather than 403: a job the caller may not touch must not be
		// distinguishable from one that does not exist.
		expect(res.status(), 'another user paused a job that is not theirs').toBe(
			404,
		)
	})
	test('a failed run is listed with its reason, and the list filters', async () => {
		const res = await admin.get(
			`${API}/operations/runs?outcome=failed&hours=168&limit=20`,
		)
		expect(res.ok(), `run history read failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()

		// The filters the answer reports are the filters that were applied.
		// Without this, a service that ignored `outcome` and returned every
		// run would pass the shape assertions below unnoticed.
		expect(body.filters.outcome).toBe('failed')
		expect(body.filters.windowHours).toBe(168)
		expect(Array.isArray(body.results)).toBeTruthy()

		for (const run of body.results) {
			expect(run.outcome, 'the failed filter returned another outcome').toBe(
				'failed',
			)
			expect(run.job, 'a run row named no job').toBeTruthy()
			// A failed run without a reason is the row this whole change
			// exists to stop shipping.
			expect(
				run.message,
				`the failed run ${run.id} carried no reason`,
			).toBeTruthy()
		}
	})

	test('the run history is refused to an ordinary signed-in user', async () => {
		const res = await other.get(`${API}/operations/runs`)

		expect(
			res.status(),
			'an ordinary user read the instance run history',
		).toBeGreaterThanOrEqual(400)
	})

	test('a maintenance action is started by hand and leaves a run row', async () => {
		const started = await admin.post(`${API}/operations/run-now`, {
			data: { job: 'consistency-check' },
		})
		expect(
			started.status(),
			`run now failed: ${await started.text()}`,
		).toBe(202)

		const body = await started.json()
		expect(body.started).toBeTruthy()
		expect(body.job).toContain('ConsistencyCheckJob')

		// The run row carries WHO asked, which is the half that makes the act
		// answerable afterwards.
		const runs = await admin.get(
			`${API}/operations/runs?job=${encodeURIComponent(body.job)}&limit=5`,
		)
		expect(runs.ok()).toBeTruthy()

		const rows = (await runs.json()).results
		expect(rows.length, 'run now left no run row').toBeGreaterThan(0)
		expect(rows[0].cause).toBe('manual')
		expect(rows[0].actor).toBe(ADMIN)
		expect(rows[0].outcome).toBe('completed')
		expect(rows[0].durationMs).not.toBeNull()
	})

	test('an ordinary user cannot start a job by hand', async () => {
		const res = await other.post(`${API}/operations/run-now`, {
			data: { job: 'consistency-check' },
		})

		expect(
			res.status(),
			'an ordinary user started a maintenance job',
		).toBeGreaterThanOrEqual(400)
	})

	test('a class the instance does not register cannot be started', async () => {
		const res = await admin.post(`${API}/operations/run-now`, {
			data: { job: 'Evil\\Payload' },
		})

		expect(res.status(), 'an arbitrary class name was accepted').toBe(422)
		expect((await res.json()).reason).toBe('unknown-job')
	})

	test('a job is disabled, loses its next due time, and is enabled again', async () => {
		const job = 'OCA\\OpenRegister\\BackgroundJob\\ConsistencyCheckJob'

		const disabled = await admin.put(`${API}/operations/schedule`, {
			data: { job, enabled: false },
		})
		expect(
			disabled.ok(),
			`disabling failed: ${await disabled.text()}`,
		).toBeTruthy()

		const off = await disabled.json()
		expect(off.enabled).toBe(false)
		expect(off.nextDue, 'a disabled job still reported a next due').toBeNull()

		const enabled = await admin.put(`${API}/operations/schedule`, {
			data: { job, enabled: true, intervalSeconds: 3600 },
		})
		expect(enabled.ok()).toBeTruthy()

		const on = await enabled.json()
		expect(on.enabled).toBe(true)
		expect(on.nextDue).not.toBeNull()
		expect(on.intervalSeconds).toBe(3600)
	})

	test('the consistency check reports and changes nothing', async () => {
		const before = await admin.get(`${API}/operations/consistency`)
		expect(
			before.ok(),
			`consistency read failed: ${await before.text()}`,
		).toBeTruthy()

		const first = await before.json()
		expect(first.checked, 'the check ran no probes at all').toBeGreaterThan(0)

		// Read twice: a check that repaired as it went would answer a
		// different count the second time, which is the cheapest evidence
		// available over HTTP that it wrote nothing.
		const again = await admin.get(`${API}/operations/consistency`)
		const second = await again.json()

		expect(second.checked).toBe(first.checked)
		expect(second.inconsistent).toBe(first.inconsistent)
	})

	test('a repair names what it would change before it changes it', async () => {
		const plan = await admin.get(
			`${API}/operations/repair-plan?check=orphan-relations`,
		)
		expect(plan.ok(), `repair plan failed: ${await plan.text()}`).toBeTruthy()

		const body = await plan.json()
		expect(body.action, 'the plan did not say what it would do').toBeTruthy()
		expect(body.table).toBe('openregister_object_relations')
		expect(Array.isArray(body.objects)).toBeTruthy()
	})

	test('an ordinary user cannot repair anything', async () => {
		const res = await other.post(`${API}/operations/repair`, {
			data: { check: 'orphan-relations' },
		})

		expect(
			res.status(),
			'an ordinary user applied a repair',
		).toBeGreaterThanOrEqual(400)
	})

	test('the support bundle carries the configuration keys and no secret', async () => {
		const res = await admin.get(`${API}/operations/support-bundle`)
		expect(res.ok(), `bundle read failed: ${await res.text()}`).toBeTruthy()

		const bundle = await res.json()
		const asText = JSON.stringify(bundle)

		expect(bundle.instance.version, 'the bundle named no version').toBeTruthy()
		expect(bundle.instance.licence).toBe('EUPL-1.2')

		for (const [key, value] of Object.entries(bundle.configuration ?? {})) {
			if (/password|secret|token|api_?key|credential/i.test(key)) {
				expect(
					value,
					`the bundle carried the value of ${key}`,
				).toBe('***redacted***')
			}
		}

		// The marker itself is the assertion when a credential IS configured;
		// when none is, the loop above is vacuous, so the shape is asserted
		// too rather than leaving a green that proved nothing.
		expect(typeof bundle.configuration).toBe('object')
		expect(asText).not.toContain('BEGIN PRIVATE KEY')
	})

	test('the instance facts name the running version', async () => {
		const res = await admin.get(`${API}/operations/facts`)
		expect(res.ok()).toBeTruthy()

		const facts = await res.json()
		expect(facts.version).toBeTruthy()
		expect(facts.php).toBeTruthy()
		expect(facts.licence).toBe('EUPL-1.2')
	})

	test('maintenance mode closes the instance and the console still opens it', async () => {
		const entered = await admin.post(`${API}/operations/maintenance`, {
			data: { message: 'onderhoud tot 14:00' },
		})
		expect(
			entered.ok(),
			`entering maintenance failed: ${await entered.text()}`,
		).toBeTruthy()
		expect((await entered.json()).holds).toBe(true)

		try {
			// A user is told WHY, not merely refused.
			const refused = await other.get(`${API}/registers`)
			expect(
				refused.status(),
				'a register was read while the instance was closed',
			).toBe(503)

			const body = await refused.json()
			expect(body.error).toBe('maintenance-mode')
			expect(body.message).toBe('onderhoud tot 14:00')

			// And the console stays reachable, which is what makes leaving
			// possible at all.
			const consoleRead = await admin.get(`${API}/operations/maintenance`)
			expect(
				consoleRead.ok(),
				'the console was closed by the mode it controls',
			).toBeTruthy()
			expect((await consoleRead.json()).actor).toBe(ADMIN)
		} finally {
			const left = await admin.delete(`${API}/operations/maintenance`)
			expect(left.ok(), `leaving maintenance failed`).toBeTruthy()
			expect((await left.json()).holds).toBe(false)
		}

		// Open again: the register read that was refused now answers.
		const open = await other.get(`${API}/registers`)
		expect(open.status(), 'the instance stayed closed').not.toBe(503)
	})

	test('an ordinary user cannot close the instance', async () => {
		const res = await other.post(`${API}/operations/maintenance`, {
			data: { message: 'mine now' },
		})

		expect(
			res.status(),
			'an ordinary user closed the instance',
		).toBeGreaterThanOrEqual(400)

		const state = await admin.get(`${API}/operations/maintenance`)
		expect((await state.json()).holds, 'the instance was left closed').toBe(
			false,
		)
	})
})
