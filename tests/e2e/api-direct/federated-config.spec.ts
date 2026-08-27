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
// Defaults to the shared dev container — see resolveContainer(). Running one
// named `occ` command there is how this box is meant to be exercised; NC_CONTAINER
// points it elsewhere. Only `docker restart` still needs an explicit opt-in.
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

// The install assertion hands its uuid to the run assertion that follows, so the
// order is a contract, not an accident.
test.describe.configure({ mode: 'serial' })

test.describe('Federated configuration sharing', () => {
	const created: string[] = []

	/** The uuid `install` handed back, for the run assertion that follows it. */
	let installedForRun = ''

	test.beforeAll(async ({ request }) => {
		// The `flows` register is still a precondition — 'a register bundles into
		// a portable OpenAPI document' bundles it BY SLUG — but nothing here needs
		// its numeric ids any more, now that makeFlow() authors through
		// /api/flows. Asserted rather than dropped: on an instance where the
		// descriptor never landed this file used to die here with
		// `registers slug=flows`, and that message, arriving up front, is the
		// most useful thing this block can do. See
		// `occ openregister:descriptors:list`.
		await idBySlug(request, 'registers', 'flows')
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			// Deleted from /api/flows — the store makeFlow() writes to. Deleting
			// through the objects API would silently leave every fixture behind.
			await request
				.delete(`/apps/openregister/api/flows/${id}`)
				.catch(() => {})
		}
	})

	async function makeFlow(
		request: APIRequestContext,
		name: string,
	): Promise<string> {
		// 🔴 AUTHORED THROUGH /api/flows, NOT AS A REGISTER OBJECT.
		//
		// OpenRegister has TWO stores for "a flow": the `openregister_flows`
		// table behind /api/flows, and objects in the `flows` register.
		// `FlowShareableConfigType` bundles via `FlowMapper`, so it reads only the
		// first — a flow authored in the register bundles to `flows: []`, HTTP 200,
		// with nothing to say why. This fixture used to author into the register
		// and the test read as a broken bundler.
		//
		// Verified with a control: bundling a table-backed flow returns 1, bundling
		// a register-authored one returns 0, same request otherwise.
		//
		// The gap itself is a real defect and is tracked separately — the register
		// is the store `flow_register.json` documents as the place a flow may live.
		// This test is about BUNDLING, so it uses the store bundling supports.
		const resp = await request.post('/apps/openregister/api/flows', {
			headers: JSON_HEADERS,
			data: {
				name,
				description: 'Created by the federated-config e2e suite.',
				enabled: true,
				trigger: 'manual',
				nodes: [
					{
						id: 'a',
						type: 'openregister.set-fields',
						config: { set: { shared: true } },
					},
					{ id: 'b', type: 'openregister.end', config: {} },
				],
				edges: [{ id: 's1', from: 'a', to: 'b' }],
			},
		})
		expect(resp.status(), await resp.text()).toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.id
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

	test('a flow bundles into a portable shape and installs as a fresh flow', async ({
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

		// 🔴 Whether that flow can actually RUN is asserted separately below, and
		// it currently cannot — see the next test. Splitting it keeps these
		// assertions live: folding both into one expected-to-fail test would stop
		// this bundle-and-install coverage from ever failing again.
		installedForRun = newUuid
	})

	// 🔴 EXPECTED TO FAIL — product defect, not a fixture problem.
	//
	// `federated-config/install` reports HTTP 200 with `{"installed":[uuid]}` for
	// a flow that exists NOWHERE: not in `/api/flows`, not in the `flows`
	// register, and `/api/flows/{uuid}` answers 404. The caller is handed a
	// receipt for goods that were never delivered, and only finds out when
	// something tries to run the flow.
	//
	// `test.fail()` rather than a skip on purpose: a skip would go quiet and stay
	// quiet, while this reports RED THE DAY THE DEFECT IS FIXED and the
	// expectation needs removing. Tracked as ConductionNL/openregister#2905.
	test('🔴 #2905: the installed flow can be run', async ({ request }) => {
		// Scoped to THIS test. A bare `test.fail()` in the describe body would
		// mark every test declared after it too, quietly excusing the allowlist
		// test below from ever having to pass.
		test.fail()
		test.skip(
			installedForRun === '',
			'the install step did not run, so there is no flow to try',
		)

		const runResp = await request.post(`${API}/flow-runs/test`, {
			headers: JSON_HEADERS,
			data: { flowId: installedForRun },
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
