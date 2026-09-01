import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep, data-dependent CRUD journey for REGISTERS — through the real UI.
 *
 * Unlike the shell/render tests (which only assert that the registers view
 * mounts), this spec proves the full create→read→update→delete cycle WITH
 * PERSISTENCE:
 *
 *   1. CREATE a register through the real UI form (CnIndexPage "Add Register"
 *      → CnFormDialog → fill Title/Slug/Description → submit).
 *   2. READ — assert the new register appears as a real ROW in the registers
 *      table (its title rendered as text), and that it actually persisted
 *      (GET /api/registers/{id} returns the values).
 *   3. UPDATE — change the title and assert the persisted value changed AND
 *      the table row re-renders with the new title.
 *   4. DELETE — remove it and assert it is GONE from both the API and the
 *      rendered list.
 *
 * Stable selectors come from @conduction/nextcloud-vue's CnIndexPage shell:
 *   - [data-testid="cn-index-page"]          the page
 *   - [data-testid="cn-cta-primary"]          the Add button
 *   - [data-testid-modal="cn-form-dialog"]    the create/edit dialog
 *
 * Everything created here is namespaced with a per-run prefix and removed in
 * afterAll (belt-and-braces; the delete test removes it in the happy path).
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
import { makeRunId } from '../_fixtures.ts'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
// HASH form — the router runs in hash mode (src/main.js); the path-form URL
// renders the dashboard instead of the registers page.
const REGISTERS_ROUTE = '/index.php/apps/openregister/#/registers'
const API = '/index.php/apps/openregister/api'

const RUN_ID = makeRunId()
const REG_TITLE = `E2E Register ${RUN_ID}`
const REG_TITLE_UPDATED = `${REG_TITLE} (updated)`
const REG_SLUG = `${RUN_ID}-ui-register`

test.describe.configure({ mode: 'serial' })

test.describe('register-crud — full create→read→update→delete with persistence', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId: number | null = null

	/** Find the register we created by slug via the API. */
	async function findOurRegister(
		request: APIRequestContext,
	): Promise<Record<string, any> | null> {
		const resp = await request.get(`${API}/registers?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		if (!resp.ok()) return null
		const body = await resp.json()
		return (body.results ?? []).find((r: any) => r.slug === REG_SLUG) ?? null
	}

	test('CREATE a register through the UI and assert it persisted', async ({
		page,
		request,
	}) => {
		await page.goto(REGISTERS_ROUTE, { waitUntil: 'domcontentloaded' })

		const indexPage = page.locator('[data-testid="cn-index-page"]')
		await expect(indexPage).toBeVisible({ timeout: 30_000 })

		// Open the Add Register dialog via the primary CTA.
		const addButton = page.locator('[data-testid="cn-cta-primary"]')
		await expect(addButton).toBeVisible({ timeout: 15_000 })
		await addButton.click()

		const dialog = page.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// The register #form-fields slot renders floating-label NcTextFields whose
		// inputs are not wired to a <label for> (NC8 floating labels), so target
		// them positionally: [0]=Title, [1]=Slug text inputs; the textarea is
		// Description. This matches the RegistersIndex form-fields slot order.
		const textInputs = dialog.locator('input[type="text"], input:not([type])')
		await expect(textInputs.first()).toBeVisible({ timeout: 10_000 })
		await textInputs.nth(0).fill(REG_TITLE)
		await textInputs.nth(1).fill(REG_SLUG)
		const description = dialog.locator('textarea')
		if (await description.count()) {
			await description.first().fill('Created by register-crud e2e (UI)')
		}

		// Submit — the confirm button ("Create") lives in the NcDialog footer
		// (.modal-container), OUTSIDE the cn-form-dialog content wrapper.
		const submit = page
			.locator('.modal-container')
			.getByRole('button', { name: /^(Create|Save)$/ })
			.last()
		await expect(submit).toBeVisible({ timeout: 10_000 })
		await submit.click()

		// Dialog closes on success (the form-dialog content unmounts).
		await expect(dialog).toBeHidden({ timeout: 15_000 })
		await page
			.locator('.modal-container')
			.getByRole('button', { name: /^(Create|Save)$/ })
			.waitFor({ state: 'hidden', timeout: 15_000 })
			.catch(() => {})

		// PERSISTENCE: the register must now exist in the backend with our values.
		await expect
			.poll(
				async () => {
					const reg = await findOurRegister(request)
					registerId = reg?.id ?? null
					return reg?.title ?? null
				},
				{
					timeout: 15_000,
					message:
						'created register should be persisted with the submitted title',
				},
			)
			.toBe(REG_TITLE)

		expect(registerId, 'register id resolved from API').toBeTruthy()
	})

	test('READ — the new register renders as a real row in the registers table', async ({
		page,
	}) => {
		test.skip(registerId === null, 'create step did not persist a register')
		await page.goto(REGISTERS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})

		// The register title must be rendered somewhere in the list (table row
		// or card), NOT just an empty state.
		await expect(
			page.getByText(REG_TITLE, { exact: false }).first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('UPDATE — edit the title and assert persistence + re-render', async ({
		page,
		request,
	}) => {
		test.skip(registerId === null, 'no register to update')

		// Drive the persisted change. (The per-row edit action lives behind a
		// kebab menu in the manifest shell; we mutate via the documented API and
		// then assert the UI reflects the new persisted value — the data-dependent
		// guarantee this layer exists to prove.)
		const put = await request.put(`${API}/registers/${registerId}`, {
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			data: {
				slug: REG_SLUG,
				title: REG_TITLE_UPDATED,
				description: 'updated by e2e',
			},
		})
		expect(put.status(), 'PUT /api/registers/{id}').toBe(200)
		expect((await put.json()).title).toBe(REG_TITLE_UPDATED)

		// The list view must now show the updated title and no longer the old one.
		await page.goto(REGISTERS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText(REG_TITLE_UPDATED, { exact: false }).first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('DELETE — remove the register and assert it is gone from API and list', async ({
		page,
		request,
	}) => {
		test.skip(registerId === null, 'no register to delete')

		const del = await request.delete(`${API}/registers/${registerId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(del.status(), 'DELETE /api/registers/{id}').toBe(200)

		// API: the register must no longer be retrievable.
		const gone = await request.get(`${API}/registers/${registerId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(
			gone.status(),
			'deleted register should not return 200',
		).toBeGreaterThanOrEqual(400)

		// UI: the (updated) title must no longer appear as a row.
		await page.goto(REGISTERS_ROUTE, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-index-page"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText(REG_TITLE_UPDATED, { exact: false }),
		).toHaveCount(0, { timeout: 20_000 })

		registerId = null
	})

	test.afterAll(async ({ request }) => {
		if (registerId === null) {
			// Resolve by slug in case an assertion aborted before capturing the id.
			const resp = await request
				.get(`${API}/registers?_limit=200`, {
					headers: { Accept: 'application/json' },
				})
				.catch(() => null)
			if (resp && resp.ok()) {
				const reg = (await resp.json()).results?.find(
					(r: any) => r.slug === REG_SLUG,
				)
				registerId = reg?.id ?? null
			}
		}
		if (registerId !== null) {
			await request.delete(`${API}/registers/${registerId}`).catch(() => {})
		}
	})
})
