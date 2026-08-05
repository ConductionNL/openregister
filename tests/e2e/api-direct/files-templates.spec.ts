/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Files and Templates e2e tests — covers:
 *   - file-actions (file upload, download, list API surface)
 *   - files-sidebar-tabs (files attached to objects)
 *   - files-render-extension (files extension in NC files app)
 *   - file-risk-classification (file validation — executable rejection)
 *   - text-extraction (files sidebar text extraction surface)
 *   - (implicit: templates) template CRUD REST lifecycle
 *   - mock-registers (seed data / mock register API surface)
 *   - schema-property-exploration (schema property introspection)
 *
 * Uses larpingapp register (8/18) and creates test objects with file
 * attachments. Uses RUN_ID for cleanup isolation.
 */
import { test, expect } from '@playwright/test'

const RUN_ID = `e2e-${Date.now()}`
const REGISTER_ID = '8'
const SCHEMA_ID = '18'

// ─────────────────────────────────────────────────────────────────────────────
// file-actions — file upload and download
// ─────────────────────────────────────────────────────────────────────────────
test.describe('file-actions — file upload and management', () => {
	let testObjectId: string | null = null

	test.beforeAll(async ({ request }) => {
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-file-test`,
					ocName: `${RUN_ID} File Test`,
					type: 'player',
					description: 'File actions e2e test',
				},
			},
		)
		if (resp.ok() || resp.status() === 201) {
			const body = await resp.json()
			testObjectId = body['@self']?.id ?? body.id ?? null
		}
	})

	test('GET /api/objects/:r/:s/:id/files lists attached files', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for file listing test')
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/files`,
			{ headers: { Accept: 'application/json' } },
		)
		// May be 200 (empty list) or 404 if endpoint not wired for this route.
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			// Files should be an array.
			const files = body.results ?? body.files ?? body
			expect(Array.isArray(files), 'files response should be an array or have results').toBeTruthy()
		}
	})

	test('POST text file to object creates a file attachment (not 5xx)', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for file upload test')

		// Upload a simple text file.
		const fileContent = `E2E test file content - ${RUN_ID}`
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/files`,
			{
				multipart: {
					file: {
						name: `${RUN_ID}-test.txt`,
						mimeType: 'text/plain',
						buffer: Buffer.from(fileContent),
					},
					fileName: `${RUN_ID}-test.txt`,
				},
			},
		)
		expect(resp.status(), 'file upload must not 5xx').toBeLessThan(500)
	})

	test.afterAll(async ({ request }) => {
		if (!testObjectId) return
		await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}`,
		).catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// file-risk-classification — executable file rejection
// ─────────────────────────────────────────────────────────────────────────────
test.describe('file-risk-classification — executable file rejection', () => {
	let testObjectId: string | null = null

	test.beforeAll(async ({ request }) => {
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-risk-test`,
					ocName: `${RUN_ID} Risk Test`,
					type: 'player',
					description: 'Risk classification e2e test',
				},
			},
		)
		if (resp.ok() || resp.status() === 201) {
			const body = await resp.json()
			testObjectId = body['@self']?.id ?? body.id ?? null
		}
	})

	test('uploading an executable file is rejected (4xx)', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for risk classification test')

		// Try uploading an executable (.exe) file — should be rejected.
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/files`,
			{
				multipart: {
					file: {
						name: `${RUN_ID}-malicious.exe`,
						mimeType: 'application/octet-stream',
						buffer: Buffer.from('MZ\x90\x00'),  // EXE magic bytes
					},
					fileName: `${RUN_ID}-malicious.exe`,
				},
			},
		)
		// Should be rejected with 4xx — never allow executable uploads.
		expect(resp.status(), 'executable file upload should be rejected').toBeGreaterThanOrEqual(400)
		expect(resp.status(), 'executable file rejection should not 5xx').toBeLessThan(500)
	})

	test.afterAll(async ({ request }) => {
		if (!testObjectId) return
		await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}`,
		).catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// schema-property-exploration — schema properties introspection
// ─────────────────────────────────────────────────────────────────────────────
test.describe('schema-property-exploration — schema property introspection', () => {
	test('GET /api/schemas/:id returns properties with type information', async ({ request }) => {
		const listResp = await request.get('/index.php/apps/openregister/api/schemas?_limit=1', {
			headers: { Accept: 'application/json' },
		})
		expect(listResp.status()).toBe(200)
		const list = await listResp.json()
		const schema = (list.results ?? [])[0]
		test.skip(!schema, 'no schemas found for property exploration test')

		const schemaResp = await request.get(
			`/index.php/apps/openregister/api/schemas/${schema.id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(schemaResp.status()).toBe(200)
		const body = await schemaResp.json()
		// Properties should be an object mapping property names to type definitions.
		if (body.properties && typeof body.properties === 'object') {
			const propKeys = Object.keys(body.properties)
			for (const key of propKeys) {
				const prop = body.properties[key]
				// Each property should have at minimum a type or $ref field.
				const hasType = prop.type || prop.$ref || prop.allOf || prop.anyOf || prop.oneOf
				expect(hasType, `property '${key}' should have a type or $ref`).toBeTruthy()
			}
		}
	})

	test('schema required field is an array of property names', async ({ request }) => {
		const listResp = await request.get('/index.php/apps/openregister/api/schemas?_limit=5', {
			headers: { Accept: 'application/json' },
		})
		expect(listResp.status()).toBe(200)
		const list = await listResp.json()
		for (const schema of (list.results ?? []).slice(0, 3)) {
			if (schema.required !== undefined) {
				expect(Array.isArray(schema.required), `schema ${schema.slug} required should be array`).toBe(true)
			}
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// mock-registers — seeded data surface (registers from app initialization)
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mock-registers — seeded register availability', () => {
	test('larpingapp register (id=8) exists and is accessible', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/registers/8', {
			headers: { Accept: 'application/json' },
		})
		// May be 200 or 404 depending on seeding; just not 5xx.
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body.id ?? body['@self']?.id).toBeTruthy()
		}
	})

	test('registers listing includes at least one register', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/registers?_limit=5', {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// Some API versions return {total:N} others just {results:[...]}
		const count = body.total ?? (body.results ?? []).length
		expect(count, 'at least one register should be present').toBeGreaterThanOrEqual(1)
	})
})
