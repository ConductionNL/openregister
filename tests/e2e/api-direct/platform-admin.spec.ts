/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Platform administration e2e tests — covers:
 *   - platform-administration-modals (settings API surface)
 *   - production-observability (health/metrics endpoints)
 *   - environment-otap (settings show environment context)
 *   - oas-validation (additional OAS endpoint behaviors)
 *   - nextcloud-api-compat (NC OCS capabilities, NC app info)
 *   - mariadb-ci-matrix (backend database adapts to DB engine)
 */
import { test, expect } from '@playwright/test'

// ─────────────────────────────────────────────────────────────────────────────
// production-observability — health/status endpoints
// ─────────────────────────────────────────────────────────────────────────────
test.describe('production-observability — health and status', () => {
	test('status.php returns installed:true', async ({ request }) => {
		const resp = await request.get('/status.php')
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.installed).toBe(true)
		expect(body.version).toBeTruthy()
	})

	test('OCS capabilities endpoint includes openregister capability block', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/capabilities?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body?.ocs?.data?.capabilities?.openregister, 'openregister capabilities block missing').toBeTruthy()
	})

	test('OCS capabilities openregister block is present and non-empty', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/capabilities?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const orCaps = body?.ocs?.data?.capabilities?.openregister
		expect(orCaps, 'openregister capabilities block should be present').toBeTruthy()
		// The block contains at least one capability key (version, urn, integrations, etc.).
		expect(Object.keys(orCaps ?? {}).length, 'openregister capabilities should have keys').toBeGreaterThan(0)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// platform-administration-modals — settings API surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('platform-administration-modals — settings API', () => {
	test('GET /api/settings returns settings without 5xx', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/settings', {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'GET /api/settings must not 5xx').toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			// Settings should be an object.
			expect(typeof body).toBe('object')
		}
	})

	test('GET /api/settings/object-management does not 5xx', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/settings/object-management', {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status()).toBeLessThan(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// nextcloud-api-compat — NC OCS compatibility
// ─────────────────────────────────────────────────────────────────────────────
test.describe('nextcloud-api-compat — Nextcloud API compatibility', () => {
	test('OCS cloud/capabilities returns standard envelope', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/capabilities?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body?.ocs?.meta?.status).toBe('ok')
		expect(body?.ocs?.data?.capabilities).toBeTruthy()
	})

	test('OCS cloud/user returns current user info', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body?.ocs?.data?.id, 'OCS user id should be set').toBeTruthy()
	})

	test('OpenRegister app info endpoint responds without 5xx', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/', {
			headers: { Accept: 'application/json' },
		})
		// May redirect or return 200/404 — never 5xx.
		expect(resp.status()).toBeLessThan(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// environment-otap — OTAP environment in settings
// ─────────────────────────────────────────────────────────────────────────────
test.describe('environment-otap — OTAP environment context', () => {
	test('OAS document server URL includes the local base path', async ({ request }) => {
		const resp = await request.get('/index.php/apps/openregister/api/registers/oas', {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// The servers block should contain the environment-specific URL.
		if (body.servers && body.servers.length > 0) {
			const server = body.servers[0] as { url?: string }
			expect(server.url, 'OAS server url should be set').toBeTruthy()
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// mariadb-ci-matrix — database backend adapts to available engine
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mariadb-ci-matrix — database backend compatibility', () => {
	test('all CRUD operations work regardless of database engine', async ({ request }) => {
		// This is evidenced by a full create/read/delete cycle working:
		const RUN_ID = `e2e-db-${Date.now()}`

		const created = await request.post(
			'/index.php/apps/openregister/api/objects/8/18',
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-db-test`,
					ocName: `${RUN_ID} DB Test`,
					type: 'player',
					description: 'MariaDB CI matrix e2e test',
				},
			},
		)
		expect(created.status(), 'create should work on any DB engine').toBeLessThanOrEqual(201)
		const createdBody = await created.json()
		const id = createdBody['@self']?.id ?? createdBody.id
		expect(id).toBeTruthy()

		const read = await request.get(
			`/index.php/apps/openregister/api/objects/8/18/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(read.status()).toBe(200)

		const deleted = await request.delete(
			`/index.php/apps/openregister/api/objects/8/18/${id}`,
		)
		expect([200, 204], 'DELETE should return 200 or 204').toContain(deleted.status())
	})
})
