import type { APIRequestContext } from '@playwright/test'

/**
 * Flow execution mode and the run-level flow token — end-to-end via the live API.
 *
 * The two things this proves that unit tests cannot:
 *
 *  1. `executionMode: sync` really runs the flow INSIDE the request that
 *     triggered it. The assertion is timing-free and therefore not flaky: the
 *     run is already in a terminal state the moment the create response comes
 *     back, whereas an `async` run is still `queued` because nothing has drained
 *     it yet.
 *  2. The token round-trips through the real persistence path. `execute()`
 *     rehydrates `context['token']` into an object and `persistResult()`
 *     serialises it back into the run's JSON column — so a run fetched from the
 *     history endpoint carries a token, through a real database.
 *
 * Auth is the Basic-auth admin context from the playwright config, so no browser
 * session is needed. Everything created here is torn down in afterAll.
 *
 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-token/spec.md
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const runId = `e2e-exec-${Date.now()}`

/** Find a register by slug. */
async function registerBySlug(
	request: APIRequestContext,
	slug: string,
): Promise<any> {
	const resp = await request.get(`${API}/registers?limit=1000`)
	expect(resp.ok(), 'list registers').toBeTruthy()
	const body = await resp.json()
	const rows: any[] = body.results ?? body ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(
		match,
		`register slug=${slug} exists (shipped by ImportFlowRegister)`,
	).toBeTruthy()
	return match
}

/**
 * Resolve a schema by slug WITHIN a register.
 *
 * Deliberately not a global slug lookup: `flow` is not unique across the
 * instance — nine apps ship a schema with that slug — so picking the first
 * global match lands on some other app's schema and every write 400s against
 * properties this flow never had. The register scopes it to the one we mean.
 */
async function schemaInRegister(
	request: APIRequestContext,
	register: any,
	slug: string,
): Promise<number> {
	const ids: number[] = (register.schemas ?? []).map((s: any) =>
		typeof s === 'object' ? (s.id ?? s) : s,
	)
	expect(ids.length, 'the register lists its schemas').toBeGreaterThan(0)

	for (const id of ids) {
		const resp = await request.get(`${API}/schemas/${id}`)
		if (!resp.ok()) {
			continue
		}
		const sch = await resp.json()
		if ((sch.slug ?? sch['@self']?.slug) === slug) {
			return sch.id ?? sch['@self']?.id
		}
	}

	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
}

/** The runs recorded for one flow, newest first. */
async function runsFor(
	request: APIRequestContext,
	flowUuid: string,
): Promise<any[]> {
	const resp = await request.get(`${API}/flow-runs?limit=100`)
	expect(resp.ok(), 'list flow runs').toBeTruthy()
	const body = await resp.json()
	const rows: any[] = body.results ?? body ?? []
	return rows.filter((r) => (r.flowId ?? r['@self']?.flowId) === flowUuid)
}

test.describe('Flow execution mode and token', () => {
	let flowReg: number
	let flowSch: number
	let subjReg: number
	let subjSch: number
	const flows: string[] = []
	const subjects: string[] = []

	test.beforeAll(async ({ request }) => {
		const flowsRegister = await registerBySlug(request, 'flows')
		flowReg = flowsRegister.id ?? flowsRegister['@self']?.id
		flowSch = await schemaInRegister(request, flowsRegister, 'flow')

		// A throwaway register/schema to trigger on. Triggering on the flow store
		// itself would make authoring a flow fire the flow being authored.
		const schResp = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `ExecMode Subject ${runId}`,
				properties: { name: { type: 'string' } },
			},
		})
		expect(schResp.status(), 'create subject schema').toBeLessThan(300)
		subjSch = (await schResp.json()).id

		const regResp = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: { title: `ExecMode Register ${runId}`, schemas: [subjSch] },
		})
		expect(regResp.status(), 'create subject register').toBeLessThan(300)
		subjReg = (await regResp.json()).id
	})

	test.afterAll(async ({ request }) => {
		for (const u of subjects) {
			await request
				.delete(`${API}/objects/${subjReg}/${subjSch}/${u}`)
				.catch(() => {})
		}
		for (const u of flows) {
			await request
				.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`)
				.catch(() => {})
		}
		await request.delete(`${API}/registers/${subjReg}`).catch(() => {})
		await request.delete(`${API}/schemas/${subjSch}`).catch(() => {})
	})

	/** Author a flow wired to object.created on the throwaway schema. */
	async function createTriggeredFlow(
		request: APIRequestContext,
		name: string,
		executionMode?: string,
	): Promise<string> {
		const data: Record<string, unknown> = {
			name: `${name} ${runId}`,
			enabled: true,
			trigger: 'object.created',
			triggerRegister: String(subjReg),
			triggerSchema: String(subjSch),
			nodes: [{ id: 'start' }, { id: 'end' }],
			edges: [
				{
					id: 'e1',
					from: 'start',
					to: 'end',
					type: 'openregister.set-fields',
					config: { set: { touched: true } },
				},
			],
		}
		if (executionMode !== undefined) {
			data.executionMode = executionMode
		}

		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
			headers: JSON_HEADERS,
			data,
		})
		expect(resp.status(), 'create flow').toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.['@self']?.id
		expect(uuid, 'flow uuid').toBeTruthy()
		flows.push(uuid)
		return uuid
	}

	/** Create an object in the throwaway schema — this is what fires the trigger. */
	async function fireTrigger(
		request: APIRequestContext,
		name: string,
	): Promise<void> {
		const resp = await request.post(`${API}/objects/${subjReg}/${subjSch}`, {
			headers: JSON_HEADERS,
			data: { name },
		})
		expect(resp.status(), 'create subject object').toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.['@self']?.id
		if (uuid) {
			subjects.push(uuid)
		}
	}

	test('a sync flow has already run when the triggering request returns', async ({
		request,
	}) => {
		const flow = await createTriggeredFlow(request, 'Sync flow', 'sync')

		await fireTrigger(request, 'fires-sync')

		// No polling and no waiting: if `sync` works, the run is terminal now.
		const runs = await runsFor(request, flow)
		expect(runs.length, 'the trigger produced a run').toBeGreaterThan(0)
		expect(
			['completed', 'stopped', 'failed', 'dead_letter'],
			'a sync run is finished inside the triggering request',
		).toContain(runs[0].status)
	})

	test('an async flow is still queued when the triggering request returns', async ({
		request,
	}) => {
		const flow = await createTriggeredFlow(request, 'Async flow', 'async')

		await fireTrigger(request, 'fires-async')

		const runs = await runsFor(request, flow)
		expect(runs.length, 'the trigger produced a run').toBeGreaterThan(0)
		expect(runs[0].status, 'an async run waits for the worker').toBe('queued')
	})

	test('a flow that declares no mode keeps the queued default', async ({
		request,
	}) => {
		const flow = await createTriggeredFlow(request, 'Default flow')

		await fireTrigger(request, 'fires-default')

		const runs = await runsFor(request, flow)
		expect(runs.length, 'the trigger produced a run').toBeGreaterThan(0)
		expect(runs[0].status, 'no mode means async').toBe('queued')
	})

	test('a run persists a flow token through the real database', async ({
		request,
	}) => {
		// The test endpoint drives execute() -> engine -> persistResult(), which is
		// exactly the path that rehydrates and re-serialises the token.
		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
			headers: JSON_HEADERS,
			data: {
				name: `Token flow ${runId}`,
				enabled: false,
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [
					{
						id: 'e1',
						from: 'a',
						to: 'b',
						type: 'openregister.set-fields',
						config: { set: { ok: true } },
					},
				],
			},
		})
		expect(resp.status()).toBeLessThanOrEqual(201)
		const flow = (await resp.json())?.['@self']?.id
		flows.push(flow)

		const testResp = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: flow, seedItems: [{ json: { seed: 1 } }] },
		})
		expect(testResp.status(), 'test run').toBe(200)
		const result = await testResp.json()
		expect(result.status, 'the run completed').toBe('completed')

		// Re-read the persisted run: the token survived the JSON column.
		const runs = await runsFor(request, flow)
		expect(runs.length, 'the test run was persisted').toBeGreaterThan(0)
		const stored = runs[0].context ?? {}
		expect(
			Object.hasOwn(stored, 'token'),
			'the persisted context carries the token the engine handed to the steps',
		).toBeTruthy()
	})
})
