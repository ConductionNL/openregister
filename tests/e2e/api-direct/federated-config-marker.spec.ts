import type { APIRequestContext } from '@playwright/test'

/**
 * Federated configuration sharing — the schema-marker generic path, e2e via HTTP.
 *
 * Proves that an app makes its objects shareable with DATA, not code: a schema
 * carrying `configuration['x-openregister-shareable']` is auto-surfaced by
 * OpenRegister as a shareable type (no per-app IShareableConfigType class), and
 * that type bundles its objects into a portable, instance-independent shape and
 * installs them back as fresh objects.
 *
 * This is the leverage that collapses the per-app rollout — procest case types,
 * shillinq payroll packs, opencatalogi publication types — into a one-line
 * marker each.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const runId = `e2e-fedmarker-${Date.now()}`

test.describe('Federated config — schema marker (generic type)', () => {
	let regId: number
	let schId: number
	let regSlug: string
	let schSlug: string
	let typeId: string
	const objects: string[] = []

	test.beforeAll(async ({ request }) => {
		// A schema that opts in with the marker only — no PHP anywhere.
		const schResp = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `Marker Casetype ${runId}`,
				properties: { name: { type: 'string' }, steps: { type: 'array' } },
				configuration: { 'x-openregister-shareable': true },
			},
		})
		expect(schResp.status()).toBeLessThan(300)
		const sch = await schResp.json()
		schId = sch.id ?? sch['@self']?.id
		schSlug = sch.slug ?? sch['@self']?.slug

		const regResp = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: { title: `Marker Register ${runId}`, schemas: [schId] },
		})
		expect(regResp.status()).toBeLessThan(300)
		const reg = await regResp.json()
		regId = reg.id ?? reg['@self']?.id
		regSlug = reg.slug ?? reg['@self']?.slug

		typeId = `${regSlug}.${schSlug}`
	})

	test.afterAll(async ({ request }) => {
		for (const u of objects) {
			await request
				.delete(`${API}/objects/${regId}/${schId}/${u}`)
				.catch(() => {})
		}
		await request.delete(`${API}/registers/${regId}`).catch(() => {})
		await request.delete(`${API}/schemas/${schId}`).catch(() => {})
	})

	async function makeObject(
		request: APIRequestContext,
		name: string,
	): Promise<string> {
		const resp = await request.post(`${API}/objects/${regId}/${schId}`, {
			headers: JSON_HEADERS,
			data: { name, steps: ['intake', 'besluit'] },
		})
		const uuid = (await resp.json())?.['@self']?.id
		objects.push(uuid)
		return uuid
	}

	test('the marked schema is auto-surfaced as a shareable type', async ({
		request,
	}) => {
		const resp = await request.get(`${API}/federated-config/types`)
		expect(resp.status()).toBe(200)
		const types = (await resp.json()).types ?? []
		const mine = types.find((t: any) => t.id === typeId)
		expect(
			mine,
			`a marked schema becomes type ${typeId} with no per-app code`,
		).toBeTruthy()
		expect(mine.topic).toBe(`${regSlug}-${schSlug}`)
	})

	test('its objects bundle portably and install as fresh objects', async ({
		request,
	}) => {
		await makeObject(request, `${runId} bezwaar`)

		// Bundle — instance fields stripped, only portable data.
		const bundleResp = await request.post(`${API}/federated-config/bundle`, {
			headers: JSON_HEADERS,
			data: { type: typeId, selection: {} },
		})
		expect(bundleResp.status()).toBe(200)
		const bundle = await bundleResp.json()
		expect(bundle.type).toBe(typeId)
		expect(bundle.register).toBe(regSlug)
		expect(bundle.objects.length).toBe(1)
		expect(bundle.objects[0].name).toBe(`${runId} bezwaar`)
		expect(bundle.objects[0].steps).toEqual(['intake', 'besluit'])
		expect(
			bundle.objects[0].uuid,
			'no instance uuid in the bundle',
		).toBeUndefined()
		expect(bundle.objects[0].owner, 'no owner in the bundle').toBeUndefined()

		// Install — a fresh object with a new uuid, same portable data.
		const installResp = await request.post(`${API}/federated-config/install`, {
			headers: JSON_HEADERS,
			data: { type: typeId, bundle, source: 'ConductionNL/casetype-pack' },
		})
		expect(installResp.status()).toBe(200)
		const installed = (await installResp.json()).installed ?? []
		expect(installed.length).toBe(1)
		objects.push(installed[0])

		// The register now holds both the source and the freshly installed copy.
		const listResp = await request.get(
			`${API}/objects/${regId}/${schId}?_limit=100`,
		)
		const rows = (await listResp.json()).results ?? []
		const names = rows.map((o: any) => o.name)
		expect(rows.length).toBe(2)
		expect(names.every((n: string) => n === `${runId} bezwaar`)).toBe(true)
	})

	test('an unknown marker-derived type is a 404', async ({ request }) => {
		const resp = await request.post(`${API}/federated-config/bundle`, {
			headers: JSON_HEADERS,
			data: { type: 'never-registered.nope', selection: {} },
		})
		expect(resp.status()).toBe(404)
	})
})
