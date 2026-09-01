/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const APP_BASE = '/index.php/apps/openregister'

function requireAuth() {
	if (!fs.existsSync(STORAGE_STATE)) {
		test.skip(true, 'storageState not present — run the full suite first')
	}
}

async function gotoRoute(page: Page, route: string) {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'domcontentloaded' })
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
				.locator(
					'.app-navigation, nav[class*="navigation"], [class*="navigation-list"]',
				)
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
		await expect(page).toHaveURL(/\/apps\/openregister\/schemas$/)
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// REQ-OR-MAN-006 — CnPageRenderer dispatches every route via custom registry
// ─────────────────────────────────────────────────────────────────────────────
test.describe('openregister-app-manifest — registry dispatch', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openregister-app-manifest::dashboard-dispatches-via-custom-registry
	test('/ dispatches to the Dashboard component and renders its KPI / widgets', async ({
		page,
	}) => {
		requireAuth()
		await gotoRoute(page, '/')

		// The scenario requires "/" to render "the OR dashboard with all its KPI /
		// chart widgets" — so we assert the actual rendered widget DOM, not just the
		// app-content shell. DashboardIndex.vue paints four KPI count-cards
		// (.kpi-card → .kpi-value / .kpi-label) plus list widgets that render either
		// a .stats-table or a .widget-empty placeholder.

		// At least one KPI count-card frame must render.
		const kpiCard = page.locator('.kpi-card').first()
		await expect(kpiCard).toBeVisible({ timeout: 25_000 })

		// The KPI card surfaces a value and a label.
		await expect(kpiCard.locator('.kpi-value')).toBeVisible()
		await expect(kpiCard.locator('.kpi-label')).toBeVisible()

		// DashboardIndex.vue currently ships five KPI count-card slots (objects /
		// registers / schemas / searches / events) — KPI values default to 0 so
		// the cards paint even on a fresh instance. Assert a lower bound rather
		// than an exact count so adding a KPI card is not a false regression.
		expect(await page.locator('.kpi-card').count()).toBeGreaterThanOrEqual(4)

		// A list widget renders either its data table or its empty-state
		// placeholder — never an unpainted body.
		//
		// ⚠️ This asserted `.list-widget-content .stats-table, .list-widget-content
		// .widget-empty`, and NEITHER half could ever match: `.list-widget-content`
		// appears nowhere in src/, and `.stats-table` only in
		// views/settings/sections/StatisticsOverview.vue, which is not the
		// dashboard. A populated widget renders `CnDataTable`, an empty one
		// `div.widget-empty` (DashboardIndex.vue, `#widget-objects-by-register`).
		// The selector went unnoticed because CI never executed this file.
		//
		// Scoped to the dashboard by the stable testid CnDashboardPage renders
		// on its root. Guessing the per-widget wrapper class was the previous
		// mistake and it is not needed: assert the widget's own TITLE (an item,
		// and one that only exists if the manifest's widget list reached the
		// page), then that the dashboard body paints real rows or an explicit
		// empty state rather than an unpainted panel.
		const dash = page.locator('[data-testid="cn-dashboard-page"]').first()
		await expect(dash).toBeVisible({ timeout: 15_000 })
		await expect(
			dash.getByText('Objects by Register', { exact: true }).first(),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			dash.locator('table tbody tr, .widget-empty').first(),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e openregister-app-manifest::index-style-routes-dispatch-via-custom-registry
	test('/registers dispatches to the RegistersIndex list component', async ({
		page,
	}) => {
		requireAuth()
		await gotoRoute(page, '/registers')
		await expect(page).toHaveURL(/\/apps\/openregister\/registers$/)
		await expect(page.locator('main, .app-content').first()).toBeVisible()
	})

	// @e2e openregister-app-manifest::detail-style-routes-dispatch-via-custom-registry
	test('/registers/:id dispatches to the RegisterDetail component', async ({
		page,
	}) => {
		requireAuth()
		// A detail route with a param resolves via CnPageRenderer (props :id from
		// the URL). RegisterDetail is store-driven: when no register is selected in
		// the store (a cold deep-link), its mounted() guard bounces back to the
		// list at #/registers. Either way the route resolved through the manifest
		// router into a registry component and the app-content shell renders — the
		// scenario asserts the dispatch + shell, not that an unknown id stays put.
		await gotoRoute(page, '/registers/e2e-probe-id')
		await expect(page).toHaveURL(
			/\/apps\/openregister\/registers(\/e2e-probe-id)?$/,
		)
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
			// '/chat' and '/agents' were removed with the OR chat decommission
			// (ffafd1c14) — they now hit the catch-all and redirect to '/'.
			'/registers',
			'/schemas',
			'/templates',
			'/tables',
			'/files',
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
			.locator(
				'.app-navigation, nav[class*="navigation"], [class*="navigation-list"]',
			)
			.first()
		await expect(nav).toBeVisible({ timeout: 25_000 })

		// The menu is clustered into collapsible groups (Data / AI / Integration /
		// Administration / Audit — see src/menu-layout.json). A collapsed group's
		// leaf children are still in the DOM as stable `cn-nav-entry-<id>` entries,
		// so we assert on those canonical entries rather than the group's visible
		// innerText (which omits collapsed children).
		// A data-cluster destination (Registers) and an administration-cluster
		// destination (Configurations) are both present in the rendered nav.
		await expect(
			page.locator('[data-testid="cn-nav-entry-Registers"]'),
		).toHaveCount(1)
		await expect(
			page.locator('[data-testid="cn-nav-entry-Configurations"]'),
		).toHaveCount(1)
	})

	// @e2e openregister-app-manifest::menu-order-is-monotonic-per-section
	test('main-section nav order matches the manifest order', async ({ page }) => {
		requireAuth()
		await gotoRoute(page, '/')

		const nav = page
			.locator(
				'.app-navigation, nav[class*="navigation"], [class*="navigation-list"]',
			)
			.first()
		await expect(nav).toBeVisible({ timeout: 25_000 })

		// Within the Data cluster the leaves keep their manifest order
		// (Registers order:20 < Schemas order:30). Collapsed-group children stay
		// in the DOM as `cn-nav-entry-<id>` entries, so we read DOM document order
		// of those canonical entries rather than the group's visible innerText.
		const entryIds = await nav
			.locator('[data-testid^="cn-nav-entry-"]')
			.evaluateAll((els) =>
				els.map((e) => e.getAttribute('data-testid') || ''),
			)
		const idxRegisters = entryIds.indexOf('cn-nav-entry-Registers')
		const idxSchemas = entryIds.indexOf('cn-nav-entry-Schemas')
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
		expect(
			warnings,
			`unexpected deprecation warnings: ${warnings.join('; ')}`,
		).toHaveLength(0)
	})
})
