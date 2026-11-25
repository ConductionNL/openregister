/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CASE-INSENSITIVE ORDERING — the object list sorted by a column header.
 *
 * @e2e case-insensitive-ordering::mixed-case-sorts-as-a-reader-expects
 *
 * openspec/changes/case-insensitive-ordering task 1.4. A reader opens the
 * Tables page, picks a register and schema, clicks the "title" header and reads
 * the rows: "aardbei", "Appel"/"appel", "banaan", "Cherry", where byte order
 * would put every capital first. A second click reverses it.
 *
 * The first click is also the regression this file found: the header used to
 * store its sort as `sortKey`/`sortOrder`, which the search store sends to the
 * API as two unknown property filters, and the list came back EMPTY. The sort
 * now travels as `_order`; the request the click sends is asserted too.
 *
 * Hermetic: it creates its own schema, register and records through the API
 * and removes them. Runs as the administrator from the shared storage state.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)
const SCHEMA_TITLE = `Ordering ${RUN}`
const REGISTER_TITLE = `Ordering register ${RUN}`
const TITLES = ['banaan', 'Appel', 'Cherry', 'aardbei', 'appel']

let api: APIRequestContext
let registerId = ''
let schemaId = ''
const uuids: string[] = []

/** Close the support dialog and the setup wizard when either opens over the page. */
async function clearDialogs(page: Page): Promise<void> {
	const support = page.locator('[data-testid-modal="cn-support-dialog"]')
	if (await support.isVisible().catch(() => false)) {
		await support.getByRole('button', { name: 'Close' }).click().catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {})
	}
}

/** Pick one option in a sidebar select by typing its label. */
async function pick(page: Page, index: number, label: string): Promise<void> {
	const select = page.locator('#tab-search-tab').getByRole('combobox').nth(index)
	await select.click()
	await page.keyboard.type(label)
	await expect(page.getByRole('option', { name: label })).toBeVisible()
	await page.keyboard.press('Enter')
}

/** The title cells of the table, in the order they are shown. */
async function shownTitles(page: Page): Promise<string[]> {
	const header = page.locator('table thead th')
	const names = (await header.allInnerTexts()).map((name) => name.trim())
	const column = names.indexOf('title') + 1
	expect(column, `the table has a title column (${names.join(', ')})`).toBeGreaterThan(0)

	// The first cell of a row also carries the read-state marker ("Unread"): the title is its last line.
	return (await page.locator(`table tbody tr td:nth-child(${column})`).allInnerTexts())
		.map((cell) => cell.split('\n').map((line) => line.trim()).filter((line) => line !== '').pop() ?? '')
}

test.describe.configure({ mode: 'serial' })

test.describe('object list ordered by a column header', () => {
	test.beforeAll(async () => {
		api = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const schema = await api.post(`${API}/schemas`, {
			data: { title: SCHEMA_TITLE, slug: `ordering-${RUN}`, properties: { title: { type: 'string' } } },
		})
		expect(schema.status(), await schema.text()).toBe(201)
		schemaId = String((await schema.json()).id)

		const register = await api.post(`${API}/registers`, {
			data: { title: REGISTER_TITLE, slug: `ordering-${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(register.status(), await register.text()).toBe(201)
		registerId = String((await register.json()).id)

		for (const title of TITLES) {
			const created = await api.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title } })
			expect(created.status(), await created.text()).toBe(201)
			const body = await created.json()
			uuids.push(String(body['@self']?.id ?? body.id))
		}
	})

	test.afterAll(async () => {
		for (const uuid of uuids) {
			await api.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		}
		if (registerId) {
			await api.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId) {
			await api.delete(`${API}/schemas/${schemaId}`)
		}
		await api.dispose()
	})

	test('clicking the title header sorts the rows ignoring letter case', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/tables')
		await clearDialogs(page)
		await pick(page, 0, REGISTER_TITLE)
		await pick(page, 1, SCHEMA_TITLE)
		await expect(page.locator('table tbody tr')).toHaveCount(TITLES.length)

		const header = page.locator('table thead th', { hasText: 'title' })
		const ascending = page.waitForRequest((req) => req.url().includes(`/api/objects/${registerId}/${schemaId}`))
		await header.click()
		const sent = decodeURIComponent((await ascending).url())
		expect(sent, 'the sort goes out as _order').toContain('_order={"title":"asc"}')
		expect(sent, 'no unknown sort params reach the API').not.toContain('sortKey')

		await expect(page.locator('table tbody tr')).toHaveCount(TITLES.length)
		await expect.poll(async () => (await shownTitles(page)).map((title) => title.toLowerCase()))
			.toEqual(['aardbei', 'appel', 'appel', 'banaan', 'cherry'])

		await header.click()
		await expect.poll(async () => (await shownTitles(page)).map((title) => title.toLowerCase()))
			.toEqual(['cherry', 'banaan', 'appel', 'appel', 'aardbei'])
	})
})
