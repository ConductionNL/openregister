/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared helpers for the visual-regression layer (GAP-5).
 *
 * This module is intentionally self-contained (imports only @playwright/test)
 * and is copied verbatim into each app's tests/e2e/visual/ directory so every
 * app's visual suite is identical and portable.
 *
 * Determinism is the whole point of a visual baseline: a flaky baseline is
 * worse than none. Every shot is taken with:
 *   - a fixed 1280x800 viewport (set on the `visual` project in the config),
 *   - CSS animations / transitions / caret blink disabled,
 *   - the auto-opening cn-support-dialog dismissed + hidden,
 *   - dynamic regions masked (dates, ids, avatars, counts, relative times),
 *   - a wait for *content* (no spinners / skeletons) before the shot,
 *   - maxDiffPixelRatio tolerance to absorb sub-pixel font hinting.
 *
 * PLATFORM CAVEAT (read before trusting a CI run): Playwright screenshot
 * baselines are rendered by the host's font stack + GPU, so a baseline shot on
 * a dev workstation will NOT byte-match the same page rendered on a CI Linux
 * runner. Baselines committed here are generated against the local dev
 * container. In CI the visual project must therefore either (a) regenerate its
 * own baselines on first run, or (b) stay non-gating until baselined in the CI
 * environment. See tests/e2e/visual/README in-repo wiring notes.
 */
import { expect, type Page, type Locator } from '@playwright/test'

/** Common screenshot options applied to every visual assertion. */
export const SHOT_OPTIONS = {
	animations: 'disabled' as const,
	// Absorb sub-pixel font hinting / antialiasing differences without
	// hiding real layout regressions.
	maxDiffPixelRatio: 0.02,
	// Full page so a regression below the fold is still caught.
	fullPage: true,
}

/**
 * Inject a stylesheet that kills animations, transitions and the caret so a
 * shot taken mid-frame is identical every run.
 */
export async function freezePage(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `
			*, *::before, *::after {
				animation-duration: 0s !important;
				animation-delay: 0s !important;
				transition-duration: 0s !important;
				transition-delay: 0s !important;
				caret-color: transparent !important;
				scroll-behavior: auto !important;
			}
			/* Hide the auto-opening support dialog + its backdrop entirely so
			   it can never bleed into a shot even if dismissal races. */
			[data-testid-modal="cn-support-dialog"],
			.cn-support-dialog,
			.modal-mask { visibility: hidden !important; }
		`,
	})
}

/**
 * Dismiss the "Support <App>" dialog that auto-opens over the app and would
 * otherwise dominate (and randomise) the shot.
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('[data-testid-modal="cn-support-dialog"]')
	if (await dialog.isVisible().catch(() => false)) {
		await dialog.getByRole('button', { name: 'Close' }).click().catch(() => {})
		await dialog.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {})
	}
}

/**
 * Wait until the app content area has rendered and any loading spinner /
 * skeleton has gone. We use domcontentloaded + explicit waits because the NC
 * SPA keeps background XHR alive, so `networkidle` never fires.
 */
export async function waitForContentReady(page: Page): Promise<void> {
	await expect(
		page.locator('#header, header.header-appcontainer, .header-appcontainer').first(),
	).toBeVisible({ timeout: 25_000 })
	await expect(
		page.locator('main, #app-content, .app-content, #content-vue').first(),
	).toBeVisible({ timeout: 20_000 })

	// Give Vue a beat to paint, then wait for spinners/skeletons to disappear.
	const spinner = page.locator(
		'.icon-loading, .loading, .material-design-icon.loading-icon, [class*="skeleton"], .app-content-loading',
	)
	await spinner.first().waitFor({ state: 'hidden', timeout: 8_000 }).catch(() => {})

	// Wait for common async "Loading …" placeholder text to vanish. Many of
	// the Conduction dashboards stream widget data after first paint
	// ("Loading statistics…", "Loading version information…"); shooting before
	// it lands produces a non-deterministic baseline. Poll up to ~10s.
	const loadingText = page.getByText(/Loading[\s.]*(statistics|version|data|information)?…?/i)
	for (let i = 0; i < 20; i++) {
		const count = await loadingText.count().catch(() => 0)
		if (count === 0) break
		const anyVisible = await loadingText.first().isVisible().catch(() => false)
		if (!anyVisible) break
		await page.waitForTimeout(500)
	}
	// Settle layout (fonts, async widgets) before the shot.
	await page.waitForTimeout(1_500)
}

/**
 * Dynamic regions that must be masked out of every shot so volatile content
 * (timestamps, uuids, user avatars, live counts, relative times) never flips a
 * baseline. Returns the locators present on the page; absent ones are simply
 * not masked.
 */
export function dynamicMasks(page: Page): Locator[] {
	const selectors = [
		// Nextcloud header right-side: user menu / avatar / notifications /
		// contacts menu / unified search — all volatile or focus-dependent.
		'#user-menu', '.avatardiv', '.user-menu', '#settings',
		'#notifications', '.notifications', '#contactsmenu', '.unified-search',
		'.header-right',
		// Common dynamic-content hooks across the Conduction apps.
		'[class*="timestamp"]', '[class*="date"]', 'time',
		'[class*="relative-time"]', '[class*="last-modified"]', '[class*="updated"]',
		'[class*="uuid"]', '[class*="avatar"]',
		'[data-visual-mask]',
		// Count badges / stat numbers on dashboard cards + tables. These are
		// live data that churns between runs; mask the value, not the label.
		'.cn-stat-value', '[class*="stat-value"]', '.counter-bubble__counter',
		'[class*="statistic"]', '[class*="metric-value"]', '[class*="count"]',
		// Side detail / right sidebar panels stream live aggregates
		// ("Totals", "Loading statistics…") that never settle against a shared
		// live-data instance — mask the panel so structure stays the signal.
		'.app-content-details', '.app-sidebar', '[class*="dashboard-detail"]',
	]
	return selectors.map((s) => page.locator(s))
}

/**
 * Navigate, stabilise, and assert a full-page screenshot for one surface.
 */
export async function shootSurface(
	page: Page,
	url: string,
	name: string,
): Promise<void> {
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await waitForContentReady(page)
	await freezePage(page)
	await expect(page).toHaveScreenshot(name, {
		...SHOT_OPTIONS,
		mask: dynamicMasks(page),
	})
}

/**
 * Land on the app root, then reach a surface by CLICKING its sidebar nav link
 * (by visible label) and shoot it. Many of the Conduction SPAs reset a
 * deep-link `goto('…#/route')` back to the dashboard, so an in-app nav click
 * is the reliable way to reach a non-default view. Falls back to a shot of
 * wherever we land if the link is absent, so a baseline is still produced.
 */
export async function shootByNav(
	page: Page,
	appRoot: string,
	label: string,
	name: string,
): Promise<void> {
	await page.goto(appRoot, { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await waitForContentReady(page)

	// Close any open detail/side panel that can overlay + swallow nav clicks.
	const panelClose = page.locator('.app-content-details .icon-close, [class*="detail"] button[aria-label*="lose"], .app-sidebar__close').first()
	if (await panelClose.isVisible().catch(() => false)) {
		await panelClose.click().catch(() => {})
		await page.waitForTimeout(300)
	}

	const nav = page.locator('[id^="app-navigation"], .app-navigation, nav').first()
	const link = nav.getByRole('link', { name: label, exact: true }).first()
	if (await link.isVisible().catch(() => false)) {
		await link.click().catch(() => {})
		await dismissSupportDialog(page)
		await waitForContentReady(page)
	}
	await freezePage(page)
	await expect(page).toHaveScreenshot(name, {
		...SHOT_OPTIONS,
		mask: dynamicMasks(page),
	})
}
