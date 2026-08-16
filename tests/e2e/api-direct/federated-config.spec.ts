/**
 * Federated configuration sharing — end-to-end via the live HTTP API.
 *
 * Proves the standard on its first consumer (Flows): list the shareable types
 * every app contributed, bundle a flow into a portable, instance-independent
 * shape, install that bundle as a fresh flow, and run the installed flow — then
 * that the organisation source allowlist refuses an install from a source not on
 * it, and an unknown type is a 404.
 *
 * The allowlist is toggled via `occ config:app:set` through the dev container
 * (NC_CONTAINER, default `nextcloud`); those two tests skip cleanly off the dev
 * host.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveContainer } from '../base-url'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
// ⚠️ No `|| 'nextcloud'` default — see resolveContainer(). The old default
// ran `occ` inside the SHARED dev container, which bind-mounts real host
// checkouts. With no NC_CONTAINER set these tests now skip (occ returns null)
// rather than mutating somebody else's instance.
const CONTAINER = resolveContainer()
const runId = `e2e-fed-${Date.now()}`

function occ(args: string): string | null {
	if (CONTAINER === null) {
		return null
	}
	try {
		return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
			encoding: 'utf8',
		})
	} catch {
		return null
	}
}

async function idBySlug(
	request: APIRequestContext,
	kind: 'registers' | 'schemas',
	slug: string,
): Promise<number> {
	const resp = await request.get(`${API}/${kind}?limit=1000`)
	const body = await resp.json()
	const rows: any[] = body.results ?? body ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(match, `${kind} slug=${slug}`).toBeTruthy()
	return match.id ?? match['@self']?.id
}

test.describe('Federated configuration sharing', () => {
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

	async function makeFlow(
		request: APIRequestContext,
		name: string,
	): Promise<string> {
		const resp = await request.post(`${API}/objects/${reg}/${sch}`, {
			headers: JSON_HEADERS,
			data: {
				name,
				enabled: true,
				trigger: 'manual',
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [
					{
						id: 's1',
						from: 'a',
						to: 'b',
						type: 'openregister.set-fields',
						config: { set: { shared: true } },
					},
				],
			},
		})
		const uuid = (await resp.json())?.['@self']?.id
		created.push(uuid)
		return uuid
	}

	test('the shareable types include Flows and Registers', async ({ request }) => {
		const resp = await request.get(`${API}/federated-config/types`)
		expect(resp.status()).toBe(200)
		const types = (await resp.json()).types ?? []
		const flows = types.find((t: any) => t.id === 'openregister.flows')
		expect(flows, 'Flows is a shareable type').toBeTruthy()
		expect(flows.topic).toBe('openregister-flow')
		// A second, very different consumer proves the standard generalises.
		const registers = types.find((t: any) => t.id === 'openregister.registers')
		expect(registers, 'Registers & schemas is a shareable type').toBeTruthy()
		expect(registers.topic).toBe('openregister-register')
	})

	test('a register bundles into a portable OpenAPI document', async ({
		request,
	}) => {
		// Read-only: bundle the shipped `flows` register; do not install (that
		// would re-import a shared register).
		const resp = await request.post(`${API}/federated-config/bundle`, {
			headers: JSON_HEADERS,
			data: {
				type: 'openregister.registers',
				selection: { register: 'flows' },
			},
		})
		expect(resp.status()).toBe(200)
		const bundle = await resp.json()
		expect(bundle.openapi, 'the bundle is an OpenAPI document').toBeTruthy()
		expect(
			bundle.components?.registers?.flows,
			'it carries the flows register',
		).toBeTruthy()
		expect(bundle.components?.schemas?.flow, 'and its flow schema').toBeTruthy()
	})

	test('a flow bundles into a portable shape and installs as a fresh flow that runs', async ({
		request,
	}) => {
		const uuid = await makeFlow(request, `${runId} source`)

		// Bundle — portable, no instance ids.
		const bundleResp = await request.post(`${API}/federated-config/bundle`, {
			headers: JSON_HEADERS,
			data: { type: 'openregister.flows', selection: { flowIds: [uuid] } },
		})
		expect(bundleResp.status()).toBe(200)
		const bundle = await bundleResp.json()
		expect(bundle.type).toBe('openregister.flows')
		expect(bundle.flows.length).toBe(1)
		expect(bundle.flows[0].name).toBe(`${runId} source`)
		expect(
			bundle.flows[0].uuid,
			'no instance uuid in the bundle',
		).toBeUndefined()

		// Install — a fresh flow.
		const installResp = await request.post(`${API}/federated-config/install`, {
			headers: JSON_HEADERS,
			data: {
				type: 'openregister.flows',
				bundle,
				source: 'ConductionNL/flow-pack',
			},
		})
		expect(installResp.status()).toBe(200)
		const installed = (await installResp.json()).installed ?? []
		expect(installed.length).toBe(1)
		const newUuid = installed[0]
		created.push(newUuid)
		expect(newUuid).not.toBe(uuid)

		// The installed flow runs.
		const runResp = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: newUuid },
		})
		expect(runResp.status()).toBe(200)
		const run = await runResp.json()
		expect(run.status).toBe('completed')
		expect((run.items ?? [])[0]?.json?.shared).toBe(true)
	})

	test('an unknown type is a 404', async ({ request }) => {
		const resp = await request.post(`${API}/federated-config/bundle`, {
			headers: JSON_HEADERS,
			data: { type: 'nope.nothing', selection: {} },
		})
		expect(resp.status()).toBe(404)
	})

	test('the org source allowlist refuses a non-allowlisted source', async ({
		request,
	}) => {
		const set = occ(
			'config:app:set openregister federated_config_source_allowlist --value="ConductionNL"',
		)
		test.skip(set === null, 'occ not reachable (not on the dev host)')

		try {
			const uuid = await makeFlow(request, `${runId} allowlist`)
			const bundle = await (
				await request.post(`${API}/federated-config/bundle`, {
					headers: JSON_HEADERS,
					data: {
						type: 'openregister.flows',
						selection: { flowIds: [uuid] },
					},
				})
			).json()

			// Not on the allowlist → 403.
			const denied = await request.post(`${API}/federated-config/install`, {
				headers: JSON_HEADERS,
				data: { type: 'openregister.flows', bundle, source: 'evil/repo' },
			})
			expect(denied.status(), 'a non-allowlisted source is refused').toBe(403)

			// On the allowlist → succeeds.
			const ok = await request.post(`${API}/federated-config/install`, {
				headers: JSON_HEADERS,
				data: {
					type: 'openregister.flows',
					bundle,
					source: 'ConductionNL/pack',
				},
			})
			expect(ok.status()).toBe(200)
			const installed = (await ok.json()).installed ?? []
			installed.forEach((u: string) => created.push(u))
		} finally {
			occ('config:app:delete openregister federated_config_source_allowlist')
		}
	})
})
