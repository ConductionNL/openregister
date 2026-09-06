/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal-task node, end to end over the live HTTP API: the seven
 * @e2e-marked scenarios of the flow-portal-task change (six on its own spec,
 * one on the flow-tasks delta).
 *
 * A case object with an initiator is created through the objects API, a flow
 * with an `openregister.portal-task` node is authored through the flows API
 * and run through the synchronous test endpoint. The resident acts through
 * the PORTAL seam: a request context with NO Nextcloud credentials, carrying
 * a signed X-Portal-Subject assertion minted here with the same HS256 shape
 * portaliq mints — which requires the shared secret to be configured, done
 * through `occ config:app:set openregister portal_assertion_secret`. Where
 * occ is not reachable the portal-side scenarios SKIP, loudly.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { request as apiRequest, expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { createHmac } from 'node:crypto'
import { resolveBaseUrl, resolveContainer } from '../base-url.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const RUN_ID = `e2e-portaltask-${Date.now().toString(36)}`
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS =
	process.env.NEXTCLOUD_ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const SECRET = `${RUN_ID}-shared-assertion-secret`
const SUBJECT_A = `${RUN_ID}-resident-a`
const SUBJECT_B = `${RUN_ID}-resident-b`

// Basic auth, no session cookie: no CSRF token is demanded and
// `OCS-APIRequest` marks the calls as API traffic.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
}

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })
test.describe.configure({ mode: 'serial' })

const CONTAINER = resolveContainer()

function occ(args: string): string {
	if (CONTAINER === null) {
		throw new Error('NC_CONTAINER is not set; refusing to guess a container.')
	}
	return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
		encoding: 'utf8',
	})
}

/** Whether the shared assertion secret could be configured; null = not tried. */
let secretConfigured: boolean | null = null

function ensureSecret(): boolean {
	if (secretConfigured !== null) {
		return secretConfigured
	}
	try {
		occ(
			`config:app:set openregister portal_assertion_secret --value="${SECRET}"`,
		)
		secretConfigured = true
	} catch {
		secretConfigured = false
	}
	return secretConfigured
}

/** Mint the X-Portal-Subject assertion exactly as portaliq's edge does. */
function assertionFor(subjectRef: string): string {
	const b64 = (bytes: Buffer): string =>
		bytes
			.toString('base64')
			.replace(/\+/g, '-')
			.replace(/\//g, '_')
			.replace(/=+$/, '')
	const header = b64(Buffer.from(JSON.stringify({ alg: 'HS256', typ: 'JWT' })))
	const now = Math.floor(Date.now() / 1000)
	const claims = b64(
		Buffer.from(
			JSON.stringify({
				sub: subjectRef,
				audience: 'client',
				organisation: 'e2e',
				trust: 'substantial',
				jti: `${RUN_ID}-session`,
				use: 'assertion',
				iat: now,
				exp: now + 300,
				iss: 'portaliq',
			}),
		),
	)
	const signature = b64(
		createHmac('sha256', SECRET).update(`${header}.${claims}`).digest(),
	)
	return `${header}.${claims}.${signature}`
}

/** A request context acting as a PORTAL SUBJECT: no Nextcloud credentials at all. */
async function portalContext(subjectRef: string): Promise<APIRequestContext> {
	return apiRequest.newContext({
		baseURL: resolveBaseUrl(),
		extraHTTPHeaders: {
			Accept: 'application/json',
			'X-Portal-Subject': assertionFor(subjectRef),
		},
	})
}

type Node = Record<string, unknown>

async function createFlow(
	request: APIRequestContext,
	label: string,
	nodes: Node[],
	edges: Array<Record<string, unknown>>,
): Promise<string> {
	const resp = await request.post(`${API}/flows`, {
		headers: JSON_HEADERS,
		data: {
			name: `${RUN_ID} ${label}`,
			description: 'Created by the flow-portal-task e2e suite.',
			trigger: 'manual',
			enabled: true,
			nodes,
			edges,
		},
	})
	expect(resp.status(), await resp.text()).toBe(201)
	const body = await resp.json()
	return (body.uuid ?? body.id) as string
}

function portalTask(id: string, config: Record<string, unknown> = {}): Node {
	return {
		id,
		type: 'openregister.portal-task',
		config: {
			title: `${RUN_ID} ${id}: send the missing {{ name }}`,
			partyRole: 'initiator',
			advance: 'all',
			...config,
		},
		position: { x: 0, y: 0 },
	}
}

function setFields(id: string, set: Record<string, unknown>): Node {
	return {
		id,
		type: 'openregister.set-fields',
		config: { set },
		position: { x: 0, y: 0 },
	}
}

/** Run a flow synchronously over the case object as its one seed item. */
async function testRun(
	request: APIRequestContext,
	flowId: string,
	caseAnchor: Record<string, unknown>,
) {
	const resp = await request.post(`${API}/flow-runs/test`, {
		headers: JSON_HEADERS,
		data: { flowId, seedItems: [{ json: caseAnchor }] },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function readRun(request: APIRequestContext, uuid: string) {
	const resp = await request.get(`${API}/flow-runs/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

/** The external task a run's node created, read on its CASE (the caseworker's view). */
async function caseTaskFor(
	request: APIRequestContext,
	caseUuid: string,
	runUuid: string,
	openOnly = true,
) {
	const terminal = openOnly ? '&isTerminal=false' : ''
	const resp = await request.get(
		`${API}/flow-tasks?scope=all&objectUuid=${caseUuid}${terminal}&limit=100&sort=created`,
	)
	expect(resp.status(), await resp.text()).toBe(200)
	const rows = ((await resp.json()).results ?? []) as Array<
		Record<string, unknown>
	>
	return rows.filter((row) => row.runUuid === runUuid)
}

test.describe('flow-portal-task: a party outside the instance in the graph', () => {
	const flows: string[] = []
	let registerId: number
	let schemaId: number
	let caseUuid: string
	let caseId: string

	test.beforeAll(async ({ request }) => {
		// The register, the schema and the case object the flows are about.
		const register = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: {
				slug: `${RUN_ID}-register`,
				title: `${RUN_ID} register`,
				description: 'flow-portal-task e2e',
			},
		})
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		const schema = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				slug: `${RUN_ID}-schema`,
				title: `${RUN_ID} case`,
				description: 'flow-portal-task e2e case schema',
				properties: {
					name: { type: 'string', title: 'Name' },
					initiator: { type: 'string', title: 'Initiator' },
				},
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const created = await request.post(
			`${API}/objects/${registerId}/${schemaId}`,
			{
				headers: JSON_HEADERS,
				data: { name: 'passport renewal', initiator: SUBJECT_A },
			},
		)
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		const caseBody = await created.json()
		caseUuid = (caseBody['@self']?.uuid
			?? caseBody.uuid
			?? caseBody.id) as string
		caseId = (caseBody['@self']?.id ?? caseBody.id ?? caseUuid) as string
		expect(caseUuid, 'the case object has a uuid').toBeTruthy()
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of flows) {
			await request.delete(`${API}/flows/${uuid}`).catch(() => {})
		}
	})

	/** The seed item: the case object, anchored the way object triggers anchor it. */
	function caseItem(extra: Record<string, unknown> = {}) {
		return {
			name: 'passport renewal',
			initiator: SUBJECT_A,
			'@self': { uuid: caseUuid, register: registerId, schema: schemaId },
			...extra,
		}
	}

	test('the node catalog offers the portal-task node with its form', async ({
		request,
	}) => {
		const resp = await request.get(`${API}/flow/node-catalog`)
		expect(resp.status(), await resp.text()).toBe(200)
		const entry = ((await resp.json()).results ?? []).find(
			(node: { id?: string }) => node.id === 'openregister.portal-task',
		)
		expect(entry, 'the palette carries openregister.portal-task').toBeTruthy()
		const formKeys = entry.configForm.map((field: { key: string }) => field.key)
		for (const key of [
			'title',
			'partyRole',
			'uploadRequired',
			'uploadMaxFiles',
			'uploadAcceptedTypes',
			'uploadMaxSizeMb',
			'reasonField',
			'advance',
		]) {
			expect(formKeys, `form field ${key}`).toContain(key)
		}
		// The three-waiter division is stated where the author picks.
		expect(String(entry.description)).toContain('Ask a person')
		expect(String(entry.description)).toContain('Wait for an answer')
	})

	// @e2e flow-portal-task::the-first-firing-produces-an-external-task-and-a-suspended-run
	// @e2e flow-tasks::the-caseworker-still-sees-the-ask-on-the-case
	test('a flow with a portal task suspends; the ask reaches the case, no inbox', async ({
		request,
	}) => {
		const flowId = await createFlow(
			request,
			'suspends',
			[portalTask('ask'), setFields('done', { finished: true })],
			[{ id: 'e1', from: 'ask', to: 'done' }],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId, caseItem())
		expect(run.status).toBe('suspended')
		// The heartbeat: a suspension a clock can reach. Null is the one shape
		// the 14-day abandoned-signal reaper would FAIL, and a hersteltermijn
		// outlives fourteen days.
		expect(
			run.resumeAt,
			'a portal task never parks on a null resumeAt',
		).toBeTruthy()

		// The caseworker's view: the ask is on the case, external, matched to
		// the initiator, with a queryable delivery state.
		const [task] = await caseTaskFor(request, caseUuid, run.uuid)
		expect(task, 'the ask is anchored to the case').toBeTruthy()
		expect(task.performerType).toBe('external')
		expect(task.assignee).toBe(`party:${SUBJECT_A}`)
		expect(task.state).toBe('active')
		expect(task.delivery, 'the delivery state is on the row').toBeTruthy()
		expect(['requested', 'delivered', 'failed', 'not-recorded']).toContain(
			(task.delivery as Record<string, unknown>).state,
		)

		// NO Nextcloud inbox carries it: not assigned, not pooled, not in the
		// admin's "everything" view, not in the count.
		for (const scope of ['assigned', 'pooled', 'all']) {
			const inbox = await request.get(
				`${API}/flow-tasks?scope=${scope}&isTerminal=false&limit=100`,
			)
			expect(inbox.status()).toBe(200)
			const body = await inbox.json()
			const rows = (body.results ?? []) as Array<Record<string, unknown>>
			expect(
				rows.find((row) => row.uuid === task.uuid),
				`scope=${scope} must not list the external task`,
			).toBeUndefined()
		}
	})

	// @e2e flow-portal-task::the-task-is-visible-to-its-matched-subject-and-to-nobody-else
	test('a portal task is listed for its matched subject and hidden from another', async ({
		request,
	}) => {
		test.skip(
			!ensureSecret(),
			'occ not reachable; the assertion secret cannot be configured',
		)

		const flowId = await createFlow(
			request,
			'visibility',
			[portalTask('ask')],
			[],
		)
		flows.push(flowId)
		const run = await testRun(request, flowId, caseItem())
		expect(run.status).toBe('suspended')
		const [task] = await caseTaskFor(request, caseUuid, run.uuid)

		const asA = await portalContext(SUBJECT_A)
		const asB = await portalContext(SUBJECT_B)
		try {
			const mine = await asA.get(`${API}/portal-tasks?limit=100`)
			expect(mine.status(), await mine.text()).toBe(200)
			const mineBody = await mine.json()
			const mineRow = (
				mineBody.results as Array<Record<string, unknown>>
			).find((row) => row.uuid === task.uuid)
			expect(mineRow, "subject A's list carries the task").toBeTruthy()
			expect(mineRow!.subject, 'with its case context').toBeTruthy()

			const theirs = await asB.get(`${API}/portal-tasks?limit=100`)
			expect(theirs.status(), await theirs.text()).toBe(200)
			const theirsBody = await theirs.json()
			expect(
				(theirsBody.results as Array<Record<string, unknown>>).find(
					(row) => row.uuid === task.uuid,
				),
				"subject B's list must not carry it",
			).toBeUndefined()
			// And not counted either: B has no tasks at all in this suite.
			expect(theirsBody.total).toBe(0)

			// Reading it directly answers absence, not denial.
			const peek = await asB.get(`${API}/portal-tasks/${task.uuid}`)
			expect(peek.status()).toBe(404)

			// No assertion at all is a 401 with a stable code.
			const anonymous = await apiRequest.newContext({
				baseURL: resolveBaseUrl(),
			})
			const bare = await anonymous.get(`${API}/portal-tasks`)
			expect(bare.status()).toBe(401)
			expect((await bare.json()).code).toBe('portal-subject-missing')
			await anonymous.dispose()
		} finally {
			await asA.dispose()
			await asB.dispose()
		}
	})

	// @e2e flow-portal-task::another-subject-who-knows-the-task-cannot-answer-it
	test('another portal subject cannot complete a task that is not theirs', async ({
		request,
	}) => {
		test.skip(
			!ensureSecret(),
			'occ not reachable; the assertion secret cannot be configured',
		)

		const flowId = await createFlow(request, 'refusal', [portalTask('ask')], [])
		flows.push(flowId)
		const run = await testRun(request, flowId, caseItem())
		const [task] = await caseTaskFor(request, caseUuid, run.uuid)

		const asB = await portalContext(SUBJECT_B)
		try {
			const answered = await asB.post(
				`${API}/portal-tasks/${task.uuid}/complete`,
				{
					multipart: {
						outcome: 'submitted',
						answers: JSON.stringify({ remarks: 'not mine' }),
					},
				},
			)
			// Fail-closed AND unrevealing: a stranger who knows the uuid gets
			// absence, never a denial that confirms it.
			expect(answered.status(), await answered.text()).toBe(404)
			expect((await answered.json()).code).toBe('no-such-task')
		} finally {
			await asB.dispose()
		}

		const [after] = await caseTaskFor(request, caseUuid, run.uuid)
		expect(after.state).toBe('active')
		expect(after.isTerminal).toBe(false)
		expect((await readRun(request, run.uuid)).status).toBe('suspended')
	})

	// @e2e flow-portal-task::the-uploaded-file-is-on-the-case
	// @e2e flow-portal-task::completing-the-task-advances-the-run-with-the-answer-on-the-items
	test("a resident's upload lands on the case and the run advances with the answer", async ({
		request,
	}) => {
		test.skip(
			!ensureSecret(),
			'occ not reachable; the assertion secret cannot be configured',
		)

		const flowId = await createFlow(
			request,
			'upload',
			[
				portalTask('ask', {
					uploadRequired: true,
					uploadAcceptedTypes: 'application/pdf',
					uploadMaxSizeMb: 5,
				}),
				setFields('done', { finished: true }),
			],
			[{ id: 'e1', from: 'ask', to: 'done' }],
		)
		flows.push(flowId)
		const run = await testRun(request, flowId, caseItem())
		expect(run.status).toBe('suspended')
		const [task] = await caseTaskFor(request, caseUuid, run.uuid)

		const asA = await portalContext(SUBJECT_A)
		try {
			// A required upload cannot be skipped: refused naming the requirement.
			const empty = await asA.post(
				`${API}/portal-tasks/${task.uuid}/complete`,
				{
					multipart: { outcome: 'submitted' },
				},
			)
			expect(empty.status(), await empty.text()).toBe(400)
			expect((await empty.json()).error).toContain('uploadRequired')

			const completed = await asA.post(
				`${API}/portal-tasks/${task.uuid}/complete`,
				{
					multipart: {
						outcome: 'submitted',
						answers: JSON.stringify({ remarks: 'here it is' }),
						comment: 'uploaded from the portal',
						file: {
							name: 'payslip.pdf',
							mimeType: 'application/pdf',
							buffer: Buffer.from(`%PDF-1.4 ${RUN_ID} payslip`),
						},
					},
				},
			)
			expect(completed.status(), await completed.text()).toBe(200)
			const completedBody = await completed.json()
			expect(completedBody.state).toBe('completed')
			expect(completedBody.completedBy).toBe(`party:${SUBJECT_A}`)
			expect(completedBody.evidence?.[0]?.name).toBe('payslip.pdf')
		} finally {
			await asA.dispose()
		}

		// The file IS on the case object, as an ordinary OR file attachment.
		const files = await request.get(
			`${API}/objects/${registerId}/${schemaId}/${caseId}/files`,
		)
		expect(files.status(), await files.text()).toBe(200)
		const fileRows = JSON.stringify(await files.json())
		expect(fileRows, 'the upload is a file on the case').toContain('payslip.pdf')

		// advance:'all' ran the completion request to the end of the graph:
		// the run is completed and every item carries the answer bag.
		const after = await readRun(request, run.uuid)
		expect(after.status).toBe('completed')
		const item = (after.items ?? [])[0]?.json ?? {}
		expect(item.portalTask?.decided).toBe(true)
		expect(item.portalTask?.outcome).toBe('submitted')
		expect(item.portalTask?.party).toBe(`party:${SUBJECT_A}`)
		expect(item.portalTask?.answers?.remarks).toBe('here it is')
		expect(String(item.portalTask?.files?.[0]?.name)).toBe('payslip.pdf')
		expect(item.finished).toBe(true)
	})

	// @e2e flow-portal-task::a-rejected-submission-goes-back-with-the-reason
	test('a rejected submission returns to the resident with the reason', async ({
		request,
	}) => {
		test.skip(
			!ensureSecret(),
			'occ not reachable; the assertion secret cannot be configured',
		)

		const flowId = await createFlow(
			request,
			're-ask',
			[
				portalTask('ask', { reasonField: 'review.comment' }),
				{
					id: 'review',
					type: 'openregister.user-task',
					config: {
						title: `${RUN_ID} review the submission`,
						assignee: ADMIN,
						outcomes: 'approved, rejected',
						outcomeKey: 'review',
						advance: 'all',
					},
					position: { x: 0, y: 0 },
				},
				{
					id: 'route',
					type: 'openregister.route',
					config: {
						rules: [
							{
								condition: {
									'==': [
										{ var: 'json.review.outcome' },
										'rejected',
									],
								},
								output: 'back',
							},
						],
						default: 'on',
					},
					exits: [{ id: 'back' }, { id: 'on' }],
					position: { x: 0, y: 0 },
				},
				setFields('done', { finished: true }),
			],
			[
				{ id: 'e1', from: 'ask', to: 'review' },
				{ id: 'e2', from: 'review', to: 'route' },
				{ id: 'e3', from: 'route', fromExit: 'back', to: 'ask' },
				{ id: 'e4', from: 'route', fromExit: 'on', to: 'done' },
			],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId, caseItem())
		expect(run.status).toBe('suspended')
		const [first] = await caseTaskFor(request, caseUuid, run.uuid)
		expect(first.assignee).toBe(`party:${SUBJECT_A}`)

		// The resident answers; advance:'all' walks on to the review task.
		const asA = await portalContext(SUBJECT_A)
		try {
			const submitted = await asA.post(
				`${API}/portal-tasks/${first.uuid}/complete`,
				{
					multipart: {
						outcome: 'submitted',
						answers: JSON.stringify({ remarks: 'first try' }),
					},
				},
			)
			expect(submitted.status(), await submitted.text()).toBe(200)
		} finally {
			await asA.dispose()
		}

		// The caseworker rejects, with the reason the resident will read.
		const review = await request.get(
			`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100`,
		)
		const reviewTask = (
			((await review.json()).results ?? []) as Array<Record<string, unknown>>
		).find((row) => row.runUuid === run.uuid && row.nodeId === 'review')
		expect(reviewTask, 'the review task is in the caseworker inbox').toBeTruthy()
		const rejected = await request.post(
			`${API}/flow-tasks/${reviewTask!.uuid}/complete`,
			{
				headers: JSON_HEADERS,
				data: { outcome: 'rejected', comment: 'The scan is unreadable' },
			},
		)
		expect(rejected.status(), await rejected.text()).toBe(200)

		// The rejection routed the walk back into the node: a SECOND ask
		// exists, cycle 2, carrying the reason and the first task's uuid; the
		// first task is untouched; the run waits on the resident again.
		const open = await caseTaskFor(request, caseUuid, run.uuid)
		const second = open.find((row) => row.uuid !== first.uuid)
		expect(second, 'a second ask exists').toBeTruthy()
		expect(second!.state).toBe('active')
		const metadata = second!.metadata as Record<string, unknown>
		expect(metadata.cycle).toBe(2)
		expect(metadata.previousTaskUuid).toBe(first.uuid)
		expect(metadata.reaskReason).toBe('The scan is unreadable')

		const all = await caseTaskFor(request, caseUuid, run.uuid, false)
		const firstAfter = all.find((row) => row.uuid === first.uuid)
		expect(firstAfter!.state).toBe('completed')
		expect((await readRun(request, run.uuid)).status).toBe('suspended')
	})
})
