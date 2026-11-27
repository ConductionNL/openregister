/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The audit trail page browses, filters and exports entries (REQ-ADS-001), in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The unit tests pin the list query and the export query separately. What
 * they cannot see is the page: that the sidebar's register, schema, action
 * and date range reach the list request, that the list then holds only the
 * matching entries, that the export downloads exactly those entries, and
 * that opening an entry shows the value before and after the change.
 *
 * The fixture is one register with two schemas. An update is made on a
 * `melding` (status `nieuw` to `in behandeling`) and on an object of the other
 * schema, so a filter that is dropped shows up as a second row.
 *
 * The filters are set through the page's route query, which is the address
 * the sidebar writes when a filter is picked. The spec then reads the sidebar
 * back, so it also proves that the sidebar shows what the list is filtered on.
 *
 * SELF-CLEANING. The register, both schemas and their objects are created
 * under a per-run slug and removed in `afterAll`.
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e${Date.now()}`
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' }

/** Create one entity over the API and return its id. */
async function create(request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(url, { headers: JSON_HEADERS, data })
	expect(resp.status(), `POST ${url} is accepted`).toBeLessThan(300)
	const body = await resp.json()
	return String(body.id)
}

test.describe.configure({ mode: 'serial' })

test.describe('audit-trail-page', () => {
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 900 } })
	test.setTimeout(120_000)

	let registerId: string
	let meldingSchema: string
	let otherSchema: string
	const objects: Array<[string, string]> = []

	/** The filtered page address: the route query the sidebar writes. */
	function filteredPage(): string {
		const from = new Date(Date.now() - 86_400_000).toISOString()
		const to = new Date(Date.now() + 86_400_000).toISOString()
		const query = new URLSearchParams({ register: registerId, schema: meldingSchema, action: 'update', dateFrom: from, dateTo: to })
		return `/index.php/apps/openregister/audit-trails?${query.toString()}`
	}

	/** Open the filtered page and wait for the filtered list request. */
	async function openFiltered(page: Page): Promise<number> {
		const listed = page.waitForResponse((resp) => resp.url().includes('/api/audit-trails?') && resp.url().includes(`schema=${meldingSchema}`))
		await page.goto(filteredPage(), { waitUntil: 'domcontentloaded' })
		const resp = await listed
		expect(resp.status(), 'the filtered list answers').toBe(200)
		const url = new URL(resp.url())
		expect(url.searchParams.get('register'), 'the register filter is sent').toBe(registerId)
		expect(url.searchParams.get('action'), 'the action filter is sent').toBe('update')
		expect(new Date(String(url.searchParams.get('dateFrom'))).getTime(), 'the start date is sent as a date').not.toBeNaN()
		expect(new Date(String(url.searchParams.get('dateTo'))).getTime(), 'the end date is sent as a date').not.toBeNaN()
		return (await resp.json()).total
	}

	test.beforeAll(async ({ request }) => {
		meldingSchema = await create(request, `${API}/schemas`, {
			title: `Melding ${RUN_ID}`,
			slug: `${RUN_ID}-melding`,
			properties: { status: { type: 'string' } },
		})
		otherSchema = await create(request, `${API}/schemas`, {
			title: `Klacht ${RUN_ID}`,
			slug: `${RUN_ID}-klacht`,
			properties: { status: { type: 'string' } },
		})
		registerId = await create(request, `${API}/registers`, {
			title: `Meldingen ${RUN_ID}`,
			slug: `${RUN_ID}-meldingen`,
			schemas: [Number(meldingSchema), Number(otherSchema)],
		})

		for (const schema of [meldingSchema, otherSchema]) {
			const id = await create(request, `${API}/objects/${registerId}/${schema}`, { status: 'nieuw' })
			objects.push([schema, id])
			const updated = await request.put(`${API}/objects/${registerId}/${schema}/${id}`, {
				headers: JSON_HEADERS,
				data: { status: 'in behandeling' },
			})
			expect(updated.status(), 'the fixture update is accepted').toBeLessThan(300)
		}
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of objects) {
			await request.delete(`${API}/objects/${registerId}/${schema}/${id}`)
		}
		if (registerId) {
			await request.delete(`${API}/registers/${registerId}`)
		}
		for (const schema of [meldingSchema, otherSchema]) {
			if (schema) {
				await request.delete(`${API}/schemas/${schema}`)
			}
		}
	})

	// @e2e enhanced-audit-trail::an-administrator-filters-on-one-objects-changes
	test('the sidebar filters the list to the update entries of one schema in a date range', async ({ page }) => {
		const total = await openFiltered(page)
		expect(total, 'one update on a melding in this run').toBe(1)

		const rows = page.locator('tbody tr')
		await expect(rows).toHaveCount(1, { timeout: 30_000 })
		await expect(rows.first()).toContainText('UPDATE')
		await expect(rows.first()).toContainText(meldingSchema)

		const sidebar = page.locator('#app-sidebar-vue')
		await expect(sidebar.getByText(`Melding ${RUN_ID}`), 'the sidebar shows the schema').toBeVisible()
		await expect(sidebar.getByText(`Meldingen ${RUN_ID}`), 'the sidebar shows the register').toBeVisible()
		await expect(sidebar.getByText('Update', { exact: true }), 'the sidebar shows the action').toBeVisible()
	})

	// @e2e enhanced-audit-trail::the-export-follows-the-filters
	test('the CSV export holds exactly the filtered entries', async ({ page }) => {
		const total = await openFiltered(page)
		await expect(page.locator('tbody tr')).toHaveCount(total, { timeout: 30_000 })

		const exported = page.waitForResponse((resp) => resp.url().includes('/api/audit-trails/export'))
		const download = page.waitForEvent('download')
		await page.getByRole('button', { name: 'Actions', exact: true }).first().click()
		await page.getByRole('menuitem', { name: 'Export' }).click()

		const resp = await exported
		const url = new URL(resp.url())
		expect(url.searchParams.get('format')).toBe('csv')
		expect(url.searchParams.get('schema'), 'the export carries the schema filter').toBe(meldingSchema)
		expect(url.searchParams.get('action'), 'the export carries the action filter').toBe('update')

		const file = await (await download).path()
		const lines = fs.readFileSync(String(file), 'utf8').split('\n').filter((line) => line.trim() !== '')
		expect(lines[0], 'the file starts with its header').toMatch(/^id,uuid,action,/)
		expect(lines.length - 1, 'the file holds exactly the listed entries').toBe(total)
		expect(lines[1]).toContain(',update,')
	})

	// @e2e enhanced-audit-trail::an-entry-shows-what-changed
	test('opening the update entry shows the old and the new status', async ({ page }) => {
		await openFiltered(page)
		const row = page.locator('tbody tr').first()
		await expect(row).toBeVisible({ timeout: 30_000 })

		await row.getByRole('button').last().click()
		await page.getByRole('menuitem', { name: 'View Changes' }).click()

		const dialog = page.getByRole('dialog').filter({ hasText: 'in behandeling' })
		await expect(dialog, 'the changes dialog shows the new value').toBeVisible({ timeout: 30_000 })
		await expect(dialog).toContainText('nieuw')
		await expect(dialog).toContainText('status')
	})
})
