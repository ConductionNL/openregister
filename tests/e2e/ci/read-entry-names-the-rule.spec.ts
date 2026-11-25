import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A READ ENTRY'S DETAIL NAMES THE RULE THAT LET THE READER SEE THE OBJECT.
 *
 * read-audit-entries-name-the-rule task 4.1: an object whose schema lets only
 * `e2e-grantees` read it is opened by `e2e-other`, a member of that group and
 * not an administrator, so the read is mediated by that rule. The administrator
 * opens that read entry's details on the audit trail page and reads the rule
 * digest (SHA-256 of the canonical JSON of the matched entry: here the JSON
 * string "e2e-grantees") and the schema version.
 *
 * Uses the seeded account and group of tests/e2e/ci/seed.sh. HERMETIC
 * otherwise: it creates its register, schema and object, and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { createHash } from 'crypto'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const READER = 'e2e-other'
const READER_PASS = 'E2e-Share-Pass-123'
const GROUP = 'e2e-grantees'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)

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

test.describe('a read entry names its rule', () => {
	test.use({ storageState: STORAGE_STATE })

	let admin: APIRequestContext
	let reader: APIRequestContext
	let registerId: string
	let schemaId: string
	let uuid: string
	let entry: { id: number, ruleDigest: string, ruleVersion: string }

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		reader = await contextFor(READER, READER_PASS)

		const schema = await admin.post(`${API}/schemas`, {
			data: { title: `Read rule ${RUN}`, slug: `readrule${RUN}`, properties: { titel: { type: 'string' } }, authorization: { read: [GROUP] } },
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Read rule ${RUN}`, slug: `readruleregister${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel: 'Losliggende stoeptegel' } })
		expect(record.status(), await record.text()).toBe(201)
		uuid = (await record.json())['@self'].id

		const read = await reader.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(read.status(), await read.text()).toBe(200)

		const entries = await admin.get(`${API}/audit-trails?action=read&_limit=100`)
		expect(entries.ok()).toBe(true)
		entry = ((await entries.json()).results || []).find((row: { objectUuid: string, user: string }) => row.objectUuid === uuid && row.user === READER)
		expect(entry, 'the reader\'s read entry').toBeTruthy()
	})

	test.afterAll(async () => {
		if (uuid !== undefined) {
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
		await reader.dispose()
	})

	test('the entry carries the digest of the matched rule and the schema version', () => {
		expect(entry.ruleDigest).toBe(createHash('sha256').update(JSON.stringify(GROUP)).digest('hex'))
		expect(entry.ruleVersion).toBeTruthy()
	})

	test('the audit trail detail shows the rule digest and version', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/audit-trails')

		const row = page.locator('tr', { hasText: READER }).filter({ hasText: /read/i }).first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.getByRole('button').last().click()
		await page.getByRole('menuitem', { name: 'View Details' }).click()

		const dialog = page.getByRole('dialog', { name: 'Audit Trail Details' })
		await expect(dialog).toBeVisible()
		await expect(dialog).toContainText(String(entry.id))
		await expect(dialog.locator('.detail-item', { hasText: 'Rule Digest' })).toContainText(entry.ruleDigest)
		await expect(dialog.locator('.detail-item', { hasText: 'Rule Version' })).toContainText(entry.ruleVersion)
	})
})
