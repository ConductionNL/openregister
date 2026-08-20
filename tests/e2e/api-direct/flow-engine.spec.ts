/**
 * OpenRegister flow engine — end-to-end via the live HTTP API.
 *
 * Exercises the flow programme through the surfaces a real client uses, not the
 * PHP internals: the shipped flow store (`flows` register / `flow` schema, from
 * the ImportFlowRegister repair step), authoring a flow as an object, and running
 * it synchronously through `POST /api/flow-runs/test` — which drives the engine,
 * the node types (set-fields, route), pinned output and run-from-here, then
 * persists a run that the history endpoint returns.
 *
 * Auth is the Basic-auth admin context from playwright.config.ts, so no browser
 * session is needed. Each flow object is created under a run-unique name and
 * deleted in afterAll.
 *
 * @spec openspec/changes/or-flow-store/specs/flow-store/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const runId = `e2e-flow-${Date.now()}`

/** Find a shipped register/schema id by slug (the flow store is shipped, not seeded here). */
async function idBySlug(
	request: APIRequestContext,
	kind: 'registers' | 'schemas',
	slug: string,
): Promise<number> {
	const resp = await request.get(`${API}/${kind}?limit=1000`)
	expect(resp.ok(), `list ${kind}`).toBeTruthy()
	const body = await resp.json()
	const rows: any[] = body.results ?? body ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(
		match,
		`${kind} slug=${slug} exists (shipped by ImportFlowRegister)`,
	).toBeTruthy()
	return match.id ?? match['@self']?.id
}

/** Author a flow object in the flow store, returning its uuid. */
async function createFlow(
	request: APIRequestContext,
	reg: number,
	sch: number,
	flow: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${API}/objects/${reg}/${sch}`, {
		headers: JSON_HEADERS,
		data: flow,
	})
	expect(resp.status(), 'create flow object').toBeLessThanOrEqual(201)
	const body = await resp.json()
	const id = body?.['@self']?.id ?? body?.id
	expect(id, 'flow uuid').toBeTruthy()
	return id
}

/** Run a flow synchronously via the test endpoint. */
async function testRun(
	request: APIRequestContext,
	payload: Record<string, unknown>,
) {
	const resp = await request.post(`${API}/flow-runs/test`, {
		headers: JSON_HEADERS,
		data: payload,
	})
	expect(resp.status(), `test run (${JSON.stringify(payload)})`).toBe(200)
	return resp.json()
}

test.describe('Flow engine — end to end', () => {
	let reg: number
	let sch: number
	const created: string[] = []

	test.beforeAll(async ({ request }) => {
		reg = await idBySlug(request, 'registers', 'flows')
		sch = await idBySlug(request, 'schemas', 'flow')
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request
				.delete(`${API}/objects/${reg}/${sch}/${id}`)
				.catch(() => {})
		}
	})

	test('the flow store is shipped (flows register + flow schema)', async () => {
		expect(reg, 'flows register id').toBeGreaterThan(0)
		expect(sch, 'flow schema id').toBeGreaterThan(0)
	})

	test('a flow authored as an object runs to completion', async ({ request }) => {
		const uuid = await createFlow(request, reg, sch, {
			name: `${runId} linear`,
			enabled: true,
			trigger: 'manual',
			nodes: [{ id: 'start' }, { id: 'greet' }, { id: 'done' }],
			edges: [
				{
					id: 's1',
					from: 'start',
					to: 'greet',
					type: 'openregister.set-fields',
					config: { set: { greeting: 'hi', step: 1 } },
				},
				{
					id: 's2',
					from: 'greet',
					to: 'done',
					type: 'openregister.set-fields',
					config: { set: { step: 2, finished: true } },
				},
			],
		})
		created.push(uuid)

		const run = await testRun(request, { flowId: uuid })
		expect(run.status).toBe('completed')
		const item = (run.items ?? [])[0]?.json ?? {}
		expect(item.greeting).toBe('hi')
		expect(item.step).toBe(2)
		expect(item.finished).toBe(true)
	})

	test('run-from-here skips the steps before the chosen node', async ({
		request,
	}) => {
		const uuid = await createFlow(request, reg, sch, {
			name: `${runId} startAt`,
			enabled: true,
			trigger: 'manual',
			nodes: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
			edges: [
				{
					id: 's1',
					from: 'a',
					to: 'b',
					type: 'openregister.set-fields',
					config: { set: { ran_first: true } },
				},
				{
					id: 's2',
					from: 'b',
					to: 'c',
					type: 'openregister.set-fields',
					config: { set: { ran_second: true } },
				},
			],
		})
		created.push(uuid)

		const run = await testRun(request, {
			flowId: uuid,
			startAt: 'b',
			seedItems: [{ json: { seeded: true } }],
		})
		expect(run.status).toBe('completed')
		// Only s2 ran; the log records exactly one step, and s1's field is absent.
		const steps = (run.log ?? []).map((l: any) => l.transition)
		expect(steps).toEqual(['s2'])
		const item = (run.items ?? [])[0]?.json ?? {}
		expect(item.ran_second).toBe(true)
		expect(item.ran_first).toBeUndefined()
		expect(item.seeded).toBe(true)
	})

	test('a pinned step is skipped and its stored output used', async ({
		request,
	}) => {
		const uuid = await createFlow(request, reg, sch, {
			name: `${runId} pin`,
			enabled: true,
			trigger: 'manual',
			nodes: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
			edges: [
				{
					id: 's1',
					from: 'a',
					to: 'b',
					type: 'openregister.set-fields',
					config: { set: { real: true } },
				},
				{
					id: 's2',
					from: 'b',
					to: 'c',
					type: 'openregister.set-fields',
					config: { set: { downstream: true } },
				},
			],
		})
		created.push(uuid)

		const run = await testRun(request, {
			flowId: uuid,
			pins: { s1: [{ json: { pinned: 'yes' } }] },
		})
		expect(run.status).toBe('completed')
		const s1 = (run.log ?? []).find((l: any) => l.transition === 's1')
		expect(s1?.status, 's1 was served from the pin').toBe('pinned')
		const item = (run.items ?? [])[0]?.json ?? {}
		// The real s1 output ({real:true}) never happened; the pin flowed through.
		expect(item.pinned).toBe('yes')
		expect(item.real).toBeUndefined()
		expect(item.downstream).toBe(true)
	})

	test('a router splits items per-item across branches', async ({ request }) => {
		const uuid = await createFlow(request, reg, sch, {
			name: `${runId} route`,
			enabled: true,
			trigger: 'manual',
			nodes: [
				{ id: 'start' },
				{ id: 'high' },
				{ id: 'low' },
				{ id: 'hEnd' },
				{ id: 'lEnd' },
			],
			edges: [
				{
					id: 'route',
					from: 'start',
					to: ['high', 'low'],
					type: 'openregister.route',
					config: {
						rules: [
							{
								condition: { '>': [{ var: 'json.n' }, 5] },
								output: 'high',
							},
						],
						default: 'low',
					},
				},
				{
					id: 'doHigh',
					from: 'high',
					to: 'hEnd',
					type: 'openregister.set-fields',
					config: { set: { branch: 'high' } },
				},
				{
					id: 'doLow',
					from: 'low',
					to: 'lEnd',
					type: 'openregister.set-fields',
					config: { set: { branch: 'low' } },
				},
			],
		})
		created.push(uuid)

		const run = await testRun(request, {
			flowId: uuid,
			seedItems: [{ json: { n: 1 } }, { json: { n: 7 } }, { json: { n: 3 } }],
		})
		expect(run.status).toBe('completed')
		// The router sent one item (n=7) to high and two (n=1,3) to low.
		const doHigh = (run.log ?? []).find((l: any) => l.transition === 'doHigh')
		const doLow = (run.log ?? []).find((l: any) => l.transition === 'doLow')
		expect(doHigh?.itemsIn).toBe(1)
		expect(doLow?.itemsIn).toBe(2)
	})

	test('an unknown flow is a 404', async ({ request }) => {
		const resp = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: 'this-flow-does-not-exist' },
		})
		expect(resp.status()).toBe(404)
	})

	test('a test run is persisted in the run history', async ({ request }) => {
		const uuid = await createFlow(request, reg, sch, {
			name: `${runId} history`,
			enabled: true,
			trigger: 'manual',
			nodes: [{ id: 'a' }, { id: 'b' }],
			edges: [
				{
					id: 's1',
					from: 'a',
					to: 'b',
					type: 'openregister.set-fields',
					config: { set: { done: true } },
				},
			],
		})
		created.push(uuid)

		await testRun(request, { flowId: uuid })

		const hist = await request.get(`${API}/flow-runs?flowId=${uuid}`)
		expect(hist.status()).toBe(200)
		const body = await hist.json()
		expect(
			body.results.length,
			'the test run shows in history',
		).toBeGreaterThanOrEqual(1)
		expect(body.results[0].trigger).toBe('test')
	})
})
