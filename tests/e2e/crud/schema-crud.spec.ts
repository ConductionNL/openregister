/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep, data-dependent CRUD journey for SCHEMAS.
 *
 * Proves the create→read→update→delete cycle for schemas WITH PERSISTENCE
 * and with the schema's property set round-tripping correctly:
 *
 *   1. CREATE a schema with TWO properties (title:string, count:integer).
 *   2. READ — it lists as a real ROW in the schemas table (title rendered),
 *      and GET /api/schemas/{id} returns both properties.
 *   3. UPDATE — add a THIRD property and assert the persisted schema now has
 *      three properties, and the row still renders.
 *   4. DELETE — remove it; GET returns ≥400 and the row is gone from the list.
 *
 * The schema create/edit form in the UI is the CnAdvancedFormDialog
 * (properties-table + JSON tab) which is not reliably driveable headlessly
 * for arbitrary property types; per the project's testing policy (deep data
 * assertions, API for setup) the mutations go through the documented Schemas
 * REST controller and every step asserts the REAL persisted data and the
 * rendered table row — this is exactly the persistence guarantee the shell
 * tests do not cover.
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
import { makeRunId, twoPropertySchema } from '../_fixtures.ts'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
// HASH form — the router runs in hash mode (src/main.js); the path-form URL
// renders the dashboard instead of the schemas page.
const SCHEMAS_ROUTE = '/index.php/apps/openregister/#/schemas'
const API = '/index.php/apps/openregister/api'

const RUN_ID = makeRunId()
const SCHEMA_TITLE = `E2E Schema ${RUN_ID}`
const SCHEMA_SLUG = `${RUN_ID}-crud-schema`

test.describe.configure({ mode: 'serial' })

test.describe('schema-crud — create→read→update→delete with property persistence', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemaId: number | null = null

	test('CREATE a schema with two properties (persisted)', async ({ request }) => {
		const resp = await request.post(`${API}/schemas`, {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: {
				slug: SCHEMA_SLUG,
				title: SCHEMA_TITLE,
				description: 'Created by schema-crud e2e',
				properties: twoPropertySchema(),
			},
		})
		expect(resp.status(), 'POST /api/schemas').toBeLessThanOrEqual(201)
		const body = await resp.json()
		schemaId = body.id ?? null
		expect(schemaId, 'created schema must have an id').toBeTruthy()
		// Both properties must round-trip.
		expect(body.properties).toHaveProperty('title')
		expect(body.properties).toHaveProperty('count')
	})

	test('READ — schema lists as a real row and exposes both properties', async ({
		page,
		request,
	}) => {
		test.skip(schemaId === null, 'no schema created')

		// API persistence: GET returns the two properties.
		const get = await request.get(`${API}/schemas/${schemaId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(get.status()).toBe(200)
		const body = await get.json()
		expect(Object.keys(body.properties ?? {})).toEqual(
			expect.arrayContaining(['title', 'count']),
		)

		// UI: the schema title renders as a row in the schemas table.
		await page.goto(SCHEMAS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText(SCHEMA_TITLE, { exact: false }).first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('UPDATE — add a third property and assert it persisted + re-renders', async ({
		page,
		request,
	}) => {
		test.skip(schemaId === null, 'no schema to update')

		const threeProps = {
			...twoPropertySchema(),
			tag: {
				type: 'string',
				title: 'Tag',
				description: 'Added by update step',
			},
		}
		const put = await request.put(`${API}/schemas/${schemaId}`, {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: { slug: SCHEMA_SLUG, title: SCHEMA_TITLE, properties: threeProps },
		})
		expect(put.status(), 'PUT /api/schemas/{id}').toBe(200)
		const updated = await put.json()
		// The new property must be persisted.
		expect(updated.properties).toHaveProperty('tag')
		expect(Object.keys(updated.properties).length).toBeGreaterThanOrEqual(3)

		// Confirm via a fresh GET (true persistence, not just echo).
		const reget = await request.get(`${API}/schemas/${schemaId}`, {
			headers: { Accept: 'application/json' },
		})
		expect((await reget.json()).properties).toHaveProperty('tag')

		// UI still renders the schema row.
		await page.goto(SCHEMAS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText(SCHEMA_TITLE, { exact: false }).first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('DELETE — remove the schema and assert it is gone', async ({
		page,
		request,
	}) => {
		test.skip(schemaId === null, 'no schema to delete')

		const del = await request.delete(`${API}/schemas/${schemaId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(del.status(), 'DELETE /api/schemas/{id}').toBe(200)

		const gone = await request.get(`${API}/schemas/${schemaId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(
			gone.status(),
			'deleted schema should not return 200',
		).toBeGreaterThanOrEqual(400)

		await page.goto(SCHEMAS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText(SCHEMA_TITLE, { exact: false })).toHaveCount(0, {
			timeout: 20_000,
		})

		schemaId = null
	})

	test.afterAll(async ({ request }) => {
		if (schemaId === null) {
			const resp = await request
				.get(`${API}/schemas?_limit=200`, {
					headers: { Accept: 'application/json' },
				})
				.catch(() => null)
			if (resp && resp.ok()) {
				const s = (await resp.json()).results?.find(
					(x: any) => x.slug === SCHEMA_SLUG,
				)
				schemaId = s?.id ?? null
			}
		}
		if (schemaId !== null) {
			await request.delete(`${API}/schemas/${schemaId}`).catch(() => {})
		}
	})
})
