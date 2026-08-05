/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENUINE behavioural UI e2e for the kanban + calendar object-view
 * presentations ("Tables that scale", PRs #2063/#2098). Seeds a register + a
 * `task` schema (enum `status`, date `dueDate`) with 5 objects, then verifies
 * the shared nextcloud-vue `CnObjectKanban`/`CnObjectCalendar` components
 * actually paint real data on the `/tables` (SearchIndex) surface — not just
 * that the page loaded.
 *
 * The active view on `/tables` is store-driven (no route param), so this
 * spec activates a saved view by reaching into the live Pinia `views` store
 * from the page (see `activatePresentationView` below) rather than driving
 * the sidebar's save/select UI (already covered by
 * `spec-coverage/saved-search-views.spec.ts`).
 *
 * Methodology mirrors `spec-coverage/core-list-pages.spec.ts`: a NOISE[]
 * filter for core-NC console noise, a page.on('console')/page.on('response')
 * >=500 tracker, navigate via the manifest shell, assert real DOM.
 *
 * @e2e openspec/specs/saved-search-views/spec.md
 */
import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import {
	makeRunId,
	createRegister,
	createSchema,
	linkSchemaToRegister,
	createObject,
	deleteSchema,
	deleteRegister,
	type SeededRegister,
	type SeededSchema,
} from '../_fixtures'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')
const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }

const RUN_ID = makeRunId()

const TASK_SCHEMA_PROPERTIES = {
	title: { type: 'string', title: 'Title', description: 'Task title' },
	status: {
		type: 'string',
		title: 'Status',
		description: 'Task status',
		enum: ['todo', 'doing', 'done'],
	},
	dueDate: { type: 'string', title: 'Due date', description: 'Task due date (ISO date)' },
}

/**
 * Seed dueDates on days 1-5 of the CURRENT calendar month (year/month taken
 * from the real clock at test-run time). CnObjectCalendar defaults to
 * `new Date()` for its visible month, so days 1-5 are always inside that
 * default grid regardless of what day-of-month "today" happens to be —
 * no month-navigation click required.
 */
function currentMonthIsoDate(day: number): string {
	const now = new Date()
	const y = now.getFullYear()
	const m = String(now.getMonth() + 1).padStart(2, '0')
	const d = String(day).padStart(2, '0')
	return `${y}-${m}-${d}`
}

const SEED_TASKS = [
	{ title: `${RUN_ID}-task-1`, status: 'todo', dueDate: currentMonthIsoDate(1) },
	{ title: `${RUN_ID}-task-2`, status: 'doing', dueDate: currentMonthIsoDate(2) },
	{ title: `${RUN_ID}-task-3`, status: 'doing', dueDate: currentMonthIsoDate(3) },
	{ title: `${RUN_ID}-task-4`, status: 'doing', dueDate: currentMonthIsoDate(4) },
	{ title: `${RUN_ID}-task-5`, status: 'done', dueDate: currentMonthIsoDate(5) },
]

// ─────────────────────────────────────────────────────────────────────────────
// Console-error / HTTP-5xx noise filter (mirrors core-list-pages.spec.ts)
// ─────────────────────────────────────────────────────────────────────────────
const NOISE = [
	'user_status',
	'heartbeat',
	'Failed to load user status',
	'/apps/activity/',
	'/notifications/api/',
	'dashboard/api/v1/widgets',
	'[AppInit]',
	'Failed to fetch',
	'Failed to load data',
	'Failed to load resource: the server responded with a status of 5',
	// The dev instance polls a hermiq health endpoint that 404s independent
	// of OpenRegister.
	'hermiq',
]

function isNoise(text: string): boolean {
	return NOISE.some((n) => text.includes(n))
}

/** Attach console-error + >=500 collectors that ignore core-NC noise. */
function trackErrors(page: Page): { console: string[]; http: string[] } {
	const errors = { console: [] as string[], http: [] as string[] }
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.console.push(t.slice(0, 160))
	})
	page.on('response', (r) => {
		if (r.status() < 500) return
		const u = r.url()
		if (!isNoise(u)) errors.http.push(`${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`)
	})
	return errors
}

/** Navigate to /tables (SearchIndex) via the manifest shell. */
async function gotoTablesPage(page: Page): Promise<void> {
	await page.goto('/index.php/apps/openregister/tables', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
	await page.waitForSelector('#app-content-vue, .app-content, main', { timeout: 20_000 })
	await page.waitForTimeout(800)
}

interface ActivateResult {
	ok: boolean
	presentationType?: string
	error?: string
}

/**
 * Activate a saved view on the live `/tables` page by reaching into the
 * mounted SearchIndex component's Pinia `viewsStore` and calling
 * `setActiveView()` directly — the active view is store-driven (no route
 * param) so there is no URL to navigate to.
 *
 * Walks the Vue-2 component tree from the app's root mount element
 * (`document.body`'s first element carries `__vue__` on a Vue-2 app) down
 * through `$children` to the component exposing `presentationType`
 * (SearchIndex.vue's own computed — unique in the app), fetches the full
 * view (unwrapping the `{ view: {...} }` GET envelope), sets it active, and
 * awaits `$nextTick()` so the dispatch (`v-if="presentationType === ..."`)
 * has run before the caller asserts on the DOM.
 */
async function activatePresentationView(page: Page, viewId: number | string): Promise<ActivateResult> {
	return page.evaluate(async (id) => {
		function findVueRoot(): any {
			const first = document.body.querySelector('*') as (Element & { __vue__?: any }) | null
			if (first?.__vue__) return first.__vue__.$root
			// Fallback: scan every element for one carrying a Vue-2 instance.
			const all = document.body.querySelectorAll('*')
			for (const el of Array.from(all) as Array<Element & { __vue__?: any }>) {
				if (el.__vue__) return el.__vue__.$root
			}
			return null
		}

		function findSearchIndexVm(vm: any): any {
			if (!vm) return null
			if (vm.presentationType !== undefined) return vm
			if (Array.isArray(vm.$children)) {
				for (const child of vm.$children) {
					const found = findSearchIndexVm(child)
					if (found) return found
				}
			}
			return null
		}

		const root = findVueRoot()
		if (!root) return { ok: false, error: 'Vue root not found under document.body' }

		const vm = findSearchIndexVm(root)
		if (!vm) return { ok: false, error: 'SearchIndex component (presentationType) not found in tree' }

		const viewsStore = vm.$data?.viewsStore ?? vm.viewsStore
		if (!viewsStore) return { ok: false, error: 'viewsStore not exposed on SearchIndex vm' }

		const resp = await fetch(`/index.php/apps/openregister/api/views/${id}`, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		if (!resp.ok) return { ok: false, error: `GET /api/views/${id} failed: ${resp.status}` }
		const body = await resp.json()
		const view = body.view ?? body

		viewsStore.setActiveView(view)
		await vm.$nextTick()

		return { ok: true, presentationType: vm.presentationType }
	}, viewId)
}

test.describe('object-views-kanban-calendar — kanban + calendar presentations render real data', () => {
	test.use({ storageState: STORAGE_STATE })

	let register: SeededRegister
	let schema: SeededSchema
	const objectIds: string[] = []
	let kanbanViewId: number | null = null
	let calendarViewId: number | null = null

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, RUN_ID, 'obj-views-reg')
		schema = await createSchema(request, RUN_ID, 'task', TASK_SCHEMA_PROPERTIES)
		await linkSchemaToRegister(request, register, [schema.id])

		for (const task of SEED_TASKS) {
			const obj = await createObject(request, register.id, schema.id, task)
			objectIds.push(obj.id)
		}

		const kanbanResp = await request.post(`${API}/views`, {
			headers: JSON_HEADERS,
			data: {
				name: `${RUN_ID}-ui-kanban-view`,
				description: 'UI e2e kanban view',
				query: { registers: [String(register.id)], schemas: [String(schema.id)] },
				presentation: {
					viewType: 'kanban',
					kanban: {
						groupByField: 'status',
						columnOrder: ['todo', 'doing', 'done'],
						cardFields: ['title', 'dueDate'],
					},
				},
				isPublic: false,
				isDefault: false,
			},
		})
		expect(kanbanResp.status(), 'seed kanban view').toBeLessThanOrEqual(201)
		const kanbanBody = await kanbanResp.json()
		kanbanViewId = (kanbanBody.view ?? kanbanBody).id ?? null

		const calendarResp = await request.post(`${API}/views`, {
			headers: JSON_HEADERS,
			data: {
				name: `${RUN_ID}-ui-calendar-view`,
				description: 'UI e2e calendar view',
				query: { registers: [String(register.id)], schemas: [String(schema.id)] },
				presentation: {
					viewType: 'calendar',
					calendar: { dateField: 'dueDate' },
				},
				isPublic: false,
				isDefault: false,
			},
		})
		expect(calendarResp.status(), 'seed calendar view').toBeLessThanOrEqual(201)
		const calendarBody = await calendarResp.json()
		calendarViewId = (calendarBody.view ?? calendarBody).id ?? null
	})

	test.afterAll(async ({ request }) => {
		for (const id of objectIds) {
			await request.delete(`${API}/objects/${register.id}/${schema.id}/${id}`).catch(() => {})
		}
		if (kanbanViewId) await request.delete(`${API}/views/${kanbanViewId}`).catch(() => {})
		if (calendarViewId) await request.delete(`${API}/views/${calendarViewId}`).catch(() => {})
		await deleteSchema(request, schema.id)
		await deleteRegister(request, register.id)
	})

	// @e2e openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
	// @e2e openspec/specs/saved-search-views/spec.md#requirement-presentation-components-are-shared-and-wired-not-owned-by-or-req-view-pres-05
	test('activating a kanban view renders CnObjectKanban with real columns and cards', async ({ page }) => {
		test.skip(!kanbanViewId, 'no kanban view seeded')
		const errors = trackErrors(page)

		await gotoTablesPage(page)
		const activation = await activatePresentationView(page, kanbanViewId as number)
		expect(activation.ok, activation.error).toBe(true)
		expect(activation.presentationType).toBe('kanban')

		// The board fetch (GET /api/views/:id/kanban) is async — poll for the
		// real columns to paint (not just a loading spinner).
		const columns = page.locator('.cn-object-kanban__column')
		await expect(columns).toHaveCount(3, { timeout: 20_000 })

		const columnTitles = await page.locator('.cn-object-kanban__column-title').allTextContents()
		expect(columnTitles).toEqual(['todo', 'doing', 'done'])

		const columnCounts = await page.locator('.cn-object-kanban__column-count').allTextContents()
		// Seeded distribution: 1 todo, 3 doing, 1 done.
		expect(columnCounts).toEqual(['1', '3', '1'])

		// Cards render seeded titles — not placeholder/empty cards.
		const cardTitles = await page.locator('.cn-object-kanban__card-title').allTextContents()
		for (const task of SEED_TASKS) {
			expect(cardTitles).toContain(task.title)
		}

		expect(errors.console, `OR console errors: ${errors.console.join(' | ')}`).toHaveLength(0)
		expect(errors.http, `OR 5xx responses: ${errors.http.join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
	// @e2e openspec/specs/saved-search-views/spec.md#requirement-presentation-components-are-shared-and-wired-not-owned-by-or-req-view-pres-05
	test('activating a calendar view renders CnObjectCalendar with objects plotted on their dueDate', async ({ page }) => {
		test.skip(!calendarViewId, 'no calendar view seeded')
		const errors = trackErrors(page)

		await gotoTablesPage(page)
		const activation = await activatePresentationView(page, calendarViewId as number)
		expect(activation.ok, activation.error).toBe(true)
		expect(activation.presentationType).toBe('calendar')

		// The month grid renders synchronously; the day-cell events populate
		// once handleCalendarRangeChange's fetch (triggered by
		// CnObjectCalendar's own `range-change` on mount) resolves.
		await expect(page.locator('.cn-object-calendar__month')).toBeVisible({ timeout: 15_000 })
		// Grid length is 35 or 42 cells depending on how the month pads to
		// whole Sun-Sat weeks — assert a real grid painted, not a fixed count.
		const cellCount = await page.locator('.cn-object-calendar__month-cell').count()
		expect(cellCount).toBeGreaterThanOrEqual(28)

		const eventTexts = page.locator('.cn-object-calendar__event')
		await expect(eventTexts.first()).toBeVisible({ timeout: 20_000 })
		const allEventTexts = await eventTexts.allTextContents()
		for (const task of SEED_TASKS) {
			expect(allEventTexts).toContain(task.title)
		}

		expect(errors.console, `OR console errors: ${errors.console.join(' | ')}`).toHaveLength(0)
		expect(errors.http, `OR 5xx responses: ${errors.http.join(' | ')}`).toHaveLength(0)
	})
})
