import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "ADD TO OBJECT" FROM FILES: A FILE IN THE CALLER'S OWN FILES LANDS ON THE
 * OBJECT THEY PICK, AND THE PICKER OFFERS ONLY WHAT THEY MAY CHANGE.
 *
 * Scenario anchors (change `files-leaf-save-to-object`, spec `file-actions`):
 *
 * @e2e file-actions::a-file-in-files-is-attached-to-a-case
 * @e2e file-actions::the-picker-offers-only-writable-objects
 *
 * As the non-administrator `e2e-owner` (tests/e2e/ci/seed.sh), who may change
 * objects of one schema and only read another. The browser half runs the
 * Files action on a file in their own Files, searches the case by title in the
 * picker, picks it, and the file is then read back from the case's files over
 * HTTP. Talk's "Save chat to object" is not here: Talk is not installed on the
 * CI instance (the exporter and the action have unit tests).
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schemas, objects and
 * file, and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const OWNER = process.env.E2E_OWNER_USER || 'e2e-owner'
const PASS = process.env.E2E_SEED_PASSWORD || 'E2e-Share-Pass-123'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const FILE_NAME = `notulen-${RUN}.txt`
const CASE_TITLE = `Zaak ${RUN} intake`
const ARCHIVED_TITLE = `Zaak ${RUN} archief`

/** Build an API context authenticated as one user, with no browser session. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
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

test.describe.configure({ mode: 'serial', timeout: 180_000 })

test.describe('add a file from Files to a register object', () => {
	// The page is driven as the owner, not as admin: an administrator may change
	// every schema, so the writable filter would have nothing to leave out.
	test.use({
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${OWNER}:${PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		},
	})

	let admin: APIRequestContext
	let owner: APIRequestContext
	let registerId: string
	let registerSlug: string
	let writableId: string
	let writableSlug: string
	let readOnlyId: string
	let readOnlySlug: string
	let caseUuid: string
	let archivedUuid: string

	/** Create a schema with a title property and the given authorization. */
	async function createSchema(title: string, authorization: object) {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title,
				description: 'e2e',
				properties: {
					title: { type: 'string', title: 'Title', maxLength: 255 },
				},
				configuration: { objectNameField: 'title' },
				authorization,
			},
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		return { id: String(body.id), slug: String(body.slug ?? body.id) }
	}

	/** Create an object as one user. */
	async function createObject(
		ctx: APIRequestContext,
		schema: string,
		title: string,
	): Promise<string> {
		const res = await ctx.post(`${API}/objects/${registerId}/${schema}`, {
			data: { title },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		return String(body['@self']?.id ?? body.id ?? body.uuid)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)

		const probe = await owner.get(`${API}/registers`)
		expect(
			probe.status(),
			`seeded account '${OWNER}' cannot authenticate; did seed.sh run?`,
		).toBeLessThan(400)

		const writable = await createSchema(`e2e attach case ${RUN}`, {
			read: [`user:${OWNER}`],
			create: [`user:${OWNER}`],
			update: [`user:${OWNER}`],
			delete: [`user:${OWNER}`],
		})
		writableId = writable.id
		writableSlug = writable.slug

		// Readable by the owner, changeable by nobody but an administrator.
		const readOnly = await createSchema(`e2e attach archive ${RUN}`, {
			read: [`user:${OWNER}`],
			create: ['admin'],
			update: ['admin'],
			delete: ['admin'],
		})
		readOnlyId = readOnly.id
		readOnlySlug = readOnly.slug

		const reg = await admin.post(`${API}/registers`, {
			data: {
				title: `e2e attach register ${RUN}`,
				description: 'e2e',
				schemas: [Number(writableId), Number(readOnlyId)],
			},
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		const regBody = await reg.json()
		registerId = String(regBody.id)
		registerSlug = String(regBody.slug ?? regBody.id)

		caseUuid = await createObject(owner, writableId, CASE_TITLE)
		archivedUuid = await createObject(admin, readOnlyId, ARCHIVED_TITLE)

		const put = await owner.put(
			`/remote.php/dav/files/${OWNER}/${FILE_NAME}`,
			{ data: Buffer.from(`Notulen ${RUN}`), headers: { 'Content-Type': 'text/plain' } },
		)
		expect(put.status(), `WebDAV upload failed: ${await put.text()}`).toBeLessThan(300)
	})

	test.afterAll(async () => {
		if (owner) {
			await owner.delete(`/remote.php/dav/files/${OWNER}/${FILE_NAME}`)
		}
		if (caseUuid) {
			await admin.delete(`${API}/objects/${registerId}/${writableId}/${caseUuid}`)
		}
		if (archivedUuid) {
			await admin.delete(`${API}/objects/${registerId}/${readOnlyId}/${archivedUuid}`)
		}
		for (const id of [writableId, readOnlyId].filter(Boolean)) {
			await admin.delete(`${API}/schemas/${id}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('the picker offers only objects of schemas the caller may change', async () => {
		const res = await owner.get(`${API}/files/attach-targets`, {
			params: { search: `Zaak ${RUN}` },
		})
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()

		const offered = (body.targets as Array<{ schema: string }>).map((t) => t.schema)
		expect(offered).toContain(writableSlug)
		expect(offered).not.toContain(readOnlySlug)

		const found = (body.results as Array<{ objectUuid: string, title: string }>)
		expect(found.map((r) => r.objectUuid)).toEqual([caseUuid])
		expect(found[0].title).toBe(CASE_TITLE)

		// The administrator may change both, so both cases are found for them:
		// the archive case was left out for the owner, not missing from the index.
		const asAdmin = await admin.get(`${API}/files/attach-targets`, {
			params: { search: `Zaak ${RUN}` },
		})
		const adminFound = ((await asAdmin.json()).results as Array<{ objectUuid: string }>)
			.map((r) => r.objectUuid)
		expect(adminFound).toEqual(expect.arrayContaining([caseUuid, archivedUuid]))
	})

	test('a file in Files is attached to the case picked by its title', async ({ page }) => {
		await page.goto('/index.php/apps/files/files?dir=/')

		const row = page.locator(`[data-cy-files-list-row-name="${FILE_NAME}"]`)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.getByRole('button', { name: /actions/i }).first().click()
		await page.getByRole('menuitem', { name: 'Add to object' }).click()

		const dialog = page.getByRole('dialog', { name: 'Add to object' })
		await expect(dialog).toBeVisible()
		await dialog.getByLabel('Search objects').fill(`Zaak ${RUN}`)

		const hit = dialog.getByRole('button', { name: new RegExp(CASE_TITLE) })
		await expect(hit).toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByRole('button', { name: new RegExp(ARCHIVED_TITLE) })).toHaveCount(0)
		await hit.click()

		await expect(page.getByText(`Added to ${CASE_TITLE}`, { exact: true })).toBeVisible({ timeout: 15_000 })

		const files = await owner.get(`${API}/objects/${registerSlug}/${writableSlug}/${caseUuid}/files`)
		expect(files.status(), await files.text()).toBe(200)
		const listed = await files.json()
		const names = ((listed.results ?? listed) as Array<{ title?: string, name?: string }>)
			.map((f) => f.title ?? f.name)
		expect(names).toContain(FILE_NAME)
	})
})
