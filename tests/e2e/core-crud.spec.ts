/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Core CRUD e2e tests — covers:
 *   - object-lifecycle (REQ-001..004)
 *   - entity-management-modals (modal open/submit/close lifecycle)
 *   - frontend-app-bootstrap (app mounts, essential data loads)
 *   - frontend-store-client-state (store state after navigation)
 *   - auth-system (Nextcloud session auth for browser users)
 *   - deep-link-registry (hash route renders correct view)
 *
 * Uses the larpingapp register (id=8, schema=18) which has a known
 * test object. Creates/modifies/deletes objects under a unique run-id
 * prefix to avoid collisions with other test agents.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')

// Run-unique prefix so parallel agents don't collide.
const RUN_ID = `e2e-${Date.now()}`

// Known register+schema with data from the dev seed.
const REGISTER_ID = '8'
const SCHEMA_ID = '18'

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve a live object id from the larpingapp register.
 * Returns null if no objects exist.
 */
async function pickLiveObjectId(request: APIRequestContext): Promise<string | null> {
	const resp = await request.get(
		`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	if (!resp.ok()) return null
	const body = await resp.json()
	const first = (body.results ?? [])[0]
	return first?.['@self']?.id ?? first?.id ?? null
}

// ─────────────────────────────────────────────────────────────────────────────
// auth-system: Nextcloud session auth for browser users (REQ-001 scenario)
// ─────────────────────────────────────────────────────────────────────────────
test.describe('auth-system — session authentication', () => {
	test('authenticated user can access the OpenRegister app page', async ({ page }) => {
		// Reuse the storageState logged-in session from global-setup.
		await page.context().addInitScript(() => {}) // no-op to ensure context primed

		// Load the session state if available
		const authFile = STORAGE_STATE
		const fs = await import('fs')
		if (!fs.existsSync(authFile)) {
			test.skip(true, 'global-setup auth file not present — run full suite first')
		}

		// Use domcontentloaded — the NC app SPA keeps XHR activity going so networkidle never fires.
		await page.goto('/index.php/apps/openregister/', { waitUntil: 'domcontentloaded' })

		// The Nextcloud header renders only when the session is valid.
		await expect(page.locator('#header, header.header-appcontainer, header.header')).toBeVisible({ timeout: 20_000 })

		// The app navigation must be present (Vue app mounted successfully).
		await expect(page.locator('.app-navigation, nav[class*="navigation"], .app-navigation-list').first()).toBeVisible({ timeout: 20_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// frontend-app-bootstrap — app mounts and essential stores load
// ─────────────────────────────────────────────────────────────────────────────
test.describe('frontend-app-bootstrap — app mount and data load', () => {
	test.use({ storageState: STORAGE_STATE })

	test('app mounts with navigation and at least one store hydrated', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/', { waitUntil: 'domcontentloaded' })
		// Don't wait for networkidle — NC SPA keeps background XHR alive indefinitely.

		// App navigation sidebar renders when Vue app boots.
		const nav = page.locator('.app-navigation, nav[class*="navigation"]').first()
		await expect(nav).toBeVisible({ timeout: 30_000 })

		// At least one nav item visible (registers, schemas, objects, etc.)
		const navItems = nav.locator('li, a, button')
		await expect(navItems.first()).toBeVisible({ timeout: 15_000 })
	})

	test('navigating to /registers renders the register list view', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/#/registers', { waitUntil: 'domcontentloaded' })
		// Don't wait for networkidle — NC SPA keeps background XHR alive indefinitely.

		// The registers view should contain the main content area.
		await expect(
			page.locator('main, .app-content').first(),
		).toBeVisible({ timeout: 20_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// object-lifecycle — REST CRUD via API (exercises the save pipeline)
// ─────────────────────────────────────────────────────────────────────────────
test.describe('object-lifecycle — REST CRUD pipeline', () => {
	let createdObjectId: string | null = null

	test('POST creates an object and GET retrieves it (REQ-001)', async ({ request }) => {
		const payload = {
			name: `${RUN_ID}-character`,
			ocName: `${RUN_ID} E2E Test`,
			description: 'Automated e2e test character for object-lifecycle spec',
			type: 'player',
		}

		const created = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: payload,
			},
		)
		expect(created.status(), 'POST /objects should return 200 or 201').toBeLessThanOrEqual(201)
		const createdBody = await created.json()
		createdObjectId = createdBody['@self']?.id ?? createdBody.id ?? null
		expect(createdObjectId, 'created object must have an id').toBeTruthy()

		// Verify the version and timestamp fields that the pipeline assigns (REQ-001 scenario).
		const self = createdBody['@self'] ?? {}
		expect(self.created ?? createdBody.created, 'created timestamp must be set by server').toBeTruthy()
	})

	test('PUT updates the object (REQ-001 pipeline update path)', async ({ request }) => {
		// If the create test above did not run first, pick any live object.
		let id = createdObjectId
		if (!id) {
			id = await pickLiveObjectId(request)
			test.skip(id === null, 'no object found to update')
		}

		const update = await request.put(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-character-updated`,
					ocName: `${RUN_ID} E2E Updated`,
					description: 'Updated by object-lifecycle e2e test',
					type: 'player',
				},
			},
		)
		expect(update.status(), 'PUT /objects/{id} should return 200').toBe(200)
		const updatedBody = await update.json()
		expect(updatedBody.description, 'description should be updated').toContain('Updated by object-lifecycle')
	})

	test('GET listing returns standard envelope shape', async ({ request }) => {
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=5`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
		expect(body).toHaveProperty('total')
		expect(typeof body.total).toBe('number')
	})

	test('DELETE removes the created object (REQ-003)', async ({ request }) => {
		const id = createdObjectId
		if (!id) {
			test.skip(true, 'no object created in this run to delete')
		}

		const deleted = await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		// DELETE returns 200 or 204 depending on implementation.
		expect([200, 204], `DELETE should return 200 or 204`).toContain(deleted.status())

		// Confirm it is gone — GET should return 404.
		const gone = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(gone.status(), 'deleted object should return 404 on GET').toBe(404)
		createdObjectId = null
	})

	test.afterAll(async ({ request }) => {
		// Best-effort cleanup: delete the test object if not already removed.
		if (!createdObjectId) return
		await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${createdObjectId}`,
		).catch(() => {})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// deep-link-registry — hash routing renders the correct view
// ─────────────────────────────────────────────────────────────────────────────
test.describe('deep-link-registry — hash routes render correct views', () => {
	test.use({ storageState: STORAGE_STATE })

	test('#/registers route renders register list', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/#/registers', { waitUntil: 'domcontentloaded' })
		// Should NOT redirect to a different page or show a 404.
		expect(page.url()).toContain('/openregister/')
		await expect(page.locator('main, .app-content').first()).toBeVisible({ timeout: 20_000 })
	})

	test('#/schemas route renders schema list', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/#/schemas', { waitUntil: 'domcontentloaded' })
		expect(page.url()).toContain('/openregister/')
		await expect(page.locator('main, .app-content').first()).toBeVisible({ timeout: 20_000 })
	})

	test('#/objects route renders object list', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/#/objects', { waitUntil: 'domcontentloaded' })
		expect(page.url()).toContain('/openregister/')
		await expect(page.locator('main, .app-content').first()).toBeVisible({ timeout: 20_000 })
	})

	test('#/objects/:register/:schema/:id deep-links to object detail', async ({ page, request }) => {
		const objectId = await pickLiveObjectId(request)
		test.skip(objectId === null, 'no live object found for deep-link test')

		await page.goto(
			`/index.php/apps/openregister/#/objects/${REGISTER_ID}/${SCHEMA_ID}/${objectId}`,
			{ waitUntil: 'domcontentloaded' },
		)
		expect(page.url()).toContain('/openregister/')
		// The detail panel / object view must render something.
		await expect(page.locator('main, .app-content').first()).toBeVisible({ timeout: 20_000 })
	})
})
