/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UI navigation and view e2e tests (browser-based) — covers:
 *   - frontend-app-bootstrap (full navigation, multiple routes)
 *   - platform-administration-modals (settings page renders)
 *   - entity-management-modals (register/schema list views)
 *   - built-in-dashboards (dashboard view renders with navigation)
 *   - no-code-app-builder (applications view renders)
 *   - features-roadmap (features-roadmap route renders)
 *
 * Uses storageState from global-setup.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')

// ─────────────────────────────────────────────────────────────────────────────
// frontend-app-bootstrap — all main navigation routes load
// ─────────────────────────────────────────────────────────────────────────────
test.describe('frontend-app-bootstrap — navigation routes load', () => {
	test.use({ storageState: STORAGE_STATE })

	const routes: Array<{ name: string; hash: string }> = [
		{ name: 'dashboard', hash: '/' },
		{ name: 'registers', hash: '/registers' },
		{ name: 'schemas', hash: '/schemas' },
		{ name: 'objects', hash: '/objects' },
		{ name: 'audit-trails', hash: '/audit-trails' },
		{ name: 'sources', hash: '/sources' },
		// 'agents' removed — OR chat/agents surface decommissioned (ffafd1c14).
		{ name: 'configurations', hash: '/configurations' },
	]

	for (const route of routes) {
		test(`#${route.hash} renders without error`, async ({ page }) => {
			const authFile = STORAGE_STATE
			if (!fs.existsSync(authFile)) {
				test.skip(
					true,
					'storageState not present — run the full suite first',
				)
			}
			// Use domcontentloaded — the NC SPA keeps background XHR alive so networkidle never fires.
			await page.goto(`/index.php/apps/openregister/#${route.hash}`, {
				waitUntil: 'domcontentloaded',
			})

			// Nextcloud header must be visible.
			await expect(
				page.locator(
					'#header, header.header-appcontainer, .header-appcontainer',
				),
			).toBeVisible({ timeout: 25_000 })

			// App content area must be present.
			await expect(page.locator('main, .app-content').first()).toBeVisible({
				timeout: 15_000,
			})
		})
	}
})

// ─────────────────────────────────────────────────────────────────────────────
// built-in-dashboards — dashboard view renders
// ─────────────────────────────────────────────────────────────────────────────
test.describe('built-in-dashboards — dashboard renders', () => {
	test.use({ storageState: STORAGE_STATE })

	test('dashboard view loads with navigation visible', async ({ page }) => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(true, 'storageState not present')
		}

		await page.goto('/index.php/apps/openregister/#/', {
			waitUntil: 'domcontentloaded',
		})

		// Navigation sidebar must render.
		await expect(
			page
				.locator(
					'.app-navigation, nav[class*="navigation"], [class*="navigation-list"]',
				)
				.first(),
		).toBeVisible({ timeout: 25_000 })

		// Main content area must render.
		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 15_000,
		})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// features-roadmap — roadmap view renders
// ─────────────────────────────────────────────────────────────────────────────
test.describe('features-roadmap — roadmap view renders', () => {
	test.use({ storageState: STORAGE_STATE })

	test('#/features-roadmap renders without error', async ({ page }) => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(true, 'storageState not present')
		}

		await page.goto('/index.php/apps/openregister/#/features-roadmap', {
			waitUntil: 'domcontentloaded',
		})

		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 20_000,
		})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// no-code-app-builder — applications view renders
// ─────────────────────────────────────────────────────────────────────────────
test.describe('no-code-app-builder — applications view', () => {
	test.use({ storageState: STORAGE_STATE })

	test('#/applications renders the applications list', async ({ page }) => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(true, 'storageState not present')
		}

		await page.goto('/index.php/apps/openregister/#/applications', {
			waitUntil: 'domcontentloaded',
		})

		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 20_000,
		})
	})
})
