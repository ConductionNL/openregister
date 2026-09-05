import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RUN-SCOPED OBJECT LOCKING, on a live instance, against the real engine.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The capability shipped validated by a MANUAL rig walkthrough. The
 * walkthrough never parked a run, so nobody noticed that the first version
 * released every lock a run held the moment the run suspended — which is the
 * one moment a lock is for. A lock that survives only while the run is
 * actively executing is not a lock; it is a mutex held for the duration of a
 * function call.
 *
 * So the assertion this suite is built around is the PARKED one:
 * `a run's lock survives the run being parked` reads the lock while the run's
 * stored status is `suspended`, not merely before and after the run.
 *
 * WHAT IS ASSERTED, AND WHY IT CANNOT PASS AGAINST THE BUG
 * -------------------------------------------------------
 * Every lock assertion is made through something that can only answer one way
 * if the lock is really held:
 *
 *   - `@self.locked` read back from a fresh GET carries `kind` and `runUuid`,
 *     so "locked" and "locked BY THIS RUN" are distinguishable. An earlier
 *     round of tests for this feature asserted `locked !== null` and passed
 *     against a user lock the run had silently stolen;
 *   - a refused write is asserted as 423 with `lockedByRun` equal to the run
 *     uuid, not as "some 4xx". A 400 from validation and a 500 from an
 *     untyped service exception are both "not 200" and neither is a lock;
 *   - the run that is supposed to bounce is asserted to reach `failed` with
 *     the HOLDER's uuid in its error. A run that fails for any other reason
 *     is a different bug wearing this test's green.
 *
 * HOW THE ENGINE IS DRIVEN
 * ------------------------
 * `POST /api/flow-runs/test` walks a flow synchronously until it suspends,
 * which is how a run is brought to the parked state deterministically, with
 * no clock and no polling. Advancing a PARKED run needs the worker, and the
 * worker is driven explicitly with
 * `occ background-job:execute <id> --force-execute`.
 *
 * ⚠️ NOT `cron.php`. `cron.php` is interval-gated: a tight loop of it performs
 * almost no worker passes, so a run that is perfectly healthy sits at
 * `suspended` for as long as the loop runs and the symptom reads as "wedged
 * forever". Every wait in this file is an explicit worker pass followed by a
 * read, never a sleep.
 *
 * IDEMPOTENCE (dossiq#1824, dossiq#1829)
 * --------------------------------------
 * Two runs of this file on one rig must give the same result. Everything it
 * creates is namespaced with `RUN_ID` and removed in `afterAll`:
 *   - its register and schema are deleted through the REST API;
 *   - its objects are destroyed with `occ openregister:objects:purge --apply
 *     --force`, so nothing is left in the deleted list either;
 *   - ⚠️ ITS LOCKS ARE RELEASED FIRST. A fixture that leaves a lock behind is
 *     not merely litter: the object cannot be written, so a second run of the
 *     same file meets a state the first run never saw. Teardown breaks every
 *     lock as the administrator before it purges.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md
 */
import { request as apiRequest, expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveBaseUrl, resolveContainer } from '../base-url.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}

const RUN_ID = `e2elock-${Date.now().toString(36)}`
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS =
	process.env.NEXTCLOUD_ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A second real person, so "refused" can be told from "refused only the run". */
const BYSTANDER = `${RUN_ID}-bystander`
const BYSTANDER_PASS = `Bystand3r!${Date.now().toString(36)}A`

// Same reasoning as flow-user-task.spec.ts: Basic auth and NO session cookie,
// so Nextcloud never demands a CSRF token, and `OCS-APIRequest` marks the
// calls as API traffic. With a session cookie present every write answers 412.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
}

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })
test.describe.configure({ mode: 'serial' })

const CONTAINER = resolveContainer()

/**
 * Run one `occ` command in the instance's container.
 *
 * @param args The occ arguments.
 *
 * @return The command's stdout.
 */
function occ(args: string): string {
	if (CONTAINER === null) {
		throw new Error('NC_CONTAINER is not set; refusing to guess a container.')
	}
	return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
		encoding: 'utf8',
		// `background-job:list` prints every job's serialized argument in one
		// table, measured at 1.8 MiB on a fresh instance — well past Node's
		// 1 MiB execSync default, whose ENOBUFS would surface as "occ is not
		// reachable" while occ is perfectly fine.
		maxBuffer: 32 * 1024 * 1024,
	})
}

/**
 * The FlowRunWorker's background-job id, found by class BASENAME so a
 * namespace move cannot turn this into a silent skip. Null when occ is
 * unreachable.
 *
 * @return The job id, or null.
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

let workerJob: string | null = null

/**
 * Perform one worker pass.
 *
 * Explicit, and asserted to be possible: a "wait" that cannot actually
 * advance anything is how a healthy run comes to look wedged.
 *
 * @return void
 */
function workerPass(): void {
	expect(
		workerJob,
		'the FlowRunWorker job could not be found, so a parked run can never be advanced here',
	).not.toBeNull()
	occ(`background-job:execute ${workerJob} --force-execute`)
}

type Json = Record<string, any>

let registerSlug = ''
let registerId: number | null = null
let schemaSlug = ''
let schemaId: number | null = null

/** Every object uuid this file created, for the purge in afterAll. */
const objects: string[] = []
/** Every flow uuid this file created. */
const flows: string[] = []

/**
 * Create an object on the fixture schema.
 *
 * @param request The admin API context.
 * @param name The object's name.
 *
 * @return The created object's uuid.
 */
async function createObject(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const resp = await request.post(`${API}/objects/${registerSlug}/${schemaSlug}`, {
		headers: JSON_HEADERS,
		data: { name, note: 'created by flow-object-locking.spec.ts' },
	})
	expect(resp.status(), await resp.text()).toBeLessThanOrEqual(201)
	const body = (await resp.json()) as Json
	const uuid = String(body['@self']?.id ?? body.id ?? '')
	expect(uuid, 'the created object must have a uuid').toBeTruthy()
	objects.push(uuid)
	return uuid
}

/**
 * Read an object back, fresh.
 *
 * @param request The API context to read as.
 * @param uuid The object uuid.
 *
 * @return The object body.
 */
async function readObject(request: APIRequestContext, uuid: string): Promise<Json> {
	const resp = await request.get(
		`${API}/objects/${registerSlug}/${schemaSlug}/${uuid}`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.status(), await resp.text()).toBe(200)
	return (await resp.json()) as Json
}

/**
 * The stored lock payload of an object, or null when it holds none.
 *
 * Read from `@self.locked`, which carries `kind`, `runUuid` and `user` — the
 * three fields that make "locked" and "locked BY WHOM" different questions.
 *
 * @param request The API context to read as.
 * @param uuid The object uuid.
 *
 * @return The lock payload, or null.
 */
async function lockOf(
	request: APIRequestContext,
	uuid: string,
): Promise<Json | null> {
	const body = await readObject(request, uuid)
	const lock = body['@self']?.locked ?? body.locked ?? null
	if (lock === null || lock === undefined) {
		return null
	}
	if (Array.isArray(lock) === true && lock.length === 0) {
		return null
	}
	return lock as Json
}

/**
 * Write to an object, merged over its current body.
 *
 * OpenRegister's PUT is a full replace validated against the schema, so a
 * partial body 400s on every required property it omits — which would look
 * like a refusal and is not one.
 *
 * @param request The API context to write as.
 * @param uuid The object uuid.
 * @param patch The fields to change.
 *
 * @return The raw response.
 */
async function writeObject(request: APIRequestContext, uuid: string, patch: Json) {
	const current = await readObject(request, uuid)
	const body = { ...current }
	delete body['@self']
	return request.put(`${API}/objects/${registerSlug}/${schemaSlug}/${uuid}`, {
		headers: JSON_HEADERS,
		data: { ...body, ...patch },
	})
}

/**
 * Author a flow and hand back its uuid.
 *
 * @param request The admin API context.
 * @param label What the flow is for.
 * @param nodes The graph's nodes.
 * @param edges The graph's edges.
 *
 * @return The flow uuid.
 */
async function createFlow(
	request: APIRequestContext,
	label: string,
	nodes: Json[],
	edges: Json[],
): Promise<string> {
	const resp = await request.post(`${API}/flows`, {
		headers: JSON_HEADERS,
		data: {
			name: `${RUN_ID} ${label}`,
			description: 'Created by the run-scoped object-locking e2e suite.',
			trigger: 'manual',
			enabled: true,
			nodes,
			edges,
		},
	})
	expect(resp.status(), await resp.text()).toBe(201)
	const body = (await resp.json()) as Json
	const uuid = String(body.uuid ?? body.id ?? '')
	expect(uuid, 'flow uuid').toBeTruthy()
	flows.push(uuid)
	return uuid
}

/** A lock step against one explicit object. */
function lockStep(id: string, uuid: string, config: Json = {}): Json {
	return {
		id,
		type: 'openregister.lock-object',
		config: { uuid, process: `${RUN_ID} holding for a test`, ...config },
		position: { x: 0, y: 0 },
	}
}

/** A user task, which is this suite's deterministic way to PARK a run. */
function userTask(id: string): Json {
	return {
		id,
		type: 'openregister.user-task',
		config: {
			title: `${RUN_ID} ${id}`,
			assignee: ADMIN,
			outcomes: 'done',
		},
		position: { x: 0, y: 0 },
	}
}

/**
 * The step a flow ends ON.
 *
 * ⚠️ DELIBERATELY `set-fields` AND NOT `openregister.end`. A run that reaches
 * an `end` node is committed as `stopped`, because `stopped` outranks
 * `completed` in the commit path's severity table — so a flow terminated by
 * `end` can never assert the spec's "a COMPLETED run releases its locks"
 * scenario, and asserting `stopped` there would quietly test a different
 * requirement than the one named. A run whose last step simply has no
 * outgoing edge is committed `completed`, which is the outcome under test.
 * Measured on the rig 2026-09-05: identical graphs ending in `end` and in
 * `set-fields` commit as `stopped` and `completed` respectively.
 *
 * @param id The node id.
 *
 * @return The node.
 */
function finalStep(id: string): Json {
	return {
		id,
		type: 'openregister.set-fields',
		config: { set: { finished: true } },
		position: { x: 0, y: 0 },
	}
}

/**
 * Walk a flow synchronously until it suspends or ends.
 *
 * @param request The admin API context.
 * @param flowId The flow uuid.
 *
 * @return The run row.
 */
async function testRun(request: APIRequestContext, flowId: string): Promise<Json> {
	const resp = await request.post(`${API}/flow-runs/test`, {
		headers: JSON_HEADERS,
		data: { flowId, seedItems: [{ json: { origin: RUN_ID } }] },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	return (await resp.json()) as Json
}

/**
 * Read a run back.
 *
 * @param request The admin API context.
 * @param uuid The run uuid.
 *
 * @return The run row.
 */
async function readRun(request: APIRequestContext, uuid: string): Promise<Json> {
	const resp = await request.get(`${API}/flow-runs/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return (await resp.json()) as Json
}

/**
 * The open task a run raised, through the assignee's inbox.
 *
 * @param request The admin API context.
 * @param runUuid The run that raised it.
 *
 * @return The task row, or null.
 */
async function openTaskFor(
	request: APIRequestContext,
	runUuid: string,
): Promise<Json | null> {
	const resp = await request.get(
		`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100&sort=created&direction=desc`,
	)
	expect(resp.status(), await resp.text()).toBe(200)
	const rows = ((await resp.json()).results ?? []) as Json[]
	return rows.find((row) => row.runUuid === runUuid) ?? null
}

/**
 * Advance a parked run until it leaves `suspended`, or until the worker
 * passes run out.
 *
 * ⚠️ THE WAITING IS EXPLICIT, IN BOTH HALVES, AND NEITHER HALF IS OPTIONAL.
 *
 * 1. A worker pass only picks up a run whose `resumeAt` is DUE. A lock step
 *    parks on `min(now + backoff, deadline)`, so a pass fired before that
 *    moment does nothing at all — and a loop of such passes produces a
 *    perfectly convincing "wedged forever", which is a symptom of the test,
 *    not of the run. So sleep to the run's own stated wake time first, and
 *    say that that is what is being waited for.
 * 2. Then fire a pass. NOT `cron.php`: it is interval-gated, so a tight loop
 *    of it performs almost no worker passes and fabricates the same false
 *    symptom from the other direction.
 *
 * The bound is a COUNT OF WORKER PASSES rather than a wall clock, because a
 * pass is what a parked run is actually waiting for — so the failure message
 * can say how many it got.
 *
 * @param request The admin API context.
 * @param uuid The run uuid.
 * @param passes How many worker passes to spend.
 *
 * @return The run row as last read.
 */
async function driveUntilTerminal(
	request: APIRequestContext,
	uuid: string,
	passes = 6,
): Promise<Json> {
	let run = await readRun(request, uuid)

	for (let i = 0; i < passes && run.status === 'suspended'; i++) {
		const due = run.resumeAt ? new Date(String(run.resumeAt)).getTime() : 0
		const waitFor = due - Date.now()
		if (waitFor > 0) {
			// A 250 ms cushion: `resumeAt` is stored to whole-second precision,
			// so waking at exactly the stamped second can land a hair early and
			// spend a pass on a run the worker does not yet consider due.
			await new Promise((resolve) => setTimeout(resolve, waitFor + 250))
		}
		workerPass()
		run = await readRun(request, uuid)
	}

	return run
}

test.beforeAll(async () => {
	workerJob = runWorkerJobId()
	test.skip(
		workerJob === null,
		'occ is not reachable (set NC_CONTAINER), so a parked run could never be advanced and every '
			+ 'assertion about a run ENDING would be untestable here',
	)

	const admin = await apiRequest.newContext({
		baseURL: resolveBaseUrl(),
		extraHTTPHeaders: ADMIN_HEADERS,
	})
	try {
		// A register and schema of this file's own, so the suite depends on no
		// seed and two rigs behave identically.
		registerSlug = `${RUN_ID}-register`
		schemaSlug = `${RUN_ID}-schema`

		const schema = await admin.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				slug: schemaSlug,
				title: `${RUN_ID} lockable`,
				description: 'Fixture schema for the object-locking e2e suite.',
				properties: {
					name: { type: 'string', title: 'Name' },
					note: { type: 'string', title: 'Note' },
				},
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id ?? null
		expect(schemaId, 'fixture schema id').toBeTruthy()

		const register = await admin.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: {
				slug: registerSlug,
				title: `${RUN_ID} lockable register`,
				description: 'Fixture register for the object-locking e2e suite.',
				schemas: [schemaId],
			},
		})
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id ?? null
		expect(registerId, 'fixture register id').toBeTruthy()
	} finally {
		await admin.dispose()
	}
})

test.afterAll(async () => {
	const admin = await apiRequest.newContext({
		baseURL: resolveBaseUrl(),
		extraHTTPHeaders: ADMIN_HEADERS,
	})

	try {
		// 1. STOP EVERY RUN THIS FILE STARTED. A suspended run wakes on the
		//    next worker pass — including one another spec triggers — and
		//    re-takes a lock on an object that is about to be purged.
		for (const flow of flows) {
			await admin.delete(`${API}/flows/${flow}`).catch(() => {})
		}

		// 2. RELEASE EVERY LOCK, as the administrator, who is the only caller
		//    allowed to break a run's lock. Skipping this is what makes a
		//    suite non-rerunnable: the objects below cannot be written by the
		//    next run, and the FAILURE surfaces in whichever test writes
		//    first, nowhere near the fixture that caused it.
		for (const uuid of objects) {
			await admin
				.post(
					`${API}/objects/${registerSlug}/${schemaSlug}/${uuid}/unlock`,
					{
						headers: JSON_HEADERS,
					},
				)
				.catch(() => {})
		}

		// 3. DESTROY THE ROWS. `occ openregister:objects:purge` rather than a
		//    DELETE, because a DELETE only soft-deletes: the rows would still
		//    be in the deleted list on the next run (dossiq#1829).
		if (objects.length > 0 && CONTAINER !== null) {
			try {
				occ(
					`openregister:objects:purge ${objects.join(' ')} --force --apply`,
				)
			} catch (error) {
				console.warn('[flow-object-locking] purge failed:', error)
			}
		}

		// 4. Then the container entities.
		if (registerId !== null) {
			await admin.delete(`${API}/registers/${registerId}`).catch(() => {})
		}
		if (schemaId !== null) {
			await admin.delete(`${API}/schemas/${schemaId}`).catch(() => {})
		}
	} finally {
		await admin.dispose()
	}
})

test.describe('run-scoped object locking, against the live engine', () => {
	// ── LEG 5 IS NOT HERE, DELIBERATELY ─────────────────────────────────────
	// That both lock steps are present in the node catalogue, that each one's
	// icon actually resolves, and that each can be dropped on the editor's
	// canvas is `tests/e2e/ci/flow-lock-nodes.spec.ts`. It needs no worker and
	// no occ, so it runs on the CI floor, where this file cannot. Asserting it
	// in both places would mean two spellings of one guarantee, and the one
	// that runs less often is the one that would rot.

	// ── LEG 1 ───────────────────────────────────────────────────────────────
	// @e2e run-scoped-object-locking::a-parked-run-keeps-its-locks
	// @e2e run-scoped-object-locking::a-completed-run-releases-its-locks
	test('an object is locked by its run, stays locked while the run is PARKED, and is released when the run ends', async ({
		request,
	}) => {
		const subject = await createObject(request, `${RUN_ID} parked subject`)

		const flow = await createFlow(
			request,
			'lock then park',
			[lockStep('lock', subject), userTask('wait'), finalStep('done')],
			[
				{ id: 'e1', from: 'lock', to: 'wait' },
				{ id: 'e2', from: 'wait', to: 'done' },
			],
		)

		const run = await testRun(request, flow)

		// The run is parked — on a clock, never on a null resumeAt, which is
		// the shape `findAbandonedSignals()` fails after 14 days.
		expect(run.status, 'the run should be parked at its user task').toBe(
			'suspended',
		)
		expect(
			run.resumeAt,
			'a parked run waits on a time, not on nothing',
		).toBeTruthy()

		// 🔴 THE ASSERTION THIS WHOLE FILE EXISTS FOR.
		// Read the lock WHILE the run's stored status is `suspended`. The
		// first version of this capability announced a terminal event on the
		// way to `suspended`, and the announcement is what releases the
		// locks — so the object was wide open for exactly as long as the run
		// was waiting. A test that read the lock before and after the run
		// would have passed against that.
		const parked = await readRun(request, run.uuid)
		expect(parked.status, 'still parked when the lock is read').toBe('suspended')

		const held = await lockOf(request, subject)
		expect(
			held,
			'the parked run holds NO lock — a run that loses its lock the moment it waits has no lock at all',
		).not.toBeNull()
		expect(held!.kind, 'the lock must be run-scoped, not a user lock').toBe(
			'run',
		)
		expect(
			held!.runUuid,
			'the lock must name THIS run; a lock held by something else is a different bug',
		).toBe(run.uuid)
		expect(held!.user, "the run's acting identity is recorded too").toBe(ADMIN)

		// Answer the task and let the worker finish the run.
		const task = await openTaskFor(request, run.uuid as string)
		expect(task, 'the parking step raised a task').toBeTruthy()
		const done = await request.post(`${API}/flow-tasks/${task!.uuid}/complete`, {
			headers: JSON_HEADERS,
			data: { outcome: 'done', comment: null },
		})
		expect(done.status(), await done.text()).toBe(200)

		const ended = await driveUntilTerminal(request, run.uuid as string)
		expect(
			ended.status,
			'the run never reached a terminal state, so "released on end" was never actually exercised',
		).toBe('completed')

		expect(
			await lockOf(request, subject),
			'the completed run did not release its lock',
		).toBeNull()

		// And the object is writable again — the release is real, not just a
		// cleared field.
		const after = await writeObject(request, subject, { note: 'released' })
		expect(after.status(), await after.text()).toBeLessThanOrEqual(201)
	})

	// ── LEG 2 ───────────────────────────────────────────────────────────────
	// @e2e run-scoped-object-locking::an-exhausted-budget-fails-and-names-the-holder
	test('a second run bounces off the lock: it parks, then fails naming the holding run', async ({
		request,
	}) => {
		const subject = await createObject(request, `${RUN_ID} contended subject`)

		// Holder: parks at a user task with the lock held, and stays there for
		// the whole of this test.
		const holdingFlow = await createFlow(
			request,
			'holder',
			[lockStep('lock', subject), userTask('wait'), finalStep('done')],
			[
				{ id: 'e1', from: 'lock', to: 'wait' },
				{ id: 'e2', from: 'wait', to: 'done' },
			],
		)
		const holder = await testRun(request, holdingFlow)
		expect(holder.status).toBe('suspended')
		expect((await lockOf(request, subject))!.runUuid).toBe(holder.uuid)

		// Challenger: a THREE-second budget, so the first attempt parks and the
		// second is past the deadline.
		//
		// ⚠️ NOT ONE SECOND, WHICH IS RACY. The deadline is persisted with
		// `format('c')`, which truncates to whole seconds, so a one-second
		// budget stamped at .900 is already expired 200 ms later when the
		// first acquire returns — and the run FAILS on its first attempt
		// instead of parking. Measured on the rig 2026-09-05: identical
		// fixtures gave `failed` and `suspended` on consecutive runs. Three
		// seconds leaves at least two whole seconds after truncation, which is
		// an order of magnitude more than the ~120 ms an acquire takes.
		//
		// It also fixes the wake time: the backoff floor is 60 s but
		// `nextAttemptAt()` clamps to the deadline, so the run wakes AT the
		// deadline rather than a minute later.
		const challengingFlow = await createFlow(
			request,
			'challenger',
			[lockStep('lock', subject, { waitSeconds: 3 }), finalStep('done')],
			[{ id: 'e1', from: 'lock', to: 'done' }],
		)
		const challenger = await testRun(request, challengingFlow)

		expect(
			challenger.status,
			'the second run did not park — it either took a lock another run holds, or failed on first contact',
		).toBe('suspended')
		expect(
			challenger.resumeAt,
			'a lock wait is on a clock; a null resumeAt is the "waiting for a signal" shape the reaper kills',
		).toBeTruthy()

		// The holder still holds it. The challenger parking must not have
		// displaced anything.
		const stillHeld = await lockOf(request, subject)
		expect(
			stillHeld!.runUuid,
			'the challenger took the lock while waiting',
		).toBe(holder.uuid)

		const bounced = await driveUntilTerminal(request, challenger.uuid as string)
		expect(
			bounced.status,
			'the challenger never gave up; a wait budget that never expires is not a budget',
		).toBe('failed')

		const reason = JSON.stringify(bounced.error ?? bounced.log ?? bounced)
		expect(
			reason,
			'the failure does not name the run that was holding the object, so an operator cannot act on it',
		).toContain(holder.uuid)

		// AND IT DID NOT BREAK THE LOCK ON ITS WAY OUT. Letting a waiting run
		// take the lock by outlasting it would make the lock advisory again.
		const afterBounce = await lockOf(request, subject)
		expect(
			afterBounce,
			'the failing run broke the lock it was refused',
		).not.toBeNull()
		expect(afterBounce!.runUuid).toBe(holder.uuid)
	})

	// ── LEG 3 ───────────────────────────────────────────────────────────────
	// @e2e run-scoped-object-locking::a-person-is-refused-while-a-run-holds-the-lock
	// @e2e run-scoped-object-locking::a-runs-own-runas-user-is-still-refused
	test("a person's write is refused with 423 while a run holds the lock, the run's own runAs user included", async ({
		request,
	}) => {
		const subject = await createObject(request, `${RUN_ID} guarded subject`)

		const flow = await createFlow(
			request,
			'guard',
			[lockStep('lock', subject), userTask('wait'), finalStep('done')],
			[
				{ id: 'e1', from: 'lock', to: 'wait' },
				{ id: 'e2', from: 'wait', to: 'done' },
			],
		)
		const run = await testRun(request, flow)
		expect(run.status).toBe('suspended')
		expect((await lockOf(request, subject))!.runUuid).toBe(run.uuid)

		// 🔴 THE ADMIN IS THE RUN'S OWN `runAs`. Run-scoped ownership means a
		// run and the person it executes as are DIFFERENT HOLDERS, so this
		// account is refused too. Under the old user-keyed lock this write
		// succeeded, and on an instance where flows run as one service
		// account that is EVERY person's write.
		const asRunAs = await writeObject(request, subject, { note: 'by the runAs' })
		expect(
			asRunAs.status(),
			`the run's own runAs user was allowed to write: ${await asRunAs.text()}`,
		).toBe(423)
		const refusal = (await asRunAs.json()) as Json
		expect(
			refusal.lockedByRun,
			'the refusal must name the holding RUN, not just say "locked"',
		).toBe(run.uuid)
		expect(String(refusal.error)).toContain('flow run')
		expect(String(refusal.error)).toContain(String(run.uuid))

		// And a different person, who is not the runAs either.
		const provisioned = await request.post('/ocs/v2.php/cloud/users', {
			data: { userid: BYSTANDER, password: BYSTANDER_PASS },
		})
		expect(
			provisioned.status(),
			'could not provision a bystander account, so the "any person" half is untested',
		).toBe(200)

		const bystander = await apiRequest.newContext({
			baseURL: resolveBaseUrl(),
			extraHTTPHeaders: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				Authorization: `Basic ${Buffer.from(
					`${BYSTANDER}:${BYSTANDER_PASS}`,
				).toString('base64')}`,
			},
		})
		try {
			const byOther = await bystander.put(
				`${API}/objects/${registerSlug}/${schemaSlug}/${subject}`,
				{
					headers: JSON_HEADERS,
					data: { name: 'taken over', note: 'nope' },
				},
			)
			// 423 when the caller can see the object, 403/404 when RBAC hides
			// it from them first — but never a write that lands.
			expect(
				[423, 403, 404],
				`a bystander's write answered ${byOther.status()}`,
			).toContain(byOther.status())
			expect(
				byOther.status(),
				'a bystander wrote to a locked object',
			).not.toBe(200)
		} finally {
			await bystander.dispose()
			await request
				.delete(`/ocs/v2.php/cloud/users/${BYSTANDER}`)
				.catch(() => {})
		}

		// PATCH is the same door. It used to answer 400 or 500 here, because
		// only PUT carried the guard.
		const patched = await request.patch(
			`${API}/objects/${registerSlug}/${schemaSlug}/${subject}`,
			{ headers: JSON_HEADERS, data: { note: 'by patch' } },
		)
		expect(
			patched.status(),
			`PATCH answered ${patched.status()}: a lock only PUT enforces is not a lock`,
		).toBe(423)

		// Leave nothing parked behind: end the run, which releases the lock.
		const task = await openTaskFor(request, run.uuid as string)
		if (task !== null) {
			await request.post(`${API}/flow-tasks/${task.uuid}/complete`, {
				headers: JSON_HEADERS,
				data: { outcome: 'done', comment: null },
			})
			await driveUntilTerminal(request, run.uuid as string)
		}
	})

	// ── LEG 4 ───────────────────────────────────────────────────────────────
	// @e2e run-scoped-object-locking::a-persons-lock-survives-a-run-passing-over-the-object
	// @e2e run-scoped-object-locking::the-holder-writes-freely
	test("a person's own lock survives a run passing over the object, and the run takes it once she releases", async ({
		request,
	}) => {
		const subject = await createObject(request, `${RUN_ID} person-held subject`)

		// A PERSON takes the lock, as a person.
		const taken = await request.post(
			`${API}/objects/${registerSlug}/${schemaSlug}/${subject}/lock`,
			{
				headers: JSON_HEADERS,
				data: { process: `${RUN_ID} mine`, duration: 3600 },
			},
		)
		expect(taken.status(), await taken.text()).toBe(200)

		const mine = await lockOf(request, subject)
		expect(mine, 'the person did not get a lock').not.toBeNull()
		expect(mine!.user).toBe(ADMIN)
		expect(mine!.kind ?? 'user').toBe('user')

		// The holder writes freely — the guard refuses others, not the owner.
		const ownWrite = await writeObject(request, subject, { note: 'still mine' })
		expect(
			ownWrite.status(),
			`the lock holder was refused her own write: ${await ownWrite.text()}`,
		).toBeLessThanOrEqual(201)

		// ⚠️ AND THAT WRITE RELEASED HER LOCK. A completed write releases the
		// writer's OWN lock — the object was checked out, the edit landed, the
		// checkout is over. That is by design, but it is invisible from the
		// call, and reading it as "the lock survives a write" is exactly how
		// this test first went green for the wrong reason: the run below then
		// acquired an object nobody held, and the pass said "a person's lock
		// survived a run".
		expect(
			await lockOf(request, subject),
			"a holder's own successful write is expected to release her lock; if it no longer does, "
				+ 'the re-lock below is silently testing an extend rather than a fresh take',
		).toBeNull()

		// So take it again, and THIS time do not write, so the lock the run
		// meets below is one a person is actually still holding.
		const retaken = await request.post(
			`${API}/objects/${registerSlug}/${schemaSlug}/${subject}/lock`,
			{
				headers: JSON_HEADERS,
				data: { process: `${RUN_ID} mine again`, duration: 3600 },
			},
		)
		expect(retaken.status(), await retaken.text()).toBe(200)
		expect((await lockOf(request, subject))!.kind ?? 'user').toBe('user')

		// 🔴 NOW A RUN WALKS OVER IT, EXECUTING AS THE SAME PERSON. Under the
		// user-keyed lock this did not merely pass the guard: it took the
		// EXTEND branch, rewrote the payload as its own run lock, and released
		// it when the run ended — destroying a lock a person was relying on,
		// with no error and no audited displacement.
		// An eight-second budget, for the same reason the challenger above uses
		// three: `nextAttemptAt()` clamps the 60 s backoff floor to the
		// deadline, so a short budget makes the run's own wake time short and
		// the wait deterministic instead of a flat minute of wall clock. The
		// person releases the lock within a few hundred milliseconds, so the
		// budget is never what decides this test — the retry does.
		const flow = await createFlow(
			request,
			'passer-by',
			[lockStep('lock', subject, { waitSeconds: 8 }), finalStep('done')],
			[{ id: 'e1', from: 'lock', to: 'done' }],
		)
		const run = await testRun(request, flow)

		expect(
			run.status,
			"the run was handed a person's lock instead of waiting for it",
		).toBe('suspended')

		const survived = await lockOf(request, subject)
		expect(survived, "the run destroyed the person's lock").not.toBeNull()
		expect(
			survived!.kind ?? 'user',
			"the run rewrote the person's lock as its own",
		).toBe('user')
		expect(survived!.user, 'the recorded holder changed').toBe(ADMIN)
		expect(
			survived!.runUuid ?? null,
			'a user lock must not carry a run uuid',
		).toBeNull()

		// The person releases, and only then the run picks it up.
		const released = await request.post(
			`${API}/objects/${registerSlug}/${schemaSlug}/${subject}/unlock`,
			{ headers: JSON_HEADERS },
		)
		expect(released.status(), await released.text()).toBe(200)
		expect(
			await lockOf(request, subject),
			'the unlock did not release',
		).toBeNull()

		const finished = await driveUntilTerminal(request, run.uuid as string)
		expect(
			finished.status,
			'the run never resumed after the object was freed, so the retry path is unproven',
		).toBe('completed')

		// It completed, which means the lock step succeeded on re-entry — and
		// the completed run released again, so nothing is left holding.
		expect(
			await lockOf(request, subject),
			'the run that finally took the lock did not release it',
		).toBeNull()
	})
})
