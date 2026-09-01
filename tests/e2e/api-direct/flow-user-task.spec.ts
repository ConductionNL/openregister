/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user-task node, end to end over the live HTTP API: the eight
 * @e2e-marked scenarios of the flow-user-task-node spec.
 *
 * A flow is authored through `POST /api/flows` and run through the
 * synchronous test endpoint (`POST /api/flow-runs/test`), which walks it
 * until the user-task node suspends. The task is then driven through the
 * flow-tasks verbs, and the run is read back through `GET /api/flow-runs`.
 *
 * Two scenarios need the background worker: the DEFAULT budget parks the
 * run for the worker, and "stopping a run" has no HTTP verb on this
 * surface (there is no run-stop endpoint), so the operator's stop is used:
 * the instance kill switch vetoes the next hop, which ends the run as
 * `stopped` and lets cancellation propagation empty the inbox. Both drive
 * the worker with `occ background-job:execute` through the dev container
 * and SKIP, loudly, where occ is not reachable.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { request as apiRequest, expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveContainer } from '../base-url.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const RUN_ID = `e2e-usertask-${Date.now().toString(36)}`
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS =
	process.env.NEXTCLOUD_ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STRANGER = `${RUN_ID}-perf`
const STRANGER_PASS = `Perf0rmer!${Date.now().toString(36)}A`

// Same reasoning as flow-engine.spec.ts: Basic auth, no session cookie, so no
// CSRF token is demanded and `OCS-APIRequest` marks the calls as API traffic.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
}
const STRANGER_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${STRANGER}:${STRANGER_PASS}`).toString('base64')}`,
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

/**
 * The FlowRunWorker's background-job id, by class BASENAME (a namespace move
 * must not turn this into a silent skip). Null when occ is unreachable.
 */
function runWorkerJobId(): string | null {
	try {
		const line = occ('background-job:list')
			.split('\n')
			.find((l) => l.includes('FlowRunWorker'))
		return line ? (line.match(/\|\s*(\d+)\s*\|/)?.[1] ?? null) : null
	} catch {
		return null
	}
}

type Node = Record<string, unknown>

/** Author a flow through the flows API and hand back its uuid. */
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
			description: 'Created by the flow-user-task e2e suite.',
			trigger: 'manual',
			enabled: true,
			nodes,
			edges,
		},
	})
	expect(resp.status(), await resp.text()).toBe(201)
	const body = await resp.json()
	const uuid = body.uuid ?? body.id
	expect(uuid, 'flow uuid').toBeTruthy()
	return uuid as string
}

/** A user-task node with the given config, assigned to the admin unless told otherwise. */
function userTask(id: string, config: Record<string, unknown> = {}): Node {
	return {
		id,
		type: 'openregister.user-task',
		config: {
			title: `${RUN_ID} ${id}: approve {{ name }}`,
			assignee: ADMIN,
			outcomes: 'approved, rejected',
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

/** Run a flow synchronously through the test endpoint; returns the run row. */
async function testRun(request: APIRequestContext, flowId: string) {
	const resp = await request.post(`${API}/flow-runs/test`, {
		headers: JSON_HEADERS,
		data: { flowId, seedItems: [{ json: { name: 'Case 7' } }] },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function readRun(request: APIRequestContext, uuid: string) {
	const resp = await request.get(`${API}/flow-runs/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

/** The open task a run's node created, found through the assignee's inbox. */
async function inboxTaskFor(
	request: APIRequestContext,
	runUuid: string,
	nodeId: string,
) {
	const resp = await request.get(
		`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100&sort=created&direction=desc`,
	)
	expect(resp.status(), await resp.text()).toBe(200)
	const rows = ((await resp.json()).results ?? []) as Array<
		Record<string, unknown>
	>
	return (
		rows.find((row) => row.runUuid === runUuid && row.nodeId === nodeId) ?? null
	)
}

async function readTask(request: APIRequestContext, uuid: string) {
	const resp = await request.get(`${API}/flow-tasks/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function complete(
	request: APIRequestContext,
	uuid: string,
	outcome: string,
	comment: string | null = null,
) {
	const resp = await request.post(`${API}/flow-tasks/${uuid}/complete`, {
		headers: JSON_HEADERS,
		data: { outcome, comment },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

test.describe('flow-user-task-node: a person in the graph', () => {
	const flows: string[] = []

	test.afterAll(async ({ request }) => {
		// Deleting the flow cascades its runs. Tasks are terminated by the
		// scenarios that create them, or left terminal; never deleted.
		for (const uuid of flows) {
			await request.delete(`${API}/flows/${uuid}`).catch(() => {})
		}
	})

	// @e2e flow-user-task-node::the-catalog-serves-the-nodes-form
	test('the node catalog offers the user-task node with its form', async ({
		request,
	}) => {
		const resp = await request.get(`${API}/flow/node-catalog`)
		expect(resp.status(), await resp.text()).toBe(200)
		const entry = ((await resp.json()).results ?? []).find(
			(node: { id?: string }) => node.id === 'openregister.user-task',
		)
		expect(entry, 'the palette carries openregister.user-task').toBeTruthy()
		expect(Array.isArray(entry.configForm)).toBe(true)
		expect(entry.configForm.length).toBeGreaterThan(0)
		const formKeys = entry.configForm.map((field: { key: string }) => field.key)
		for (const key of [
			'title',
			'candidateUsers',
			'priority',
			'dueAt',
			'outcomes',
			'advance',
		]) {
			expect(formKeys, `form field ${key}`).toContain(key)
		}
		expect(entry.configKeys).toContain('advance')
		// The division of labour is stated where the author picks.
		expect(String(entry.description)).toContain('Wait for an answer')
	})

	// @e2e flow-user-task-node::the-first-firing-produces-a-task-and-a-suspended-run
	test('a flow with a user task suspends and the task appears in the inbox', async ({
		request,
	}) => {
		const flowId = await createFlow(
			request,
			'suspends',
			[
				setFields('start', { step: 1 }),
				userTask('ask'),
				setFields('done', { step: 2 }),
			],
			[
				{ id: 'e1', from: 'start', to: 'ask' },
				{ id: 'e2', from: 'ask', to: 'done' },
			],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		// The heartbeat: a suspension a clock can reach. Null is the one shape
		// the 14-day abandoned-signal reaper would FAIL.
		expect(
			run.resumeAt,
			'a user task never parks on a null resumeAt',
		).toBeTruthy()

		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task, 'exactly this run and node raised a task').toBeTruthy()
		expect(task!.assignee).toBe(ADMIN)
		expect(task!.state).toBe('active')
		expect(String(task!.title)).toContain('approve Case 7')

		// One task for this node, not one per heartbeat: a re-run of the same
		// suspended run (the worker's wake, done here through the resume nudge
		// and a second read) must not add a second row.
		const nudge = await request.post(`${API}/flow-runs/${run.uuid}/resume`, {
			headers: JSON_HEADERS,
			data: {},
		})
		expect([200, 403]).toContain(nudge.status())
		const listed = await request.get(
			`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100`,
		)
		const mine = (
			((await listed.json()).results ?? []) as Array<Record<string, unknown>>
		).filter((row) => row.runUuid === run.uuid)
		expect(mine).toHaveLength(1)

		await complete(request, task!.uuid as string, 'approved')
	})

	// @e2e flow-user-task-node::completing-the-task-advances-the-run
	test('completing a task from the inbox advances its flow run', async ({
		request,
	}) => {
		const flowId = await createFlow(
			request,
			'default budget',
			[userTask('ask'), setFields('done', { finished: true })],
			[{ id: 'e1', from: 'ask', to: 'done' }],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		const before = Date.now()
		const completed = await complete(request, task!.uuid as string, 'approved')
		expect(completed.state).toBe('completed')

		// The DEFAULT budget: the completing request parks the run as due and
		// returns. Still suspended, and the resume time is now, not the
		// heartbeat fifteen minutes out.
		const parked = await readRun(request, run.uuid)
		expect(parked.status).toBe('suspended')
		expect(new Date(parked.resumeAt as string).getTime()).toBeLessThanOrEqual(
			before + 60_000,
		)

		// On the next advance the node MUST NOT suspend again. That advance is
		// the worker's; drive it when occ is reachable, and say so when not.
		const job = runWorkerJobId()
		test.skip(
			job === null,
			'FlowRunWorker not reachable via occ; the worker half of this scenario cannot run here',
		)
		occ(`background-job:execute ${job} --force-execute`)

		const after = await readRun(request, run.uuid)
		expect(after.status).toBe('completed')
		const item = (after.items ?? [])[0]?.json ?? {}
		expect(item.task?.outcome).toBe('approved')
		expect(item.task?.decided).toBe(true)
		expect(item.task?.completedBy).toBe(ADMIN)
		expect(item.finished).toBe(true)
	})

	// @e2e flow-user-task-node::the-resume-endpoint-cannot-answer-for-a-performer
	test('a flow-runner who is not the performer cannot answer a user task', async ({
		request,
	}) => {
		// A second real account is the task's performer. The ADMIN owns the
		// flow and may run it, and is exactly the caller the spec names: may
		// run the FLOW, is not the performer.
		const provisioned = await request.post('/ocs/v2.php/cloud/users', {
			data: { userid: STRANGER, password: STRANGER_PASS },
		})
		test.skip(
			provisioned.status() !== 200,
			`cannot provision a performer account (HTTP ${provisioned.status()})`,
		)

		const performer = await apiRequest.newContext({
			baseURL:
				process.env.PLAYWRIGHT_BASE_URL
				|| process.env.NEXTCLOUD_URL
				|| process.env.BASE_URL,
			extraHTTPHeaders: STRANGER_HEADERS,
		})

		try {
			const flowId = await createFlow(
				request,
				'not the performer',
				[
					userTask('ask', { assignee: STRANGER }),
					setFields('done', { finished: true }),
				],
				[{ id: 'e1', from: 'ask', to: 'done' }],
			)
			flows.push(flowId)

			const run = await testRun(request, flowId)
			expect(run.status).toBe('suspended')

			// The performer sees it in THEIR inbox.
			const theirs = await performer.get(
				`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100`,
			)
			expect(theirs.status(), await theirs.text()).toBe(200)
			const task = (
				((await theirs.json()).results ?? []) as Array<
					Record<string, unknown>
				>
			).find((row) => row.runUuid === run.uuid)
			expect(task, 'the performer is asked').toBeTruthy()

			// The flow-runner posts a decision at the RUN. Whatever the door
			// says (the assignee guard refuses, or a nudge is accepted), the
			// task must not move and the run must still be waiting.
			const answered = await request.post(
				`${API}/flow-runs/${run.uuid}/resume`,
				{
					headers: JSON_HEADERS,
					data: { decision: 'approve', outcome: 'approved' },
				},
			)
			expect([200, 403]).toContain(answered.status())

			const taskAfter = await readTask(request, task!.uuid as string)
			expect(taskAfter.state).toBe('active')
			expect(taskAfter.isTerminal).toBe(false)
			expect(taskAfter.completedBy).toBeNull()

			const runAfter = await readRun(request, run.uuid)
			expect(runAfter.status).toBe('suspended')

			// Positive control: the performer CAN answer, through the task verb.
			const done = await performer.post(
				`${API}/flow-tasks/${task!.uuid}/complete`,
				{
					headers: JSON_HEADERS,
					data: { outcome: 'approved' },
				},
			)
			expect(done.status(), await done.text()).toBe(200)
		} finally {
			await performer.dispose()
			await request
				.delete(`/ocs/v2.php/cloud/users/${STRANGER}`)
				.catch((error) =>
					console.warn(
						'[flow-user-task] performer cleanup failed:',
						error,
					),
				)
		}
	})

	// @e2e flow-user-task-node::a-downstream-switch-branches-on-the-outcome
	test('a rejected task routes the flow down its rejection branch', async ({
		request,
	}) => {
		const flowId = await createFlow(
			request,
			'rejection branch',
			[
				userTask('ask', { advance: 'all' }),
				{
					id: 'route',
					type: 'openregister.route',
					config: {
						rules: [
							{
								condition: {
									'==': [{ var: 'json.task.outcome' }, 'rejected'],
								},
								output: 'no',
							},
						],
						default: 'yes',
					},
					exits: [{ id: 'no' }, { id: 'yes' }],
					position: { x: 0, y: 0 },
				},
				setFields('onRejected', { branch: 'rejected' }),
				setFields('onApproved', { branch: 'approved' }),
			],
			[
				{ id: 'e1', from: 'ask', to: 'route' },
				{ id: 'e2', from: 'route', fromExit: 'no', to: 'onRejected' },
				{ id: 'e3', from: 'route', fromExit: 'yes', to: 'onApproved' },
			],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		// A rejecting outcome requires a comment; the run is NOT failed by it.
		await complete(
			request,
			task!.uuid as string,
			'rejected',
			'Missing signature',
		)

		const after = await readRun(request, run.uuid)
		expect(after.status, 'a rejection is a branch, not a failure').toBe(
			'completed',
		)
		const transitions = (after.log ?? []).map(
			(entry: { transition?: string }) => entry.transition,
		)
		expect(transitions).toContain('onRejected')
		expect(transitions).not.toContain('onApproved')
		const item = (after.items ?? [])[0]?.json ?? {}
		expect(item.branch).toBe('rejected')
		expect(item.task?.rejected).toBe(true)
		expect(item.task?.comment).toBe('Missing signature')
	})

	// @e2e flow-user-task-node::two-approvals-require-two-answers
	test('a two-approval flow requires both approvals', async ({ request }) => {
		const flowId = await createFlow(
			request,
			'two approvals',
			[
				userTask('first', { advance: 'all', outcomeKey: 'first' }),
				userTask('second', { advance: 'all', outcomeKey: 'second' }),
				setFields('done', { finished: true }),
			],
			[
				{ id: 'e1', from: 'first', to: 'second' },
				{ id: 'e2', from: 'second', to: 'done' },
			],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		const first = await inboxTaskFor(request, run.uuid, 'first')
		expect(first).toBeTruthy()
		expect(
			await inboxTaskFor(request, run.uuid, 'second'),
			'the second question is not asked yet',
		).toBeNull()

		await complete(request, first!.uuid as string, 'approved')

		// The run continued past the first node and suspended AGAIN, on the
		// second, which raised a task of its own.
		const between = await readRun(request, run.uuid)
		expect(between.status).toBe('suspended')
		const second = await inboxTaskFor(request, run.uuid, 'second')
		expect(second, 'a second, distinct task').toBeTruthy()
		expect(second!.uuid).not.toBe(first!.uuid)

		await complete(request, second!.uuid as string, 'approved')

		const after = await readRun(request, run.uuid)
		expect(after.status).toBe('completed')
		const item = (after.items ?? [])[0]?.json ?? {}
		expect(item.first?.outcome).toBe('approved')
		expect(item.second?.outcome).toBe('approved')
		expect(item.finished).toBe(true)
	})

	// @e2e flow-user-task-node::a-budget-of-all-runs-to-the-next-stopping-point
	test('completing a task with an "all" budget finishes the run in one request', async ({
		request,
	}) => {
		const flowId = await createFlow(
			request,
			'all budget',
			[
				userTask('ask', { advance: 'all' }),
				setFields('one', { one: true }),
				setFields('two', { two: true }),
				{
					id: 'end',
					type: 'openregister.end',
					config: { message: 'done' },
					position: { x: 0, y: 0 },
				},
			],
			[
				{ id: 'e1', from: 'ask', to: 'one' },
				{ id: 'e2', from: 'one', to: 'two' },
				{ id: 'e3', from: 'two', to: 'end' },
			],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		await complete(request, task!.uuid as string, 'approved')

		// No worker pass happened between the completion and this read: the
		// completing request itself walked the two steps and the end.
		const after = await readRun(request, run.uuid)
		expect(['completed', 'stopped'], 'ended in-request').toContain(after.status)
		const transitions = (after.log ?? []).map(
			(entry: { transition?: string }) => entry.transition,
		)
		expect(transitions).toContain('one')
		expect(transitions).toContain('two')
	})

	// @e2e flow-user-task-node::stopping-a-run-empties-its-inboxes
	test("stopping a run removes its tasks from the assignees' inboxes", async ({
		request,
	}) => {
		const job = runWorkerJobId()
		test.skip(
			job === null,
			'FlowRunWorker not reachable via occ; there is no run-stop HTTP verb, so the operator stop needs the worker',
		)

		const flowId = await createFlow(
			request,
			'stopped',
			[userTask('ask'), setFields('done', { finished: true })],
			[{ id: 'e1', from: 'ask', to: 'done' }],
		)
		flows.push(flowId)

		const run = await testRun(request, flowId)
		expect(run.status).toBe('suspended')
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		try {
			// The operator's stop: the instance kill switch vetoes the next hop,
			// so the worker's walk ends the run as `stopped` (terminal), and
			// cancellation propagation terminates the run's open tasks.
			occ('config:app:set openregister flow_kill_switch --value=1')
			const nudged = await request.post(
				`${API}/flow-runs/${run.uuid}/resume`,
				{
					headers: JSON_HEADERS,
					data: {},
				},
			)
			expect(nudged.status(), await nudged.text()).toBe(200)
			occ(`background-job:execute ${job} --force-execute`)

			const after = await readRun(request, run.uuid)
			expect(after.status).toBe('stopped')

			const gone = await inboxTaskFor(request, run.uuid, 'ask')
			expect(gone, 'the task is no longer actionable in the inbox').toBeNull()

			// Terminated, not deleted: the record and its reason survive.
			const terminated = await readTask(request, task!.uuid as string)
			expect(terminated.state).toBe('terminated')
			expect(terminated.isTerminal).toBe(true)
			const audit = await request.get(`${API}/flow-tasks/${task!.uuid}/audit`)
			expect(audit.status()).toBe(200)
			const entries = ((await audit.json()).results ?? []) as Array<
				Record<string, unknown>
			>
			const termination = entries.find((e) => e.action === 'terminate')
			expect(termination, 'the termination is audited').toBeTruthy()
			expect(String(termination!.reason)).toContain(run.uuid)
		} finally {
			occ('config:app:delete openregister flow_kill_switch')
		}
	})
})
