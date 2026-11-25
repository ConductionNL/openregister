import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A RECORD IS FETCHED BY THE KEY THE OUTSIDE WORLD KNOWS IT BY.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e objects-crud::a-functional-administrator-declares-a-composite-key
 * @e2e objects-crud::a-report-constraint-cannot-be-the-identity
 * @e2e objects-crud::an-outside-system-fetches-a-case-by-its-number
 * @e2e objects-crud::a-caller-who-may-not-read-the-case-gets-not-found
 * @e2e objects-crud::a-caseworker-cannot-renumber-a-case-by-editing-it
 *
 * A schema declares a `refuse` unique constraint over `gemeentecode` and
 * `zaaknummer` as its identity; a case is then fetched at
 * `GET .../by-key?gemeentecode=...&zaaknummer=...` and must answer exactly as
 * `objects#show` does for the same case, with `@self.key` added.
 *
 * HERMETIC. It creates its own register, schema and case and removes them.
 * The reader without access is the seeded `e2e-other`. Admitted to the CI
 * floor in tests/e2e/ci/playwright.config.ts.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded non-admin account, given no read on the schema. */
const OUTSIDER = 'e2e-other'
const OUTSIDER_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

const PROPERTIES = {
	gemeentecode: { type: 'string', title: 'Gemeentecode', maxLength: 10 },
	zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 50 },
	omschrijving: { type: 'string', title: 'Omschrijving', maxLength: 255 },
}

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** An object body with the fields that move on every read taken out. */
function stable(body: Record<string, any>): Record<string, any> {
	const self = { ...(body['@self'] ?? {}) }
	for (const volatile of ['key', 'unread', 'watching', 'watcherCount', 'favourite', 'access']) {
		delete self[volatile]
	}

	return { ...body, '@self': self }
}

test.describe.configure({ mode: 'serial' })

test.describe('a case fetched by its composite key', () => {
	let admin: APIRequestContext
	let outsider: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let caseUuid = ''
	const refusedSchemas: string[] = []

	const byKey = (gemeentecode: string, zaaknummer: string): string =>
		`${API}/objects/${registerId}/${schemaId}/by-key?gemeentecode=${encodeURIComponent(gemeentecode)}&zaaknummer=${encodeURIComponent(zaaknummer)}`

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		outsider = await contextFor(OUTSIDER, OUTSIDER_PASS)

		const seeded = await outsider.get(`${API}/registers`)
		expect(seeded.status(), `seeded account '${OUTSIDER}' cannot authenticate`).toBeLessThan(400)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e by key register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)
	})

	test.afterAll(async () => {
		if (caseUuid !== '') {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${caseUuid}`)
			await admin.delete(`${API}/deleted/${caseUuid}?force=true`)
		}
		for (const id of [schemaId, ...refusedSchemas]) {
			if (id !== '') {
				await admin.delete(`${API}/schemas/${id}`)
			}
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await outsider?.dispose()
	})

	test('a report constraint is refused as the identity', async () => {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e by key refused ${RUN}`,
				description: 'e2e',
				properties: PROPERTIES,
				configuration: {
					uniqueConstraints: [
						{ name: 'rapportsleutel', properties: ['zaaknummer'], action: 'report', identity: true },
					],
				},
			},
		})
		if (res.ok()) {
			refusedSchemas.push(String((await res.json()).id))
		}
		expect(res.status(), await res.text()).toBe(400)
		expect(await res.text()).toContain('rapportsleutel')
	})

	test('an administrator declares the composite key and reads it back', async () => {
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e by key zaak ${RUN}`,
				description: 'e2e',
				properties: PROPERTIES,
				authorization: { read: ['admin'], create: ['admin'], update: ['admin'], delete: ['admin'] },
				configuration: {
					uniqueConstraints: [
						{
							name: 'zaaksleutel',
							properties: ['gemeentecode', 'zaaknummer'],
							action: 'refuse',
							identity: true,
						},
					],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e by key register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		const read = await admin.get(`${API}/schemas/${schemaId}`)
		expect(read.ok()).toBeTruthy()
		expect((await read.json()).configuration.uniqueConstraints).toEqual([
			expect.objectContaining({ name: 'zaaksleutel', action: 'refuse', identity: true }),
		])
	})

	test('the case answers at its key as it does at its uuid', async () => {
		const created = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { gemeentecode: '0363', zaaknummer: 'Z-2026-0042', omschrijving: 'Bouwvergunning' },
		})
		expect(created.ok(), `object create failed: ${await created.text()}`).toBeTruthy()
		caseUuid = String((await created.json())['@self'].id)

		const keyed = await admin.get(byKey('0363', 'Z-2026-0042'))
		expect(keyed.status(), await keyed.text()).toBe(200)
		const body = await keyed.json()
		expect(body['@self'].id).toBe(caseUuid)
		expect(body['@self'].key).toBe('0363:Z-2026-0042')

		const shown = await admin.get(`${API}/objects/${registerId}/${schemaId}/${caseUuid}`)
		expect(shown.status()).toBe(200)
		expect(stable(body), 'the same body objects#show returns').toEqual(stable(await shown.json()))

		const missing = await admin.get(byKey('0363', 'Z-0000-0000'))
		expect(missing.status()).toBe(404)
	})

	test('a reader without access gets not found, as for a key nobody holds', async () => {
		const res = await outsider.get(byKey('0363', 'Z-2026-0042'))
		expect(res.status()).toBe(404)
		expect(await res.text()).not.toContain('Bouwvergunning')
	})

	test('the case cannot be renumbered by an edit', async () => {
		const patched = await admin.patch(`${API}/objects/${registerId}/${schemaId}/${caseUuid}`, {
			data: { zaaknummer: 'Z-2026-0043' },
		})
		expect(patched.status(), await patched.text()).toBe(422)
		expect(await patched.text()).toContain('zaaknummer')

		const after = await admin.get(`${API}/objects/${registerId}/${schemaId}/${caseUuid}`)
		expect((await after.json()).zaaknummer).toBe('Z-2026-0042')
	})
})
