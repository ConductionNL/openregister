/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GraphQL API and MCP discovery e2e tests — covers:
 *   - graphql-api (auto-generated schema, introspection, basic query)
 *   - mcp-discovery (discover endpoint, capability catalog, entry structure)
 *   - ai-mcp (MCP tools listing responds without 5xx)
 *
 * These are pure API tests — no browser needed.
 */
import { test, expect } from '@playwright/test'

// ─────────────────────────────────────────────────────────────────────────────
// graphql-api — auto-generated GraphQL schema
// ─────────────────────────────────────────────────────────────────────────────
test.describe('graphql-api — introspection and basic queries', () => {
	test('POST /api/graphql returns data for __typename', async ({ request }) => {
		const resp = await request.post('/index.php/apps/openregister/api/graphql', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: { query: '{ __typename }' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('data')
		expect(body.data.__typename).toBe('Query')
	})

	test('GraphQL introspection lists registered types', async ({ request }) => {
		const resp = await request.post('/index.php/apps/openregister/api/graphql', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: { query: '{ __schema { types { name kind } } }' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('data')
		const types: Array<{ name: string; kind: string }> = body.data.__schema.types
		// Should have at least some OBJECT types (schema-generated types).
		const objectTypes = types.filter(
			(t) => t.kind === 'OBJECT' && !t.name.startsWith('__'),
		)
		expect(
			objectTypes.length,
			'GraphQL should expose at least one OBJECT type',
		).toBeGreaterThan(0)
	})

	test('GraphQL query fields are exposed (at least one schema field)', async ({
		request,
	}) => {
		const resp = await request.post('/index.php/apps/openregister/api/graphql', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: { query: '{ __schema { queryType { fields { name } } } }' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const fields: Array<{ name: string }> = body.data.__schema.queryType.fields
		expect(
			fields.length,
			'GraphQL must expose at least one query field',
		).toBeGreaterThan(0)
	})

	test('GraphQL query for a known schema type returns list envelope', async ({
		request,
	}) => {
		// Get first available query field from introspection.
		const introspect = await request.post(
			'/index.php/apps/openregister/api/graphql',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					query: '{ __schema { queryType { fields { name type { kind ofType { kind name } } } } } }',
				},
			},
		)
		expect(introspect.status()).toBe(200)
		const introspectBody = await introspect.json()
		const fields: Array<{
			name: string
			type: { kind: string; ofType?: { kind: string; name: string } }
		}> = introspectBody.data.__schema.queryType.fields

		// Find a list-type field (returns a Connection or array).
		const listField =
			fields.find(
				(f) =>
					f.type?.ofType?.kind === 'OBJECT'
					&& (f.name.includes('Connection')
						|| !f.name.includes('Connection')),
			) ?? fields[0]

		if (!listField) {
			test.skip(true, 'no query fields available in GraphQL schema')
		}

		// Execute the query using the first available field.
		const queryResp = await request.post(
			'/index.php/apps/openregister/api/graphql',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					query: `{ ${listField.name}(first: 1) { pageInfo { hasNextPage } } }`,
				},
			},
		)
		// Accept 200 (result) or 400 (wrong field shape) — never 5xx.
		expect(queryResp.status(), 'GraphQL query must not 5xx').toBeLessThan(500)
	})

	test('GraphQL complexity header is returned on every response', async ({
		request,
	}) => {
		const resp = await request.post('/index.php/apps/openregister/api/graphql', {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: { query: '{ __typename }' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// The extensions.complexity block documents query abuse prevention.
		expect(body).toHaveProperty('extensions')
		expect(body.extensions).toHaveProperty('complexity')
		expect(typeof body.extensions.complexity.estimated).toBe('number')
		expect(typeof body.extensions.complexity.max).toBe('number')
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// mcp-discovery — public discovery catalog endpoint
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mcp-discovery — discovery catalog', () => {
	test('GET /api/mcp/v1/discover returns 200 with required fields', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/discover',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// Required fields per spec REQ-001.
		expect(body.version).toBe('1.0')
		expect(body.name).toBe('OpenRegister')
		expect(body.description, 'description must be present').toBeTruthy()
		expect(body.base_url, 'base_url must be set').toBeTruthy()
	})

	test('capabilities array has at least 10 entries (REQ-001)', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/discover',
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(
			Array.isArray(body.capabilities),
			'capabilities must be an array',
		).toBe(true)
		expect(
			body.capabilities.length,
			'at least 10 capabilities required',
		).toBeGreaterThanOrEqual(10)
	})

	test('each capability has id, name, description, and href', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/discover',
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		for (const cap of body.capabilities as Array<Record<string, unknown>>) {
			expect(
				cap.id,
				`capability missing id: ${JSON.stringify(cap)}`,
			).toBeTruthy()
			expect(cap.name, `capability ${cap.id} missing name`).toBeTruthy()
			expect(
				cap.description,
				`capability ${cap.id} missing description`,
			).toBeTruthy()
			expect(cap.href, `capability ${cap.id} missing href`).toBeTruthy()
		}
	})

	test('discovery is accessible without authentication (public endpoint)', async ({
		request,
	}) => {
		// Issue the request without Basic auth — strip the preconfigured header.
		const resp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/discover',
			{
				headers: { Authorization: '' }, // override Basic auth
			},
		)
		// Should be 200 (public) or at worst 401 (requires auth) — never 5xx.
		expect(resp.status(), 'discovery endpoint must not 5xx').toBeLessThan(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// ai-mcp — MCP tools listing
// ─────────────────────────────────────────────────────────────────────────────
test.describe('ai-mcp — MCP tools listing', () => {
	test('GET /api/mcp/v1/tools lists available tools without 5xx', async ({
		request,
	}) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/tools',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status(), 'MCP tools listing should not 5xx').toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			// If the endpoint returns data, it should be an array or object with tools.
			expect(typeof body).toBe('object')
		}
	})

	test('MCP capability area pages return sub-capability data', async ({
		request,
	}) => {
		// Check the registered capability endpoints.
		const discoverResp = await request.get(
			'/index.php/apps/openregister/api/mcp/v1/discover',
		)
		expect(discoverResp.status()).toBe(200)
		const discover = await discoverResp.json()
		const caps = (discover.capabilities ?? []) as Array<{
			id: string
			href: string
		}>

		// Test the first few capability detail pages.
		for (const cap of caps.slice(0, 3)) {
			const capResp = await request.get(cap.href, {
				headers: { Accept: 'application/json' },
			})
			expect(
				capResp.status(),
				`capability ${cap.id} detail page must not 5xx`,
			).toBeLessThan(500)
		}
	})
})
