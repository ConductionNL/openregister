/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Rights are managed in a permission matrix screen (REQ-RSS-001), in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The component tests render the matrix from stubbed stores. This spec opens
 * the real admin settings page and reads the matrix against a register and a
 * schema made for the run:
 * - a register that grants `read` to a group, and a schema in it without a
 *   rule of its own, shows that group in the schema's read cell, marked as
 *   inherited from the register;
 * - after a group is granted `read` on the schema through the save the matrix
 *   makes (PUT /api/schemas/{id} with the changed authorization), the read
 *   cell shows the group as the schema's own, and a member of the group lists
 *   the schema's objects where they listed none before.
 *
 * The matrix has no editor for a single cell: it edits the public switches and
 * assigns roles in bulk. The grant therefore goes through the save route the
 * matrix uses, and the spec reads what the screen shows of it.
 *
 * SELF-CLEANING. The register, schema, object, group and user are created
 * under a per-run name and removed in `afterAll`.
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const OCS = '/ocs/v2.php/cloud'
const RUN = Math.random().toString(36).slice(2, 10)
const TEAM = `wijkteam${RUN}`
const STAFF = `medewerkers${RUN}`
const MEMBER = `wijk${RUN}`
const MEMBER_PASS = 'E2e-Wijk-Pass-123'
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' }

/** An API context signed in as one user, without a session cookie that would override it. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** Create one entity over the API and return its id. */
async function create(request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(url, { headers: JSON_HEADERS, data })
	expect(resp.status(), `POST ${url} is accepted`).toBeLessThan(300)
	return String((await resp.json()).id)
}

test.describe.configure({ mode: 'serial' })

test.describe('permission-matrix', () => {
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
	test.setTimeout(120_000)

	let schemaId: string
	let registerId: string
	let objectId: string

	/** The matrix's read cell of the run's schema, after opening the page and the register row. */
	async function schemaReadCell(page: Page) {
		await page.goto('/index.php/settings/admin/openregister', { waitUntil: 'domcontentloaded' })
		const registerCell = page.locator(`#permission-matrix-register-${registerId}`)
		await expect(registerCell, 'the register has a row in the matrix').toBeVisible({ timeout: 30_000 })
		await registerCell.locator('button.expand-toggle').click()

		const schemaRow = page.locator('tr.schema-row').filter({ has: page.locator(`#permission-matrix-schema-${registerId}-${schemaId}`) })
		await expect(schemaRow, 'the schema has a row under its register').toBeVisible()
		// Columns: name, read, create, update, delete, manage, public.
		return { row: schemaRow, read: schemaRow.locator('td.action-cell').first() }
	}

	test.beforeAll(async ({ request }) => {
		expect((await request.post(`${OCS}/groups?format=json`, { headers: JSON_HEADERS, data: { groupid: TEAM } })).status()).toBe(200)
		expect((await request.post(`${OCS}/users?format=json`, { headers: JSON_HEADERS, data: { userid: MEMBER, password: MEMBER_PASS, groups: [TEAM] } })).status()).toBe(200)

		schemaId = await create(request, `${API}/schemas`, {
			title: `Melding ${RUN}`,
			slug: `melding${RUN}`,
			properties: { omschrijving: { type: 'string' } },
		})
		registerId = await create(request, `${API}/registers`, {
			title: `Meldingen ${RUN}`,
			slug: `meldingen${RUN}`,
			schemas: [Number(schemaId)],
			authorization: { read: [STAFF] },
		})
		objectId = await create(request, `${API}/objects/${registerId}/${schemaId}`, { omschrijving: 'Lantaarnpaal kapot' })
	})

	test.afterAll(async ({ request }) => {
		if (objectId) {
			await request.delete(`${API}/objects/${registerId}/${schemaId}/${objectId}`)
		}
		if (registerId) {
			await request.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
		await request.delete(`${OCS}/users/${MEMBER}?format=json`, { headers: JSON_HEADERS })
		await request.delete(`${OCS}/groups/${TEAM}?format=json`, { headers: JSON_HEADERS })
	})

	// @e2e rbac-scopes::an-inherited-grant-is-told-apart
	test('a schema without a rule shows the register grant as inherited', async ({ page }) => {
		const { row, read } = await schemaReadCell(page)
		await expect(read).toContainText(STAFF)
		await expect(read.locator('.group-list.inherited'), 'the read cell is marked inherited').toBeVisible()
		await expect(row.locator('.inherited-badge'), 'the schema is marked as inheriting').toBeVisible()
	})

	// @e2e rbac-scopes::an-administrator-grants-a-group-read-on-a-schema
	test('a group granted read on the schema shows in its read cell, and its member can list the schema', async ({ page, request }) => {
		const member = await contextFor(MEMBER, MEMBER_PASS)
		const before = await member.get(`${API}/objects/${registerId}/${schemaId}`)
		expect(before.status()).toBe(200)
		expect((await before.json()).results, 'without the grant the member lists nothing').toHaveLength(0)

		const saved = await request.put(`${API}/schemas/${schemaId}`, { headers: JSON_HEADERS, data: { authorization: { read: [TEAM] } } })
		expect(saved.status(), 'the save the matrix makes is accepted').toBe(200)
		expect((await saved.json()).authorization?.read).toEqual([TEAM])

		const { row, read } = await schemaReadCell(page)
		await expect(read).toContainText(TEAM)
		await expect(read.locator('.group-list.inherited'), 'the grant is the schema\'s own').toHaveCount(0)
		await expect(row.locator('.inherited-badge')).toHaveCount(0)

		const after = await member.get(`${API}/objects/${registerId}/${schemaId}`)
		expect(after.status()).toBe(200)
		const ids = (await after.json()).results.map((o: { id: string }) => o.id)
		expect(ids, 'the member lists the schema\'s object').toEqual([objectId])
		await member.dispose()
	})
})
