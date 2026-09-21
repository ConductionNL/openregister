/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opening hours as administered configuration, in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The walk over the windows has had unit coverage since the day it was
 * written. What nothing covered was whether a window an administrator TYPES
 * ever reaches the walk, and it did not: the schema declared no
 * `serviceHours` property, so the object store dropped the key without a
 * word, and the engine read a calendar that had never heard of opening hours.
 * Every layer reported success.
 *
 * So the assertion here is the read-back. The form is filled in the real
 * admin settings page, saved, and the stored object is read through the API:
 * a field that survives that round trip is declared, and one that does not is
 * the silent drop this spec exists to catch.
 *
 * THE REFUSAL IS PROBED WITH THE LEAST PRIVILEGED PRINCIPAL. A working
 * calendar decides statutory deadlines for everybody on the instance, and the
 * schema grants create and update to admin alone. The last test writes as an
 * ordinary authenticated user and requires a refusal, because a permission
 * asserted only as an admin success is not asserted at all.
 *
 * SELF-CLEANING. The calendar is seeded under a per-run slug and removed in
 * `afterAll`. Nothing here touches the seeded `nl-national` calendar, which
 * declares no service hours on purpose and which other suites depend on.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const ADMIN_ROUTE = '/index.php/settings/admin/openregister'
const API = '/index.php/apps/openregister/api'
const OBJECTS = `${API}/objects/flow-timers/working-calendar`

const RUN_ID = `e2e-hours-${Date.now()}`
const CAL_SLUG = `${RUN_ID}-calendar`

test.describe.configure({ mode: 'serial' })

test.describe('service-hours-admin', () => {
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
				title: `E2E service hours ${RUN_ID}`,
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: 8,
				rules: [{ kind: 'fixed', month: 1, day: 1, name: 'Nieuwjaarsdag' }],
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

	// @e2e flow-business-timers::four-service-hours-from-friday-afternoon-land-on-monday
	// @e2e flow-business-timers::a-closed-midday-is-closed
	test('an administrator types the opening hours and they are stored', async ({
		page,
		request,
	}) => {
		await page.goto(ADMIN_ROUTE, { waitUntil: 'domcontentloaded' })
		await page
			.locator(`[data-testid="working-calendar-edit-${CAL_SLUG}"]`)
			.click()

		const form = page.locator('[data-testid="working-calendar-form"]')
		await expect(form).toBeVisible({ timeout: 30_000 })

		// Monday, split over a closed midday. The split is the case a single
		// opening minute and a day length could never express.
		const monday = form.locator('[data-testid="working-calendar-hours-1"]')
		await expect(monday).toBeVisible()
		await monday.locator('[data-testid="working-calendar-add-window-1"]').click()
		await monday.locator('[data-testid="working-calendar-add-window-1"]').click()

		const windows = monday.locator('[data-testid="working-calendar-window"]')
		await expect(windows).toHaveCount(2)
		await windows.nth(0).locator('input[type="time"]').nth(0).fill('09:00')
		await windows.nth(0).locator('input[type="time"]').nth(1).fill('12:30')
		await windows.nth(1).locator('input[type="time"]').nth(0).fill('13:30')
		await windows.nth(1).locator('input[type="time"]').nth(1).fill('17:00')

		await form.locator('[data-testid="working-calendar-save"]').click()
		await expect(form).toBeHidden({ timeout: 30_000 })

		// 🔴 THE READ-BACK IS THE ASSERTION. A form that closes is not a form
		// that stored anything, and an undeclared property is dropped by the
		// object store with a 200 and no message.
		const stored = await findOurCalendar(request)
		expect(
			stored,
			'the calendar is still readable after the save',
		).not.toBeNull()
		expect(
			stored?.serviceHours?.monday,
			'the opening hours survived the write, so the schema declares them',
		).toEqual([
			{ start: '09:00', end: '12:30' },
			{ start: '13:30', end: '17:00' },
		])
	})

	// @e2e flow-business-timers::an-overlapping-window-is-refused
	test('two windows that overlap on one weekday are refused, naming the weekday', async ({
		request,
	}) => {
		const resp = await request.post(OBJECTS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				slug: `${RUN_ID}-overlap`,
				title: 'Overlapping windows',
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: 8,
				rules: [{ kind: 'fixed', month: 1, day: 1, name: 'Nieuwjaarsdag' }],
				serviceHours: {
					monday: [
						{ start: '09:00', end: '13:00' },
						{ start: '12:00', end: '17:00' },
					],
				},
			},
		})

		expect(
			resp.status(),
			'an overlap double-counts its overlap and every hours term fires early, so it is refused',
		).toBeGreaterThanOrEqual(400)
		expect(await resp.text()).toContain('onday')
	})

	// @e2e flow-business-timers::an-unconfigured-holiday-list-means-no-holidays
	test('a calendar with no holiday list is accepted', async ({ request }) => {
		const slug = `${RUN_ID}-no-holidays`
		const resp = await request.post(OBJECTS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				slug,
				title: 'Open every weekday of the year',
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: 8,
			},
		})

		expect(
			resp.status(),
			'an organisation that keeps no holidays must not be asked to invent one',
		).toBeLessThan(300)

		const body = await resp.json()
		const id = String(body['@self']?.id ?? body['@self']?.uuid ?? body.id)
		await request.delete(`${OBJECTS}/${id}`)
	})

	// @e2e flow-business-timers::an-overlapping-window-is-refused
	test('an ordinary user cannot change the hours everyone is counted against', async () => {
		// The least privileged principal that should be refused. Signed in,
		// but not an administrator: the calendar decides statutory deadlines
		// for the whole instance and the schema grants update to admin alone.
		const asUser = await playwrightRequest.newContext({
			baseURL: process.env.NC_BASE_URL ?? 'http://localhost',
			httpCredentials: {
				username: process.env.NC_USER ?? 'user1',
				password: process.env.NC_USER_PASSWORD ?? 'user1',
			},
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})

		const resp = await asUser.post(OBJECTS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				slug: `${RUN_ID}-by-a-user`,
				title: 'Written by someone who may not',
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: 8,
				serviceHours: { monday: [{ start: '00:00', end: '23:59' }] },
			},
		})

		expect(
			resp.status(),
			'a non-admin write of a working calendar is refused',
		).toBeGreaterThanOrEqual(400)

		await asUser.dispose()
	})
})
