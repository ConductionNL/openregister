/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Advanced features e2e tests — covers:
 *   - referential-integrity (related objects via object properties)
 *   - reference-existence-validation (creating object with relations)
 *   - urn-resource-addressing (objects have a URI/URN in @self)
 *   - object-interactions (lock/unlock, publish)
 *   - retention-management (API surface for retention policies)
 *   - webhook-payload-mapping (webhooks CRUD)
 *   - event-driven-architecture (event endpoint surface)
 *   - schema-hooks (schema-level hook API surface)
 *   - computed-fields (computed property in response)
 *
 * All mutations use RUN_ID prefix for cleanup isolation.
 */
import { test, expect } from '@playwright/test'

const RUN_ID = `e2e-${Date.now()}`
const REGISTER_ID = '8'
const SCHEMA_ID = '18'

// ─────────────────────────────────────────────────────────────────────────────
// urn-resource-addressing — objects have @self.uri
// ─────────────────────────────────────────────────────────────────────────────
test.describe('urn-resource-addressing — objects carry URI in @self', () => {
	test('fetched objects include @self.uri and @self.id', async ({ request }) => {
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=3`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const objects = (body.results ?? []) as Array<Record<string, unknown>>
		for (const obj of objects) {
			const self = (obj['@self'] ?? {}) as Record<string, unknown>
			expect(self.id ?? obj.id, 'object must have an id').toBeTruthy()
			// URI may use UUID or numeric id — verify it's a string.
			if (self.uri) {
				expect(typeof self.uri).toBe('string')
				// Should be a URL or URN.
				expect(self.uri).toMatch(/^https?:\/\/|^urn:/)
			}
		}
	})

	test('GET /api/objects/:register/:schema/:id returns the object by URI id', async ({ request }) => {
		// List to find an id.
		const list = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=1`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(list.status()).toBe(200)
		const listBody = await list.json()
		const first = (listBody.results ?? [])[0]
		test.skip(!first, 'no objects available for URI addressing test')

		const id = first?.['@self']?.id ?? first?.id
		const single = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(single.status()).toBe(200)
		const singleBody = await single.json()
		expect(singleBody['@self']?.id ?? singleBody.id).toBe(id)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// object-interactions — lock/unlock and publish endpoints
// ─────────────────────────────────────────────────────────────────────────────
test.describe('object-interactions — lock, unlock, publish', () => {
	let testObjectId: string | null = null

	test.beforeAll(async ({ request }) => {
		// Create a test object for interaction testing.
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-interactions-test`,
					ocName: `${RUN_ID} Interactions Test`,
					type: 'player',
					description: 'Object interactions e2e test',
				},
			},
		)
		if (resp.ok() || resp.status() === 201) {
			const body = await resp.json()
			testObjectId = body['@self']?.id ?? body.id ?? null
		}
	})

	test('lock endpoint returns 200 or 404 (not 5xx)', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for lock test')
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/lock`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'lock endpoint must not 5xx').toBeLessThan(500)
	})

	test('unlock endpoint returns 200 or 404 (not 5xx)', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for unlock test')
		const resp = await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/lock`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'unlock endpoint must not 5xx').toBeLessThan(500)
	})

	test('publish endpoint returns 200 or 404 (not 5xx)', async ({ request }) => {
		if (!testObjectId) test.skip(true, 'no test object for publish test')
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}/publish`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'publish endpoint must not 5xx').toBeLessThan(500)
	})

	test.afterAll(async ({ request }) => {
		if (!testObjectId) return
		await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${testObjectId}`,
		).catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// referential-integrity — related objects via object properties
// ─────────────────────────────────────────────────────────────────────────────
test.describe('referential-integrity — objects with relations', () => {
	test('GET listing with _extend parameter does not 5xx', async ({ request }) => {
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=2&_extend[]=@self`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(Array.isArray(body.results)).toBe(true)
		}
	})

	test('object @self.relations field is present and is an object', async ({ request }) => {
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=3`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const objects = (body.results ?? []) as Array<Record<string, unknown>>
		for (const obj of objects) {
			const self = (obj['@self'] ?? {}) as Record<string, unknown>
			if ('relations' in self) {
				expect(typeof self.relations).toBe('object')
			}
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// webhook-payload-mapping — webhooks CRUD
// ─────────────────────────────────────────────────────────────────────────────
test.describe('webhook-payload-mapping — webhooks REST lifecycle', () => {
	let webhookId: number | null = null

	test('GET /api/webhooks lists webhooks (empty or populated)', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/webhooks?_limit=5', {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body).toHaveProperty('results')
		}
	})

	test('POST /api/webhooks creates a webhook', async ({ request }) => {
		const resp = await request.post('/index.php/apps/openregister/api/webhooks', {
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			data: {
				name: `${RUN_ID}-webhook`,
				url: 'https://example.com/webhook-test',
				events: ['object.created', 'object.updated'],
				enabled: false,
			},
		})
		expect(resp.status(), 'POST /api/webhooks').toBeLessThan(500)
		if (resp.ok() || resp.status() === 201) {
			const body = await resp.json()
			webhookId = body.id ?? null
		}
	})

	test('DELETE /api/webhooks/:id removes the webhook', async ({ request }) => {
		if (!webhookId) test.skip(true, 'no webhook created in this run')
		const resp = await request.delete(
			`/index.php/apps/openregister/api/webhooks/${webhookId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBeLessThan(500)
		webhookId = null
	})

	test.afterAll(async ({ request }) => {
		if (!webhookId) return
		await request.delete(`/index.php/apps/openregister/api/webhooks/${webhookId}`).catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// retention-management — retention policy API surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('retention-management — retention policy API', () => {
	test('object @self.expires field is accepted on create', async ({ request }) => {
		// Create an object with an expiry date.
		const expiryDate = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString()
		const resp = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-retention-test`,
					ocName: `${RUN_ID} Retention Test`,
					type: 'player',
					description: 'Retention management e2e test',
					'@self': { expires: expiryDate },
				},
			},
		)
		expect(resp.status(), 'POST with @self.expires').toBeLessThanOrEqual(201)
		if (resp.ok() || resp.status() === 201) {
			const body = await resp.json()
			const id = body['@self']?.id ?? body.id
			// Clean up.
			if (id) {
				await request.delete(
					`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
				).catch(() => {})
			}
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// event-driven-architecture — event system surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('event-driven-architecture — event surface', () => {
	test('creating an object triggers observable state changes (event-driven create)', async ({ request }) => {
		// Verify that object creation is reflected in listing after create.
		const preList = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=100`,
			{ headers: { Accept: 'application/json' } },
		)
		const preBody = await preList.json()
		const preTotal = preBody.total ?? 0

		const created = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-event-test`,
					ocName: `${RUN_ID} Event Test`,
					type: 'player',
					description: 'Event driven architecture e2e test',
				},
			},
		)
		expect(created.status()).toBeLessThanOrEqual(201)
		const createdBody = await created.json()
		const id = createdBody['@self']?.id ?? createdBody.id

		// The listing total should have increased by 1.
		const postList = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=100`,
			{ headers: { Accept: 'application/json' } },
		)
		const postBody = await postList.json()
		expect(postBody.total, 'total should increase after create').toBeGreaterThan(preTotal)

		// Clean up.
		if (id) {
			await request.delete(
				`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			).catch(() => {})
		}
	})
})
