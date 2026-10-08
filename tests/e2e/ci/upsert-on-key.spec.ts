/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Create or update a record by a key the schema declares unique, in one call
 * (change api-upsert-on-a-declared-key).
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * ObjectsControllerUpsertOnKeyTest drives the real controller and handler
 * against a stubbed store. What it cannot see is whether the declared key
 * survives a real schema save, whether `_upsertOn` reaches the controller
 * through the real router and middleware as a query parameter, whether the
 * lookup finds the record the first call wrote in the real magic table, and
 * whether an anonymous call is refused before anything is written.
 *
 * SELF-CLEANING. It creates its own register, schema and records and removes
 * them in `afterAll`.
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e-${Date.now()}`

const JSON_HEADERS = {
	Accept: 'application/json',
	'Content-Type': 'application/json',
}

const PROPERTIES = {
	gemeentecode: { type: 'string', title: 'Gemeentecode', maxLength: 10 },
	zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 50 },
	omschrijving: { type: 'string', title: 'Omschrijving', maxLength: 255 },
}

test.describe.configure({ mode: 'serial' })

test.describe('upsert-on-key', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId: number | null = null
	let schemaId: number | null = null
	const objectIds: string[] = []

	const objects = (): string => `${API}/objects/${registerId}/${schemaId}`
	const uuidOf = (body: Record<string, any>): string =>
		String(body['@self']?.id ?? body.id ?? body.uuid)

	test.beforeAll(async ({ request }) => {
		const register = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: {
				title: `upsert ${RUN_ID}`,
				description: 'upsert-on-key e2e fixture',
			},
		})
		expect(register.status(), await register.text()).toBeLessThan(300)
		registerId = (await register.json()).id

		// Two records share key Z-DUP before the key is declared, the way
		// legacy data does.
		const schema = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: { title: `upsert zaak ${RUN_ID}`, properties: PROPERTIES },
		})
		expect(schema.status(), await schema.text()).toBeLessThan(300)
		schemaId = (await schema.json()).id

		const attach = await request.put(`${API}/registers/${registerId}`, {
			headers: JSON_HEADERS,
			data: { schemas: [schemaId] },
		})
		expect(attach.status(), await attach.text()).toBeLessThan(300)

		for (const omschrijving of ['eerste', 'tweede']) {
			const resp = await request.post(objects(), {
				headers: JSON_HEADERS,
				data: { gemeentecode: '0363', zaaknummer: 'Z-DUP', omschrijving },
			})
			expect(resp.status(), await resp.text()).toBeLessThan(300)
			objectIds.push(uuidOf(await resp.json()))
		}

		const declare = await request.put(`${API}/schemas/${schemaId}`, {
			headers: JSON_HEADERS,
			data: {
				title: `upsert zaak ${RUN_ID}`,
				properties: PROPERTIES,
				configuration: {
					uniqueConstraints: [
						{
							name: 'zaaksleutel',
							properties: ['gemeentecode', 'zaaknummer'],
							action: 'refuse',
						},
					],
				},
			},
		})
		expect(declare.status(), await declare.text()).toBeLessThan(300)
		expect(
			(await declare.json()).configuration?.uniqueConstraints?.[0]?.name,
		).toBe('zaaksleutel')
	})

	test.afterAll(async ({ request }) => {
		for (const id of objectIds) {
			await request.delete(`${objects()}/${id}`)
		}
		if (schemaId !== null) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== null) {
			await request.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a signed-in integration creates then updates a case by its key', async ({
		request,
	}) => {
		const first = await request.post(`${objects()}?_upsertOn=zaaksleutel`, {
			headers: JSON_HEADERS,
			data: {
				gemeentecode: '0363',
				zaaknummer: 'Z-2026-0042',
				omschrijving: 'Kapvergunning',
			},
		})
		expect(first.status(), await first.text()).toBe(201)
		const uuid = uuidOf(await first.json())
		objectIds.push(uuid)

		const second = await request.post(`${objects()}?_upsertOn=zaaksleutel`, {
			headers: JSON_HEADERS,
			data: {
				gemeentecode: '0363',
				zaaknummer: 'Z-2026-0042',
				omschrijving: 'Kapvergunning Dorpsstraat',
			},
		})
		expect(second.status(), await second.text()).toBe(200)
		const updated = await second.json()
		expect(uuidOf(updated)).toBe(uuid)
		expect(updated.omschrijving).toBe('Kapvergunning Dorpsstraat')
	})

	test('an anonymous call cannot upsert', async ({ playwright, baseURL }) => {
		const anonymous = await playwright.request.newContext({
			baseURL,
			// Empty headers are what make it anonymous: a bare newContext()
			// inherits the suite's Authorization header from playwright.config.ts.
			extraHTTPHeaders: {},
		})
		const resp = await anonymous.post(`${objects()}?_upsertOn=zaaksleutel`, {
			headers: JSON_HEADERS,
			data: {
				gemeentecode: '0363',
				zaaknummer: 'Z-2026-0042',
				omschrijving: 'overschreven',
			},
		})
		expect(resp.status(), await resp.text()).toBe(401)
		await anonymous.dispose()
	})

	test('a key two records hold is refused with its matches and changes nothing', async ({
		request,
	}) => {
		const resp = await request.post(`${objects()}?_upsertOn=zaaksleutel`, {
			headers: JSON_HEADERS,
			data: {
				gemeentecode: '0363',
				zaaknummer: 'Z-DUP',
				omschrijving: 'derde',
			},
		})
		expect(resp.status(), await resp.text()).toBe(409)
		expect(((await resp.json()).matches ?? []).sort()).toEqual(
			[objectIds[0], objectIds[1]].sort(),
		)

		const untouched = await request.get(`${objects()}/${objectIds[0]}`)
		expect(untouched.status()).toBe(200)
		expect((await untouched.json()).omschrijving).toBe('eerste')
	})
})
