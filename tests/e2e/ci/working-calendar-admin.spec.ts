/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The working-calendar admin surface, in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The arithmetic is covered by PHPUnit, and the API contract by Newman. What
 * neither can see is whether an administrator can REACH any of it. The gap
 * register's note on row 8.12 is exactly that shape: the engine already
 * resolved a calendar, and
 * `grep -ril "holiday\|feestdag\|werkdag\|kalender" src/views/settings/`
 * returned nothing. A settings section that is written but never rendered
 * looks, from every other test, identical to one that works.
 *
 * So this spec opens the real admin settings page, adds a closure day through
 * the real form, previews the year, saves, and reads the value back through
 * the API. The read-back is the assertion: a form that fills and a button that
 * clicks prove nothing if the value never lands.
 *
 * SELF-CLEANING (criterion 2, second branch). The calendar is seeded through
 * the objects API under a per-run slug and removed in `afterAll`, re-resolving
 * by slug when a mid-run failure lost the id. Nothing here touches the seeded
 * `nl-national` calendar, which other suites depend on.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const ADMIN_ROUTE = '/index.php/settings/admin/openregister'
const API = '/index.php/apps/openregister/api'
const OBJECTS = `${API}/objects/flow-timers/working-calendar`

const RUN_ID = `e2e-${Date.now()}`
const CAL_SLUG = `${RUN_ID}-calendar`
const CLOSURE_DATE = '2027-05-05'
const CLOSURE_NAME = 'Lokale sluitingsdag'

/** The rules a Dutch municipal calendar declares. */
const RULES = [
	{ kind: 'fixed', month: 1, day: 1, name: 'Nieuwjaarsdag' },
	{ kind: 'easter', offset: -2, name: 'Goede Vrijdag' },
	{ kind: 'easter', offset: 1, name: 'Tweede Paasdag' },
	{
		kind: 'fixed',
		month: 4,
		day: 27,
		name: 'Koningsdag',
		observedShift: { whenWeekday: 'sunday', days: -1 },
	},
	{ kind: 'easter', offset: 39, name: 'Hemelvaartsdag' },
	{ kind: 'easter', offset: 50, name: 'Tweede Pinksterdag' },
	{ kind: 'fixed', month: 12, day: 25, name: 'Eerste Kerstdag' },
	{ kind: 'fixed', month: 12, day: 26, name: 'Tweede Kerstdag' },
]

test.describe.configure({ mode: 'serial' })

test.describe('working-calendar-admin', () => {
	test.use({ storageState: STORAGE_STATE })

	let calendarId: string | null = null

	/** Resolve our calendar by slug, so an aborted run still tears down. */
	async function findOurCalendar(
		request: APIRequestContext,
	): Promise<Record<string, any> | null> {
		const resp = await request.get(`${OBJECTS}?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		if (!resp.ok()) return null
		const body = await resp.json()
		return (body.results ?? []).find((row: any) => row.slug === CAL_SLUG) ?? null
	}

	test.beforeAll(async ({ request }) => {
		const resp = await request.post(OBJECTS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				slug: CAL_SLUG,
				title: `E2E working calendar ${RUN_ID}`,
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: 8,
				rules: RULES,
				exceptions: [],
			},
		})
		expect(
			resp.status(),
			'the fixture calendar must be accepted by the write guard',
		).toBeLessThan(300)
		const body = await resp.json()
		calendarId = String(body['@self']?.id ?? body['@self']?.uuid ?? body.id)
	})

	test.afterAll(async ({ request }) => {
		if (calendarId === null) {
			const existing = await findOurCalendar(request)
			calendarId = existing
				? String(existing['@self']?.id ?? existing['@self']?.uuid)
				: null
		}
		if (calendarId !== null) {
			await request.delete(`${OBJECTS}/${calendarId}`)
		}
	})

	// @e2e flow-business-timers::an-administrator-adds-a-local-closure-day
	test('the section lists the calendars an administrator may edit', async ({
		page,
	}) => {
		await page.goto(ADMIN_ROUTE, { waitUntil: 'domcontentloaded' })

		const table = page.locator('[data-testid="working-calendars-table"]')
		await expect(table).toBeVisible({ timeout: 30_000 })

		// The seeded national calendar is the one every app falls back to, so
		// its absence from this list is the defect, not a detail.
		await expect(
			page.locator('[data-testid="working-calendar-row-nl-national"]'),
		).toBeVisible()
		await expect(
			page.locator(`[data-testid="working-calendar-row-${CAL_SLUG}"]`),
		).toBeVisible()
	})

	// @e2e flow-business-timers::an-administrator-adds-a-local-closure-day
	test('an administrator adds a closure day, previews the year and saves it', async ({
		page,
		request,
	}) => {
		await page.goto(ADMIN_ROUTE, { waitUntil: 'domcontentloaded' })
		await page
			.locator(`[data-testid="working-calendar-edit-${CAL_SLUG}"]`)
			.click()

		const form = page.locator('[data-testid="working-calendar-form"]')
		await expect(form).toBeVisible({ timeout: 30_000 })

		await form.locator('[data-testid="working-calendar-add-exception"]').click()
		const row = form.locator('[data-testid="working-calendar-exception"]').last()
		await row.locator('input[type="date"]').fill(CLOSURE_DATE)
		await row.locator('input[type="text"]').first().fill(CLOSURE_NAME)

		// Preview BEFORE saving: the whole point of the endpoint is that an
		// administrator can read what a rule means while it is still a draft.
		await form
			.locator('[data-testid="working-calendar-preview-year"] input')
			.fill('2027')
		await form.locator('[data-testid="working-calendar-preview"]').click()

		const preview = form.locator(
			'[data-testid="working-calendar-preview-dates"]',
		)
		await expect(preview).toBeVisible({ timeout: 30_000 })
		// Easter 2027 is 28 March, so Goede Vrijdag is the 26th. A tabulated
		// calendar would place it on 2026's date and nothing would say so.
		await expect(preview).toContainText('2027-03-26')
		await expect(preview).toContainText(CLOSURE_DATE)

		await form.locator('[data-testid="working-calendar-save"]').click()
		await expect(form).toBeHidden({ timeout: 30_000 })

		// The read-back is the assertion. A form that closes is not a form
		// that stored anything.
		const stored = await findOurCalendar(request)
		expect(
			stored,
			'the calendar is still readable after the save',
		).not.toBeNull()
		const dates = (stored?.exceptions ?? []).map((entry: any) => entry.date)
		expect(dates, 'the closure day landed').toContain(CLOSURE_DATE)
	})

	// @e2e flow-business-timers::an-enumerated-only-calendar-is-refused-over-the-api
	test('a calendar of nothing but dates is refused, and the reason is shown', async ({
		page,
	}) => {
		await page.goto(ADMIN_ROUTE, { waitUntil: 'domcontentloaded' })
		await page
			.locator(`[data-testid="working-calendar-edit-${CAL_SLUG}"]`)
			.click()

		const form = page.locator('[data-testid="working-calendar-form"]')
		await expect(form).toBeVisible({ timeout: 30_000 })

		// Strip every rule. What is left is a list of dates, which has an
		// expiry date and degrades silently past it.
		const removals = await form
			.locator('[data-testid="working-calendar-rule"]')
			.count()
		for (let index = 0; index < removals; index++) {
			await form
				.locator('[data-testid="working-calendar-rule"]')
				.first()
				.getByRole('button')
				.last()
				.click()
		}

		await form.locator('[data-testid="working-calendar-save"]').click()

		// The form stays open and names the refusal. A dialog that closes on a
		// rejected save tells the administrator their edit landed.
		await expect(form).toBeVisible()
		await expect(form).toContainText('declare computed rules', {
			timeout: 30_000,
		})
	})
})
