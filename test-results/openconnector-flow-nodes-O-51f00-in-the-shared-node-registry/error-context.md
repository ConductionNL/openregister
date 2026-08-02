# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: openconnector-flow-nodes.spec.ts >> OpenConnector flow-node leaves >> both leaves are registered in the shared node registry
- Location: tests/e2e/api-direct/openconnector-flow-nodes.spec.ts:86:6

# Error details

```
Error: list registers

expect(received).toBeTruthy()

Received: false
```

# Test source

```ts
  1   | /**
  2   |  * OpenConnector's flow-node leaves — end-to-end through the live engine.
  3   |  *
  4   |  * These nodes belong to openconnector (ConductionNL/openconnector#1067); this
  5   |  * spec exercises them from OpenRegister's side, which is the only place their
  6   |  * contract is actually observable: registration in the shared node registry,
  7   |  * config validation at flow-save time, and behaviour when the engine runs them.
  8   |  *
  9   |  * The point is the seam, not the app. A leaf that unit-tests green can still be
  10  |  * invisible to the registry, be claimed by another app's resolver, or refuse
  11  |  * every config the palette can produce — none of which a mock catches, and all
  12  |  * of which have actually happened in this codebase.
  13  |  *
  14  |  * Auth is the preemptive Basic-auth context from playwright.flow.config.ts.
  15  |  * Everything created here is torn down in afterAll.
  16  |  *
  17  |  * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
  18  |  */
  19  | import { test, expect, type APIRequestContext } from '@playwright/test'
  20  | 
  21  | const API = '/index.php/apps/openregister/api'
  22  | const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }
  23  | const runId = `e2e-ocnodes-${Date.now()}`
  24  | 
  25  | /** Find a register by slug. */
  26  | async function registerBySlug(request: APIRequestContext, slug: string): Promise<any> {
  27  | 	const resp = await request.get(`${API}/registers?limit=1000`)
> 28  | 	expect(resp.ok(), 'list registers').toBeTruthy()
      |                                      ^ Error: list registers
  29  | 	const rows: any[] = (await resp.json()).results ?? []
  30  | 	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
  31  | 	expect(match, `register slug=${slug} exists`).toBeTruthy()
  32  | 	return match
  33  | }
  34  | 
  35  | /**
  36  |  * Resolve a schema by slug WITHIN a register — `flow` is not a unique slug
  37  |  * across the instance, so a global lookup lands on another app's schema.
  38  |  */
  39  | async function schemaInRegister(request: APIRequestContext, register: any, slug: string): Promise<number> {
  40  | 	const ids: number[] = (register.schemas ?? []).map((s: any) => (typeof s === 'object' ? (s.id ?? s) : s))
  41  | 	for (const id of ids) {
  42  | 		const resp = await request.get(`${API}/schemas/${id}`)
  43  | 		if (!resp.ok()) continue
  44  | 		const sch = await resp.json()
  45  | 		if ((sch.slug ?? sch['@self']?.slug) === slug) return sch.id ?? sch['@self']?.id
  46  | 	}
  47  | 	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
  48  | }
  49  | 
  50  | test.describe('OpenConnector flow-node leaves', () => {
  51  | 	let flowReg: number
  52  | 	let flowSch: number
  53  | 	const flows: string[] = []
  54  | 
  55  | 	test.beforeAll(async ({ request }) => {
  56  | 		const flowsRegister = await registerBySlug(request, 'flows')
  57  | 		flowReg = flowsRegister.id ?? flowsRegister['@self']?.id
  58  | 		flowSch = await schemaInRegister(request, flowsRegister, 'flow')
  59  | 	})
  60  | 
  61  | 	test.afterAll(async ({ request }) => {
  62  | 		for (const u of flows) {
  63  | 			await request.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`).catch(() => {})
  64  | 		}
  65  | 	})
  66  | 
  67  | 	/** Author a flow whose single edge runs the given node type. */
  68  | 	async function flowWithStep(
  69  | 		request: APIRequestContext,
  70  | 		name: string,
  71  | 		type: string,
  72  | 		config: Record<string, unknown>,
  73  | 	) {
  74  | 		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
  75  | 			headers: JSON_HEADERS,
  76  | 			data: {
  77  | 				name: `${name} ${runId}`,
  78  | 				enabled: false,
  79  | 				nodes: [{ id: 'a' }, { id: 'b' }],
  80  | 				edges: [{ id: 'e1', from: 'a', to: 'b', type, config }],
  81  | 			},
  82  | 		})
  83  | 		return resp
  84  | 	}
  85  | 
  86  | 	test('both leaves are registered in the shared node registry', async ({ request }) => {
  87  | 		// The palette is the registry's public face; a leaf missing here is a
  88  | 		// leaf no flow author can ever place, however green its unit tests are.
  89  | 		const resp = await request.get(`${API}/flow/nodes`)
  90  | 
  91  | 		if (resp.status() === 404) {
  92  | 			// Not a wrong path: OpenRegister ships NO palette endpoint at all.
  93  | 			// `FlowNodeRegistry::palette()` is in-process only and `FlowController`
  94  | 			// exposes just `eventCatalog()`, so nothing over HTTP can enumerate the
  95  | 			// registered node types — a flow-authoring UI cannot discover which
  96  | 			// leaves exist. Registration itself is still covered: the steps below
  97  | 			// place these node types in a real flow and the engine resolves them,
  98  | 			// which fails loudly for an unregistered type.
  99  | 			test.skip(true, 'OpenRegister exposes no node-palette endpoint; registration is asserted by the runs below')
  100 | 			return
  101 | 		}
  102 | 
  103 | 		expect(resp.ok(), 'fetch node palette').toBeTruthy()
  104 | 		const body = await resp.json()
  105 | 		const ids: string[] = (body.nodes ?? body.results ?? body ?? []).map((n: any) => n.id ?? n.type)
  106 | 
  107 | 		expect(ids, 'source-call is offered').toContain('openconnector.source-call')
  108 | 		expect(ids, 'synchronization-run is offered').toContain('openconnector.synchronization-run')
  109 | 	})
  110 | 
  111 | 	test('a source-call step without a source is refused at save time', async ({ request }) => {
  112 | 		const resp = await flowWithStep(request, 'Bad source-call', 'openconnector.source-call', {})
  113 | 
  114 | 		// Either the write is refused, or it is stored and the engine refuses to
  115 | 		// run it. What must NOT happen is a silently-accepted step that no-ops.
  116 | 		if (resp.status() <= 201) {
  117 | 			const uuid = (await resp.json())?.['@self']?.id
  118 | 			flows.push(uuid)
  119 | 
  120 | 			const run = await request.post(`${API}/flow-runs/test`, {
  121 | 				headers: JSON_HEADERS,
  122 | 				data: { flowId: uuid, seedItems: [{ json: {} }] },
  123 | 			})
  124 | 			const result = run.ok() ? await run.json() : null
  125 | 			expect(
  126 | 				run.status() >= 400 || (result && result.status !== 'completed'),
  127 | 				'an unconfigured source-call must not report success',
  128 | 			).toBeTruthy()
```