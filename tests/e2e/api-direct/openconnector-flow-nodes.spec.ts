import type { APIRequestContext } from '@playwright/test'

/**
 * OpenConnector's flow-node leaves — end-to-end through the live engine.
 *
 * These nodes belong to openconnector (ConductionNL/openconnector#1067); this
 * spec exercises them from OpenRegister's side, which is the only place their
 * contract is actually observable: registration in the shared node registry,
 * config validation at flow-save time, and behaviour when the engine runs them.
 *
 * The point is the seam, not the app. A leaf that unit-tests green can still be
 * invisible to the registry, be claimed by another app's resolver, or refuse
 * every config the palette can produce — none of which a mock catches, and all
 * of which have actually happened in this codebase.
 *
 * Auth is the preemptive Basic-auth context from playwright.flow.config.ts.
 * Everything created here is torn down in afterAll.
 *
 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const runId = `e2e-ocnodes-${Date.now()}`

/** Find a register by slug. */
async function registerBySlug(
	request: APIRequestContext,
	slug: string,
): Promise<any> {
	const resp = await request.get(`${API}/registers?limit=1000`)
	expect(resp.ok(), 'list registers').toBeTruthy()
	const rows: any[] = (await resp.json()).results ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(match, `register slug=${slug} exists`).toBeTruthy()
	return match
}

/**
 * Resolve a schema by slug WITHIN a register — `flow` is not a unique slug
 * across the instance, so a global lookup lands on another app's schema.
 */
async function schemaInRegister(
	request: APIRequestContext,
	register: any,
	slug: string,
): Promise<number> {
	const ids: number[] = (register.schemas ?? []).map((s: any) =>
		typeof s === 'object' ? (s.id ?? s) : s,
	)
	for (const id of ids) {
		const resp = await request.get(`${API}/schemas/${id}`)
		if (!resp.ok()) continue
		const sch = await resp.json()
		if ((sch.slug ?? sch['@self']?.slug) === slug)
			return sch.id ?? sch['@self']?.id
	}
	throw new Error(`schema slug=${slug} not found in register ${register.slug}`)
}

test.describe('OpenConnector flow-node leaves', () => {
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
			await request
				.delete(`${API}/objects/${flowReg}/${flowSch}/${u}`)
				.catch(() => {})
		}
	})

	/** Author a flow whose single edge runs the given node type. */
	async function flowWithStep(
		request: APIRequestContext,
		name: string,
		type: string,
		config: Record<string, unknown>,
	) {
		const resp = await request.post(`${API}/objects/${flowReg}/${flowSch}`, {
			headers: JSON_HEADERS,
			data: {
				name: `${name} ${runId}`,
				enabled: false,
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [{ id: 'e1', from: 'a', to: 'b', type, config }],
			},
		})
		return resp
	}

	test('both leaves are registered in the shared node registry', async ({
		request,
	}) => {
		// The palette is the registry's public face; a leaf missing here is a
		// leaf no flow author can ever place, however green its unit tests are.
		//
		// This assertion used to skip, because OpenRegister exposed no palette
		// endpoint at all — `FlowNodeRegistry::palette()` was in-process only.
		// #2177 added `/api/flow/node-catalog`, so the registry is now observable
		// over HTTP and the check can be real. That matters: openconnector's
		// leaves silently failed to register for a load-order reason
		// (openconnector#1076), and nothing before this could see it.
		const resp = await request.get(`${API}/flow/node-catalog`)

		expect(resp.ok(), 'fetch node catalog').toBeTruthy()
		const body = await resp.json()
		const ids: string[] = (body.results ?? []).map((n: any) => n.id)

		expect(ids, 'source-call is offered').toContain('openconnector.source-call')
		expect(ids, 'synchronization-run is offered').toContain(
			'openconnector.synchronization-run',
		)
	})

	test('a source-call step without a source is refused at save time', async ({
		request,
	}) => {
		const resp = await flowWithStep(
			request,
			'Bad source-call',
			'openconnector.source-call',
			{},
		)

		// Either the write is refused, or it is stored and the engine refuses to
		// run it. What must NOT happen is a silently-accepted step that no-ops.
		if (resp.status() <= 201) {
			const uuid = (await resp.json())?.['@self']?.id
			flows.push(uuid)

			const run = await request.post(`${API}/flow-runs/test`, {
				headers: JSON_HEADERS,
				data: { flowId: uuid, seedItems: [{ json: {} }] },
			})
			const result = run.ok() ? await run.json() : null
			expect(
				run.status() >= 400 || (result && result.status !== 'completed'),
				'an unconfigured source-call must not report success',
			).toBeTruthy()
		} else {
			expect(resp.status(), 'refused at save time').toBeGreaterThanOrEqual(400)
		}
	})

	test('a synchronization-run step without a synchronization is refused', async ({
		request,
	}) => {
		const resp = await flowWithStep(
			request,
			'Bad sync-run',
			'openconnector.synchronization-run',
			{},
		)

		if (resp.status() <= 201) {
			const uuid = (await resp.json())?.['@self']?.id
			flows.push(uuid)

			const run = await request.post(`${API}/flow-runs/test`, {
				headers: JSON_HEADERS,
				data: { flowId: uuid, seedItems: [{ json: {} }] },
			})
			const result = run.ok() ? await run.json() : null
			expect(
				run.status() >= 400 || (result && result.status !== 'completed'),
				'an unconfigured synchronization-run must not report success',
			).toBeTruthy()
		} else {
			expect(resp.status(), 'refused at save time').toBeGreaterThanOrEqual(400)
		}
	})

	test('a synchronization-run naming a missing synchronization fails loudly', async ({
		request,
	}) => {
		// The failure that matters: a step pointed at something that does not
		// exist must surface, not quietly produce an empty result set that reads
		// as a successful run with no data.
		const resp = await flowWithStep(
			request,
			'Missing sync',
			'openconnector.synchronization-run',
			{
				synchronization: '00000000-0000-4000-8000-000000000000',
			},
		)

		if (resp.status() > 201) {
			expect(resp.status()).toBeGreaterThanOrEqual(400)
			return
		}

		const uuid = (await resp.json())?.['@self']?.id
		flows.push(uuid)

		const run = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: uuid, seedItems: [{ json: {} }] },
		})

		if (run.ok()) {
			const result = await run.json()
			expect(
				result.status,
				'a missing synchronization must not complete cleanly',
			).not.toBe('completed')
		} else {
			expect(run.status()).toBeGreaterThanOrEqual(400)
		}
	})
})
