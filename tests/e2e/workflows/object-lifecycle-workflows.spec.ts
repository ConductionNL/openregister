import type { APIRequestContext } from '@playwright/test'
import type { SeededRegister, SeededSchema } from '../_fixtures.ts'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Core object-lifecycle WORKFLOWS — deep, data-dependent:
 *
 *   (a) Audit Trail — creating and then updating an object records audit-trail
 *       entries (action=create, action=update) that surface both through the
 *       object's audit-trail API and the global audit-trails view.
 *
 *   (b) Soft-delete / Restore — deleting an object should move it to the
 *       "Deleted" list and restoring it should bring it back. NOTE: on the
 *       build under test this workflow surfaced REAL DEFECTS (see the
 *       test.fixme blocks + the BUG LIST in the spec header below) — the
 *       happy-path assertions are encoded so the workflow is regression-locked
 *       once the backend is fixed.
 *
 * Everything is seeded fresh (register + schema + object) and torn down in
 * afterAll, so the run is deterministic and self-cleaning.
 *
 * ── BUG LIST (found by this data-dependent workflow layer; the shell tests
 *    never exercised it) — observed on openregister 0.2.13-unstable.86 ──
 *
 *   BUG-1  Soft-deleted objects never appear in GET /api/deleted
 *          (`{results:[],total:0}`) and GET /api/deleted/statistics reports
 *          `totalDeleted:0`, even though the object IS soft-deleted (its
 *          register/schema GET returns 404). The Deleted view is therefore
 *          always empty → users cannot find or restore deleted objects.
 *
 *   BUG-2  POST /api/deleted/{uuid}/restore returns 200
 *          `{"success":true,"message":"Object restored successfully"}` but the
 *          object is NOT restored — a subsequent GET of the object still
 *          returns 404. Restore is a silent no-op.
 *
 *   BUG-3  GET /api/objects/{register}/{schema}?_includeDeleted=true returns
 *          HTTP 500 (cannot list including soft-deleted objects).
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
import {
	createObject,
	createRegister,
	createSchema,
	deleteRegister,
	deleteSchema,
	linkSchemaToRegister,
	makeRunId,
	twoPropertySchema,
} from '../_fixtures.ts'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'

const RUN_ID = makeRunId()

test.describe.configure({ mode: 'serial' })

// ─────────────────────────────────────────────────────────────────────────────
// (a) Audit Trail records create + update
// ─────────────────────────────────────────────────────────────────────────────
test.describe('workflow: audit-trail records create and update', () => {
	test.use({ storageState: STORAGE_STATE })

	let register: SeededRegister
	let schema: SeededSchema
	let objectId: string

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, `${RUN_ID}-audit`)
		schema = await createSchema(
			request,
			`${RUN_ID}-audit`,
			'sch',
			twoPropertySchema(),
		)
		await linkSchemaToRegister(request, register, [schema.id])
		const obj = await createObject(request, register.id, schema.id, {
			title: `Audit ${RUN_ID}`,
			count: 1,
		})
		objectId = obj.id
	})

	test.afterAll(async ({ request }) => {
		await request
			.delete(`${API}/objects/${register.id}/${schema.id}/${objectId}`)
			.catch(() => {})
		await deleteSchema(request, schema.id)
		await deleteRegister(request, register.id)
	})

	async function getTrails(
		request: APIRequestContext,
	): Promise<Array<Record<string, any>>> {
		const resp = await request.get(
			`${API}/objects/${register.id}/${schema.id}/${objectId}/audit-trails`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'GET object audit-trails').toBe(200)
		const body = await resp.json()
		return body.results ?? (Array.isArray(body) ? body : [])
	}

	test('a "create" audit-trail entry exists after object creation', async ({
		request,
	}) => {
		const trails = await getTrails(request)
		expect(
			trails.length,
			'at least one audit entry after create',
		).toBeGreaterThanOrEqual(1)
		const actions = trails.map((t) => t.action)
		expect(actions, 'a create action must be recorded').toContain('create')
	})

	test('updating the object records a new "update" audit-trail entry', async ({
		request,
	}) => {
		const before = await getTrails(request)

		const put = await request.put(
			`${API}/objects/${register.id}/${schema.id}/${objectId}`,
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: { title: `Audit ${RUN_ID} v2`, count: 2 },
			},
		)
		expect(put.status(), 'PUT to trigger update audit').toBe(200)

		// The trail must now contain an update entry and have grown.
		await expect
			.poll(
				async () => {
					const after = await getTrails(request)
					return (
						after.some((t) => t.action === 'update')
						&& after.length > before.length
					)
				},
				{
					timeout: 15_000,
					message:
						'an update audit-trail entry should appear after editing',
				},
			)
			.toBe(true)
	})

	test('UI: the audit-trails view renders entries (data-dependent)', async ({
		page,
		request,
	}) => {
		// Make sure there is at least one trail row to render.
		const trails = await getTrails(request)
		test.skip(trails.length === 0, 'no audit trail entries to render')

		await page.goto(`${APP}/#/audit-trails`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 30_000,
		})

		// The audit-trail surface renders a real table of entries; at least one
		// row must carry an action token (CREATE/UPDATE) — i.e. real recorded
		// data, not just a shell. (Action is rendered uppercased in a table cell.)
		await expect(page.locator('table tbody tr').first()).toBeVisible({
			timeout: 20_000,
		})
		const actionCell = page.locator('table tbody tr td', {
			hasText: /CREATE|UPDATE/,
		})
		await expect(actionCell.first()).toBeVisible({ timeout: 20_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// (b) Soft-delete / Restore  — happy path encoded; real defects flagged fixme
// ─────────────────────────────────────────────────────────────────────────────
test.describe('workflow: soft-delete then restore', () => {
	test.use({ storageState: STORAGE_STATE })

	let register: SeededRegister
	let schema: SeededSchema
	let objectId: string

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, `${RUN_ID}-trash`)
		schema = await createSchema(
			request,
			`${RUN_ID}-trash`,
			'sch',
			twoPropertySchema(),
		)
		await linkSchemaToRegister(request, register, [schema.id])
		const obj = await createObject(request, register.id, schema.id, {
			title: `Trash ${RUN_ID}`,
			count: 5,
		})
		objectId = obj.id
	})

	test.afterAll(async ({ request }) => {
		// Hard cleanup regardless of soft-delete state.
		await request
			.delete(`${API}/objects/${register.id}/${schema.id}/${objectId}`)
			.catch(() => {})
		await request.delete(`${API}/deleted/${objectId}`).catch(() => {})
		await deleteSchema(request, schema.id)
		await deleteRegister(request, register.id)
	})

	test('deleting an object makes its direct GET return 404 (soft-delete applied)', async ({
		request,
	}) => {
		const del = await request.delete(
			`${API}/objects/${register.id}/${schema.id}/${objectId}`,
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect([200, 204], 'DELETE object').toContain(del.status())

		const gone = await request.get(
			`${API}/objects/${register.id}/${schema.id}/${objectId}`,
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(gone.status(), 'deleted object no longer directly retrievable').toBe(
			404,
		)
	})

	// BUG-1 (FIXED): the deleted object now surfaces in the Deleted list.
	// Root cause: DeletedController.index() called searchObjectsPaginated()
	// without a register/schema context, which always fell through to an empty
	// result because objects live in per-register/schema magic tables. Fixed by
	// scanning every magic table via MagicMapper::findDeletedAcrossAllMagicTables().
	test('BUG-1: the soft-deleted object appears in GET /api/deleted', async ({
		request,
	}) => {
		const resp = await request.get(`${API}/deleted?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const found = (body.results ?? []).some((r: any) =>
			JSON.stringify(r).includes(objectId),
		)
		expect(
			found,
			'soft-deleted object should be listed under /api/deleted',
		).toBe(true)
	})

	// BUG-2 (FIXED): restore now actually un-deletes the object.
	// Root cause: restore ran `UPDATE openregister_objects SET deleted=NULL`,
	// but that legacy generic table is empty — objects live in magic tables, so
	// the UPDATE matched zero rows. Fixed via MagicMapper::restoreObject(), which
	// clears `_deleted` on the correct per-register/schema magic table.
	test('BUG-2: restoring brings the object back into its register/schema', async ({
		request,
	}) => {
		const restore = await request.post(`${API}/deleted/${objectId}/restore`, {
			headers: { Accept: 'application/json' },
		})
		expect(restore.status(), 'restore endpoint').toBe(200)

		const back = await request.get(
			`${API}/objects/${register.id}/${schema.id}/${objectId}`,
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(back.status(), 'restored object should be retrievable again').toBe(
			200,
		)
	})

	// BUG-3 (FIXED): _includeDeleted=true no longer 500s.
	// Root cause: the query-string "_includeDeleted=true" arrived as the STRING
	// "true" and was passed to MagicSearchHandler::applyBasicFilters(bool $...),
	// raising a TypeError under strict_types. Fixed by coercing the value with
	// filter_var(..., FILTER_VALIDATE_BOOLEAN) before use.
	test('BUG-3: objects list with _includeDeleted=true does not 500', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API}/objects/${register.id}/${schema.id}?_includeDeleted=true&_limit=50`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'including deleted should not 500').toBeLessThan(500)
	})

	test('UI: the Deleted view renders', async ({ page }) => {
		await page.goto(`${APP}/#/deleted`, { waitUntil: 'domcontentloaded' })
		// The Deleted view must at least mount. (BUG-1 is now fixed, so the list
		// is populated by GET /api/deleted — asserted at the API level above.)
		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 30_000,
		})
	})
})
