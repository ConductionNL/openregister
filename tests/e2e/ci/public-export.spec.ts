import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PUBLISHED DATA DOWNLOADS IN AN OPEN FORMAT, WITHOUT AN ACCOUNT.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e data-import-export::a-data-steward-downloads-tsv
 * @e2e data-import-export::a-visitor-downloads-published-decisions-as-xml
 * @e2e data-import-export::a-schema-that-is-readable-but-not-exportable-stays-in-place
 *
 * An anonymous client downloads a schema that grants `read` and `export` to
 * `public`, filtered on one field, as TSV and as XML: only the matching rows,
 * and no property restricted to a group as a column or an element, with the
 * export written to the audit trail as `anonymous`. The same request on a
 * schema that keeps `export` to a group is 401. A signed-in administrator
 * picks XML in the export dialog on the registers page and gets a `.xml` file.
 *
 * HERMETIC. It creates its own register, two schemas and their objects, and
 * removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'

/** The restricted column and the values only it carries. */
const RESTRICTED = 'behandelaar'
const HANDLERS = [`jansen-${RUN}`, `pietersen-${RUN}`, `devries-${RUN}`]

const DECISIONS = [
	{ titel: 'Kapvergunning eik', thema: 'milieu', [RESTRICTED]: HANDLERS[0] },
	{ titel: 'Geluidsscherm A2', thema: 'milieu', [RESTRICTED]: HANDLERS[1] },
	{ titel: 'Parkeervergunning centrum', thema: 'verkeer', [RESTRICTED]: HANDLERS[2] },
]

/** An API context; with no user it is an anonymous client. */
async function contextFor(user?: string, password?: string): Promise<APIRequestContext> {
	const headers: Record<string, string> = { 'OCS-APIRequest': 'true' }
	if (user !== undefined && password !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}

	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

test.describe.configure({ mode: 'serial' })

test.describe('open-format export', () => {
	let admin: APIRequestContext
	let anonymous: APIRequestContext
	let registerId = ''
	let registerTitle = ''
	let publicSchemaId = ''
	let publicSchemaTitle = ''
	let privateSchemaId = ''
	const objects: Array<[string, string]> = []

	const exportOf = (schemaId: string, query: string): string =>
		`${API}/objects/${registerId}/${schemaId}/export?${query}`

	async function createSchema(title: string, exportTo: string[]): Promise<string> {
		const res = await admin.post(`${API}/schemas?register=${registerId}`, {
			data: {
				title,
				description: 'e2e',
				properties: {
					titel: { type: 'string', title: 'Titel', maxLength: 128 },
					thema: { type: 'string', title: 'Thema', maxLength: 64 },
					[RESTRICTED]: {
						type: 'string',
						title: 'Behandelaar',
						maxLength: 64,
						authorization: { read: ['admin'], update: ['admin'] },
					},
				},
				authorization: { read: ['public'], export: exportTo },
			},
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

		return String((await res.json()).id)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anonymous = await contextFor()

		registerTitle = `e2e public export register ${RUN}`
		const reg = await admin.post(`${API}/registers`, {
			data: { title: registerTitle, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		publicSchemaTitle = `e2e public export besluit ${RUN}`
		publicSchemaId = await createSchema(publicSchemaTitle, ['public'])
		privateSchemaId = await createSchema(`e2e public export melding ${RUN}`, ['admin'])

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: registerTitle, schemas: [Number(publicSchemaId), Number(privateSchemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		for (const [schemaId, rows] of [
			[publicSchemaId, DECISIONS],
			[privateSchemaId, [DECISIONS[0]]],
		] as Array<[string, typeof DECISIONS]>) {
			for (const data of rows) {
				const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data })
				expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
				objects.push([schemaId, String((await res.json())['@self'].id)])
			}
		}
	})

	test.afterAll(async () => {
		for (const [schemaId, uuid] of objects) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		for (const id of [publicSchemaId, privateSchemaId]) {
			if (id !== '') {
				await admin.delete(`${API}/schemas/${id}`)
			}
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await anonymous?.dispose()
	})

	test('an anonymous visitor downloads the filtered rows as TSV', async () => {
		const res = await anonymous.get(exportOf(publicSchemaId, 'format=tsv&thema=milieu'))
		expect(res.status(), await res.text()).toBe(200)
		expect(res.headers()['content-type']).toContain('text/tab-separated-values')
		expect(res.headers()['content-disposition']).toMatch(/\.tsv/)

		const lines = (await res.text()).replace(/^\uFEFF/, '').trim().split('\n')
		const header = lines[0].split('\t').map((cell) => cell.replace(/"/g, ''))
		expect(header).toEqual(expect.arrayContaining(['titel', 'thema']))
		expect(header, 'a group-restricted property is not a column').not.toContain(RESTRICTED)

		const rows = lines.slice(1)
		expect(rows, 'only the milieu rows').toHaveLength(2)
		const text = rows.join('\n')
		expect(text).toContain('Kapvergunning eik')
		expect(text).toContain('Geluidsscherm A2')
		expect(text).not.toContain('Parkeervergunning centrum')
		for (const handler of HANDLERS) {
			expect(text).not.toContain(handler)
		}
	})

	test('an anonymous visitor downloads the filtered rows as XML, and the export is audited', async () => {
		const res = await anonymous.get(exportOf(publicSchemaId, 'format=xml&thema=milieu'))
		expect(res.status(), await res.text()).toBe(200)
		expect(res.headers()['content-type']).toContain('application/xml')

		const xml = await res.text()
		expect(xml.match(/<object[\s>]/g) ?? [], 'only the milieu rows').toHaveLength(2)
		expect(xml).toContain('<titel>Kapvergunning eik</titel>')
		expect(xml).not.toContain('Parkeervergunning centrum')
		expect(xml, 'a group-restricted property is not an element').not.toContain(`<${RESTRICTED}>`)
		for (const handler of HANDLERS) {
			expect(xml).not.toContain(handler)
		}

		const trail = await admin.get(`${API}/audit-trails?_limit=500`)
		expect(trail.ok()).toBeTruthy()
		const audited = ((await trail.json()).results as Array<Record<string, any>>).find(
			(entry) =>
				entry.action === 'export.completed'
				&& String(entry.schema) === publicSchemaId
				&& entry.resultSummary?.format === 'xml',
		)
		expect(audited, 'the export is in the audit trail').toBeTruthy()
		expect(audited?.user).toBe('anonymous')
		expect(audited?.resultSummary?.rowCount).toBe(2)
	})

	test('a schema that keeps export to a group refuses an anonymous export', async () => {
		const res = await anonymous.get(exportOf(privateSchemaId, 'format=csv'))
		expect(res.status()).toBe(401)
		const body = await res.json()
		expect(body.rule).toBe('not-public')
		expect(JSON.stringify(body)).not.toContain('Kapvergunning eik')
	})

	test.describe('the export dialog', () => {
		test.use({ storageState: STORAGE_STATE, acceptDownloads: true })

		test('a signed-in user picks XML and gets an .xml file', async ({ page }) => {
			await page.goto(`${APP}/registers`, { waitUntil: 'domcontentloaded' })
			await expect(page.getByText(/Showing \d+ of \d+/).first(), 'the register cards have loaded').toBeVisible({
				timeout: 30000,
			})

			// The registers page lists its cards a page at a time, and a register
			// made by this run is the newest, so page forward until its schema row
			// is on screen. Bounded: a register that never shows is a failure.
			const row = page.locator('tr').filter({ hasText: publicSchemaTitle }).first()
			for (let pageTurn = 0; pageTurn < 10 && (await row.count()) === 0; pageTurn++) {
				// The pager's Next is the last button of that name: the cards above
				// it carry their own.
				const next = page.getByRole('button', { name: 'Next' }).last()
				if ((await next.count()) === 0 || (await next.isDisabled())) {
					break
				}
				await next.scrollIntoViewIfNeeded()
				await next.click()
				await page.waitForTimeout(1000)
			}
			await expect(row, 'the schema row is on the registers page').toBeVisible({ timeout: 15000 })

			await row.getByRole('button').last().click()
			await page.getByRole('menuitem', { name: 'Export' }).click()

			const dialog = page.getByRole('dialog', { name: 'Export Objects' })
			await expect(dialog).toBeVisible()
			await dialog.getByRole('combobox').click()
			await page.getByRole('option', { name: 'XML' }).click()

			const [download] = await Promise.all([
				page.waitForEvent('download'),
				dialog.getByRole('button', { name: 'Export', exact: true }).click(),
			])
			expect(download.suggestedFilename()).toMatch(/\.xml$/)

			const file = await download.path()
			const xml = fs.readFileSync(file as string, 'utf8')
			expect(xml).toContain('<titel>Kapvergunning eik</titel>')
			expect(xml).toContain('<titel>Parkeervergunning centrum</titel>')
		})
	})
})
