import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A TASK FOUND FROM NEXTCLOUD'S TOP-BAR SEARCH, AND OPENED.
 *
 * flow-task-search-provider task 2.2: create a task for the signed-in user, search
 * its title in the header search, see it under "Tasks" (provider
 * `openregister_flow_tasks`) and open it. The result's link is asserted to be
 * the task's own route before it is followed, so a result that opened the app's
 * front page would not pass.
 *
 * HERMETIC: it creates its register, schema, case and task; the task is
 * cancelled (there is no task delete route) and the rest removed.
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
const TITLE = `Beoordeel zoektaak ${RUN}`

test.describe('a task in the top-bar search', () => {
	test.use({ storageState: STORAGE_STATE })

	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	let caseUuid: string
	let taskUuid: string

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const schema = await admin.post(`${API}/schemas`, {
			data: { title: `Task search ${RUN}`, slug: `tasksearch${RUN}`, properties: { title: { type: 'string' } } },
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Task search ${RUN}`, slug: `tasksearchregister${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title: 'Bouwvergunning Dorpsstraat 1' } })
		expect(record.status(), await record.text()).toBe(201)
		caseUuid = (await record.json())['@self'].id

		const task = await admin.post(`${API}/flow-tasks`, { data: { title: TITLE, objectUuid: caseUuid, assignee: ADMIN } })
		expect(task.status(), await task.text()).toBe(201)
		taskUuid = (await task.json()).uuid
	})

	test.afterAll(async () => {
		if (taskUuid !== undefined) {
			await admin.post(`${API}/flow-tasks/${taskUuid}/cancel`, { data: { reason: 'e2e teardown' } })
		}
		if (caseUuid !== undefined) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${caseUuid}`)
			await admin.delete(`${API}/deleted/${caseUuid}?force=true`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== undefined) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('the signed-in user finds the task by its title and opens it', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/')

		// Nextcloud's header search: the button opens the unified search dialog.
		await page.locator('.unified-search-input__button').click()
		const box = page.locator('[data-cy-unified-search-input]')
		await expect(box).toBeVisible()
		await box.fill(RUN)

		const hit = page.locator(`a[href*="/apps/openregister/flow-tasks/${taskUuid}"]`).first()
		await expect(hit).toBeVisible({ timeout: 30_000 })
		await expect(hit).toContainText(TITLE)

		await hit.click()
		await expect(page).toHaveURL(new RegExp(taskUuid))
		await expect(page.getByRole('heading', { name: TITLE })).toBeVisible({ timeout: 30_000 })
	})
})
