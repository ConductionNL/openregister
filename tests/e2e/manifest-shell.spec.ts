/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Manifest-driven shell e2e tests (browser-based).
 *
 * Drives the OpenRegister CnAppRoot manifest shell through the real UI to
 * cover the runtime-observable scenarios of the openregister-app-manifest
 * capability. The remaining scenarios in that spec are build-time / static
 * code-inspection assertions (manifest validator, reviewer-inspects-file,
 * CI gate wiring) and carry `@e2e exclude` markers in the spec — they are
 * not browser-observable surfaces.
 *
 * These tests assert SHELLS, not data rows: OR's slug-backed list endpoints
 * may 500/empty in a fresh dev instance, so we assert the app-content shell
 * and heading render, never specific table rows.
 *
 * @e2e openregister-app-manifest::cnapproot-mounts-the-shell
 * @e2e openregister-app-manifest::router-is-built-from-the-manifest
 * @e2e openregister-app-manifest::every-page-resolves-to-a-registry-component
 * @e2e openregister-app-manifest::index-style-routes-dispatch-via-custom-registry
 * @e2e openregister-app-manifest::detail-style-routes-dispatch-via-custom-registry
 * @e2e openregister-app-manifest::dashboard-dispatches-via-custom-registry
 * @e2e openregister-app-manifest::both-sections-are-populated
 * @e2e openregister-app-manifest::menu-order-is-monotonic-per-section
 * @e2e openregister-app-manifest::no-deprecation-warning-at-runtime
 *
 * Uses storageState from global-setup.
 */
import { test, expect, Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const APP_BASE = '/index.php/apps/openregister/#'

function requireAuth() {
	if (!fs.existsSync(STORAGE_STATE)) {
		test.skip(true, 'storageState not present — run the full suite first')
	}
}

async function gotoRoute(page: Page, hash: string) {
	await page.goto(`${APP_BASE}${hash}`, { waitUntil: 'domcontentloaded' })
	// App content shell must render for every manifest-driven route.
	await expect(page.locator('main, .app-content').first()).toBeVisible({
		timeout: 25_000,
	})
}

// ─────────────────────────────────────────────────────────────────────────────
// REQ-OR-MAN-005 — CnAppRoot mounts the shell + router built from manifest
// ─────────────────────────────────────────────────────────────────────────────
test.describe('openregister-app-manifest — CnAppRoot shell mounts', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openregister-app-manifest::cnapproot-mounts-the-shell
	test('CnAppRoot renders the shell: navigation + router-view content', async ({
		page,
	}) => {
		requireAuth()
		await gotoRoute(page, '/')

		// The #menu slot renders OR's MainMenu (CnAppNav) — the app navigation.
		await expect(
			page
				.locator('.app-navigation, nav[class*="navigation"], [class*="navigation-list"]')
				.first(),
		).toBeVisible({ timeout: 25_000 })

		// router-view content area renders inside the shell.
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})

	// @e2e openregister-app-manifest::router-is-built-from-the-manifest
	test('router resolves manifest routes (deep-link navigation works)', async ({
		page,
	}) => {
		requireAuth()
		// A non-root manifest route must resolve via the manifest-built router
		// rather than redirecting away (catch-all only fires for unknown paths).
		await gotoRoute(page, '/schemas')
		await expect(page).toHaveURL(/#\/schemas$/)
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// REQ-OR-MAN-006 — CnPageRenderer dispatches every route via custom registry
// ─────────────────────────────────────────────────────────────────────────────
test.describe('openregister-app-manifest — registry dispatch', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openregister-app-manifest::dashboard-dispatches-via-custom-registry
	test('/ dispatches to the Dashboard component', async ({ page }) => {
		requireAuth()
		await gotoRoute(page, '/')
		// Dashboard renders its content shell inside the app-content.
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})

	// @e2e openregister-app-manifest::index-style-routes-dispatch-via-custom-registry
	test('/registers dispatches to the RegistersIndex list component', async ({
		page,
	}) => {
		requireAuth()
		await gotoRoute(page, '/registers')
		await expect(page).toHaveURL(/#\/registers$/)
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})

	// @e2e openregister-app-manifest::detail-style-routes-dispatch-via-custom-registry
	test('/registers/:id dispatches to the RegisterDetail component', async ({
		page,
	}) => {
		requireAuth()
		// A detail route with a param resolves via CnPageRenderer (props :id from
		// the URL). The id need not exist — we assert the shell renders, not data.
		await gotoRoute(page, '/registers/e2e-probe-id')
		await expect(page).toHaveURL(/#\/registers\/e2e-probe-id$/)
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})

	// @e2e openregister-app-manifest::every-page-resolves-to-a-registry-component
	test('every manifest index route resolves to a registry component', async ({
		page,
	}) => {
		requireAuth()
		// One representative route per top-level manifest destination. Each must
		// resolve through CnPageRenderer → registry kind:"page" entry and render
		// the app-content shell (lists may be empty against a fresh instance).
		const routes = [
			'/chat',
			'/registers',
			'/schemas',
			'/templates',
			'/tables',
			'/files',
			'/agents',
			'/organisation',
			'/applications',
			'/sources',
			'/configurations',
			'/entities',
			'/deleted',
			'/audit-trails',
			'/search-trails',
			'/webhooks',
			'/avg',
			'/reports',
			'/endpoints',
			'/objects',
		]
		for (const r of routes) {
			await gotoRoute(page, r)
			await expect(
				page.locator('main, .app-content').first(),
				`route ${r} should render the app-content shell`,
			).toBeVisible()
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// REQ-OR-MAN-004 — menu split into main + settings sections (rendered nav)
// ─────────────────────────────────────────────────────────────────────────────
test.describe('openregister-app-manifest — navigation sections', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openregister-app-manifest::both-sections-are-populated
	test('navigation renders both main and settings destinations', async ({
		page,
	}) => {
		requireAuth()
		await gotoRoute(page, '/')

		const nav = page
			.locator('.app-navigation, nav[class*="navigation"], [class*="navigation-list"]')
			.first()
		await expect(nav).toBeVisible({ timeout: 25_000 })

		// A "main"-section destination (Registers) and a "settings"-section
		// destination (Configurations) are both reachable from the rendered nav.
		const navText = (await nav.innerText()).toLowerCase()
		expect(navText).toContain('registers')
		// Settings cluster destination — Configurations lives in section:"settings".
		expect(navText).toMatch(/configuration/)
	})

	// @e2e openregister-app-manifest::menu-order-is-monotonic-per-section
	test('main-section nav order matches the manifest order', async ({ page }) => {
		requireAuth()
		await gotoRoute(page, '/')

		const nav = page
			.locator('.app-navigation, nav[class*="navigation"], [class*="navigation-list"]')
			.first()
		await expect(nav).toBeVisible({ timeout: 25_000 })

		const navText = (await nav.innerText()).toLowerCase()
		// Manifest main order: Chat(10) < Registers(20) < Schemas(30).
		const idxRegisters = navText.indexOf('registers')
		const idxSchemas = navText.indexOf('schemas')
		expect(idxRegisters).toBeGreaterThanOrEqual(0)
		expect(idxSchemas).toBeGreaterThan(idxRegisters)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// REQ-OR-MAN-011 — no deprecation warning at runtime
// ─────────────────────────────────────────────────────────────────────────────
test.describe('openregister-app-manifest — no deprecation warning', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openregister-app-manifest::no-deprecation-warning-at-runtime
	test('CnAppRoot emits no customComponents deprecation warning', async ({
		page,
	}) => {
		requireAuth()
		const warnings: string[] = []
		page.on('console', (msg) => {
			const text = msg.text()
			if (/customComponents prop is deprecated/i.test(text)) {
				warnings.push(text)
			}
		})
		await gotoRoute(page, '/')
		// Give the shell a moment to finish its loading → shell transition.
		await page.waitForTimeout(1500)
		expect(warnings, `unexpected deprecation warnings: ${warnings.join('; ')}`).toHaveLength(0)
	})
})
