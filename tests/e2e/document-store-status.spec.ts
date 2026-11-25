/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WHERE DOCUMENTS LIVE, ON THE ADMIN SETTINGS PAGE (change `register-document-store`, task 1.2).
 *
 * The store is read over `GET /api/settings/files` first, and the page is then
 * checked to show that same root, storage and mount point. Reading the expected
 * values from the API keeps the spec independent of the instance's locale. The
 * scenario's GIVEN is a stock instance: the root `Open Registers` on the primary
 * storage, so the store is not separate, which the API answer must say.
 *
 * Not run in the change that added it: it needs a live instance and a browser.
 *
 * @e2e file-actions::the-administrator-reads-where-documents-live
 */
import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from './base-url.ts'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const ADMIN_SETTINGS = '/index.php/settings/admin/openregister'
const FILE_SETTINGS = '/index.php/apps/openregister/api/settings/files'

/** The document store as the file settings API reports it. */
interface DocumentStore {
	root: string
	storageId: string
	backend: string
	mountPoint: string
	separate: boolean
	freeBytes: number | null
}

/**
 * Read the document store over the API, as the administrator.
 *
 * @return {Promise<DocumentStore>} The store
 */
async function readStore(): Promise<DocumentStore> {
	const api = await playwrightRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	const response = await api.get(FILE_SETTINGS)
	expect(response.status(), 'the file settings must answer an administrator').toBe(200)
	const body = await response.json()
	await api.dispose()
	expect(body.documentStore, 'the file settings must report the document store').toBeTruthy()

	return body.documentStore as DocumentStore
}

test.describe('document store — the administrator reads where documents live', () => {
	test.use({ storageState: STORAGE_STATE })
	test.describe.configure({ timeout: 120_000 })

	test('the settings page shows the root, the storage and whether it is separate', async ({ page }) => {
		const store = await readStore()
		// The scenario's GIVEN: the default root on the instance's primary storage.
		expect(store.root, 'the default root').toBe('Open Registers')
		expect(store.separate, 'on the primary storage').toBe(false)

		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded', timeout: 60_000 })

		const block = page.locator('.document-store-status').first()
		await expect(block, 'the document store block must render').toBeVisible({ timeout: 45_000 })

		const values = block.locator('dd')
		await expect(values.nth(0), 'the root folder').toHaveText(store.root)
		await expect(values.nth(1), 'the storage type').toContainText(store.backend)
		await expect(values.nth(2), 'the storage').toHaveText(store.storageId)
		await expect(values.nth(3), 'the mount point').toHaveText(store.mountPoint)
		await expect(values.nth(4), 'whether the store is separate').not.toHaveText('')
	})
})
