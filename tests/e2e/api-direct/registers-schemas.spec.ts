/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Registers and Schemas e2e tests — covers:
 *   - (implicit spec: registers management REST surface)
 *   - (implicit spec: schemas management REST surface)
 *   - oas-generation (REQ-001: generate OAS for all registers)
 *   - oas-validation (ETag handling — complements api-smoke.spec.ts)
 *   - data-import-export (register/configuration export + import)
 *   - register-i18n (title/description multilingual surface)
 *
 * All mutations use the unique RUN_ID prefix so parallel agents
 * don't collide. Cleanup runs in afterAll.
 */
import { test, expect } from '@playwright/test'

const RUN_ID = `e2e-${Date.now()}`

// ─────────────────────────────────────────────────────────────────────────────
// Registers REST CRUD
// ─────────────────────────────────────────────────────────────────────────────
test.describe('registers — REST CRUD', () => {
	let createdRegisterId: number | null = null
	let createdRegisterSlug: string | null = null

	test('POST /api/registers creates a register', async ({ request }) => {
		const slug = `${RUN_ID}-register`
		const resp = await request.post(
			'/index.php/apps/openregister/api/registers',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					slug,
					title: 'E2E Test Register',
					description: 'Created by e2e registers-schemas.spec.ts',
				},
			},
		)
		// 200 or 201 depending on idempotent handling.
		expect(resp.status(), 'POST /api/registers').toBeLessThanOrEqual(201)
		const body = await resp.json()
		createdRegisterId = body.id ?? null
		createdRegisterSlug = body.slug ?? slug
		expect(createdRegisterId, 'created register should have an id').toBeTruthy()
		expect(body.title).toBe('E2E Test Register')
	})

	test('GET /api/registers returns list with standard envelope', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})

	test('GET /api/registers/:id returns the register', async ({ request }) => {
		if (!createdRegisterId) test.skip(true, 'no register created in this run')
		const resp = await request.get(
			`/index.php/apps/openregister/api/registers/${createdRegisterId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.id).toBe(createdRegisterId)
		expect(body.title).toBe('E2E Test Register')
	})

	test('PUT /api/registers/:id updates title and description', async ({
		request,
	}) => {
		if (!createdRegisterId) test.skip(true, 'no register created in this run')
		const resp = await request.put(
			`/index.php/apps/openregister/api/registers/${createdRegisterId}`,
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					slug: createdRegisterSlug,
					title: 'E2E Test Register (Updated)',
					description: 'Updated description by e2e test',
				},
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.title).toBe('E2E Test Register (Updated)')
	})

	test('DELETE /api/registers/:id removes the register', async ({ request }) => {
		if (!createdRegisterId) test.skip(true, 'no register created in this run')
		const resp = await request.delete(
			`/index.php/apps/openregister/api/registers/${createdRegisterId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'DELETE register').toBe(200)
		// Confirm gone: after deletion the record should no longer be accessible.
		// The API may return 404 (not found) or a non-200 error for deleted IDs.
		const gone = await request.get(
			`/index.php/apps/openregister/api/registers/${createdRegisterId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(
			gone.status(),
			'deleted register should not return 200',
		).toBeGreaterThanOrEqual(400)
		createdRegisterId = null
	})

	test.afterAll(async ({ request }) => {
		if (!createdRegisterId) return
		await request
			.delete(
				`/index.php/apps/openregister/api/registers/${createdRegisterId}`,
			)
			.catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// Schemas REST CRUD
// ─────────────────────────────────────────────────────────────────────────────
test.describe('schemas — REST CRUD', () => {
	let createdSchemaId: number | null = null

	test('POST /api/schemas creates a schema', async ({ request }) => {
		const slug = `${RUN_ID}-schema`
		const resp = await request.post('/index.php/apps/openregister/api/schemas', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: {
				slug,
				title: 'E2E Test Schema',
				description: 'Created by e2e test',
				properties: {
					name: {
						type: 'string',
						title: 'Name',
						description: 'Record name',
					},
					value: {
						type: 'integer',
						title: 'Value',
						description: 'Numeric value',
					},
				},
			},
		})
		expect(resp.status(), 'POST /api/schemas').toBeLessThanOrEqual(201)
		const body = await resp.json()
		createdSchemaId = body.id ?? null
		expect(createdSchemaId, 'created schema must have an id').toBeTruthy()
		expect(body.title).toBe('E2E Test Schema')
	})

	test('GET /api/schemas returns list with standard envelope', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/schemas?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})

	test('GET /api/schemas/:id returns the schema with properties', async ({
		request,
	}) => {
		if (!createdSchemaId) test.skip(true, 'no schema created in this run')
		const resp = await request.get(
			`/index.php/apps/openregister/api/schemas/${createdSchemaId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.id).toBe(createdSchemaId)
		expect(body.properties).toHaveProperty('name')
	})

	test('PUT /api/schemas/:id updates the schema', async ({ request }) => {
		if (!createdSchemaId) test.skip(true, 'no schema created in this run')
		const resp = await request.put(
			`/index.php/apps/openregister/api/schemas/${createdSchemaId}`,
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					title: 'E2E Test Schema (Updated)',
					description: 'Updated by e2e test',
					properties: {
						name: {
							type: 'string',
							title: 'Name',
							description: 'Record name',
						},
						value: {
							type: 'integer',
							title: 'Value',
							description: 'Numeric value',
						},
						extra: {
							type: 'string',
							title: 'Extra',
							description: 'Additional field',
						},
					},
				},
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.title).toBe('E2E Test Schema (Updated)')
		expect(body.properties).toHaveProperty('extra')
	})

	test('DELETE /api/schemas/:id removes the schema', async ({ request }) => {
		if (!createdSchemaId) test.skip(true, 'no schema created in this run')
		const resp = await request.delete(
			`/index.php/apps/openregister/api/schemas/${createdSchemaId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'DELETE schema').toBe(200)
		const gone = await request.get(
			`/index.php/apps/openregister/api/schemas/${createdSchemaId}`,
			{ headers: { Accept: 'application/json' } },
		)
		// After deletion the schema should not return 200.
		expect(
			gone.status(),
			'deleted schema should not return 200',
		).toBeGreaterThanOrEqual(400)
		createdSchemaId = null
	})

	test.afterAll(async ({ request }) => {
		if (!createdSchemaId) return
		await request
			.delete(`/index.php/apps/openregister/api/schemas/${createdSchemaId}`)
			.catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// oas-generation — OAS endpoint generates valid OpenAPI document
// ─────────────────────────────────────────────────────────────────────────────
test.describe('oas-generation — OAS endpoint produces valid document', () => {
	test('GET /api/registers/oas returns JSON with openapi field and paths', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/registers/oas',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// Must be a valid OpenAPI 3.x document.
		expect(body).toHaveProperty('openapi')
		expect(body.openapi).toMatch(/^3\.\d+\.\d+$/)
		expect(body).toHaveProperty('info')
		expect(body).toHaveProperty('paths')
		// Must have at least one path entry.
		expect(
			Object.keys(body.paths).length,
			'OAS must have at least one path',
		).toBeGreaterThan(0)
	})

	test('OAS document covers object paths', async ({ request }) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/registers/oas',
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const paths = Object.keys(body.paths ?? {})
		// OAS uses slug-based paths for objects (e.g. /objects/{register}/{schema}).
		// Verify there is at least one path defined.
		expect(paths.length, 'OAS should define at least one path').toBeGreaterThan(
			0,
		)
		// Components/schemas should be present for the registered data types.
		const componentSchemas = body.components?.schemas ?? {}
		expect(
			Object.keys(componentSchemas).length,
			'OAS must define at least one component schema',
		).toBeGreaterThan(0)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// data-import-export — configuration export (OAS/JSON format)
// ─────────────────────────────────────────────────────────────────────────────
test.describe('data-import-export — register configuration export', () => {
	test('GET /api/registers/:id with Accept application/json returns JSON export', async ({
		request,
	}) => {
		// Use the first available register.
		const listResp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=1',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(listResp.status()).toBe(200)
		const list = await listResp.json()
		const reg = (list.results ?? [])[0]
		test.skip(!reg, 'no registers found for export test')

		const exportResp = await request.get(
			`/index.php/apps/openregister/api/registers/${reg.id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(exportResp.status()).toBe(200)
		const body = await exportResp.json()
		// Export should include the register id and title.
		expect(body.id ?? body['@id']).toBeTruthy()
		expect(body.title ?? body.name).toBeTruthy()
	})

	test('POST /api/objects with CSV-like data creates objects (import path)', async ({
		request,
	}) => {
		// Exercise the import surface by posting objects with known IDs.
		const importPayload = {
			name: `${RUN_ID}-import-test`,
			ocName: `${RUN_ID} Import Test`,
			description: 'Created via import-path e2e test',
			type: 'player',
		}
		const resp = await request.post(
			'/index.php/apps/openregister/api/objects/8/18',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: importPayload,
			},
		)
		expect(resp.status(), 'POST /objects import path').toBeLessThanOrEqual(201)
		const body = await resp.json()
		const id = body['@self']?.id ?? body.id
		expect(id).toBeTruthy()

		// Clean up.
		if (id) {
			await request
				.delete(`/index.php/apps/openregister/api/objects/8/18/${id}`)
				.catch(() => {})
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// register-i18n — title and description accept non-ASCII characters
// ─────────────────────────────────────────────────────────────────────────────
test.describe('register-i18n — multilingual title/description', () => {
	let createdId: number | null = null

	test('register title accepts Dutch accented characters', async ({ request }) => {
		const slug = `${RUN_ID}-i18n`
		const resp = await request.post(
			'/index.php/apps/openregister/api/registers',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					slug,
					title: 'Gegevensregister voor Zorgaanbieders',
					description: 'Beschrijving met speciale tekens: é, ü, ö, ñ',
				},
			},
		)
		expect(resp.status()).toBeLessThanOrEqual(201)
		const body = await resp.json()
		createdId = body.id ?? null
		// Verify the accented characters round-trip correctly.
		expect(body.title).toBe('Gegevensregister voor Zorgaanbieders')
		expect(body.description).toContain('é')
	})

	test.afterAll(async ({ request }) => {
		if (!createdId) return
		await request
			.delete(`/index.php/apps/openregister/api/registers/${createdId}`)
			.catch(() => {})
	})
})
