import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UNDOING A BULK ACTION, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/undo-a-bulk-action/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e bulk-action-jobs::the-preview-says-the-job-can-be-undone
 * @e2e bulk-action-jobs::the-prior-status-is-on-the-member-outcome
 * @e2e bulk-action-jobs::a-destruction-is-not-declarable-as-reversible
 *
 * WHAT THIS FILE PROVES.
 *
 * That a reversible job says so before it commits, and that it records what
 * going back would mean while it is still a rehearsal: the catalogue names
 * which actions can be undone and for how long, a created job reports itself
 * reversible with a deadline, every member outcome carries the property the
 * job would change together with its value before the change, and a schema
 * declaring a destruction reversible is refused at save with 422 naming the
 * action. It also proves the four refusals a reversal answers with rather
 * than appearing to work.
 *
 * WHAT IT DELIBERATELY DOES NOT PROVE, AND WHERE THAT LIVES.
 *
 * It never drives a reversal to completion, because that needs the
 * background worker to have walked the original job's members and nothing in
 * this environment guarantees cron ran between two HTTP calls; an assertion
 * here would be a timing race dressed up as coverage. The two scenarios that
 * need a completed job are marked `@e2e exclude` in the spec delta and
 * asserted by name, so nobody has to take this comment's word:
 *
 *   - a hundred cases go back in one act
 *     tests/Unit/Service/BulkJob/BulkJobReversalTest.php
 *       ::testAHundredCasesGoBackAsOneJobNamingTheOriginal
 *     tests/Unit/BulkAction/RestorePriorValuesActionTest.php
 *       ::testTheRecordedPriorValueIsWrittenBack
 *   - somebody's later correction survives the undo
 *     tests/Unit/BulkAction/RestorePriorValuesActionTest.php
 *       ::testALaterEditIsReportedByNameAndNeverOverwritten
 *   - the reversal is authorised for the person doing it
 *     tests/Unit/Controller/BulkJobsControllerTest.php
 *       ::testTheReversalRunsAsThePersonAskingForItNotTheOriginalActor
 *       ::testACallerWhoCannotReadTheJobCannotUndoIt
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

const API = '/index.php/apps/openregister/api'

/** The built-in attribute write, which declares itself reversible. */
const SET_PROPERTIES = 'openregister:set-properties'

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

test.describe.configure({ mode: 'serial' })

test.describe('a bulk action has an inverse', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string

	/** Every object this spec creates, as a uuid. */
	const created: string[] = []

	/** Every job this spec creates, so afterAll can cancel what is left. */
	const jobs: string[] = []

	async function createObject(key: string, status: string): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key, status },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push(uuid)

		return uuid
	}

	async function createJob(
		body: Record<string, unknown>,
	): Promise<Record<string, unknown>> {
		const res = await admin.post(`${API}/bulk-jobs`, { data: body })
		const json = (await res.json()) as Record<string, unknown>
		expect(res.status(), `job create failed: ${JSON.stringify(json)}`).toBe(201)
		jobs.push(String(json.id))

		return json
	}

	async function membersOf(
		jobId: string,
	): Promise<Array<Record<string, unknown>>> {
		const res = await admin.get(`${API}/bulk-jobs/${jobId}/members?limit=500`)
		expect(res.ok(), `member listing failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()).results as Array<Record<string, unknown>>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e undo register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e undo schema ${RUN}`,
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
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()
		schemaId = String((await res.json()).id)
	})

	test.afterAll(async () => {
		for (const jobId of jobs) {
			await admin.post(`${API}/bulk-jobs/${jobId}/cancel`)
		}

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

	test('the catalogue says which actions can be undone, and for how long', async () => {
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
			byId[SET_PROPERTIES],
			'the attribute write is not registered',
		).toBeTruthy()
		expect(
			byId[SET_PROPERTIES].reversible,
			'the attribute write does not say it can be undone',
		).toBe(true)
		expect(Number(byId[SET_PROPERTIES].reversalWindow)).toBeGreaterThan(0)

		// An action that cannot be undone says so in the same list the
		// operator picks from, not in the error after they tried (D-4).
		expect(
			byId['openregister:apply-rule'],
			'the rule replay is not registered',
		).toBeTruthy()
		expect(byId['openregister:apply-rule'].reversible).toBe(false)
		expect(byId['openregister:apply-rule'].reversalWindow).toBeNull()

		expect(
			Number(body.undoCeiling),
			'the undo storage ceiling is not published',
		).toBeGreaterThan(0)
	})

	test('the preview says the job can be undone, and names the window', async () => {
		const target = await createObject(`window-${RUN}`, 'in behandeling')

		const job = await createJob({
			action: SET_PROPERTIES,
			parameters: { properties: { status: 'afgehandeld' } },
			selection: { ids: [target] },
			register: registerId,
			schema: schemaId,
		})

		expect(job.state, 'a new job should be previewed, not running').toBe(
			'previewed',
		)
		expect(
			job.reversible,
			'the preview does not say the job can be undone',
		).toBe(true)
		expect(Number(job.reversalWindow)).toBeGreaterThan(0)
		expect(
			String(job.reversibleUntil ?? ''),
			'the preview names no deadline, so the window is a number nobody can act on',
		).not.toBe('')
		expect(new Date(String(job.reversibleUntil)).getTime()).toBeGreaterThan(
			Date.now(),
		)
	})

	test('the prior status is on every member outcome', async () => {
		const first = await createObject(`prior-${RUN}-a`, 'in behandeling')
		const second = await createObject(`prior-${RUN}-b`, 'in behandeling')

		const job = await createJob({
			action: SET_PROPERTIES,
			parameters: { properties: { status: 'afgehandeld' } },
			selection: { ids: [first, second] },
			register: registerId,
			schema: schemaId,
		})

		const members = await membersOf(String(job.id))
		expect(members.length).toBe(2)

		for (const member of members) {
			expect(
				member.priorValues,
				`member ${member.objectUuid} recorded nothing to go back to`,
			).toEqual({ status: 'in behandeling' })
			expect(member.appliedValues).toEqual({ status: 'afgehandeld' })
		}
	})

	test('a job still previewed cannot be undone, and a cancelled one wrote nothing', async () => {
		const target = await createObject(`refuse-${RUN}`, 'in behandeling')

		const job = await createJob({
			action: SET_PROPERTIES,
			parameters: { properties: { status: 'afgehandeld' } },
			selection: { ids: [target] },
			register: registerId,
			schema: schemaId,
		})

		// Still previewed: undoing it half way would be a write with no
		// preview, which is the shape this capability exists to replace.
		const early = await admin.post(`${API}/bulk-jobs/${job.id}/reverse`, {
			data: { justification: 'Verkeerde filter.' },
		})
		expect(early.status(), 'an unfinished job should not be undoable').toBe(422)
		expect((await early.json()).reason).toBe('not-finished')

		// Cancelled before it committed, so it wrote nothing and there is
		// nothing to write back. The refusal says which of the two it is.
		const cancelled = await admin.post(`${API}/bulk-jobs/${job.id}/cancel`)
		expect(
			cancelled.ok(),
			`cancel failed: ${await cancelled.text()}`,
		).toBeTruthy()
		expect((await cancelled.json()).state).toBe('cancelled')

		const empty = await admin.post(`${API}/bulk-jobs/${job.id}/reverse`, {
			data: { justification: 'Verkeerde filter.' },
		})
		expect(empty.status()).toBe(422)
		expect((await empty.json()).reason).toBe('nothing-to-reverse')

		// And nothing was written on the way through either refusal.
		const read = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${target}`,
		)
		expect(String((await read.json()).status)).toBe('in behandeling')
	})

	test('a schema declaring a destruction reversible is refused, naming the action', async () => {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e undo refusal schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				configuration: {
					'x-openregister-action': {
						shredAttachments: {
							name: 'Shred attachments',
							description:
								'Destroys every attachment beyond recovery.',
							kind: 'destroy',
							reversible: true,
						},
					},
				},
			},
		})

		expect(
			res.status(),
			`a destruction declared reversible was accepted: ${await res.text()}`,
		).toBe(422)

		const body = await res.json()
		expect(String(body.error)).toContain('shredAttachments')
		expect(
			(body.errors as Array<Record<string, string>>).map((row) => row.code),
		).toContain('reversibility-irreversible-kind')
	})

	test('a job that does not exist cannot be undone', async () => {
		const res = await admin.post(`${API}/bulk-jobs/99999999/reverse`, {
			data: { justification: 'x' },
		})

		expect(res.status()).toBe(404)
	})
})
