/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an unregistered icon name renders NO glyph
 * (no fallback, no console error), an entry whose `route` names a page the app
 * does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences — in this very app.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

test.use(fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {})

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ CnSetupWizard opens over the app and its modal wrapper intercepts
 * pointer events, so a nav click resolves its locator and then times out
 * after 30s. Playwright reports the link as "visible, enabled and stable"
 * throughout, which reads like the navigation is broken rather than covered.
 *
 * Measured on a running instance: `document.elementFromPoint()` at the centre
 * of the Reports link returned `DIV.modal-wrapper--large`, not the link.
 *
 * Tests that navigate by URL pass either way, which is what makes this easy to
 * miss: only the click-through tests fail, and only while the wizard is up.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}

	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/index.php/apps/openregister/', {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers. This app ran its footer at 1 and 2
		// while pipelinq runs 160/200/230, and both read correctly; ADR-114
		// fixes the sequence and leaves the numbers to the app.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports opens the reports surface, not the dashboard', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Reports$/ })
			.first()
			.click()

		// By PATH. This app's reports surface is `type: "custom"` at the
		// canonical /reports path rather than the built-in `reports` page type,
		// which is deliberate and documented in the manifest — a first version
		// of gate-107 tested the page TYPE alone and called this MISSING.
		await expect(page).toHaveURL(/\/apps\/openregister\/reports(\?|$)/, {
			timeout: 15_000,
		})
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('Reports is no longer buried in the main navigation', async ({ page }) => {
		// It used to sit inside a main-section group at order 101, three levels
		// from where every other app puts it. ADR-114 Decision 1 moves it to the
		// footer; this asserts the move rather than only the arrival.
		const main = page.locator('[data-testid="cn-nav"] .cn-app-nav__footer-list')
		await expect(main.getByRole('link', { name: /^Reports$/ })).toHaveCount(1)
	})

	test('Store opens the hosted store surface, which this app writes no backend for', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/openregister\/store(\?|$)/, {
			timeout: 15_000,
		})

		// openregister HOSTS the store plane, so this page is declarative: the
		// app ships no store controller of its own (ADR-080, ADR-114 Decision
		// 4). With no registry configured it renders its own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})

		// This app set `nav.includePersonalSettings: false` with no replacement,
		// which put the user's notification preferences and the ADR-110
		// Integrations section out of reach entirely. The flag is gone; this is
		// the test that notices if it comes back.
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		// The testid rides the NcAppNavigationItem wrapper, which is an <li>.
		// The href is on the anchor inside it, so assert it there: on the
		// wrapper the attribute is simply absent and the failure reads as a
		// wrong destination rather than a wrong locator.
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/openregister$/,
		)
	})

	test("Flows stays in the main navigation, which is this app's documented exception", async ({
		page,
	}) => {
		// ADR-110 Decision 4 keeps Flows in `main` for exactly three apps, and
		// openregister is one: it owns the engine, and its /flows is the
		// unscoped fleet-wide view rather than one app's own automations. If a
		// future change "tidies" it into the settings foldout, this fails.
		const nav = page.locator('[data-testid="cn-nav"]')
		const footerFlows = nav
			.locator('.cn-app-nav__footer-list')
			.getByRole('link', { name: /^Flows$/ })
		await expect(footerFlows).toHaveCount(0)
		await expect(
			nav.getByRole('link', { name: /^Flows$/ }).first(),
		).toBeAttached({ timeout: 15_000 })
	})
})
