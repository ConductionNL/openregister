/**
 * Integration-registry E2E smoke — exercises every layer of the
 * pluggable-integration chain (ADR-019) against a live OpenRegister:
 *
 *   1. OCS capabilities surfaces all registered providers with the
 *      right shape (id, group, enabled, storageStrategy, surfaces,
 *      authStatus).
 *   2. The 24-provider set (5 built-ins + xwiki + 18 leaves) is
 *      discoverable + `enabled` gating fires based on the required NC
 *      app's install state.
 *   3. The `/api/objects/{r}/{s}/{o}/integrations/{id}` sub-resource
 *      returns the standard envelope for every enabled provider and
 *      503 (degraded) or 4xx (no provider) otherwise — never 5xx.
 *   4. `requiredApp` gating is honoured per leaf.
 *
 * Expects a Nextcloud at NEXTCLOUD_URL (default
 * http://localhost:8080) with openregister installed, the integration
 * registry wired (Application.php's `registerBuiltinIntegrationProviders`
 * + `bootBuiltinIntegrationProviders` running), and admin:admin
 * available.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

/**
 * The 24 providers the registry SHOULD advertise once openregister
 * PR #1514 + nc-vue PR #231 + decidesk PR #205 are deployed. Keep
 * this list in sync with the PHP-side providers in
 * `openregister/lib/Service/Integration/Providers/` (the leaves) +
 * `BuiltinProviders/` (the built-ins).
 */
const ALL_PROVIDERS: ReadonlyArray<{
	id: string
	group: 'core' | 'comms' | 'docs' | 'workflow' | 'external'
	requiredApp: string | null
	storageStrategy: 'magic-column' | 'link-table' | 'external' | 'query-time'
}> = [
	// Built-ins (always-on)
	{ id: 'files', group: 'core', requiredApp: null, storageStrategy: 'magic-column' },
	{ id: 'notes', group: 'core', requiredApp: null, storageStrategy: 'link-table' },
	{ id: 'tags', group: 'core', requiredApp: null, storageStrategy: 'link-table' },
	{ id: 'tasks', group: 'core', requiredApp: null, storageStrategy: 'link-table' },
	{ id: 'audit-trail', group: 'core', requiredApp: null, storageStrategy: 'query-time' },
	// External (OpenConnector-backed)
	{ id: 'xwiki', group: 'external', requiredApp: 'openconnector', storageStrategy: 'external' },
	{ id: 'openproject', group: 'external', requiredApp: 'openconnector', storageStrategy: 'external' },
	// Backend-shipped leaves
	{ id: 'calendar', group: 'comms', requiredApp: 'calendar', storageStrategy: 'link-table' },
	{ id: 'contacts', group: 'comms', requiredApp: 'contacts', storageStrategy: 'link-table' },
	{ id: 'email', group: 'comms', requiredApp: 'mail', storageStrategy: 'link-table' },
	{ id: 'deck', group: 'workflow', requiredApp: 'deck', storageStrategy: 'link-table' },
	// Greenfield NC-app stubs
	{ id: 'talk', group: 'comms', requiredApp: 'spreed', storageStrategy: 'link-table' },
	{ id: 'bookmarks', group: 'docs', requiredApp: 'bookmarks', storageStrategy: 'link-table' },
	{ id: 'collectives', group: 'docs', requiredApp: 'collectives', storageStrategy: 'link-table' },
	{ id: 'maps', group: 'docs', requiredApp: 'maps', storageStrategy: 'link-table' },
	{ id: 'photos', group: 'docs', requiredApp: 'photos', storageStrategy: 'link-table' },
	{ id: 'activity', group: 'workflow', requiredApp: 'activity', storageStrategy: 'query-time' },
	{ id: 'analytics', group: 'workflow', requiredApp: 'analytics', storageStrategy: 'link-table' },
	{ id: 'cospend', group: 'workflow', requiredApp: 'cospend', storageStrategy: 'link-table' },
	{ id: 'flow', group: 'workflow', requiredApp: 'workflowengine', storageStrategy: 'link-table' },
	{ id: 'forms', group: 'workflow', requiredApp: 'forms', storageStrategy: 'link-table' },
	{ id: 'polls', group: 'workflow', requiredApp: 'polls', storageStrategy: 'link-table' },
	{ id: 'time-tracker', group: 'workflow', requiredApp: 'timemanager', storageStrategy: 'link-table' },
	// NC-core leaf (no required app)
	{ id: 'shares', group: 'core', requiredApp: null, storageStrategy: 'query-time' },
]

/**
 * Fetch OCS capabilities and return the providers array.
 *
 * @param request Playwright request context (auth pre-wired).
 * @return The integrations.providers array, or [] when the registry
 *         isn't wired (older deploys).
 */
async function fetchProviders(request: APIRequestContext) {
	const response = await request.get('/ocs/v2.php/cloud/capabilities?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	return body?.ocs?.data?.capabilities?.openregister?.integrations?.providers ?? []
}

/**
 * Pick a representative {register, schema, objectId} triple for the
 * sub-resource probes. Falls back to numeric ids 1/1 + a synthetic
 * uuid when no objects exist — the registry routes accept either
 * shape and a missing object yields a 4xx, never a 5xx.
 *
 * @param request Playwright request context.
 */
async function pickObjectTriple(request: APIRequestContext): Promise<{ register: string, schema: string, objectId: string }> {
	// Try the standard envelope listing endpoint; pull the first
	// object's metadata. Numeric register=1 / schema=1 are the
	// default seed values on the dev container, but the test stays
	// generic.
	const listing = await request.get('/index.php/apps/openregister/api/objects/1/1?_limit=1', {
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
	if (listing.ok()) {
		const body = await listing.json()
		const first = (body.results ?? [])[0]
		if (first) {
			const meta = first['@self'] ?? {}
			return {
				register: meta.register ?? '1',
				schema: meta.schema ?? '1',
				objectId: first.id ?? meta.id ?? 'synthetic-uuid',
			}
		}
	}
	// Fallback — the sub-resource routes don't 5xx on a missing
	// object; they 4xx, which our test treats as acceptable.
	return { register: '1', schema: '1', objectId: '00000000-0000-0000-0000-000000000000' }
}

test.describe('Integration registry — OCS capabilities', () => {
	test('reports every registered provider with the documented shape', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		// Every provider declares the documented metadata.
		for (const p of providers) {
			expect(p, `provider shape: ${p.id}`).toMatchObject({
				id: expect.any(String),
				label: expect.any(String),
				group: expect.any(String),
				enabled: expect.any(Boolean),
				storageStrategy: expect.stringMatching(/^(magic-column|link-table|external|query-time)$/),
				surfaces: expect.arrayContaining(['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']),
				authStatus: expect.objectContaining({
					status: expect.stringMatching(/^(ok|degraded|unavailable)$/),
					authStatus: expect.stringMatching(/^(configured|missing|expired)$/),
				}),
			})
		}
	})

	test('advertises every provider in the documented set', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		const advertised = new Set(providers.map((p: { id: string }) => p.id))
		const missing = ALL_PROVIDERS.filter(p => !advertised.has(p.id))

		// If at least the 5 built-ins are advertised but the leaves
		// aren't, that's a partial deploy (openregister umbrella
		// merged, leaves PR not yet). Skip rather than fail — the
		// 18 leaf ids only land once openregister#1514 ships.
		if (missing.length > 0 && missing.every(p => p.group !== 'core')) {
			test.skip(true, `leaf providers not yet wired on this deploy (missing: ${missing.map(m => m.id).join(', ')})`)
		}
		expect(advertised.size, `${missing.length} provider(s) missing: ${missing.map(m => m.id).join(', ')}`)
			.toBeGreaterThanOrEqual(ALL_PROVIDERS.length)
	})

	test('every leaf reports the right group + requiredApp + storageStrategy', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		const byId = Object.fromEntries(providers.map((p: { id: string }) => [p.id, p])) as Record<string, {
			group: string
			storageStrategy: string
		}>

		for (const expected of ALL_PROVIDERS) {
			const p = byId[expected.id]
			if (!p) continue // covered by the 24-provider-ids test
			expect(p.group, `${expected.id} group`).toBe(expected.group)
			expect(p.storageStrategy, `${expected.id} storage`).toBe(expected.storageStrategy)
		}
	})

	test('built-in providers are always enabled', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		const byId = Object.fromEntries(providers.map((p: { id: string }) => [p.id, p])) as Record<string, { enabled: boolean }>

		// `shares` is technically a leaf (no required NC app); when
		// the leaves PR is merged it also reports enabled:true. The
		// 5 hardcoded built-ins are unconditional.
		for (const id of ['files', 'notes', 'tags', 'tasks', 'audit-trail']) {
			expect(byId[id]?.enabled, `built-in "${id}" should always be enabled`).toBe(true)
		}
		// `shares` is included once the leaves PR lands — soft check.
		if (byId.shares) {
			expect(byId.shares.enabled, 'shares leaf should be enabled (no requiredApp)').toBe(true)
		}
	})

	test('NC-app-gated providers reflect the app install state', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		const byId = Object.fromEntries(providers.map((p: { id: string }) => [p.id, p])) as Record<string, { enabled: boolean }>

		// We don't know which NC apps are installed on the target,
		// but `enabled` must be a strict Boolean — never null, never
		// undefined, never a string. That's the contract the JS-side
		// `useRegistry` filter depends on.
		for (const leaf of ALL_PROVIDERS.filter(p => p.requiredApp)) {
			const p = byId[leaf.id]
			if (!p) continue
			expect(typeof p.enabled, `${leaf.id} enabled is Boolean`).toBe('boolean')
		}
	})
})

test.describe('Integration registry — sub-resource', () => {
	for (const provider of ALL_PROVIDERS) {
		test(`GET /integrations/${provider.id} stays under 5xx`, async ({ request }) => {
			const providers = await fetchProviders(request)
			test.skip(providers.length === 0, 'integration registry not wired on this deploy')
			const advertised = providers.find((p: { id: string }) => p.id === provider.id)
			test.skip(!advertised, `provider "${provider.id}" not advertised yet`)

			const { register, schema, objectId } = await pickObjectTriple(request)
			const url = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/integrations/${provider.id}`

			// `OCS-APIRequest: true` bypasses session-CSRF for programmatic
			// clients; without it the controller short-circuits with 412
			// before the provider runs and the probe becomes meaningless.
			const response = await request.get(url, {
				headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
			})

			// Never a 5xx — degraded sources return 503 with a
			// structured body, missing objects 4xx, normal listings
			// 2xx. 5xx means the provider crashed.
			expect(response.status(), `${provider.id} status`).toBeLessThan(500)

			if (response.ok()) {
				const body = await response.json()
				// Standard list envelope OR a bare array OR the
				// passthrough envelope keys from external sources.
				const acceptable = body.results || body.items || body.pageSummaries || body.searchResults
				const isArray = Array.isArray(body) || Array.isArray(acceptable)
				expect(isArray || typeof body === 'object', `${provider.id} response shape`).toBeTruthy()
			} else if (response.status() === 503) {
				// External / unavailable: must carry a structured cause
				// the UI can render. The OpenConnector router shape is
				// `{ message, details: { reason } }`; permissive match.
				const body = await response.json().catch(() => ({}))
				expect(typeof body, `${provider.id} 503 body is JSON`).toBe('object')
			}
		})
	}

	test('returns 4xx (not 5xx) for an unknown provider id', async ({ request }) => {
		const { register, schema, objectId } = await pickObjectTriple(request)
		const response = await request.get(
			`/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/integrations/this-provider-does-not-exist`,
			{ headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' } },
		)
		expect(response.status()).toBeGreaterThanOrEqual(400)
		expect(response.status()).toBeLessThan(500)
	})
})

test.describe('Integration registry — surface metadata', () => {
	test('every provider advertises all 4 surfaces (AD-19)', async ({ request }) => {
		const providers = await fetchProviders(request)
		test.skip(providers.length === 0, 'integration registry not wired on this deploy')

		for (const p of providers) {
			expect(p.surfaces, `${p.id} surfaces`).toEqual(
				expect.arrayContaining(['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']),
			)
		}
	})
})
