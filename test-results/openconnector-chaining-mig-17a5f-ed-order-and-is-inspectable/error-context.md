# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: openconnector-chaining-migration.spec.ts >> OpenConnector chaining expressed as a flow >> a multi-step chain runs in the declared order and is inspectable
- Location: tests/e2e/api-direct/openconnector-chaining-migration.spec.ts:73:6

# Error details

```
Error: list registers

expect(received).toBeTruthy()

Received: false
```

# Test source

```ts
  1   | /**
  2   |  * OpenConnector's chaining, before and after the flow migration.
  3   |  *
  4   |  * OpenConnector chains synchronizations two ways of its own — a `synchronization`
  5   |  * rule and a `followUps` entry — and both re-enter `synchronize()` on the same
  6   |  * service with no cycle guard, so A -> B -> A recursed until the process died.
  7   |  * The migration's claim is that a flow expresses the same chaining *better*:
  8   |  * the order is explicit, the engine bounds the recursion, and every hop gets its
  9   |  * own persisted, inspectable run.
  10  |  *
  11  |  * This spec tests that claim from the engine side, which is where it is
  12  |  * observable: a chain authored as a flow terminates, is bounded, and leaves a
  13  |  * run history — the three things the bespoke mechanism could not give you.
  14  |  *
  15  |  * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
  16  |  */
  17  | import { test, expect, type APIRequestContext } from '@playwright/test'
  18  | 
  19  | const API = '/index.php/apps/openregister/api'
  20  | const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }
  21  | const runId = `e2e-chain-${Date.now()}`
  22  | 
  23  | /** Find a register by slug. */
  24  | async function registerBySlug(request: APIRequestContext, slug: string): Promise<any> {
  25  | 	const resp = await request.get(`${API}/registers?limit=1000`)
> 26  | 	expect(resp.ok(), 'list registers').toBeTruthy()
      |                                      ^ Error: list registers
  27  | 	const rows: any[] = (await resp.json()).results ?? []
  28  | 	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
  29  | 	expect(match, `register slug=${slug} exists`).toBeTruthy()
  30  | 	return match
  31  | }
  32  | 
  33  | /** Resolve a schema by slug WITHIN a register — `flow` is not a unique slug. */
  34  | async function schemaInRegister(request: APIRequestContext, register: any, slug: string): Promise<number> {
  35  | 	const ids: number[] = (register.schemas ?? []).map((s: any) => (typeof s === 'object' ? (s.id ?? s) : s))
  36  | 	for (const id of ids) {
  37  | 		const resp = await request.get(`${API}/schemas/${id}`)
  38  | 		if (!resp.ok()) continue
  39  | 		const sch = await resp.json()
  40  | 		if ((sch.slug ?? sch['@self']?.slug) === slug) return sch.id ?? sch['@self']?.id
  41  | 	}
  42  | 	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
  43  | }
  44  | 
  45  | test.describe('OpenConnector chaining expressed as a flow', () => {
  46  | 	let flowReg: number
  47  | 	let flowSch: number
  48  | 	const flows: string[] = []
  49  | 
  50  | 	test.beforeAll(async ({ request }) => {
  51  | 		const flowsRegister = await registerBySlug(request, 'flows')
  52  | 		flowReg = flowsRegister.id ?? flowsRegister['@self']?.id
  53  | 		flowSch = await schemaInRegister(request, flowsRegister, 'flow')
  54  | 	})
  55  | 
  56  | 	test.afterAll(async ({ request }) => {
  57  | 		for (const u of flows) {
  58  | 			await request.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`).catch(() => {})
  59  | 		}
  60  | 	})
  61  | 
  62  | 	async function createFlow(request: APIRequestContext, name: string, body: Record<string, unknown>) {
  63  | 		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
  64  | 			headers: JSON_HEADERS,
  65  | 			data: { name: `${name} ${runId}`, enabled: false, ...body },
  66  | 		})
  67  | 		expect(resp.status(), `create flow ${name}`).toBeLessThanOrEqual(201)
  68  | 		const uuid = (await resp.json())?.['@self']?.id
  69  | 		flows.push(uuid)
  70  | 		return uuid
  71  | 	}
  72  | 
  73  | 	test('a multi-step chain runs in the declared order and is inspectable', async ({ request }) => {
  74  | 		// The chaining openconnector expressed as recursive followUps, expressed
  75  | 		// instead as a graph: order is a property of the edges, not of who calls
  76  | 		// whom from inside a service.
  77  | 		const flow = await createFlow(request, 'Ordered chain', {
  78  | 			nodes: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
  79  | 			edges: [
  80  | 				{ id: 'e1', from: 'a', to: 'b', type: 'openregister.set-fields', config: { set: { step: 'one' } } },
  81  | 				{ id: 'e2', from: 'b', to: 'c', type: 'openregister.set-fields', config: { set: { step: 'two' } } },
  82  | 			],
  83  | 		})
  84  | 
  85  | 		const run = await request.post(`${API}/flow-runs/test`, {
  86  | 			headers: JSON_HEADERS,
  87  | 			data: { flowId: flow, seedItems: [{ json: { seeded: true } }] },
  88  | 		})
  89  | 		expect(run.status(), 'test run').toBe(200)
  90  | 		const result = await run.json()
  91  | 
  92  | 		expect(result.status, 'the chain completed').toBe('completed')
  93  | 		// The later step's value wins — proof the edges ran in declared order.
  94  | 		expect(result.items?.[0]?.json?.step, 'the second step ran after the first').toBe('two')
  95  | 		// Every hop is recorded, which recursive followUps never gave you.
  96  | 		expect(Array.isArray(result.log), 'the run carries an inspectable trace').toBeTruthy()
  97  | 		expect(result.log.length, 'the trace is not empty').toBeGreaterThan(0)
  98  | 	})
  99  | 
  100 | 	test('a self-referencing flow is bounded instead of recursing forever', async ({ request }) => {
  101 | 		// The A -> A case. Under openconnector's followUps this recursed until the
  102 | 		// process died; the engine's sub-flow guard must refuse or bound it. The
  103 | 		// assertion is deliberately about TERMINATION, not a specific error: any
  104 | 		// bounded outcome is correct, hanging is not.
  105 | 		const flow = await createFlow(request, 'Self cycle', { nodes: [{ id: 'a' }, { id: 'b' }], edges: [] })
  106 | 
  107 | 		const selfEdge = await request.put(`${API}/objects/${flowReg}/${flowSch}/${flow}`, {
  108 | 			headers: JSON_HEADERS,
  109 | 			data: {
  110 | 				name: `Self cycle ${runId}`,
  111 | 				enabled: false,
  112 | 				nodes: [{ id: 'a' }, { id: 'b' }],
  113 | 				edges: [{ id: 'e1', from: 'a', to: 'b', type: 'openregister.sub-flow', config: { flow, wait: true } }],
  114 | 			},
  115 | 		})
  116 | 		expect(selfEdge.status(), 'store the self-referencing edge').toBeLessThan(400)
  117 | 
  118 | 		const started = Date.now()
  119 | 		const run = await request.post(`${API}/flow-runs/test`, {
  120 | 			headers: JSON_HEADERS,
  121 | 			data: { flowId: flow, seedItems: [{ json: {} }] },
  122 | 		})
  123 | 		const elapsed = Date.now() - started
  124 | 
  125 | 		// It came back at all — that is the headline.
  126 | 		expect(elapsed, 'a self-referencing chain terminates rather than hanging').toBeLessThan(90_000)
```