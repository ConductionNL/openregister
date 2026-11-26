import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WHO HOLDS THE LOCK, ON THE RECORD PAGE.
 *
 * capability-rows-closing-round task 3.1: a record locked by one user, opened
 * by another, says it is locked and by whom (`ObjectDetails.vue`). The lock is
 * taken by the seeded non-administrator `e2e-other` over the API; the record is
 * opened by the administrator in the browser. A second user is the point: the
 * holder reading their own lock would pass while the notice named nobody.
 *
 * HERMETIC: it creates its register, schema and record, releases the lock and
 * removes all of them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** The seeded non-admin account, provisioned by the workflow's seed command. */
const HOLDER = 'e2e-other'
const HOLDER_PASS = 'E2e-Share-Pass-123'

const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)

/**
 * An API context signed in as one user, with no session cookie: inside a test
 * that uses the administrator's storageState a new context would otherwise
 * carry the administrator's session and act as them.
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

test.describe('the lock holder is shown on the record', () => {
	test.use({ storageState: STORAGE_STATE })

	let admin: APIRequestContext
	let holder: APIRequestContext
	let registerId: string
	let schemaId: string
	let uuid: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		holder = await contextFor(HOLDER, HOLDER_PASS)

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				title: `Lock notice ${RUN}`,
				slug: `locknotice${RUN}`,
				properties: { titel: { type: 'string' } },
				authorization: { read: ['authenticated'], update: ['authenticated'] },
			},
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Lock notice ${RUN}`, slug: `locknoticeregister${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel: 'Vergunning' } })
		expect(record.status(), await record.text()).toBe(201)
		uuid = (await record.json())['@self'].id

		const lock = await holder.post(`${API}/objects/${registerId}/${schemaId}/${uuid}/lock`, { data: { process: 'e2e-review' } })
		expect(lock.ok(), await lock.text()).toBe(true)
	})

	test.afterAll(async () => {
		if (uuid !== undefined) {
			await holder.post(`${API}/objects/${registerId}/${schemaId}/${uuid}/unlock`)
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
		await holder.dispose()
	})

	test('another user opening the record reads that it is locked and by whom', async ({ page }) => {
		const stored = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect((await stored.json())['@self'].locked?.user).toBe(HOLDER)

		await page.goto(`/index.php/apps/openregister/objects/${registerId}/${schemaId}/${uuid}`)

		const notice = page.getByText(/locked by/i).first()
		await expect(notice).toBeVisible({ timeout: 30_000 })
		await expect(notice).toContainText(HOLDER)
	})
})
