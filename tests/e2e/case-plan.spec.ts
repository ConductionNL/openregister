/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case plan e2e: the six @e2e-marked scenarios of the flow-cases spec, driven
 * through the REST API against one freshly created anchor object.
 *
 * 1. the case-plan route: a plan is read by the object's uuid alone, with
 *    every item's state, type and parent, and no run uuid anywhere.
 * 2. the sentry cascade: completing the intake task reaches the milestone,
 *    which admits the assessment stage and its decision item, in one pass.
 * 3. stage termination: terminating the assessment stage terminates its
 *    active child and disables its unentered child, each with a `cascade`
 *    audit row naming the stage.
 * 4. task realisation: completing the realising task completes the plan item
 *    with the task completion as the audited cause.
 * 5. the ad-hoc item: attached to the active stage, entered and realised as
 *    a task, without any flow or definition version being touched.
 * 6. write-through: the anchoring object carries the mirrored status and the
 *    moment it was reached, read through the ordinary object API.
 *
 * Scenario 4 is driven through `POST /api/cases/{objectUuid}/evaluate` after
 * completing the task. Once flow-user-task-node (#3269) lands, TaskService
 * announces TaskTerminalEvent and CaseTaskTerminalListener performs that
 * evaluation itself; the explicit call stays valid (idempotent) either way.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const RUN_ID = `e2e-case-${Date.now().toString(36)}`
const ADMIN = process.env.OR_USER || 'admin'

// Known register + schema from the dev seed (same pair core-crud.spec.ts uses).
const REGISTER_ID = process.env.OR_CASE_REGISTER || '8'
const SCHEMA_ID = process.env.OR_CASE_SCHEMA || '18'

// Same reasoning as task-inbox.spec.ts: Basic auth, no session cookie, so no
// CSRF token is demanded; `OCS-APIRequest` marks the calls as API traffic.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(
		`${ADMIN}:${process.env.OR_PASS || 'admin'}`,
	).toString('base64')}`,
}

const CASES = '/index.php/apps/openregister/api/cases'
const TASKS = '/index.php/apps/openregister/api/flow-tasks'
const OBJECTS = `/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })

type Item = {
	uuid: string
	key: string
	type: string
	state: string
	parentItemId: number | null
	origin: string
	realisationKind: string | null
	realisationUuid: string | null
	flowUuid: string | null
}
type Audit = {
	caseItemId: number
	toState: string
	cause: string
	causeRef: string | null
}
type Plan = { objectUuid: string; items: Item[]; audit: Audit[] }

/** The two-stage permit definition from design.md, seed 1 and 2. */
function permitDefinition() {
	return {
		settings: {
			authorization: [`user:${ADMIN}`],
			results: ['verleend', 'geweigerd'],
			writeThrough: {
				statusField: 'status',
				statusAtField: 'statusReachedAt',
			},
		},
		items: [
			{
				key: 'intake',
				type: 'stage',
				name: 'Intake',
				children: [
					{
						key: 'completeness-check',
						type: 'humanTask',
						name: `${RUN_ID} volledigheid`,
						candidateUsers: [ADMIN],
					},
					{
						key: 'application-complete',
						type: 'milestone',
						name: 'Aanvraag volledig',
						entryCriteria: [
							{
								id: 'complete',
								on: {
									event: 'case.item.completed',
									item: 'completeness-check',
								},
							},
						],
					},
				],
			},
			{
				key: 'assessment',
				type: 'stage',
				name: 'Beoordeling',
				entryCriteria: [
					{
						id: 'after-intake',
						on: {
							event: 'case.item.completed',
							item: 'application-complete',
						},
					},
				],
				children: [
					{
						key: 'decide',
						type: 'humanTask',
						name: `${RUN_ID} besluit`,
						candidateUsers: [ADMIN],
					},
					{
						key: 'decided',
						type: 'milestone',
						name: 'Besloten',
						entryCriteria: [
							{
								id: 'after-decide',
								on: { event: 'case.item.completed', item: 'decide' },
							},
						],
					},
				],
			},
		],
	}
}

/** Create the anchor object and hand back its uuid. */
async function createObject(request: APIRequestContext): Promise<string> {
	const response = await request.post(OBJECTS, {
		data: { title: `${RUN_ID} case`, name: `${RUN_ID} case`, status: 'nieuw' },
	})
	expect(response.status(), await response.text()).toBe(201)
	const body = await response.json()
	return body['@self']?.id ?? body.id ?? body.uuid
}

/** Read the plan. */
async function readPlan(
	request: APIRequestContext,
	objectUuid: string,
): Promise<Plan> {
	const response = await request.get(`${CASES}/${objectUuid}`)
	expect(response.status(), await response.text()).toBe(200)
	return response.json()
}

function byKey(plan: Plan, key: string): Item {
	const item = plan.items.find((candidate) => candidate.key === key)
	expect(item, `item ${key} exists`).toBeTruthy()
	return item as Item
}

/** Claim and complete a task as the admin. */
async function completeTask(request: APIRequestContext, taskUuid: string) {
	const claim = await request.post(`${TASKS}/${taskUuid}/claim`)
	expect(claim.status(), await claim.text()).toBe(200)
	const complete = await request.post(`${TASKS}/${taskUuid}/complete`, {
		data: { outcome: 'done', comment: `${RUN_ID}` },
	})
	expect(complete.status(), await complete.text()).toBe(200)
}

/** Delete the plan and the object; never a verdict on the code under test. */
async function cleanup(request: APIRequestContext, objectUuid: string | null) {
	if (objectUuid === null) return
	try {
		await request.delete(`${CASES}/${objectUuid}`)
		await request.delete(`${OBJECTS}/${objectUuid}`)
	} catch (error) {
		console.warn('[case-plan] cleanup failed:', error)
	}
}

test.describe('flow-cases — a case plan anchored to an object', () => {
	let objectUuid: string | null = null

	test.afterEach(async ({ request }) => {
		await cleanup(request, objectUuid)
		objectUuid = null
	})

	test('the case plan is read by object uuid, without any run', async ({
		request,
	}) => {
		objectUuid = await createObject(request)
		const created = await request.post(`${CASES}/${objectUuid}`, {
			data: {
				register: Number(REGISTER_ID),
				schema: Number(SCHEMA_ID),
				definition: permitDefinition(),
			},
		})
		expect(created.status(), await created.text()).toBe(201)

		const plan = await readPlan(request, objectUuid)
		expect(plan.objectUuid).toBe(objectUuid)
		expect(plan.items).toHaveLength(6)
		for (const item of plan.items) {
			expect(['stage', 'humanTask', 'milestone']).toContain(item.type)
			expect(item.state).toBeTruthy()
			expect(item.flowUuid).toBeNull()
		}
		const intake = byKey(plan, 'intake')
		expect(intake.state).toBe('active')
		expect(intake.parentItemId).toBeNull()
		expect(byKey(plan, 'completeness-check').state).toBe('active')
		expect(byKey(plan, 'application-complete').state).toBe('available')
		expect(byKey(plan, 'assessment').state).toBe('available')
		expect(byKey(plan, 'decide').parentItemId).not.toBeNull()

		// A definition naming an event outside the catalog is refused at save time.
		const bad = permitDefinition()
		bad.items[1].entryCriteria = [
			{ id: 'x', on: { event: 'case.item.started', item: 'y' } },
		]
		const other = await createObject(request)
		try {
			const refused = await request.post(`${CASES}/${other}`, {
				data: {
					register: Number(REGISTER_ID),
					schema: Number(SCHEMA_ID),
					definition: bad,
				},
			})
			expect(refused.status()).toBe(400)
			expect((await refused.json()).error).toContain('case.item.started')
			const none = await request.get(`${CASES}/${other}`)
			expect(none.status()).toBe(404)
		} finally {
			await cleanup(request, other)
		}
	})

	test('completing the task completes its item, the milestone admits the next stage, and status is written through', async ({
		request,
	}) => {
		objectUuid = await createObject(request)
		const created = await request.post(`${CASES}/${objectUuid}`, {
			data: {
				register: Number(REGISTER_ID),
				schema: Number(SCHEMA_ID),
				definition: permitDefinition(),
			},
		})
		expect(created.status(), await created.text()).toBe(201)

		let plan = await readPlan(request, objectUuid)
		const check = byKey(plan, 'completeness-check')
		expect(check.realisationKind).toBe('task')
		expect(check.realisationUuid).toBeTruthy()

		// Scenario 4: the task's completion drives the item.
		await completeTask(request, check.realisationUuid as string)
		const evaluated = await request.post(`${CASES}/${objectUuid}/evaluate`)
		expect(evaluated.status(), await evaluated.text()).toBe(200)

		plan = await readPlan(request, objectUuid)
		const checkDone = byKey(plan, 'completeness-check')
		expect(checkDone.state).toBe('completed')
		const checkAudit = plan.audit.filter(
			(entry) =>
				entry.caseItemId
					=== Number((check as unknown as { id: number }).id ?? -1)
				|| entry.causeRef === check.realisationUuid,
		)
		expect(
			checkAudit.some(
				(entry) =>
					entry.cause === 'realisation'
					&& entry.causeRef === check.realisationUuid,
			),
		).toBe(true)

		// Scenario 2: the milestone was reached and admitted the assessment stage in the same evaluation.
		expect(byKey(plan, 'application-complete').state).toBe('completed')
		expect(byKey(plan, 'intake').state).toBe('completed')
		expect(byKey(plan, 'assessment').state).toBe('active')
		expect(byKey(plan, 'decide').state).toBe('active')
		expect(
			plan.audit.some(
				(entry) =>
					entry.cause === 'sentry' && entry.causeRef === 'after-intake',
			),
		).toBe(true)

		// Scenario 6: the object carries the mirrored status, read by a consumer
		// that knows nothing about plan items.
		const object = await request.get(`${OBJECTS}/${objectUuid}`)
		expect(object.status(), await object.text()).toBe(200)
		const body = await object.json()
		expect(body.status).toBe('Aanvraag volledig')
		expect(body.statusReachedAt).toBeTruthy()
	})

	test('an ad-hoc item is attached to the live stage and realised, and terminating the stage cascades', async ({
		request,
	}) => {
		objectUuid = await createObject(request)
		const created = await request.post(`${CASES}/${objectUuid}`, {
			data: {
				register: Number(REGISTER_ID),
				schema: Number(SCHEMA_ID),
				definition: permitDefinition(),
			},
		})
		expect(created.status(), await created.text()).toBe(201)

		const flowsBefore = await (
			await request.get('/index.php/apps/openregister/api/flows?limit=500')
		).json()

		// Scenario 5: attach an unplanned advice request to the active intake stage.
		const attached = await request.post(`${CASES}/${objectUuid}/items`, {
			data: {
				key: 'external-advice',
				type: 'humanTask',
				name: `${RUN_ID} extern advies`,
				parent: 'intake',
				required: false,
				candidateUsers: [ADMIN],
			},
		})
		expect(attached.status(), await attached.text()).toBe(201)
		const advice: Item = await attached.json()
		expect(advice.origin).toBe('adhoc')
		expect(advice.state).toBe('active')
		expect(advice.realisationKind).toBe('task')
		expect(advice.flowUuid).toBeNull()
		const task = await request.get(`${TASKS}/${advice.realisationUuid}`)
		expect(task.status(), await task.text()).toBe(200)
		expect((await task.json()).runUuid).toBeNull()

		// No flow definition and no definition version changed.
		const flowsAfter = await (
			await request.get('/index.php/apps/openregister/api/flows?limit=500')
		).json()
		expect(JSON.stringify(flowsAfter.results ?? flowsAfter)).toBe(
			JSON.stringify(flowsBefore.results ?? flowsBefore),
		)

		// An ad-hoc item may not declare itself unguarded.
		const unguarded = await request.post(`${CASES}/${objectUuid}/items`, {
			data: {
				key: 'sneaky',
				type: 'humanTask',
				parent: 'intake',
				authorization: [],
			},
		})
		expect(unguarded.status()).toBe(400)

		// Scenario 3: terminate the intake stage: the active items terminate,
		// the unentered milestone is terminated too (it has no disabled edge),
		// each with a cascade audit row naming the stage.
		let plan = await readPlan(request, objectUuid)
		const intake = byKey(plan, 'intake')
		const terminated = await request.post(
			`${CASES}/items/${intake.uuid}/transition`,
			{
				data: { to: 'terminated', reason: `${RUN_ID} withdrawn` },
			},
		)
		expect(terminated.status(), await terminated.text()).toBe(200)

		plan = await readPlan(request, objectUuid)
		expect(byKey(plan, 'intake').state).toBe('terminated')
		expect(byKey(plan, 'completeness-check').state).toBe('terminated')
		expect(byKey(plan, 'external-advice').state).toBe('terminated')
		expect(byKey(plan, 'application-complete').state).toBe('terminated')
		const cascaded = plan.audit.filter(
			(entry) => entry.cause === 'cascade' && entry.causeRef === intake.uuid,
		)
		expect(cascaded.length).toBeGreaterThanOrEqual(3)

		// The realising task is terminated with a reason and leaves the inbox.
		const adviceTask = await request.get(`${TASKS}/${advice.realisationUuid}`)
		expect((await adviceTask.json()).state).toBe('terminated')

		// A terminal item accepts nothing further (409), and a milestone cannot become active.
		const again = await request.post(
			`${CASES}/items/${intake.uuid}/transition`,
			{ data: { to: 'completed' } },
		)
		expect(again.status()).toBe(409)
	})
})
