/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Connections page: every outside connection OpenRegister has, on one
 * page, with a status integriq can back (adopt-connection-registry).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from lib/Settings/connections.json with `app` equal to `openregister`.
 * OpenRegister writes no row: a test or a save sends integriq a report or a
 * refresh, and integriq decides the status (hydra connection-registry D4). So
 * this spec needs integriq installed and synced, with the D12 amendments. It
 * has not been run in the change that added it.
 *
 * Locale: nothing forces the language of the E2E instance, so statuses are read
 * back over the API and the page is addressed by route, by row title (declared,
 * not translated) and by href.
 *
 * @e2e app-connections::the-declaration-lists-the-sixteen-connections
 * @e2e app-connections::a-settings-link-lands-on-a-section-that-exists
 * @e2e app-connections::a-seam-nothing-calls-reads-not-available
 * @e2e app-connections::the-page-opens-on-openregisters-own-rows
 * @e2e app-connections::add-integration-goes-to-integriq
 * @e2e app-connections::saving-a-github-token-asks-integriq-to-look-again
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const APP_BASE = '/index.php/apps/openregister'
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection'

/** The keys lib/Settings/connections.json declares, in declared order. */
const DECLARED_KEYS = [
	'llm',
	'anonymiser',
	'translation',
	'dsar-identity',
	'dsar-regulator',
	'edepot',
	'github',
	'gitlab',
	'brp',
	'kvk',
	'opencorporates',
	'openproject',
	'xwiki',
	'message-dispatch',
	'pdok',
	'office-converter',
]

/** Rows that link into the admin settings, and the element id each link names. */
const LINKED = {
	llm: 'section-llm',
	anonymiser: 'section-text-extraction',
	github: 'api-tokens',
	gitlab: 'api-tokens',
}

/**
 * Skip when the suite has not produced an admin session.
 */
function requireAuth() {
	if (!fs.existsSync(STORAGE_STATE)) {
		test.skip(true, 'storageState not present, run the full suite first')
	}
}

/**
 * OpenRegister's rows in integriq's registry, keyed by connection key.
 *
 * `app` is a BARE filter key: the objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * @param api The authenticated request context.
 */
async function connectionsByKey(
	api: APIRequestContext,
): Promise<Record<string, any>> {
	const res = await api.get(`${CONNECTIONS_API}?app=openregister&_limit=200`)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, any> = {}
	for (const row of body.results ?? []) {
		// A row from another app here means the filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe(
			'openregister',
		)
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Connections page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openConnections(page: Page) {
	await page.goto(`${APP_BASE}/settings/connections?app=openregister`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Connections', () => {
	test.use({ storageState: STORAGE_STATE })

	let api: APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		api = await playwrightRequest.newContext({
			baseURL,
			storageState: fs.existsSync(STORAGE_STATE) ? STORAGE_STATE : undefined,
		})
	})

	test.afterAll(async () => {
		await api.dispose()
	})

	// @e2e app-connections::the-declaration-lists-the-sixteen-connections
	// @e2e app-connections::the-page-opens-on-openregisters-own-rows
	test('lists the sixteen declared connections, and only OpenRegister rows', async ({
		page,
	}) => {
		requireAuth()
		const byKey = await connectionsByKey(api)
		expect(Object.keys(byKey).sort()).toEqual([...DECLARED_KEYS].sort())

		const orders = DECLARED_KEYS.map((key) => byKey[key].order)
		expect([...orders].sort((a, b) => a - b)).toEqual(orders)

		await openConnections(page)
		for (const key of DECLARED_KEYS) {
			await expect(
				page.getByRole('row', { name: new RegExp(byKey[key].title, 'i') }),
			).toBeVisible()
		}
	})

	// @e2e app-connections::a-settings-link-lands-on-a-section-that-exists
	test('every settings link lands on an element that exists', async ({ page }) => {
		requireAuth()
		const byKey = await connectionsByKey(api)

		for (const [key, anchor] of Object.entries(LINKED)) {
			expect(String(byKey[key].settingsUrl)).toBe(
				`/settings/admin/openregister#${anchor}`,
			)
		}

		await page.goto('/index.php/settings/admin/openregister', {
			waitUntil: 'domcontentloaded',
		})
		for (const anchor of new Set(Object.values(LINKED))) {
			await expect(page.locator(`#${anchor}`)).toHaveCount(1, {
				timeout: 30_000,
			})
		}
	})

	// @e2e app-connections::a-seam-nothing-calls-reads-not-available
	test('the office converter reads Not available and says nothing calls it', async () => {
		requireAuth()
		const converter = (await connectionsByKey(api))['office-converter']
		expect(converter.status).toBe('unavailable')
		expect(String(converter.statusMessage)).toMatch(/nothing calls/i)
	})

	// @e2e app-connections::add-integration-goes-to-integriq
	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		requireAuth()
		await openConnections(page)

		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(
				/\/apps\/integriq\/connections\?app=openregister&link=1$/,
				{ timeout: 30_000 },
			),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})

	// @e2e app-connections::saving-a-github-token-asks-integriq-to-look-again
	test('saving a GitHub token turns the GitHub row Configured', async ({
		page,
	}) => {
		requireAuth()
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		const token = await page.evaluate(
			() => (window as any).OC?.requestToken ?? '',
		)
		const headers = { requesttoken: token, 'Content-Type': 'application/json' }
		const tokensApi = `${APP_BASE}/api/settings/api-tokens`

		// Snapshot what this test changes, so the next run starts from the same page.
		const before = await api.get(tokensApi)
		expect(before.ok()).toBeTruthy()
		const hadToken = String((await before.json()).github_token ?? '') !== ''
		test.skip(
			hadToken,
			'a GitHub token is already saved on this instance; the test would overwrite it',
		)

		try {
			const save = await api.post(tokensApi, {
				headers,
				data: { github_token: 'e2e-connections-placeholder' },
			})
			expect(save.ok(), `save tokens -> ${save.status()}`).toBeTruthy()

			await expect
				.poll(async () => (await connectionsByKey(api)).github.status, {
					timeout: 15_000,
				})
				.toBe('configured')
		} finally {
			await api.post(tokensApi, { headers, data: { github_token: '' } })
		}
	})
})
