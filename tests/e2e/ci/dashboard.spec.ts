/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app opens on a dashboard of registers, counts and activity (REQ-ADS-002), in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The component tests render the KPI cards from stubbed stores. They cannot
 * see that the cards show what the server counts, or that changing the date
 * range asks the server for that range and shows its events. So this spec
 * seeds two registers with five objects, opens the app's root route and
 * compares each card with the API it is drawn from, then picks "Last 30
 * days" and compares the Events card with the chart the page fetched.
 *
 * The instance is shared with the other specs, so the cards are compared with
 * the server's figures read at the same moment, not with fixed numbers; the
 * seed itself is asserted as a difference of exactly five objects.
 *
 * SELF-CLEANING. The registers, the schema and the objects are created under
 * a per-run slug and removed in `afterAll`.
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e${Date.now()}`
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' }

/** Create one entity over the API and return its id. */
async function create(request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(url, { headers: JSON_HEADERS, data })
	expect(resp.status(), `POST ${url} is accepted`).toBeLessThan(300)
	return String((await resp.json()).id)
}

/** The instance's object total, as the dashboard's System Totals row states it. */
async function objectTotal(request: APIRequestContext): Promise<number> {
	const resp = await request.get(`${API}/dashboard`, { headers: { Accept: 'application/json' } })
	expect(resp.status()).toBe(200)
	const totals = (await resp.json()).registers.find((row: { title: string }) => row.title === 'System Totals')
	return Number(totals?.stats?.objects?.total ?? 0)
}

/** The number of rows a list endpoint answers. */
async function listCount(request: APIRequestContext, url: string): Promise<number> {
	const resp = await request.get(url, { headers: { Accept: 'application/json' } })
	expect(resp.status()).toBe(200)
	return (await resp.json()).results.length
}

/** The value of one KPI card, as a number. */
async function kpi(page: Page, label: string): Promise<number> {
	const value = page.locator('.kpi-card').filter({ has: page.locator('.kpi-label', { hasText: new RegExp(`^${label}$`) }) }).locator('.kpi-value')
	await expect(value).toBeVisible({ timeout: 30_000 })
	return Number((await value.innerText()).replace(/[^\d]/g, ''))
}

test.describe.configure({ mode: 'serial' })

test.describe('dashboard', () => {
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
	test.setTimeout(120_000)

	let schemaId: string
	const registers: string[] = []
	const objects: Array<[string, string]> = []
	let before = 0

	test.beforeAll(async ({ request }) => {
		before = await objectTotal(request)
		schemaId = await create(request, `${API}/schemas`, {
			title: `Melding ${RUN_ID}`,
			slug: `${RUN_ID}-melding`,
			properties: { omschrijving: { type: 'string' } },
		})
		for (const [suffix, count] of [['noord', 3], ['zuid', 2]] as Array<[string, number]>) {
			const registerId = await create(request, `${API}/registers`, {
				title: `Meldingen ${suffix} ${RUN_ID}`,
				slug: `${RUN_ID}-${suffix}`,
				schemas: [Number(schemaId)],
			})
			registers.push(registerId)
			for (let i = 0; i < count; i++) {
				objects.push([registerId, await create(request, `${API}/objects/${registerId}/${schemaId}`, { omschrijving: `${suffix} ${i}` })])
			}
		}
	})

	test.afterAll(async ({ request }) => {
		for (const [registerId, id] of objects) {
			await request.delete(`${API}/objects/${registerId}/${schemaId}/${id}`)
		}
		for (const registerId of registers) {
			await request.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
	})

	// @e2e built-in-dashboards::a-user-opens-the-app
	test('the cards show the objects, registers and schemas the server counts', async ({ page, request }) => {
		const objectsNow = await objectTotal(request)
		expect(objectsNow - before, 'the five seeded objects are counted').toBe(5)
		const registerCount = await listCount(request, `${API}/registers?_limit=1000`)
		const schemaCount = await listCount(request, `${API}/schemas?_limit=1000`)

		await page.goto('/index.php/apps/openregister/', { waitUntil: 'domcontentloaded' })

		expect(await kpi(page, 'Objects'), 'the Objects card').toBe(objectsNow)
		expect(await kpi(page, 'Registers'), 'the Registers card').toBe(registerCount)
		expect(await kpi(page, 'Schemas'), 'the Schemas card').toBe(schemaCount)
		await expect(page.locator('.kpi-card', { hasText: 'Objects' }).locator('.kpi-sub')).toHaveText(`across ${registerCount.toLocaleString()} registers`)
	})

	// @e2e built-in-dashboards::the-user-changes-the-date-range
	test('picking the last 30 days shows the events of the last 30 days, today included', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/', { waitUntil: 'domcontentloaded' })
		const events = page.locator('.kpi-card').filter({ has: page.locator('.kpi-label', { hasText: /^Events$/ }) })
		await expect(events).toBeVisible({ timeout: 30_000 })

		const charted = page.waitForResponse((resp) => resp.url().includes('/api/dashboard/charts/audit-trail-actions') && resp.url().includes('from='))
		await events.getByRole('button').first().click()
		await page.getByRole('menuitem', { name: 'Last 30 days' }).click()

		const resp = await charted
		const url = new URL(resp.url())
		const from = String(url.searchParams.get('from'))
		const till = String(url.searchParams.get('till'))
		const days = (Date.parse(till) - Date.parse(from)) / 86_400_000
		expect(days, 'the range asked for is thirty days').toBe(30)
		expect(till, 'the range ends today').toBe(new Date().toISOString().slice(0, 10))

		const chart = await resp.json()
		const sum = chart.series.reduce((total: number, s: { data: number[] }) => total + s.data.reduce((a, b) => a + (b || 0), 0), 0)
		expect(sum, 'the five creates of today are in the range').toBeGreaterThanOrEqual(5)
		await expect(events.locator('.kpi-value')).toHaveText(sum.toLocaleString())
	})
})
