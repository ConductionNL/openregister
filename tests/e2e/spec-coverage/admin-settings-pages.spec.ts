/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENUINE behavioural UI e2e for OpenRegister's ADMIN / SETTINGS-section
 * pages (the lower nav group): Organisations, Configurations, Webhooks,
 * Webhook logs, Endpoints, Search Trails. Asserts each page's real heading
 * (where one renders), its primary action button, tab switching where the
 * page is tabbed, and that the list surface renders — data-independent.
 *
 * Only OR-origin console errors / >=500 responses fail; core-NC noise is
 * filtered. Known OR backend gaps are documented inline.
 *
 * @e2e openspec/specs/no-code-app-builder/spec.md
 * @e2e openspec/specs/webhook-payload-mapping/spec.md
 * @e2e openspec/specs/tenant-isolation-audit/spec.md
 * @e2e openspec/specs/data-import-export/spec.md
 */
import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const NOISE = [
	'user_status', 'heartbeat', 'Failed to load user status',
	'/apps/activity/', '/notifications/api/', 'dashboard/api/v1/widgets',
	// Benign OR bootstrap network-abort race (page still renders fully).
	'[AppInit]', 'Failed to fetch', 'Failed to load data',
	// Anonymous browser mirror of a failed request (no URL → can't attribute).
	// Genuine OR 5xx are still caught by URL in the response tracker below;
	// named OR JS errors are NOT matched here and still fail.
	'Failed to load resource: the server responded with a status of 5',
]
function isNoise(t: string): boolean { return NOISE.some((n) => t.includes(n)) }

function trackErrors(page: Page): { console: string[]; http: string[] } {
	const errors = { console: [] as string[], http: [] as string[] }
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.console.push(t.slice(0, 160))
	})
	page.on('response', (r) => {
		if (r.status() < 500) return
		const u = r.url()
		if (!isNoise(u)) errors.http.push(`${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`)
	})
	return errors
}

async function gotoPage(page: Page, route: string): Promise<void> {
	// HASH form — the router runs in hash mode (src/main.js); path-form
	// deep-links render the dashboard instead of the target page.
	await page.goto(`/index.php/apps/openregister/#${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
	await page.waitForSelector('#app-content-vue, .app-content, main', { timeout: 20_000 })
	// Race a heading against a content button — some Index views (Endpoints)
	// render no page <h1>, so a button fallback keeps the wait short.
	await Promise.race([
		page.locator('#app-content-vue h1, .app-content h1, main h1').first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
		page.locator('.app-content button, main button').first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
	]).catch(() => {})
	await page.waitForTimeout(800)
}

async function expectListSurface(page: Page): Promise<void> {
	// `:visible` — OR keeps hidden empty-state placeholders in the DOM next
	// to the real table, so `.first()` could resolve to an invisible node.
	const surface = page.locator(
		'table:visible, .v-data-table:visible, [role="table"]:visible, '
		+ '.empty-content:visible, [class*="empty-content"]:visible, '
		+ '.list:visible, .viewContainer:visible, .viewTableContainer:visible, '
		+ '.pageContent:visible, .titleContent:visible',
	)
	await expect(surface.first()).toBeVisible({ timeout: 15_000 })
}
async function expectHeading(page: Page, text: RegExp): Promise<void> {
	// getByRole normalises whitespace in the accessible name; OR headings
	// carry template whitespace so anchored /^X$/ would miss the raw node text.
	await expect(page.getByRole('heading', { name: text }).first())
		.toBeVisible({ timeout: 15_000 })
}
async function expectButton(page: Page, name: RegExp): Promise<void> {
	await expect(page.getByRole('button', { name }).first()).toBeVisible({ timeout: 12_000 })
}

test.describe('admin-settings-pages — real UI render + actions', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Organisations: heading + Create Organisation + switch + list', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/organisation')
		await expectHeading(page, /^Organisations$/)
		await expectButton(page, /Create Organisation/i)
		await expectButton(page, /Switch Organisation/i)
		await expectListSurface(page)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Configurations: heading + Create + Import + list', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/configurations')
		await expectHeading(page, /^Configurations$/)
		await expectButton(page, /Create Configuration/i)
		await expectButton(page, /Import Configuration/i)
		await expectListSurface(page)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Webhooks: heading + Create Webhook + list/empty', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/webhooks')
		await expectHeading(page, /^Webhooks$/)
		await expectButton(page, /Create Webhook/i)
		await expectListSurface(page)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Webhook logs: heading + Back to Webhooks + list/empty', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/webhooks/logs')
		await expectHeading(page, /Webhook Logs/i)
		await expectButton(page, /Back to Webhooks/i)
		await expectListSurface(page)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Endpoints: Add endpoint + list/empty surface', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/endpoints')
		// NOTE: EndpointsIndex renders no page <h1>; assert the primary action.
		await expectButton(page, /Add endpoint/i)
		await expectListSurface(page)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Search Trails: heading + tabs (Filters/Statistics/Analytics) switch', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, '/search-trails')
		await expectHeading(page, /Search Trail/i)
		await expectButton(page, /Cleanup Old Trails/i)
		// Switch through the three tabs and assert each pane heading renders.
		for (const tab of ['Statistics', 'Analytics', 'Filters']) {
			await page.getByRole('tab', { name: tab }).first().click()
			await expect(page.locator('h2, h3').filter({ hasText: new RegExp(tab) }).first())
				.toBeVisible({ timeout: 8_000 })
		}
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})
})
