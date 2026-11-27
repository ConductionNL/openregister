import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A MERGE RELINKS EVERY REFERENCE, AND A REVERSAL PUTS THEM BACK.
 *
 * Change `mdm-merge-relinks-every-reference`, task 3.1:
 *
 * @e2e mdm-merge::a-merge-relinks-every-reference
 * @e2e mdm-merge::a-reversal-restores-the-references
 * @e2e mdm-frontend::the-duplicates-page-opens-on-a-register-and-schema-from-the-address
 *
 * Two modules that are the same module twice, and a suite whose `modules`
 * list names both. A steward opens `/duplicates?register=<slug>&schema=<slug>`
 * (no clicks to pick the pair), launches the merge wizard on the offered pair,
 * reads under "Referenced by" that one suite object points at the module that
 * goes away, and confirms. The suite's list is then read back over HTTP: it
 * names the survivor and no longer the merged module. The steward reverses
 * the operation from the Merge Operations page, and the suite's list names
 * both modules again.
 *
 * The suite is read from the object API, not from the merge's own report,
 * because a report that says "relinked" is only worth what a fresh read
 * confirms.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and three
 * objects, and removes all of them. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

test.use(fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {})

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so repeated runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'

const MODULE_SLUG = `merge-module-${RUN}`
const SUITE_SLUG = `merge-suite-${RUN}`
const REGISTER_SLUG = `merge-relink-${RUN}`

/** The uuid of an object as the object API answers it. */
function uuidOf(body: Record<string, any>): string {
	return String(body['@self']?.id ?? body.id ?? body.uuid)
}

/**
 * Dismiss the first-run setup wizard if it is open: its modal wrapper takes the
 * pointer events of everything under it.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}

	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('a merge relinks every reference, and a reversal puts them back', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext
	let registerId = ''
	let moduleSchemaId = ''
	let suiteSchemaId = ''
	let survivor = ''
	let merged = ''
	let suite = ''

	/** The suite's `modules` list, read fresh from the object API. */
	async function suiteModules(): Promise<string[]> {
		const res = await admin.get(`${API}/objects/${registerId}/${suiteSchemaId}/${suite}`)
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		return (body.modules ?? []).map(String)
	}

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const moduleSchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `Module ${RUN}`,
				slug: MODULE_SLUG,
				properties: { name: { type: 'string' }, email: { type: 'string' } },
				configuration: {
					'x-openregister-dedup': {
						matchRules: [
							{ field: 'name', method: 'normalized', weight: 0.5 },
							{ field: 'email', method: 'exact', weight: 0.5 },
						],
						threshold: 0.85,
					},
				},
			},
		})
		expect(moduleSchema.status(), await moduleSchema.text()).toBeLessThan(300)
		moduleSchemaId = String((await moduleSchema.json()).id)

		const suiteSchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `Suite ${RUN}`,
				slug: SUITE_SLUG,
				properties: {
					name: { type: 'string' },
					modules: {
						type: 'array',
						items: { type: 'string', format: 'uuid', $ref: `#/components/schemas/${MODULE_SLUG}` },
					},
				},
			},
		})
		expect(suiteSchema.status(), await suiteSchema.text()).toBeLessThan(300)
		suiteSchemaId = String((await suiteSchema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: {
				title: `Merge relink ${RUN}`,
				slug: REGISTER_SLUG,
				schemas: [Number(moduleSchemaId), Number(suiteSchemaId)],
			},
		})
		expect(register.status(), await register.text()).toBeLessThan(300)
		registerId = String((await register.json()).id)

		// The same module twice: the scorer offers the pair on name and e-mail.
		const first = await admin.post(`${API}/objects/${registerId}/${moduleSchemaId}`, {
			data: { name: 'Zaaksysteem', email: `zaaksysteem-${RUN}@example.test` },
		})
		expect(first.status(), await first.text()).toBeLessThan(300)
		survivor = uuidOf(await first.json())
		const second = await admin.post(`${API}/objects/${registerId}/${moduleSchemaId}`, {
			data: { name: 'zaaksysteem ', email: `zaaksysteem-${RUN}@example.test` },
		})
		expect(second.status(), await second.text()).toBeLessThan(300)
		merged = uuidOf(await second.json())

		const suiteObject = await admin.post(`${API}/objects/${registerId}/${suiteSchemaId}`, {
			data: { name: `Suite ${RUN}`, modules: [survivor, merged] },
		})
		expect(suiteObject.status(), await suiteObject.text()).toBeLessThan(300)
		suite = uuidOf(await suiteObject.json())
	})

	test.afterAll(async () => {
		if (!admin) {
			return
		}

		for (const [schemaId, uuid] of [[suiteSchemaId, suite], [moduleSchemaId, merged], [moduleSchemaId, survivor]]) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			}
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const schemaId of [suiteSchemaId, moduleSchemaId]) {
			if (schemaId) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		await admin.dispose()
	})

	test('the suite names both modules before anything is merged', async () => {
		expect((await suiteModules()).sort()).toEqual([survivor, merged].sort())
	})

	test('the duplicates page opened by slugs offers the pair, and the merge relinks the suite', async ({ page }) => {
		await page.goto(`${APP}/duplicates?register=${REGISTER_SLUG}&schema=${MODULE_SLUG}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSetupWizard(page)

		// The address preselected the pair: the row is there with no clicks on the pickers.
		const row = page
			.getByTestId('mdm-duplicate-row')
			.filter({ hasText: survivor })
			.filter({ hasText: merged })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await row.getByTestId('mdm-merge-launch').click()

		// The wizard names what points at the module that goes away: one suite.
		const references = page.getByTestId('mdm-merge-reference-row')
		await expect(references).toHaveCount(1, { timeout: 30_000 })
		await expect(references.first().locator('td').nth(1)).toHaveText('1')

		const confirm = page.getByTestId('mdm-merge-confirm')
		await expect(confirm).toBeDisabled()
		await page.getByTestId('mdm-merge-reason').click()
		await page.getByRole('option', { name: 'Confirmed duplicate' }).click()
		await expect(confirm).toBeEnabled()
		await confirm.click()
		await expect(confirm).toHaveCount(0, { timeout: 30_000 })

		// Read back: the suite names the survivor, and the merged module nowhere.
		const after = await suiteModules()
		expect(after).toContain(survivor)
		expect(after).not.toContain(merged)
	})

	test('reversing the operation from Merge Operations puts the suite back', async ({ page }) => {
		await page.goto(`${APP}/mergeOperations`, { waitUntil: 'domcontentloaded' })
		await dismissSetupWizard(page)

		const row = page
			.getByTestId('mdm-merge-operation-row')
			.filter({ hasText: merged })
			.filter({ hasText: survivor })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row).toContainText('Reversible')
		await row.getByTestId('mdm-merge-reverse').click()
		await expect(row).toContainText('Reversed / final', { timeout: 30_000 })
		await expect(row.getByTestId('mdm-merge-reverse')).toHaveCount(0)

		// Read back: the suite names both modules again.
		expect((await suiteModules()).sort()).toEqual([survivor, merged].sort())
	})
})
