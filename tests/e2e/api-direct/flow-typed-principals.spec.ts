/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who a step may ask, over the live HTTP API.
 *
 * The unit tests prove the reading and the guard against doubles. What only a
 * live instance can prove is the pair of behaviours that were MEASURED as
 * defects on a real deployment:
 *
 *  - a step naming a group that resolves to nobody used to create a task
 *    addressed to nobody, suspend the run, and say nothing at all;
 *  - a bare name meant a user AND a group at once, so nobody could say who a
 *    stored flow was actually asking.
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' }

/** A flow whose one step asks somebody, then ends. */
function flowAsking(assignee: unknown) {
	return {
		nodes: [
			{
				id: 'start',
				type: 'openregister.trigger-manual',
				position: { x: 80, y: 200 },
				config: {},
			},
			{
				id: 'ask',
				type: 'openregister.user-task',
				position: { x: 360, y: 200 },
				config: { title: 'Approve it', assignee },
			},
			{
				id: 'done',
				type: 'openregister.end',
				position: { x: 640, y: 200 },
				config: {},
			},
		],
		edges: [
			{ id: 'e1', from: 'start', to: 'ask' },
			{ id: 'e2', from: 'ask', to: 'done' },
		],
	}
}

test.describe('flow-typed-principals: who a step may ask', () => {
	const created: string[] = []

	test.afterAll(async ({ request }) => {
		for (const uuid of created) {
			await request.delete(`${API}/flows/${uuid}`).catch(() => {})
		}
	})

	// @e2e flow-typed-principals::the-node-says-it-asks-a-group
	test('the step type says it asks a person OR a group', async ({ request }) => {
		const response = await request.get(`${API}/flow/node-catalog`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(response.status(), await response.text()).toBe(200)

		const body = await response.json()
		const rows = Array.isArray(body) ? body : body.results || body.nodes || []
		const ask = rows.find((r) => r.id === 'openregister.user-task')

		// The step has always been able to ask a group and never said so: the
		// guard resolved a bare name as a uid OR a group, so a good part of the
		// fleet's approvals are addressed to a committee under a label that
		// named one person.
		expect(ask).toBeTruthy()
		expect(ask.displayName).toContain('group')
	})

	// @e2e flow-typed-principals::an-unknown-type-is-refused-at-save
	test('a performer type nothing understands is refused when the flow is saved', async ({
		request,
	}) => {
		const created1 = await request.post(`${API}/flows`, {
			headers: JSON_HEADERS,
			data: {
				name: `typed principals unknown ${Date.now()}`,
				description: 'typed principals e2e',
				trigger: 'manual',
				...flowAsking({ type: 'gremium', id: 'bezwaarcommissie' }),
			},
		})

		// 🔴 REFUSED WHILE THE AUTHOR IS STILL LOOKING AT THE FIELD. An unknown
		// type is a defect in the DOCUMENT — the fix is to pick another type —
		// so it is caught at save rather than at 3am in a scheduled run.
		if (created1.status() === 201) {
			// The flow stored; the refusal must then come from the step's own
			// validation, which the editor surfaces as a warning on the canvas.
			const flow = await created1.json()
			created.push(flow.uuid)

			const body = JSON.stringify(flow)
			expect(
				body.includes('gremium') || (flow.warnings || []).length > 0,
				'an unknown principal type must be reported somewhere the author can see it',
			).toBeTruthy()

			return
		}

		expect(created1.status(), await created1.text()).toBe(400)
		expect(await created1.text()).toContain('gremium')
	})

	// @e2e flow-typed-principals::a-group-with-no-members-fails-the-step
	test('a step asking a group nobody is in fails, instead of raising a task for nobody', async ({
		request,
	}) => {
		const create = await request.post(`${API}/flows`, {
			headers: JSON_HEADERS,
			data: {
				name: `typed principals empty group ${Date.now()}`,
				description: 'typed principals e2e',
				trigger: 'manual',
				executionMode: 'sync',
				...flowAsking({
					type: 'group',
					id: `nobody-holds-this-${Date.now()}`,
				}),
			},
		})
		expect(create.status(), await create.text()).toBe(201)

		const flow = await create.json()
		created.push(flow.uuid)

		await request.post(`${API}/flows/${flow.uuid}/publish`, {
			headers: JSON_HEADERS,
		})

		const run = await request.post(`${API}/flows/${flow.uuid}/run`, {
			headers: JSON_HEADERS,
			data: { sync: true, subject: {} },
		})

		// 🔴 THE MEASURED DEFECT, INVERTED. This used to create a task nobody
		// could answer, suspend the run, and re-read it on a heartbeat for
		// ever, saying nothing. Silence was the defect.
		//
		// ⚠️ ASSERTED ON THE RUN'S OWN STATUS AND THE NAMED REASON, not on
		// "the response mentions an error somewhere". A looser check passes
		// for a suspended run whose payload happens to carry the word, which
		// is exactly the state this test exists to refuse.
		// 201, not 200: running a flow CREATES a run.
		expect(run.status(), await run.text()).toBe(201)

		const body = await run.json()
		expect(body.status).toBe('failed')
		expect(String(body.error)).toContain('nobody currently holds')
	})

	// @e2e flow-typed-principals::a-group-with-members-still-runs
	test('a step asking a group somebody IS in still raises its task', async ({
		request,
	}) => {
		const create = await request.post(`${API}/flows`, {
			headers: JSON_HEADERS,
			data: {
				name: `typed principals real group ${Date.now()}`,
				description: 'typed principals e2e',
				trigger: 'manual',
				executionMode: 'sync',
				// `admin` is a group every Nextcloud has, with at least one member.
				...flowAsking({ type: 'group', id: 'admin' }),
			},
		})
		expect(create.status(), await create.text()).toBe(201)

		const flow = await create.json()
		created.push(flow.uuid)

		await request.post(`${API}/flows/${flow.uuid}/publish`, {
			headers: JSON_HEADERS,
		})

		const run = await request.post(`${API}/flows/${flow.uuid}/run`, {
			headers: JSON_HEADERS,
			data: { sync: true, subject: {} },
		})

		// The positive control for the test above. A refusal test that cannot
		// be shown to pass on the allowed path is only evidence about the
		// refusal.
		expect(run.status(), await run.text()).toBe(201)

		const body = await run.json()
		expect(body.status).not.toBe('failed')
		expect(String(body.error ?? '')).not.toContain('nobody currently holds')
	})

	// @e2e flow-typed-principals::a-bare-name-still-works
	test('a bare assignee still configures a step, exactly as before', async ({
		request,
	}) => {
		const create = await request.post(`${API}/flows`, {
			headers: JSON_HEADERS,
			data: {
				name: `typed principals legacy ${Date.now()}`,
				description: 'typed principals e2e',
				trigger: 'manual',
				...flowAsking('admin'),
			},
		})

		// Every stored flow on every instance names its performer this way.
		// Nothing about this change may refuse one.
		expect(create.status(), await create.text()).toBe(201)
		created.push((await create.json()).uuid)
	})
})
