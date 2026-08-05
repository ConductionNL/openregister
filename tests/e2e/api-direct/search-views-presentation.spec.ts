/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Kanban + calendar view-presentation e2e tests — REST contract for the
 * "Tables that scale" feature (kanban/calendar object views shipped in
 * PRs #2063/#2098).
 *
 * Seeds a register + a `task` schema (enum `status` + date `dueDate`) with
 * 5 objects spread across statuses/dates, then exercises:
 *   - presentation persistence round-trip (POST /api/views, GET /api/views/:id)
 *   - GET /api/views/:id/kanban board shape (columns in columnOrder + cards)
 *   - the guarded object write path driving a kanban "move" (no bespoke
 *     move endpoint — PATCH /api/objects/{register}/{schema}/{id})
 *   - GET /api/views/:id/calendar date-range query
 *   - presentation validation (reject an unrenderable groupByField)
 *
 * @e2e openspec/specs/saved-search-views/spec.md
 */
import { test, expect } from '@playwright/test'
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

const RUN_ID = makeRunId()
const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }

/** The task schema: enum `status` (kanban groupByField) + date `dueDate` (calendar dateField). */
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

/** 5 seeded tasks: 1 todo, 3 doing, 1 done — spread over dueDate 2026-08-01..05. */
const SEED_TASKS = [
	{ title: `${RUN_ID}-task-1`, status: 'todo', dueDate: '2026-08-01' },
	{ title: `${RUN_ID}-task-2`, status: 'doing', dueDate: '2026-08-02' },
	{ title: `${RUN_ID}-task-3`, status: 'doing', dueDate: '2026-08-03' },
	{ title: `${RUN_ID}-task-4`, status: 'doing', dueDate: '2026-08-04' },
	{ title: `${RUN_ID}-task-5`, status: 'done', dueDate: '2026-08-05' },
]

test.describe('saved-search-views — kanban + calendar presentation (REST)', () => {
	let register: SeededRegister
	let schema: SeededSchema
	const objectIds: string[] = []
	let kanbanViewId: number | string | null = null
	let calendarViewId: number | string | null = null

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, RUN_ID, 'kanban-reg')
		schema = await createSchema(request, RUN_ID, 'task', TASK_SCHEMA_PROPERTIES)
		await linkSchemaToRegister(request, register, [schema.id])

		for (const task of SEED_TASKS) {
			const obj = await createObject(request, register.id, schema.id, task)
			objectIds.push(obj.id)
		}
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

	// ─────────────────────────────────────────────────────────────────────
	// REQ-VIEW-PRES-01 — persist + round-trip a validated presentation config
	// ─────────────────────────────────────────────────────────────────────
	test('POST /api/views persists a kanban presentation and GET returns it (REQ-VIEW-PRES-01)', async ({ request }) => {
		const createResp = await request.post(`${API}/views`, {
			headers: JSON_HEADERS,
			data: {
				name: `${RUN_ID}-kanban-view`,
				description: 'E2E kanban view',
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
		expect(createResp.status(), 'POST /api/views (kanban)').toBeLessThanOrEqual(201)
		const createBody = await createResp.json()
		const createdView = createBody.view ?? createBody
		kanbanViewId = createdView.id ?? null
		expect(kanbanViewId, 'kanban view must have an id').toBeTruthy()
		expect(createdView.presentation?.viewType).toBe('kanban')

		// Single-view GET wraps the view under a `view` key (unlike the flat
		// list envelope) — unwrap both shapes defensively.
		const getResp = await request.get(`${API}/views/${kanbanViewId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(getResp.status(), 'GET /api/views/:id (kanban)').toBe(200)
		const getBody = await getResp.json()
		const fetchedView = getBody.view ?? getBody
		expect(fetchedView.presentation?.viewType, 'presentation.viewType survives the round-trip').toBe('kanban')
		expect(fetchedView.presentation?.kanban?.groupByField).toBe('status')
		expect(fetchedView.presentation?.kanban?.columnOrder).toEqual(['todo', 'doing', 'done'])
	})

	// ─────────────────────────────────────────────────────────────────────
	// REQ-VIEW-KANBAN-02 — kanban columns + cards
	// ─────────────────────────────────────────────────────────────────────
	test('GET /api/views/:id/kanban returns columns in columnOrder with cards (REQ-VIEW-KANBAN-02)', async ({ request }) => {
		test.skip(!kanbanViewId, 'no kanban view created in this run')
		const resp = await request.get(`${API}/views/${kanbanViewId}/kanban`, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'GET /api/views/:id/kanban').toBe(200)
		const body = await resp.json()
		expect(body.viewType).toBe('kanban')
		expect(Array.isArray(body.columns)).toBe(true)
		// Columns come back as an ordered array (not keyed by value) — order
		// must match the configured columnOrder.
		expect(body.columns.map((c: { value: string }) => c.value)).toEqual(['todo', 'doing', 'done'])

		const todoColumn = body.columns.find((c: { value: string }) => c.value === 'todo')
		const doingColumn = body.columns.find((c: { value: string }) => c.value === 'doing')
		const doneColumn = body.columns.find((c: { value: string }) => c.value === 'done')
		expect(Array.isArray(todoColumn.cards)).toBe(true)
		expect(todoColumn.total).toBeGreaterThanOrEqual(1)
		expect(doingColumn.total).toBeGreaterThanOrEqual(3)
		expect(doneColumn.total).toBeGreaterThanOrEqual(1)
	})

	// ─────────────────────────────────────────────────────────────────────
	// REQ-VIEW-KANBAN-03 — a "move" rides the guarded object write path
	// ─────────────────────────────────────────────────────────────────────
	test('Guarded write moves a card between columns and it persists (REQ-VIEW-KANBAN-03)', async ({ request }) => {
		// Move the first seeded object (status=todo) to 'doing' — there is no
		// bespoke "move card" endpoint; the write rides the normal guarded
		// object PATCH.
		const movedId = objectIds[0]
		const patchResp = await request.patch(`${API}/objects/${register.id}/${schema.id}/${movedId}`, {
			headers: JSON_HEADERS,
			data: { status: 'doing' },
		})
		expect(patchResp.status(), 'PATCH object status').toBeLessThan(300)

		// A follow-up GET must show the persisted new status.
		const getResp = await request.get(`${API}/objects/${register.id}/${schema.id}/${movedId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(getResp.status()).toBe(200)
		const body = await getResp.json()
		expect(body.status).toBe('doing')

		// Move it back so the remaining kanban/calendar assertions in this
		// file see the originally-seeded distribution.
		await request.patch(`${API}/objects/${register.id}/${schema.id}/${movedId}`, {
			headers: JSON_HEADERS,
			data: { status: 'todo' },
		})
	})

	// ─────────────────────────────────────────────────────────────────────
	// REQ-VIEW-CAL-04 — calendar plots objects by date field over a range
	// ─────────────────────────────────────────────────────────────────────
	test('POST /api/views persists a calendar presentation (REQ-VIEW-PRES-01 / REQ-VIEW-CAL-04)', async ({ request }) => {
		const resp = await request.post(`${API}/views`, {
			headers: JSON_HEADERS,
			data: {
				name: `${RUN_ID}-calendar-view`,
				description: 'E2E calendar view',
				query: { registers: [String(register.id)], schemas: [String(schema.id)] },
				presentation: {
					viewType: 'calendar',
					calendar: { dateField: 'dueDate' },
				},
				isPublic: false,
				isDefault: false,
			},
		})
		expect(resp.status(), 'POST /api/views (calendar)').toBeLessThanOrEqual(201)
		const body = await resp.json()
		const createdView = body.view ?? body
		calendarViewId = createdView.id ?? null
		expect(calendarViewId, 'calendar view must have an id').toBeTruthy()
		expect(createdView.presentation?.viewType).toBe('calendar')
		expect(createdView.presentation?.calendar?.dateField).toBe('dueDate')
	})

	test('GET /api/views/:id/calendar returns objects in range carrying dueDate (REQ-VIEW-CAL-04)', async ({ request }) => {
		test.skip(!calendarViewId, 'no calendar view created in this run')
		const resp = await request.get(
			`${API}/views/${calendarViewId}/calendar?start=2026-08-01&end=2026-08-31`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'GET /api/views/:id/calendar').toBe(200)
		const body = await resp.json()
		expect(body.viewType).toBe('calendar')
		expect(body.dateField).toBe('dueDate')
		expect(Array.isArray(body.objects)).toBe(true)
		// All 5 seeded tasks carry a dueDate within the requested range.
		expect(body.objects.length).toBeGreaterThanOrEqual(SEED_TASKS.length)
		for (const obj of body.objects as Array<{ dueDate?: string }>) {
			if (obj.dueDate) {
				expect(obj.dueDate >= '2026-08-01' && obj.dueDate <= '2026-08-31').toBe(true)
			}
		}

		// Narrowing the range excludes tasks outside it.
		const narrowResp = await request.get(
			`${API}/views/${calendarViewId}/calendar?start=2026-08-01&end=2026-08-01`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(narrowResp.status()).toBe(200)
		const narrowBody = await narrowResp.json()
		for (const obj of narrowBody.objects as Array<{ dueDate?: string }>) {
			expect(obj.dueDate).toBe('2026-08-01')
		}
	})

	// ─────────────────────────────────────────────────────────────────────
	// REQ-VIEW-PRES-01 — reject an unrenderable presentation
	// ─────────────────────────────────────────────────────────────────────
	test('POST /api/views rejects a kanban groupByField that is not a schema property (REQ-VIEW-PRES-01)', async ({ request }) => {
		const resp = await request.post(`${API}/views`, {
			headers: JSON_HEADERS,
			data: {
				name: `${RUN_ID}-invalid-kanban-view`,
				description: 'Should be rejected',
				query: { registers: [String(register.id)], schemas: [String(schema.id)] },
				presentation: {
					viewType: 'kanban',
					kanban: { groupByField: 'not_a_real_property' },
				},
				isPublic: false,
				isDefault: false,
			},
		})
		expect(resp.status(), 'invalid groupByField must be rejected').toBeGreaterThanOrEqual(400)
		expect(resp.status()).toBeLessThan(500)
	})
})
