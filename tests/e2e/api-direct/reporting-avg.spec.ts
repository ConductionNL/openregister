/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reporting, AVG, and specialized specs e2e tests — covers:
 *   - rapportage-bi-export (reports/BI export API surface)
 *   - avg-verwerkingsregister (AVG register API surface)
 *   - verwerkingsregister-api (AVG/processing register API)
 *   - realtime-updates (SSE endpoint surface)
 *   - vector-embeddings (vector search endpoint surface)
 *   - aggregations-backend-native (aggregations API surface)
 *   - schema-driven-read-coercion (coerced type values in responses)
 *   - datetime-input-handling (datetime fields in objects)
 *
 * These are mostly API surface tests verifying HTTP contracts.
 */
import { test, expect } from '@playwright/test'

const RUN_ID = `e2e-${Date.now()}`

// ─────────────────────────────────────────────────────────────────────────────
// rapportage-bi-export — reports API surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('rapportage-bi-export — reports API', () => {
	test('GET /api/reports lists reports (no 5xx)', async ({ request }) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/reports?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body).toHaveProperty('results')
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// avg-verwerkingsregister / verwerkingsregister-api — AVG register
// ─────────────────────────────────────────────────────────────────────────────
test.describe('avg-verwerkingsregister — AVG processing register API', () => {
	test('GET /api/avg lists avg entries (no 5xx)', async ({ request }) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/avg?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBeLessThan(500)
	})

	test('AVG register is accessible as a standard register', async ({
		request,
	}) => {
		// The AVG verwerkingsregister is backed by a standard OR register.
		// Verify the general register list works for compliance registers.
		const resp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=10',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(
			Array.isArray(body.results),
			'registers list should be an array',
		).toBe(true)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// realtime-updates — SSE endpoint surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('realtime-updates — SSE / event stream endpoint', () => {
	test('GraphQL subscription endpoint responds (not 5xx)', async ({ request }) => {
		// SSE subscriptions in OR go through the GraphQL subscription endpoint.
		// Just verify the endpoint is reachable and doesn't 5xx.
		const resp = await request.post('/index.php/apps/openregister/api/graphql', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: {
				query: `
					subscription {
						objectCreated {
							id
						}
					}
				`,
			},
		})
		// Subscription support returns 200 (established) or 400 (not supported yet) — never 5xx.
		expect(resp.status()).toBeLessThan(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// aggregations-backend-native — aggregation queries
// ─────────────────────────────────────────────────────────────────────────────
test.describe('aggregations-backend-native — aggregation API', () => {
	test('listing endpoint with _facets parameter does not 5xx', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/objects/8/18?_limit=5&_facets[]=type',
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body).toHaveProperty('results')
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// schema-driven-read-coercion — type coercion in object responses
// ─────────────────────────────────────────────────────────────────────────────
test.describe('schema-driven-read-coercion — type coercion in responses', () => {
	test('integer schema properties return numeric values (not strings)', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/objects/8/18?_limit=3',
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const objects = (body.results ?? []) as Array<Record<string, unknown>>
		for (const obj of objects) {
			// 'gold', 'silver', 'copper' are integer fields in larpingapp schema.
			if (obj.gold !== undefined) {
				expect(typeof obj.gold, 'gold should be numeric').toBe('number')
			}
			if (obj.silver !== undefined) {
				expect(typeof obj.silver, 'silver should be numeric').toBe('number')
			}
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// datetime-input-handling — datetime fields in objects
// ─────────────────────────────────────────────────────────────────────────────
test.describe('datetime-input-handling — datetime fields', () => {
	test('created and updated timestamps in @self are ISO8601 strings', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/objects/8/18?_limit=3',
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const objects = (body.results ?? []) as Array<Record<string, unknown>>
		for (const obj of objects) {
			const self = (obj['@self'] ?? {}) as Record<string, unknown>
			if (self.created) {
				// Should be an ISO 8601 datetime string.
				const ts = String(self.created)
				expect(ts).toMatch(/^\d{4}-\d{2}-\d{2}T/)
			}
			if (self.updated) {
				const ts = String(self.updated)
				expect(ts).toMatch(/^\d{4}-\d{2}-\d{2}T/)
			}
		}
	})

	test('creating object with ISO datetime in a string field works', async ({
		request,
	}) => {
		const now = new Date().toISOString()
		const resp = await request.post(
			'/index.php/apps/openregister/api/objects/8/18',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					name: `${RUN_ID}-datetime-test`,
					ocName: `${RUN_ID} DateTime Test`,
					type: 'player',
					description: `Created at ${now}`,
				},
			},
		)
		expect(resp.status()).toBeLessThanOrEqual(201)
		const createdBody = await resp.json()
		const id = createdBody['@self']?.id ?? createdBody.id
		if (id) {
			await request
				.delete(`/index.php/apps/openregister/api/objects/8/18/${id}`)
				.catch(() => {})
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// vector-embeddings — vector search endpoint surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('vector-embeddings — vector search API surface', () => {
	test('listing with _search does not 5xx (vector search falls back to SQL)', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/objects/8/18?_search=elf&_limit=3',
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(Array.isArray(body.results)).toBe(true)
		}
	})
})
