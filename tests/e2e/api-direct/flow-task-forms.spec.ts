/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Task forms, end to end over the live HTTP API: the @e2e-marked scenarios
 * of the flow-task-forms spec.
 *
 * A register and a subject schema with a lifecycle are created first; the
 * schema's `reject` transition declares `inputs`, and `reason` is deliberately
 * NOT in the schema's own `required` list, so the read must carry `required`
 * from the DECLARATION. A flow is authored through `POST /api/flows`, run
 * through the synchronous test endpoint until its user-task node suspends,
 * and the task is read and completed through the flow-tasks verbs.
 *
 * The rendering half of the "renders as required" scenario is the shared
 * component library's; this suite proves the server half it renders from.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const RUN_ID = `e2e-taskform-${Date.now().toString(36)}`
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

// Basic auth, no session cookie, so no CSRF token is demanded.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
}

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })
test.describe.configure({ mode: 'serial' })

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
			description: 'Created by the flow-task-forms e2e suite.',
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

function userTask(id: string, config: Record<string, unknown> = {}): Node {
	return {
		id,
		type: 'openregister.user-task',
		config: {
			title: `${RUN_ID} ${id}: assess {{ name }}`,
			assignee: ADMIN,
			outcomes: 'approved, rejected',
			...config,
		},
		position: { x: 0, y: 0 },
	}
}

function setFields(id: string, set: Record<string, unknown>): Node {
	return { id, type: 'openregister.set-fields', config: { set }, position: { x: 0, y: 0 } }
}

/** Run a flow synchronously against ONE seeded item carrying the subject anchor. */
async function testRun(request: APIRequestContext, flowId: string, subject: Record<string, unknown>) {
	const resp = await request.post(`${API}/flow-runs/test`, {
		headers: JSON_HEADERS,
		data: { flowId, seedItems: [{ json: subject }] },
	})
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function readRun(request: APIRequestContext, uuid: string) {
	const resp = await request.get(`${API}/flow-runs/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function inboxTaskFor(request: APIRequestContext, runUuid: string, nodeId: string) {
	const resp = await request.get(
		`${API}/flow-tasks?scope=assigned&isTerminal=false&limit=100&sort=created&direction=desc`,
	)
	expect(resp.status(), await resp.text()).toBe(200)
	const rows = ((await resp.json()).results ?? []) as Array<Record<string, unknown>>
	return rows.find((row) => row.runUuid === runUuid && row.nodeId === nodeId) ?? null
}

async function readTask(request: APIRequestContext, uuid: string) {
	const resp = await request.get(`${API}/flow-tasks/${uuid}`)
	expect(resp.status(), await resp.text()).toBe(200)
	return resp.json()
}

async function postComplete(
	request: APIRequestContext,
	uuid: string,
	body: Record<string, unknown>,
) {
	return request.post(`${API}/flow-tasks/${uuid}/complete`, { headers: JSON_HEADERS, data: body })
}

test.describe('flow-task-forms: the form a person fills to complete a task', () => {
	const flows: string[] = []
	let registerId: string
	let schemaId: string
	let schemaSlug: string

	/** A fresh subject object in `open` state; returns its record with the `@self` anchor. */
	async function subject(request: APIRequestContext, name: string): Promise<Record<string, unknown>> {
		const resp = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
			headers: JSON_HEADERS,
			data: { name, status: 'open' },
		})
		expect(resp.status(), await resp.text()).toBeLessThanOrEqual(201)
		const body = await resp.json()
		const self = body['@self'] ?? {}
		expect(self.uuid ?? body.uuid, 'subject uuid').toBeTruthy()
		return {
			...body,
			'@self': {
				uuid: self.uuid ?? body.uuid,
				register: Number(self.register ?? registerId),
				schema: Number(self.schema ?? schemaId),
			},
		}
	}

	async function readSubject(request: APIRequestContext, uuid: string) {
		const resp = await request.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(resp.status(), await resp.text()).toBe(200)
		return resp.json()
	}

	test.beforeAll(async ({ request }) => {
		const register = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: { title: `${RUN_ID} register`, description: 'flow-task-forms e2e' },
		})
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		const registerBody = await register.json()
		registerId = String(registerBody.id ?? registerBody['@self']?.id)

		schemaSlug = `${RUN_ID}-case`
		const schema = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `${RUN_ID} case`,
				slug: schemaSlug,
				properties: {
					name: { type: 'string', title: 'Name' },
					status: { type: 'string', title: 'Status' },
					// Optional on the object, mandatory when rejecting.
					reason: { type: 'string', title: 'Reason' },
					note: { type: 'string', title: 'Note' },
				},
				required: ['name'],
				configuration: {
					'x-openregister-lifecycle': {
						field: 'status',
						transitions: {
							approve: { from: ['open'], to: 'approved' },
							reject: {
								from: ['open'],
								to: 'rejected',
								inputs: [
									{ field: 'reason', required: true },
									{ field: 'note', required: false },
								],
							},
						},
					},
				},
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		const schemaBody = await schema.json()
		schemaId = String(schemaBody.id ?? schemaBody['@self']?.id)
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of flows) {
			await request.delete(`${API}/flows/${uuid}`).catch(() => {})
		}
	})

	// @e2e flow-task-forms::a-step-with-no-form-still-completes
	test('a task with no form completes with an outcome alone', async ({ request }) => {
		const flowId = await createFlow(
			request,
			'no form',
			[setFields('start', { step: 1 }), userTask('ask'), setFields('done', { step: 2 })],
			[
				{ id: 'e1', from: 'start', to: 'ask' },
				{ id: 'e2', from: 'ask', to: 'done' },
			],
		)
		flows.push(flowId)
		const run = await testRun(request, flowId, await subject(request, 'Case A'))
		expect(run.status).toBe('suspended')
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		const read = await readTask(request, task!.uuid as string)
		expect(read.form, 'a step with no form describes none').toBeNull()
		expect(read.requireChecklist).toBe(false)

		const done = await postComplete(request, task!.uuid as string, { outcome: 'approved', comment: 'fine' })
		expect(done.status(), await done.text()).toBe(200)
		expect((await done.json()).state).toBe('completed')
	})

	// @e2e flow-task-forms::a-transition-required-field-renders-as-required
	test('the task read marks a transition-required, schema-optional field as required', async ({ request }) => {
		const flowId = await createFlow(
			request,
			'reject form',
			[
				setFields('start', { step: 1 }),
				userTask('ask', { formKind: 'fields', formSchema: schemaSlug, formAction: 'reject' }),
				setFields('done', { step: 2 }),
			],
			[
				{ id: 'e1', from: 'start', to: 'ask' },
				{ id: 'e2', from: 'ask', to: 'done' },
			],
		)
		flows.push(flowId)
		const run = await testRun(request, flowId, await subject(request, 'Case B'))
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		const read = await readTask(request, task!.uuid as string)
		expect(read.form.kind).toBe('fields')
		expect(read.form.state).toBe('ready')
		expect(read.form.action).toBe('reject')
		const fields = read.form.fields as Array<Record<string, unknown>>
		expect(fields.map((f) => f.field)).toEqual(['reason', 'note'])
		// `reason` is NOT in the schema's required list; the declaration says it is.
		expect(fields[0].required).toBe(true)
		expect(fields[0].order).toBe(0)
		expect(fields[1].required).toBe(false)
		expect(fields[1].order).toBe(1)

		// Cleanup: complete through the form so the run can end.
		const done = await postComplete(request, task!.uuid as string, {
			outcome: 'rejected',
			comment: 'late',
			data: { reason: 'late' },
		})
		expect(done.status(), await done.text()).toBe(200)
	})

	// @e2e flow-task-forms::a-missing-required-field-is-named-and-the-task-stays-open
	test('a completion missing a required field is refused naming it, and the task stays in the inbox', async ({ request }) => {
		const flowId = await createFlow(
			request,
			'missing required',
			[setFields('start', { step: 1 }), userTask('ask', { formKind: 'fields', formSchema: schemaSlug, formAction: 'reject' })],
			[{ id: 'e1', from: 'start', to: 'ask' }],
		)
		flows.push(flowId)
		const record = await subject(request, 'Case C')
		const run = await testRun(request, flowId, record)
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		const refused = await postComplete(request, task!.uuid as string, {
			outcome: 'rejected',
			comment: 'no reason given',
			data: { note: 'only a note' },
		})
		expect(refused.status(), await refused.text()).toBe(400)
		const body = await refused.json()
		expect(body.fields).toEqual(['reason'])
		expect(body.kind).toBe('missing')

		// An undeclared key is a different refusal, named as such.
		const undeclared = await postComplete(request, task!.uuid as string, {
			outcome: 'rejected',
			comment: 'x',
			data: { reason: 'late', surprise: 1 },
		})
		expect(undeclared.status()).toBe(400)
		expect((await undeclared.json()).fields).toEqual(['surprise'])
		expect((await undeclared.json()).kind).toBe('undeclared')

		// The task is still actionable, the run still suspended, the subject unchanged.
		const still = await inboxTaskFor(request, run.uuid, 'ask')
		expect(still, 'the task stays in the assignee inbox').toBeTruthy()
		expect(still!.state).toBe('active')
		expect((await readRun(request, run.uuid)).status).toBe('suspended')
		const selfUuid = (record['@self'] as Record<string, unknown>).uuid as string
		const object = await readSubject(request, selfUuid)
		expect(object.status).toBe('open')
		expect(object.reason ?? null).toBeNull()

		// The audit knows about the attempts, and records no completion.
		const audit = await request.get(`${API}/flow-tasks/${task!.uuid}/audit`)
		const actions = ((await audit.json()).results ?? []).map((row: { action: string }) => row.action)
		expect(actions).toContain('complete-refused')
		expect(actions).not.toContain('complete')

		// A correct payload lands the values and the state change in one save.
		const done = await postComplete(request, task!.uuid as string, {
			outcome: 'rejected',
			comment: 'late',
			data: { reason: 'late', note: 'second attempt' },
		})
		expect(done.status(), await done.text()).toBe(200)
		const written = await readSubject(request, selfUuid)
		expect(written.status).toBe('rejected')
		expect(written.reason).toBe('late')
		expect(written.note).toBe('second attempt')
	})

	// @e2e flow-task-forms::a-field-the-schema-dropped-later-is-visible-as-broken
	test('a declared field the schema no longer has shows as broken, never silently omitted', async ({ request }) => {
		const dropSlug = `${RUN_ID}-drift`
		const created = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `${RUN_ID} drift`,
				slug: dropSlug,
				properties: {
					name: { type: 'string', title: 'Name' },
					evidence: { type: 'string', title: 'Evidence' },
				},
			},
		})
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		const driftId = String((await created.json()).id ?? (await created.json())['@self']?.id)

		const flowId = await createFlow(
			request,
			'drift',
			[setFields('start', { step: 1 }), userTask('ask', { formKind: 'fields', formSchema: dropSlug, formFields: 'evidence*' })],
			[{ id: 'e1', from: 'start', to: 'ask' }],
		)
		flows.push(flowId)
		const run = await testRun(request, flowId, { name: 'Drift' })
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()

		// The schema drops the field AFTER the step was saved and the task created.
		const patched = await request.put(`${API}/schemas/${driftId}`, {
			headers: JSON_HEADERS,
			data: {
				title: `${RUN_ID} drift`,
				slug: dropSlug,
				properties: { name: { type: 'string', title: 'Name' } },
			},
		})
		expect(patched.status(), await patched.text()).toBeLessThanOrEqual(201)

		const read = await readTask(request, task!.uuid as string)
		expect(read.form.state).toBe('broken')
		const field = (read.form.fields as Array<Record<string, unknown>>)[0]
		expect(field.field).toBe('evidence')
		expect(field.renderable).toBe(false)
		expect(String(field.reason)).toContain('no such property')
		expect(field.required, 'the declaration is not silently narrowed').toBe(true)

		await postComplete(request, task!.uuid as string, { outcome: 'approved' }).catch(() => {})
	})

	// @e2e flow-task-forms::editing-the-flow-leaves-an-open-tasks-form-alone
	test('editing and publishing the flow does not change an already-open task form', async ({ request }) => {
		const flowId = await createFlow(
			request,
			'versioned',
			[setFields('start', { step: 1 }), userTask('ask', { formKind: 'fields', formSchema: schemaSlug, formFields: 'reason*, note' })],
			[{ id: 'e1', from: 'start', to: 'ask' }],
		)
		flows.push(flowId)

		// Publish, so a run pins to version 1 rather than walking the draft.
		const published = await request.post(`${API}/flows/${flowId}/publish`, { headers: JSON_HEADERS, data: {} })
		test.skip(published.status() === 404, 'no publish endpoint on this build; versioning covered by unit tests')
		expect(published.status(), await published.text()).toBeLessThanOrEqual(201)

		const run = await testRun(request, flowId, await subject(request, 'Case D'))
		const task = await inboxTaskFor(request, run.uuid, 'ask')
		expect(task).toBeTruthy()
		const before = await readTask(request, task!.uuid as string)
		expect((before.form.fields as unknown[]).length).toBe(2)

		// Edit the step to declare four fields and publish again.
		const flow = await (await request.get(`${API}/flows/${flowId}`)).json()
		const nodes = (flow.nodes as Node[]).map((node) =>
			node.id === 'ask'
				? { ...node, config: { ...(node.config as Record<string, unknown>), formFields: 'reason*, note, name, status' } }
				: node,
		)
		const edited = await request.put(`${API}/flows/${flowId}`, {
			headers: JSON_HEADERS,
			data: { ...flow, nodes },
		})
		expect(edited.status(), await edited.text()).toBeLessThanOrEqual(201)
		const republished = await request.post(`${API}/flows/${flowId}/publish`, { headers: JSON_HEADERS, data: {} })
		expect(republished.status(), await republished.text()).toBeLessThanOrEqual(201)

		const after = await readTask(request, task!.uuid as string)
		expect((after.form.fields as unknown[]).length, 'the open task keeps the form its version declared').toBe(2)

		await postComplete(request, task!.uuid as string, { outcome: 'approved', data: { reason: 'ok' } })
	})

	// @e2e flow-task-forms::an-unchecked-mandatory-item-refuses-the-completion
	test('an unchecked mandatory checklist item refuses the completion naming the item', async ({ request }) => {
		// A run-less task, first-class: the checklist and the rule live on the record.
		const created = await request.post(`${API}/flow-tasks`, {
			headers: JSON_HEADERS,
			data: {
				title: `${RUN_ID} checklist`,
				assignee: ADMIN,
				state: 'active',
				checklist: [
					{ id: 'c1', label: 'Identity verified', description: '', checked: true },
					{ id: 'c2', label: 'Documents scanned', description: '', checked: false },
				],
				metadata: { form: { kind: null, requireChecklist: true } },
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		const task = await created.json()

		const read = await readTask(request, task.uuid as string)
		expect(read.requireChecklist).toBe(true)
		expect(read.form).toBeNull()

		const refused = await postComplete(request, task.uuid as string, { outcome: 'approved' })
		expect(refused.status(), await refused.text()).toBe(400)
		const body = await refused.json()
		expect(body.kind).toBe('checklist')
		expect(body.fields).toEqual(['c2'])

		// Checking the item is task state, through the task's own verb.
		const checked = await request.patch(`${API}/flow-tasks/${task.uuid}/checklist/c2`, {
			headers: JSON_HEADERS,
			data: { checked: true },
		})
		expect(checked.status(), await checked.text()).toBe(200)

		const done = await postComplete(request, task.uuid as string, { outcome: 'approved' })
		expect(done.status(), await done.text()).toBe(200)
		expect((await done.json()).state).toBe('completed')
	})
})
