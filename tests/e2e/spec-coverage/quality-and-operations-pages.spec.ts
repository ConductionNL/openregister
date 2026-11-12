import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE DATA QUALITY, ENTITIES AND OPERATIONS PAGES, DRIVEN IN A BROWSER.
 *
 * Seven manifest pages shipped with no executed browser test: the entities
 * list, the operations console and the five data quality pages. Their only
 * screenshots live in tests/e2e/visual/**, which the CI config does not
 * collect, so nothing CI runs had ever opened them.
 *
 * Each test loads the page by its real route, as the admin, and asserts what
 * that page draws on a fresh instance with no data of its own:
 *
 *   - its own h1, by its translated name;
 *   - the state it settles in: the register and schema prompt for the pages
 *     that need a selection, and a finished load (no spinner left) for the
 *     ones that fetch on mount;
 *   - no error card, no OpenRegister console error and no failed request.
 *
 * Routes are imported by COMPONENT NAME from ../_page-routes.ts, so each test
 * names the page host it drives.
 *
 * Against the CI config's four admission criteria: hermetic (no occ, no
 * docker, no seed), read-only (it navigates and reads, nothing is written),
 * no conditional assertion, and no test.skip.
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
import {
	DuplicatesIndex,
	EntitiesIndex,
	MasterEntitiesIndex,
	MergeOperationsIndex,
	OperationsConsoleIndex,
	QualityIndex,
	QueueHealthIndex,
} from '../_page-routes.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

/*
 * Core Nextcloud noise, not OpenRegister regressions. The same list
 * core-list-pages.spec.ts keeps, for the same reasons; see that file.
 */
const NOISE = [
	'user_status',
	'heartbeat',
	'Failed to load user status',
	'/apps/activity/',
	'/notifications/api/',
	'dashboard/api/v1/widgets',
	'[AppInit]',
	'Failed to fetch',
	'Failed to load resource: the server responded with a status of 5',
	'Failed to load resource: the server responded with a status of 404',
	'/apps/hermiq/',
]

function isNoise(text: string): boolean {
	return NOISE.some((n) => text.includes(n))
}

/** Collect OpenRegister console errors and failed requests, by URL. */
function trackErrors(page: Page): { console: string[]; http: string[] } {
	const errors = { console: [] as string[], http: [] as string[] }
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.console.push(t.slice(0, 160))
	})
	page.on('response', (r) => {
		if (r.status() < 400) return
		const u = r.url()
		if (!isNoise(u))
			errors.http.push(`${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`)
	})
	return errors
}

/** Load a page by route and wait for its own h1. */
async function openPage(page: Page, route: string, heading: RegExp): Promise<void> {
	await page.goto(`/index.php/apps/openregister${route}`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.getByRole('heading', { level: 1, name: heading }).first(),
	).toBeVisible({
		timeout: 25_000,
	})
}

/** The page finished its load and shows no error card. */
async function expectSettled(page: Page): Promise<void> {
	await expect(
		page.locator('#app-content-vue .loading-icon, .app-content .loading-icon'),
	).toHaveCount(0, {
		timeout: 20_000,
	})
	await expect(
		page.locator('.notecard--error, .notecard.notecard--error'),
	).toHaveCount(0)
}

/** The page asks for a register and schema before it shows anything. */
async function expectSelectionPrompt(page: Page): Promise<void> {
	await expect(page.getByText('Select a register and schema').first()).toBeVisible(
		{ timeout: 15_000 },
	)
}

function expectNoErrors(e: { console: string[]; http: string[] }): void {
	expect(e.console, `console errors: ${e.console.join(' | ')}`).toHaveLength(0)
	expect(e.http, `failed requests: ${e.http.join(' | ')}`).toHaveLength(0)
}

test.describe('data quality, entities and operations pages', () => {
	test.use({ storageState: STORAGE_STATE })

	test('EntitiesIndex renders its heading and settles', async ({ page }) => {
		const e = trackErrors(page)
		await openPage(page, EntitiesIndex, /^Entities$/)
		await expectSettled(page)
		expectNoErrors(e)
	})

	test('OperationsConsoleIndex renders its heading and its panes', async ({
		page,
	}) => {
		const e = trackErrors(page)
		await openPage(page, OperationsConsoleIndex, /^Operations$/)
		await expectSettled(page)
		await expect(page.locator('.paneCard').first()).toBeVisible({
			timeout: 15_000,
		})
		expectNoErrors(e)
	})

	test('QualityIndex asks for a register and schema', async ({ page }) => {
		const e = trackErrors(page)
		await openPage(page, QualityIndex, /^Data Quality$/)
		await expectSelectionPrompt(page)
		expectNoErrors(e)
	})

	test('DuplicatesIndex asks for a register and schema', async ({ page }) => {
		const e = trackErrors(page)
		await openPage(page, DuplicatesIndex, /^Duplicate Candidates$/)
		await expectSelectionPrompt(page)
		expectNoErrors(e)
	})

	test('MasterEntitiesIndex asks for a register and schema', async ({ page }) => {
		const e = trackErrors(page)
		await openPage(page, MasterEntitiesIndex, /^Master entities$/)
		await expectSelectionPrompt(page)
		expectNoErrors(e)
	})

	test('QueueHealthIndex renders its heading and settles', async ({ page }) => {
		const e = trackErrors(page)
		await openPage(page, QueueHealthIndex, /^Queue \/ sync health$/)
		await expectSettled(page)
		expectNoErrors(e)
	})

	test('MergeOperationsIndex renders its heading and settles', async ({
		page,
	}) => {
		const e = trackErrors(page)
		await openPage(page, MergeOperationsIndex, /^Merge Operations$/)
		await expectSettled(page)
		expectNoErrors(e)
	})
})
