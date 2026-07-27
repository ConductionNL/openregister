/**
 * OpenConnector's chaining, before and after the flow migration.
 *
 * OpenConnector chains synchronizations two ways of its own — a `synchronization`
 * rule and a `followUps` entry — and both re-enter `synchronize()` on the same
 * service with no cycle guard, so A -> B -> A recursed until the process died.
 * The migration's claim is that a flow expresses the same chaining *better*:
 * the order is explicit, the engine bounds the recursion, and every hop gets its
 * own persisted, inspectable run.
 *
 * This spec tests that claim from the engine side, which is where it is
 * observable: a chain authored as a flow terminates, is bounded, and leaves a
 * run history — the three things the bespoke mechanism could not give you.
 *
 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }
const runId = `e2e-chain-${Date.now()}`

/** Find a register by slug. */
async function registerBySlug(request: APIRequestContext, slug: string): Promise<any> {
	const resp = await request.get(`${API}/registers?limit=1000`)
	expect(resp.ok(), 'list registers').toBeTruthy()
	const rows: any[] = (await resp.json()).results ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(match, `register slug=${slug} exists`).toBeTruthy()
	return match
}

/** Resolve a schema by slug WITHIN a register — `flow` is not a unique slug. */
async function schemaInRegister(request: APIRequestContext, register: any, slug: string): Promise<number> {
	const ids: number[] = (register.schemas ?? []).map((s: any) => (typeof s === 'object' ? (s.id ?? s) : s))
	for (const id of ids) {
		const resp = await request.get(`${API}/schemas/${id}`)
		if (!resp.ok()) continue
		const sch = await resp.json()
		if ((sch.slug ?? sch['@self']?.slug) === slug) return sch.id ?? sch['@self']?.id
	}
	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
}

test.describe('OpenConnector chaining expressed as a flow', () => {
	let flowReg: number
	let flowSch: number
	const flows: string[] = []

	test.beforeAll(async ({ request }) => {
		const flowsRegister = await registerBySlug(request, 'flows')
		flowReg = flowsRegister.id ?? flowsRegister['@self']?.id
		flowSch = await schemaInRegister(request, flowsRegister, 'flow')
	})

	test.afterAll(async ({ request }) => {
		for (const u of flows) {
			await request.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`).catch(() => {})
		}
	})

	async function createFlow(request: APIRequestContext, name: string, body: Record<string, unknown>) {
		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
			headers: JSON_HEADERS,
			data: { name: `${name} ${runId}`, enabled: false, ...body },
		})
		expect(resp.status(), `create flow ${name}`).toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.['@self']?.id
		flows.push(uuid)
		return uuid
	}

	test('a multi-step chain runs in the declared order and is inspectable', async ({ request }) => {
		// The chaining openconnector expressed as recursive followUps, expressed
		// instead as a graph: order is a property of the edges, not of who calls
		// whom from inside a service.
		const flow = await createFlow(request, 'Ordered chain', {
			nodes: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
			edges: [
				{ id: 'e1', from: 'a', to: 'b', type: 'openregister.set-fields', config: { set: { step: 'one' } } },
				{ id: 'e2', from: 'b', to: 'c', type: 'openregister.set-fields', config: { set: { step: 'two' } } },
			],
		})

		const run = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: flow, seedItems: [{ json: { seeded: true } }] },
		})
		expect(run.status(), 'test run').toBe(200)
		const result = await run.json()

		expect(result.status, 'the chain completed').toBe('completed')
		// The later step's value wins — proof the edges ran in declared order.
		expect(result.items?.[0]?.json?.step, 'the second step ran after the first').toBe('two')
		// Every hop is recorded, which recursive followUps never gave you.
		expect(Array.isArray(result.log), 'the run carries an inspectable trace').toBeTruthy()
		expect(result.log.length, 'the trace is not empty').toBeGreaterThan(0)
	})

	test('a self-referencing flow is bounded instead of recursing forever', async ({ request }) => {
		// The A -> A case. Under openconnector's followUps this recursed until the
		// process died; the engine's sub-flow guard must refuse or bound it. The
		// assertion is deliberately about TERMINATION, not a specific error: any
		// bounded outcome is correct, hanging is not.
		const flow = await createFlow(request, 'Self cycle', { nodes: [{ id: 'a' }, { id: 'b' }], edges: [] })

		const selfEdge = await request.put(`${API}/objects/${flowReg}/${flowSch}/${flow}`, {
			headers: JSON_HEADERS,
			data: {
				name: `Self cycle ${runId}`,
				enabled: false,
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [{ id: 'e1', from: 'a', to: 'b', type: 'openregister.sub-flow', config: { flow, wait: true } }],
			},
		})
		expect(selfEdge.status(), 'store the self-referencing edge').toBeLessThan(400)

		const started = Date.now()
		const run = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: flow, seedItems: [{ json: {} }] },
		})
		const elapsed = Date.now() - started

		// It came back at all — that is the headline.
		expect(elapsed, 'a self-referencing chain terminates rather than hanging').toBeLessThan(90_000)

		if (run.ok()) {
			const result = await run.json()
			expect(result.status, 'a cycle must not be reported as a clean success').not.toBe('completed')
		} else {
			expect(run.status(), 'refused outright is equally correct').toBeGreaterThanOrEqual(400)
		}
	})

	test('every chain hop is persisted as its own run', async ({ request }) => {
		// What makes a flow chain debuggable and a recursive followUp chain not:
		// the history endpoint can answer "what actually ran".
		const flow = await createFlow(request, 'History chain', {
			nodes: [{ id: 'a' }, { id: 'b' }],
			edges: [{ id: 'e1', from: 'a', to: 'b', type: 'openregister.set-fields', config: { set: { ok: true } } }],
		})

		await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: flow, seedItems: [{ json: {} }] },
		})

		const list = await request.get(`${API}/flow-runs?limit=100`)
		expect(list.ok(), 'run history is queryable').toBeTruthy()
		const runs: any[] = (await list.json()).results ?? []
		const mine = runs.filter((r) => (r.flowId ?? r['@self']?.flowId) === flow)

		expect(mine.length, 'the hop left a persisted run').toBeGreaterThan(0)
		expect(mine[0].status, 'and the run records its outcome').toBeTruthy()
	})
})
