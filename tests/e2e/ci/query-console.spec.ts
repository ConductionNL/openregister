import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE ADMINISTRATOR'S QUERY CONSOLE, END TO END.
 *
 * @e2e admin-query-console::an-administrator-runs-a-read-only-query
 * @e2e admin-query-console::a-mutation-is-refused-before-it-runs
 * @e2e admin-query-console::a-non-administrator-is-refused
 *
 * operate-admin-query-console task 4.2: an administrator runs a `groupBy`
 * query on the Operations page, downloads the buckets as CSV, and finds the
 * `query.run` and `query.export` audit rows; a mutation is refused; a
 * non-administrator gets 403 on the run route.
 *
 * HERMETIC: it creates its own register, schema and objects, and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** The seeded non-admin account, provisioned by the workflow's seed command. */
const READER = 'e2e-other'
const READER_PASS = 'E2e-Share-Pass-123'

const API = '/index.php/apps/openregister/api'

/** A GraphQL-safe unique name: the schema's list field is named after its slug. */
const SLUG = `qc${Math.random().toString(36).slice(2, 10)}`

/**
 * An API context signed in as one user.
 *
 * The empty storage state matters: inside a test that uses the administrator's
 * `storageState`, a new request context would otherwise carry the
 * administrator's session cookie, and Nextcloud answers as the session's user
 * rather than the Basic credentials, so a "non-administrator" call would run
 * as the administrator.
 */
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

/** How many audit rows carry this action. */
async function auditCount(admin: APIRequestContext, action: string): Promise<number> {
	const res = await admin.get(`${API}/audit-trails?action=${encodeURIComponent(action)}&_limit=1`)
	expect(res.ok(), await res.text()).toBe(true)
	const body = await res.json()
	return Number(body.total ?? body.results?.length ?? 0)
}

test.describe.configure({ mode: 'serial' })

test.describe('the query console', () => {
	test.use({ storageState: STORAGE_STATE })

	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	const created: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		const schema = await admin.post(`${API}/schemas`, {
			data: { title: `Query console ${SLUG}`, slug: SLUG, properties: { status: { type: 'string' } } },
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Query console ${SLUG}`, slug: `${SLUG}-register`, schemas: [Number(schemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		for (const status of ['open', 'open', 'closed']) {
			const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { status } })
			expect(res.status(), await res.text()).toBe(201)
			created.push((await res.json())['@self'].id)
		}
	})

	test.afterAll(async () => {
		for (const uuid of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== undefined) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('an administrator runs a groupBy query, downloads the buckets and both are audited', async ({ page }, testInfo) => {
		const runsBefore = await auditCount(admin, 'query.run')
		const exportsBefore = await auditCount(admin, 'query.export')

		await page.goto('/index.php/apps/openregister/operations')
		const consoleSection = page.locator('section.queryConsole')
		await expect(consoleSection).toBeVisible({ timeout: 30_000 })

		const editor = consoleSection.locator('[data-test="query-editor"] .cm-content')
		await editor.click()
		await page.keyboard.insertText(`{ ${SLUG}(groupBy: { field: "status" }) { groups { key value } } }`)

		await consoleSection.getByRole('button', { name: 'Run query' }).click()
		const table = consoleSection.locator('table.queryConsoleTable')
		await expect(table).toBeVisible()
		await expect(table.locator('thead th')).toHaveText(['key', 'value'])
		await expect(table.locator('tbody tr')).toHaveCount(2)
		await expect(table.locator('tbody tr', { hasText: 'open' })).toContainText('2')

		const downloadPromise = page.waitForEvent('download')
		await consoleSection.getByRole('button', { name: 'Download CSV' }).click()
		const download = await downloadPromise
		expect(download.suggestedFilename()).toMatch(/^query-\d{8}-\d{4}\.csv$/)
		const file = testInfo.outputPath('query.csv')
		await download.saveAs(file)
		const csv = fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '')
		expect(csv.split(/\r?\n/)[0]).toBe('key,value')
		expect(csv).toContain('open,2')
		expect(csv).toContain('closed,1')

		await expect.poll(() => auditCount(admin, 'query.run')).toBeGreaterThan(runsBefore)
		await expect.poll(() => auditCount(admin, 'query.export')).toBeGreaterThan(exportsBefore)
	})

	test('a mutation is refused before it runs', async () => {
		const res = await admin.post(`${API}/operations/query`, { data: { query: 'mutation { deleteObject(id: "x") }' } })
		expect(res.status()).toBe(400)
		expect((await res.json()).error).toBe('READ_ONLY')
	})

	test('a non-administrator is refused on the run route', async () => {
		const reader = await contextFor(READER, READER_PASS)
		try {
			const res = await reader.post(`${API}/operations/query`, { data: { query: `{ ${SLUG} { totalCount } }` } })
			expect(res.status()).toBe(403)
		} finally {
			await reader.dispose()
		}
	})
})
