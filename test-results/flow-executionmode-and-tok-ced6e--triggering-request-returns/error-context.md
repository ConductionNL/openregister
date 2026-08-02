# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: flow-executionmode-and-token.spec.ts >> Flow execution mode and token >> an async flow is still queued when the triggering request returns
- Location: tests/e2e/api-direct/flow-executionmode-and-token.spec.ts:165:6

# Error details

```
Error: list registers

expect(received).toBeTruthy()

Received: false
```

# Test source

```ts
  1   | /**
  2   |  * Flow execution mode and the run-level flow token — end-to-end via the live API.
  3   |  *
  4   |  * The two things this proves that unit tests cannot:
  5   |  *
  6   |  *  1. `executionMode: sync` really runs the flow INSIDE the request that
  7   |  *     triggered it. The assertion is timing-free and therefore not flaky: the
  8   |  *     run is already in a terminal state the moment the create response comes
  9   |  *     back, whereas an `async` run is still `queued` because nothing has drained
  10  |  *     it yet.
  11  |  *  2. The token round-trips through the real persistence path. `execute()`
  12  |  *     rehydrates `context['token']` into an object and `persistResult()`
  13  |  *     serialises it back into the run's JSON column — so a run fetched from the
  14  |  *     history endpoint carries a token, through a real database.
  15  |  *
  16  |  * Auth is the Basic-auth admin context from the playwright config, so no browser
  17  |  * session is needed. Everything created here is torn down in afterAll.
  18  |  *
  19  |  * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
  20  |  * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-token/spec.md
  21  |  */
  22  | import { test, expect, type APIRequestContext } from '@playwright/test'
  23  | 
  24  | const API = '/index.php/apps/openregister/api'
  25  | const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }
  26  | const runId = `e2e-exec-${Date.now()}`
  27  | 
  28  | /** Find a register by slug. */
  29  | async function registerBySlug(request: APIRequestContext, slug: string): Promise<any> {
  30  | 	const resp = await request.get(`${API}/registers?limit=1000`)
> 31  | 	expect(resp.ok(), 'list registers').toBeTruthy()
      |                                      ^ Error: list registers
  32  | 	const body = await resp.json()
  33  | 	const rows: any[] = body.results ?? body ?? []
  34  | 	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
  35  | 	expect(match, `register slug=${slug} exists (shipped by ImportFlowRegister)`).toBeTruthy()
  36  | 	return match
  37  | }
  38  | 
  39  | /**
  40  |  * Resolve a schema by slug WITHIN a register.
  41  |  *
  42  |  * Deliberately not a global slug lookup: `flow` is not unique across the
  43  |  * instance — nine apps ship a schema with that slug — so picking the first
  44  |  * global match lands on some other app's schema and every write 400s against
  45  |  * properties this flow never had. The register scopes it to the one we mean.
  46  |  */
  47  | async function schemaInRegister(request: APIRequestContext, register: any, slug: string): Promise<number> {
  48  | 	const ids: number[] = (register.schemas ?? []).map((s: any) => (typeof s === 'object' ? (s.id ?? s) : s))
  49  | 	expect(ids.length, 'the register lists its schemas').toBeGreaterThan(0)
  50  | 
  51  | 	for (const id of ids) {
  52  | 		const resp = await request.get(`${API}/schemas/${id}`)
  53  | 		if (!resp.ok()) {
  54  | 			continue
  55  | 		}
  56  | 		const sch = await resp.json()
  57  | 		if ((sch.slug ?? sch['@self']?.slug) === slug) {
  58  | 			return sch.id ?? sch['@self']?.id
  59  | 		}
  60  | 	}
  61  | 
  62  | 	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
  63  | }
  64  | 
  65  | /** The runs recorded for one flow, newest first. */
  66  | async function runsFor(request: APIRequestContext, flowUuid: string): Promise<any[]> {
  67  | 	const resp = await request.get(`${API}/flow-runs?limit=100`)
  68  | 	expect(resp.ok(), 'list flow runs').toBeTruthy()
  69  | 	const body = await resp.json()
  70  | 	const rows: any[] = body.results ?? body ?? []
  71  | 	return rows.filter((r) => (r.flowId ?? r['@self']?.flowId) === flowUuid)
  72  | }
  73  | 
  74  | test.describe('Flow execution mode and token', () => {
  75  | 	let flowReg: number
  76  | 	let flowSch: number
  77  | 	let subjReg: number
  78  | 	let subjSch: number
  79  | 	const flows: string[] = []
  80  | 	const subjects: string[] = []
  81  | 
  82  | 	test.beforeAll(async ({ request }) => {
  83  | 		const flowsRegister = await registerBySlug(request, 'flows')
  84  | 		flowReg = flowsRegister.id ?? flowsRegister['@self']?.id
  85  | 		flowSch = await schemaInRegister(request, flowsRegister, 'flow')
  86  | 
  87  | 		// A throwaway register/schema to trigger on. Triggering on the flow store
  88  | 		// itself would make authoring a flow fire the flow being authored.
  89  | 		const schResp = await request.post(`${API}/schemas`, {
  90  | 			headers: JSON_HEADERS,
  91  | 			data: { title: `ExecMode Subject ${runId}`, properties: { name: { type: 'string' } } },
  92  | 		})
  93  | 		expect(schResp.status(), 'create subject schema').toBeLessThan(300)
  94  | 		subjSch = (await schResp.json()).id
  95  | 
  96  | 		const regResp = await request.post(`${API}/registers`, {
  97  | 			headers: JSON_HEADERS,
  98  | 			data: { title: `ExecMode Register ${runId}`, schemas: [subjSch] },
  99  | 		})
  100 | 		expect(regResp.status(), 'create subject register').toBeLessThan(300)
  101 | 		subjReg = (await regResp.json()).id
  102 | 	})
  103 | 
  104 | 	test.afterAll(async ({ request }) => {
  105 | 		for (const u of subjects) {
  106 | 			await request.delete(`${API}/objects/${subjReg}/${subjSch}/${u}`).catch(() => {})
  107 | 		}
  108 | 		for (const u of flows) {
  109 | 			await request.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`).catch(() => {})
  110 | 		}
  111 | 		await request.delete(`${API}/registers/${subjReg}`).catch(() => {})
  112 | 		await request.delete(`${API}/schemas/${subjSch}`).catch(() => {})
  113 | 	})
  114 | 
  115 | 	/** Author a flow wired to object.created on the throwaway schema. */
  116 | 	async function createTriggeredFlow(request: APIRequestContext, name: string, executionMode?: string): Promise<string> {
  117 | 		const data: Record<string, unknown> = {
  118 | 			name: `${name} ${runId}`,
  119 | 			enabled: true,
  120 | 			trigger: 'object.created',
  121 | 			triggerRegister: String(subjReg),
  122 | 			triggerSchema: String(subjSch),
  123 | 			nodes: [{ id: 'start' }, { id: 'end' }],
  124 | 			edges: [{ id: 'e1', from: 'start', to: 'end', type: 'openregister.set-fields', config: { set: { touched: true } } }],
  125 | 		}
  126 | 		if (executionMode !== undefined) {
  127 | 			data.executionMode = executionMode
  128 | 		}
  129 | 
  130 | 		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, { headers: JSON_HEADERS, data })
  131 | 		expect(resp.status(), 'create flow').toBeLessThanOrEqual(201)
```