import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE RECORDS OF A REGISTER AND SCHEMA, OPENED BY URL.
 *
 * `/tables?register=<id>&schema=<id>` is where the register page's "view
 * objects" and other apps' links land. Opened that way, the index page was
 * handed the register OBJECT for a prop it puts into URLs, asked for
 * `/api/objects/[object Object]/[object Object]` and showed "An error
 * occurred" instead of the records.
 *
 * HERMETIC. It creates its own register, schema and record and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)

test.use({ storageState: STORAGE_STATE })

test.describe('the records list opened by URL', () => {
	let admin: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let recordId = ''

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})
		const schema = await admin.post(`${API}/schemas`, {
			data: { title: `e2e deep link ${RUN}`, description: 'e2e', properties: { titel: { type: 'string', title: 'Titel' } } },
		})
		expect(schema.ok(), `schema create failed: ${await schema.text()}`).toBeTruthy()
		schemaId = String((await schema.json()).id)
		const register = await admin.post(`${API}/registers`, {
			data: { title: `e2e deep link register ${RUN}`, description: 'e2e', schemas: [Number(schemaId)] },
		})
		expect(register.ok(), `register create failed: ${await register.text()}`).toBeTruthy()
		registerId = String((await register.json()).id)
		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel: `Melding ${RUN}` } })
		expect(record.ok(), `record create failed: ${await record.text()}`).toBeTruthy()
		recordId = String((await record.json()).id)
	})

	test.afterAll(async () => {
		if (recordId !== '') {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${recordId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('lists the records, and asks for no object as a path segment', async ({ page }) => {
		const malformed: string[] = []
		page.on('request', (request) => {
			if (request.url().includes('object%20Object') || request.url().includes('[object Object]')) {
				malformed.push(request.url())
			}
		})

		await page.goto(`/index.php/apps/openregister/tables?register=${registerId}&schema=${schemaId}`, { waitUntil: 'domcontentloaded' })

		await expect(page.getByText(`Melding ${RUN}`).first(), 'the record is not listed').toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('An error occurred')).toHaveCount(0)
		expect(malformed, 'a request carried a register or schema object in its URL').toEqual([])
	})
})
