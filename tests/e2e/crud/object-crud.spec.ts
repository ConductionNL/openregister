/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep, data-dependent CRUD journey for OBJECTS — the highest-leverage case.
 *
 * openregister is the data-primitive layer (Registers → Schemas → Objects);
 * this spec proves an object's real field values actually persist and render
 * end-to-end against a freshly-seeded register+schema (so the run is
 * deterministic and isolated from seed drift):
 *
 *   1. SEED a register + a two-property schema (title:string, count:integer)
 *      via the API, and link them.
 *   2. CREATE an object with real field values.
 *   3. READ (detail) — deep-linking to the object renders its persisted uuid
 *                      (data-dependent: proves the specific created object
 *                      loaded) and exposes its Data tab.
 *   4. UPDATE        — change a field; assert the new value persisted (fresh
 *                      GET) AND the detail surface still resolves the object.
 *   5. DELETE        — remove it; GET returns 404.
 *
 * Field-value persistence (title/count) is asserted against the API (fresh GET
 * round-trip) because the deployed manifest-v2 build renders an object's field
 * values inside a CodeMirror editor whose virtualized text layer is not
 * assertable, and the object create/edit UI is a dynamic per-schema CodeMirror
 * JSON form (ViewObject modal) that is not deterministically driveable
 * headlessly. The UI read assertion therefore uses the object's own uuid (real
 * persisted data) as the render signal. The UI-create gap is tracked as
 * test.fixme rather than hidden.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import * as path from 'path'
import {
	makeRunId,
	createRegister,
	createSchema,
	linkSchemaToRegister,
	deleteRegister,
	deleteSchema,
	twoPropertySchema,
	type SeededRegister,
	type SeededSchema,
} from '../_fixtures'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'

const RUN_ID = makeRunId()

test.describe.configure({ mode: 'serial' })

test.describe('object-crud — create→read→update→delete with field-value persistence', () => {
	test.use({ storageState: STORAGE_STATE })

	let register: SeededRegister
	let schema: SeededSchema
	let objectId: string | null = null

	const TITLE_VALUE = `Object ${RUN_ID}`
	const TITLE_UPDATED = `Object ${RUN_ID} EDITED`
	const COUNT_VALUE = 42
	const COUNT_UPDATED = 99

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, RUN_ID)
		schema = await createSchema(request, RUN_ID, 'sch', twoPropertySchema())
		await linkSchemaToRegister(request, register, [schema.id])
	})

	test.afterAll(async ({ request }) => {
		if (objectId) {
			await request.delete(`${API}/objects/${register.id}/${schema.id}/${objectId}`).catch(() => {})
		}
		await deleteSchema(request, schema.id)
		await deleteRegister(request, register.id)
	})

	async function getObjectBody(request: APIRequestContext, id: string) {
		const resp = await request.get(`${API}/objects/${register.id}/${schema.id}/${id}`, {
			headers: { Accept: 'application/json' },
		})
		return { status: resp.status(), body: resp.ok() ? await resp.json() : null }
	}

	test('CREATE an object with real field values (persisted)', async ({ request }) => {
		const resp = await request.post(`${API}/objects/${register.id}/${schema.id}`, {
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			data: { title: TITLE_VALUE, count: COUNT_VALUE },
		})
		expect(resp.status(), 'POST /api/objects/{register}/{schema}').toBeLessThanOrEqual(201)
		const body = await resp.json()
		objectId = body['@self']?.id ?? body.id ?? null
		expect(objectId, 'created object must have an id').toBeTruthy()

		// Persistence: a fresh GET returns the exact field values.
		const { status, body: fresh } = await getObjectBody(request, objectId as string)
		expect(status).toBe(200)
		expect(fresh.title).toBe(TITLE_VALUE)
		expect(Number(fresh.count)).toBe(COUNT_VALUE)
	})

	test('READ (detail) — deep-linking to the object renders its persisted identity', async ({ page }) => {
		test.skip(objectId === null, 'no object created')

		// Deep-link to the object detail (path routing in the manifest-v2 shell).
		await page.goto(`${APP}/#/objects/${register.id}/${schema.id}/${objectId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main, .app-content, #content-vue').first()).toBeVisible({ timeout: 30_000 })

		// The detail surface renders THIS object's real persisted uuid — a
		// data-dependent signal that the specific created object loaded and
		// rendered (not a shell/placeholder). The object's data-tab values live
		// in a CodeMirror editor whose virtualized text layer is not assertable;
		// the field-value persistence is verified against the API below.
		await expect(page.getByText(objectId as string, { exact: false }).first())
			.toBeVisible({ timeout: 20_000 })

		// The Data tab (where field values live) must be present for this object.
		await expect(page.getByText(/^Data$/).first()).toBeVisible({ timeout: 15_000 })
	})

	test('UPDATE — edit fields, assert persistence and the detail still loads', async ({ page, request }) => {
		test.skip(objectId === null, 'no object to update')

		const put = await request.put(`${API}/objects/${register.id}/${schema.id}/${objectId}`, {
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			data: { title: TITLE_UPDATED, count: COUNT_UPDATED },
		})
		expect(put.status(), 'PUT /api/objects/{register}/{schema}/{id}').toBe(200)

		// True persistence via a fresh GET — the field values actually changed.
		const { body: fresh } = await getObjectBody(request, objectId as string)
		expect(fresh.title).toBe(TITLE_UPDATED)
		expect(Number(fresh.count)).toBe(COUNT_UPDATED)

		// The detail surface still resolves the (now-updated) object by uuid.
		await page.goto(`${APP}/#/objects/${register.id}/${schema.id}/${objectId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main, .app-content, #content-vue').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(objectId as string, { exact: false }).first())
			.toBeVisible({ timeout: 20_000 })
	})

	test('DELETE — remove the object and assert it is gone', async ({ request }) => {
		test.skip(objectId === null, 'no object to delete')

		const del = await request.delete(`${API}/objects/${register.id}/${schema.id}/${objectId}`, {
			headers: { Accept: 'application/json' },
		})
		expect([200, 204], 'DELETE object').toContain(del.status())

		const { status } = await getObjectBody(request, objectId as string)
		expect(status, 'deleted object should return 404').toBe(404)
		objectId = null
	})

	// ── Best-effort UI-driven create (tracked gap) ───────────────────────────
	// The Add Object modal (ViewObject.vue) builds a dynamic, per-schema form
	// with a CodeMirror JSON editor. CodeMirror's contenteditable surface is
	// not deterministically fillable via Playwright's .fill()/.type() across
	// versions, so a headless UI create is not reliable. Tracked as fixme so
	// the gap is visible rather than silently skipped. The create PATH itself
	// is fully covered above (real persistence + real UI render of the result).
	test.fixme('UI: create an object through the Add Object modal (CodeMirror form not headlessly driveable)', async () => {
		// Intentionally unimplemented — see comment above. BUG LIST / gap, not a defect.
	})
})
