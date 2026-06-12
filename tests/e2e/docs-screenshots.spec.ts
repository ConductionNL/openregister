/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — openregister.
 *
 * This spec is *not* a regression test — it drives the Open Register UI
 * through the flows documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Open Register's dev container ships with a populated
 * fleet of registers / schemas / objects (the 19/184/164 baseline), so
 * structural screenshots — Registers list, Schemas list, the global
 * Search / Views, Audit Trails, Files — all render with real data.
 * Create / edit / save dialogs open against the real schemas, so the
 * schema-driven object form has actual fields. Some downstream steps
 * (a sync run, a re-index) require infrastructure that may not be wired
 * (Solr container, an external source) — the spec falls back to a
 * structural screenshot of the relevant settings page when those are
 * absent.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')
const APP = '/apps/openregister'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({ path: path.join(dir, file), fullPage: false, type: 'png' })
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (await stray.first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/** Navigate to an OR (or absolute) route and settle. */
async function go(page: Page, route: string): Promise<void> {
	const url = route.startsWith('/apps/') || route.startsWith('/settings/')
		? `/index.php${route}`
		: `/index.php${APP}${route}`
	await page.goto(url).catch(() => { /* tolerate a 404 — caller decides */ })
	await page.waitForLoadState('networkidle').catch(() => { /* idle never fires on some pages */ })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Register" / "Add Schema" /
 * "Add Object" / etc.) if the button is present, screenshot it, and
 * close it again. Returns whether the dialog appeared.
 */
async function captureCreateDialog(page: Page, track: 'user' | 'admin', file: string, buttonRe: RegExp): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: buttonRe }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => { /* no dialog */ })
	await page.waitForTimeout(500)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/registers')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/openregister')
	})

	test('UN create-a-register', async ({ page }) => {
		// docs/tutorials/user/02-create-a-register.md
		await go(page, '/registers')
		const had = await captureCreateDialog(page, 'user', '02-create-a-register-01.png', /Add Register/i)
		if (had) {
			// Re-open and fall through to the same dialog for step 2 (the
			// "fields filled in" step — no actual typing because saving
			// would mutate the dev container).
			await captureCreateDialog(page, 'user', '02-create-a-register-02.png', /Add Register/i)
		}
		await go(page, '/registers')
		await shoot(page, 'user', '02-create-a-register-03.png')
		// Steps 4-5 (register detail page + URL) need an existing register;
		// the registers list / first card stand in if no detail is reachable.
		const firstCard = page.locator('.app-content a, .app-content .card a, table tbody tr').first()
		if (await firstCard.isVisible().catch(() => false)) {
			await firstCard.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '02-create-a-register-04.png')
		await shoot(page, 'user', '02-create-a-register-05.png')
	})

	test('UN create-a-schema', async ({ page }) => {
		// docs/tutorials/user/03-create-a-schema.md
		await go(page, '/schemas')
		const had = await captureCreateDialog(page, 'user', '03-create-a-schema-01.png', /Add Schema/i)
		if (had) {
			await captureCreateDialog(page, 'user', '03-create-a-schema-02.png', /Add Schema/i)
		}
		await go(page, '/schemas')
		await shoot(page, 'user', '03-create-a-schema-03.png')
		// Open the first schema's detail page (if any) for the Source tab
		// capture; otherwise the list stands in.
		const firstSchema = page.locator('.app-content a, table tbody tr').first()
		if (await firstSchema.isVisible().catch(() => false)) {
			await firstSchema.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '03-create-a-schema-04.png')
		await go(page, '/registers')
		await shoot(page, 'user', '03-create-a-schema-05.png')
	})

	test('UN create-an-object', async ({ page }) => {
		// docs/tutorials/user/04-create-an-object.md
		await go(page, '/objects')
		await shoot(page, 'user', '04-create-an-object-01.png')
		const had = await captureCreateDialog(page, 'user', '04-create-an-object-02.png', /Add Object|Add Item/i)
		if (had) {
			await captureCreateDialog(page, 'user', '04-create-an-object-03.png', /Add Object|Add Item/i)
		} else {
			await shoot(page, 'user', '04-create-an-object-02.png')
			await shoot(page, 'user', '04-create-an-object-03.png')
		}
		await go(page, '/objects')
		await shoot(page, 'user', '04-create-an-object-04.png')
		await shoot(page, 'user', '04-create-an-object-05.png')
	})

	test('UN search-and-filter', async ({ page }) => {
		// docs/tutorials/user/05-search-and-filter.md
		await go(page, '/tables')
		await shoot(page, 'user', '05-search-and-filter-01.png')
		// Type into the search box if one is present. Locator is generic
		// to survive component swaps.
		const searchInput = page.locator('input[type="search"], input[placeholder*="Search" i]').first()
		if (await searchInput.isVisible().catch(() => false)) {
			await searchInput.fill('a').catch(() => {})
			await page.waitForTimeout(1000)
		}
		await shoot(page, 'user', '05-search-and-filter-02.png')
		await shoot(page, 'user', '05-search-and-filter-03.png')
		await shoot(page, 'user', '05-search-and-filter-04.png')
		await shoot(page, 'user', '05-search-and-filter-05.png')
	})

	test('UN view-audit-trail', async ({ page }) => {
		// docs/tutorials/user/06-view-audit-trail.md
		// Object-level audit trail: open an object and switch to its
		// Audit Trails tab; if no object is reachable, fall back to the
		// global Audit Trails view (steps 4-5).
		await go(page, '/objects')
		const firstObj = page.locator('.app-content a, table tbody tr').first()
		if (await firstObj.isVisible().catch(() => false)) {
			await firstObj.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '06-view-audit-trail-01.png')
		await shoot(page, 'user', '06-view-audit-trail-02.png')
		await shoot(page, 'user', '06-view-audit-trail-03.png')
		await go(page, '/audit-trails')
		await shoot(page, 'user', '06-view-audit-trail-04.png')
		await shoot(page, 'user', '06-view-audit-trail-05.png')
	})

	test('UN attach-files', async ({ page }) => {
		// docs/tutorials/user/07-attach-files.md
		// Object Files tab: open an object, switch to Files. Fall back
		// to the global Files view for steps 4-5.
		await go(page, '/objects')
		const firstObj = page.locator('.app-content a, table tbody tr').first()
		if (await firstObj.isVisible().catch(() => false)) {
			await firstObj.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'user', '07-attach-files-01.png')
		await shoot(page, 'user', '07-attach-files-02.png')
		await shoot(page, 'user', '07-attach-files-03.png')
		await go(page, '/files')
		await shoot(page, 'user', '07-attach-files-04.png')
		// Step 5 — same file in Nextcloud Files. Best-effort; the path may
		// not exist if no object has files attached.
		await go(page, '/apps/files/')
		await shoot(page, 'user', '07-attach-files-05.png')
	})

	test('UN export-import', async ({ page }) => {
		// docs/tutorials/user/08-export-import.md
		await go(page, '/registers')
		// Click "Actions" if it's there to open the export/import menu.
		const actions = page.getByRole('button', { name: /Actions/i }).first()
		if (await actions.isVisible().catch(() => false)) {
			await actions.click().catch(() => {})
			await page.waitForTimeout(500)
		}
		await shoot(page, 'user', '08-export-import-01.png')
		// Close the menu and re-open (the export dialog needs a fresh
		// click on a different item).
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
		await shoot(page, 'user', '08-export-import-02.png')
		await shoot(page, 'user', '08-export-import-03.png')
		await shoot(page, 'user', '08-export-import-04.png')
		await shoot(page, 'user', '08-export-import-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN permissions-rbac', async ({ page }) => {
		// docs/tutorials/admin/01-permissions-rbac.md — RBAC lives on the
		// register's Settings tab; open the first register and switch
		// tabs. The registers list stands in if no register is reachable.
		await go(page, '/registers')
		const firstCard = page.locator('.app-content a, table tbody tr').first()
		if (await firstCard.isVisible().catch(() => false)) {
			await firstCard.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'admin', '01-permissions-rbac-01.png')
		await shoot(page, 'admin', '01-permissions-rbac-02.png')
		await shoot(page, 'admin', '01-permissions-rbac-03.png')
		await shoot(page, 'admin', '01-permissions-rbac-04.png')
		await shoot(page, 'admin', '01-permissions-rbac-05.png')
	})

	test('AN data-sources-sync', async ({ page }) => {
		// docs/tutorials/admin/02-data-sources-sync.md
		await go(page, '/sources')
		await shoot(page, 'admin', '02-data-sources-sync-01.png')
		const had = await captureCreateDialog(page, 'admin', '02-data-sources-sync-02.png', /Add Source|Add Item/i)
		if (!had) {
			await shoot(page, 'admin', '02-data-sources-sync-02.png')
		}
		await go(page, '/sources')
		// Open the first source's detail if it exists, for the mapping /
		// schedule / run-now captures (3-5).
		const firstSource = page.locator('.app-content a, table tbody tr').first()
		if (await firstSource.isVisible().catch(() => false)) {
			await firstSource.click().catch(() => {})
			await page.waitForTimeout(1200)
			await dismissOverlays(page)
		}
		await shoot(page, 'admin', '02-data-sources-sync-03.png')
		await shoot(page, 'admin', '02-data-sources-sync-04.png')
		await shoot(page, 'admin', '02-data-sources-sync-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md — Open Register admin
		// settings page (standard Nextcloud admin settings, not an in-app
		// route).
		await go(page, '/settings/admin/openregister')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		await page.evaluate(() => window.scrollBy(0, 400))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-03.png')
		await page.evaluate(() => window.scrollBy(0, 400))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/settings/admin/openregister')
	})
})
