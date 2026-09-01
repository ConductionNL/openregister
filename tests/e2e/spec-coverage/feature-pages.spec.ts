import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENUINE behavioural UI e2e for OpenRegister's FEATURE pages that aren't
 * plain entity lists: Files, GDPR / AVG processing register, Reports,
 * My account, and Features & roadmap. Each asserts the real heading, the
 * primary action(s), tab/section switching where present, and that content
 * renders — data-independent.
 *
 * NOTE: the AI Chat page test was deleted — the OR chat product surface was
 * decommissioned (ffafd1c14, or-chat-engine-decommission); the SPA page moved
 * to hermiq.
 *
 * Only OR-origin console errors / >=500 responses fail; core-NC noise is
 * filtered. Known OR backend gaps (reports register not imported on a bare
 * dev env) are asserted-around, not asserted-on.
 *
 * @e2e openspec/specs/files-render-extension/spec.md
 * @e2e openspec/specs/avg-verwerkingsregister/spec.md
 * @e2e openspec/specs/built-in-dashboards/spec.md
 * @e2e openspec/specs/account-self-service/spec.md
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
// Routes are imported by COMPONENT NAME (see tests/e2e/_page-routes.ts): the
// binding records which page host each route mounts, which a bare path string
// cannot say. Also what makes this suite legible to gate-26.
import {
	AvgIndex,
	FeaturesRoadmapIndex,
	FilesIndex,
	MyAccount,
	ReportsIndex,
} from '../_page-routes.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const NOISE = [
	'user_status',
	'heartbeat',
	'Failed to load user status',
	'/apps/activity/',
	'/notifications/api/',
	'dashboard/api/v1/widgets',
	// Benign OR bootstrap network-abort race (page still renders fully).
	'[AppInit]',
	'Failed to fetch',
	'Failed to load data',
	// Anonymous browser mirror of a failed request (no URL → can't attribute).
	// Genuine OR 4xx/5xx are still caught BY URL in the response tracker; named
	// OR JS errors are NOT matched here and still fail.
	'Failed to load resource: the server responded with a status of 5',
	'Failed to load resource: the server responded with a status of 404',
	// OR probes the OPTIONAL hermiq app's chat health on every page. hermiq is
	// a separate ExApp and is absent on a stock CI instance, so this 404s on
	// every route. Filtered BY URL, not by status: any other 404 still fails.
	'/apps/hermiq/',
]

function trackErrors(
	page: Page,
	extraNoise: string[] = [],
): { console: string[]; http: string[] } {
	const all = [...NOISE, ...extraNoise]
	const noisy = (t: string) => all.some((n) => t.includes(n))
	const errors = { console: [] as string[], http: [] as string[] }
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!noisy(t)) errors.console.push(t.slice(0, 160))
	})
	// >= 400, not >= 500. The console mirror of a failed request carries NO
	// url, so suppressing the anonymous 404 line above would have blinded the
	// test to real 404s. Tracking by URL here is strictly STRONGER than what
	// this file did before: a genuine 404 now fails the test and NAMES the
	// endpoint, instead of surfacing as an unattributable console string.
	page.on('response', (r) => {
		if (r.status() < 400) return
		const u = r.url()
		if (!noisy(u))
			errors.http.push(`${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`)
	})
	return errors
}

async function gotoPage(page: Page, route: string): Promise<void> {
	// HASH form — the router runs in hash mode (src/main.js); path-form
	// deep-links render the dashboard instead of the target page.
	await page.goto(`/index.php/apps/openregister/#${route}`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('#header, header.header-appcontainer', {
		timeout: 25_000,
	})
	await page.waitForSelector('#app-content-vue, .app-content, main', {
		timeout: 20_000,
	})
	// Race a heading against a content button so feature pages whose top
	// heading is an <h2> (My account, Features & roadmap) still settle fast.
	await Promise.race([
		page
			.locator(
				'#app-content-vue h1, .app-content h1, main h1, '
					+ '#app-content-vue h2, .app-content h2, main h2',
			)
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
		page
			.locator('.app-content button, main button')
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
	]).catch(() => {})
	await page.waitForTimeout(800)
}
async function expectHeading(page: Page, text: RegExp): Promise<void> {
	// getByRole normalises whitespace in the accessible name; OR headings
	// carry template whitespace so anchored /^X$/ would miss the raw node text.
	await expect(page.getByRole('heading', { name: text }).first()).toBeVisible({
		timeout: 15_000,
	})
}
async function expectButton(page: Page, name: RegExp): Promise<void> {
	await expect(page.getByRole('button', { name }).first()).toBeVisible({
		timeout: 12_000,
	})
}

test.describe('feature-pages — real UI render + actions', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Files: heading + Refresh + Filters toggle + content', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, FilesIndex)
		await expectHeading(page, /^Files$/)
		await expectButton(page, /Refresh/i)
		// FilesIndex exposes a "Toggle search sidebar" action (not a
		// Show/Hide-Filters toggle — that label does not exist on this page).
		await expectButton(page, /Toggle search sidebar/i)
		const surface = page.locator(
			'table:visible, .empty-content:visible, '
				+ '[class*="empty-content"]:visible, .list:visible, .viewContainer:visible',
		)
		await expect(surface.first()).toBeVisible({ timeout: 15_000 })
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('AVG: heading + New activity + section tabs switch', async ({ page }) => {
		const e = trackErrors(page)
		await gotoPage(page, AvgIndex)
		await expectHeading(page, /GDPR \/ AVG processing register/i)
		await expectButton(page, /New activity/i)
		// The four AVG section buttons act as a tab strip.
		for (const sec of ['Accountability', 'DSAR', 'Compliance', 'Activities']) {
			await page
				.getByRole('button', { name: new RegExp(`^${sec}$`) })
				.first()
				.click()
			await page.waitForTimeout(400)
		}
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	test('Reports: heading + Refresh + content renders', async ({ page }) => {
		// KNOWN OR GAP: on a bare dev env the `reports` register is not imported,
		// so reports.fetchDashboards 404s. The page still renders its heading +
		// empty-content CTA; the 404 is data-state, not a UI regression — filter
		// both the named AxiosError and the anonymous browser 404 mirror line.
		const e = trackErrors(page, [
			'/api/dashboards',
			'fetchDashboards',
			'reports',
			'Failed to load resource: the server responded with a status of 404',
		])
		await gotoPage(page, ReportsIndex)
		await expectHeading(page, /^Reports$/)
		await expectButton(page, /Refresh/i)
		const surface = page.locator(
			'table:visible, .empty-content:visible, '
				+ '[class*="empty-content"]:visible, .list:visible, .pageContent:visible, '
				+ '.viewContainer:visible',
		)
		await expect(surface.first()).toBeVisible({ timeout: 15_000 })
		// We do not assert zero 5xx here because OR's reports register may be absent.
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
	})

	test('My account: heading + sections (Password/Avatar/API Tokens) + actions', async ({
		page,
	}) => {
		// The avatar widget fetches /avatar//512 before the uid resolves → a
		// harmless core-NC 404; filter both the URL form and the anonymous
		// browser 404 mirror line (no URL → unattributable by text).
		const e = trackErrors(page, [
			'/avatar/',
			'displayname',
			'user/',
			'Failed to load resource: the server responded with a status of 404',
		])
		await gotoPage(page, MyAccount)
		await expectHeading(page, /My Account/i)
		await expectHeading(page, /Password/i)
		await expectHeading(page, /API Tokens/i)
		await expectButton(page, /Change password/i)
		await expectButton(page, /Create new token/i)
		await expectButton(page, /Export my data/i)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
	})

	test('Features & roadmap: heading + Suggest feature + Show roadmap', async ({
		page,
	}) => {
		const e = trackErrors(page)
		await gotoPage(page, FeaturesRoadmapIndex)
		await expectHeading(page, /^Features$|Your input is the roadmap/i)
		await expectButton(page, /Suggest (a )?feature/i)
		await expectButton(page, /Show roadmap/i)
		expect(e.console, e.console.join(' | ')).toHaveLength(0)
		expect(e.http, e.http.join(' | ')).toHaveLength(0)
	})

	/**
	 * The nav entry is a real router destination, not a decorative row.
	 *
	 * Asserts the ITEM, not the container: the specific nav entry
	 * `FeaturesRoadmapMenu` (the manifest id, rendered by CnAppNav as
	 * `data-testid="cn-nav-entry-<id>"`), the resulting hash route, and the
	 * `.cn-features-and-roadmap-view` root that CnFeaturesAndRoadmapView
	 * itself renders. Then it resolves the view's ANCESTRY, which is the only
	 * way to tell the spec's two outcomes apart — "renders in the main content
	 * area" and "renders as an overlay" both look like a visible view.
	 *
	 * Starts from /registers so the click is a genuine route TRANSITION; a test
	 * that begins on the target route would pass without navigating at all.
	 *
	 * @e2e openspec/specs/features-roadmap-menu/spec.md#clicking-the-nav-entry-navigates-to-the-route
	 */
	test('clicking the Features & Roadmap nav entry routes to it in the main content area', async ({
		page,
	}) => {
		await gotoPage(page, '/registers')
		await expect(page).toHaveURL(/#\/registers$/)

		// The manifest declares this entry in the `footer` section; CnAppNav
		// renders footer entries as NcAppNavigationItem router-links.
		const navEntry = page.locator(
			'[data-testid="cn-nav-entry-FeaturesRoadmapMenu"]',
		)
		await expect(navEntry).toHaveCount(1)
		await navEntry.first().click()

		await expect(page).toHaveURL(/#\/features-roadmap$/, { timeout: 15_000 })

		const view = page.locator('.cn-features-and-roadmap-view')
		await expect(view).toBeVisible({ timeout: 15_000 })

		// "in the main content area, NOT as an overlay" — assert both halves.
		// `closest()` walks real ancestors, so a view teleported into a dialog
		// or a modal mask fails here even though it is perfectly visible.
		const overlayAncestor = await view
			.first()
			.evaluate(
				(el) =>
					!!el.closest(
						'[role="dialog"], .modal-container, .modal-mask, .modal-wrapper',
					),
			)
		expect(overlayAncestor, 'view rendered inside an overlay').toBe(false)

		const contentAncestor = await view
			.first()
			.evaluate((el) => !!el.closest('#app-content-vue, .app-content'))
		expect(
			contentAncestor,
			'view not rendered inside the app content area',
		).toBe(true)
	})
})
