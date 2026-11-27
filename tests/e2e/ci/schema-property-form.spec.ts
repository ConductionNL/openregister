/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A schema's fields are built in a form (REQ-MSR-004), in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The requirement is that a functional administrator adds a property without
 * writing JSON, and that the property is usable on the next object create
 * with no restart. A component test sees the form assemble a schema; it does
 * not see the schema page open the form, the schema store save it, or the
 * object API accept a value for the new property afterwards. So this spec
 * opens the real schema page, adds `prioriteit` through the property form,
 * reads the stored schema back over HTTP, and creates a `melding` that
 * carries `prioriteit: hoog`. The read-back is the assertion.
 *
 * The second test opens the same form and leaves the name empty: the form
 * marks the name as required, refuses to save, and the stored schema keeps
 * the properties it had.
 *
 * The scenario also names the values `laag`, `normaal` and `hoog`. The form
 * offers no input for a list of allowed values (the requirement's list of
 * fields does not name one either), so this spec creates the object with
 * `hoog` and does not assert an enum.
 *
 * SELF-CLEANING. The register, the schema and the object are created under a
 * per-run slug and removed in `afterAll`.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e${Date.now()}`
const REGISTER_SLUG = `${RUN_ID}-meldingen`
const SCHEMA_SLUG = `${RUN_ID}-melding`
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' }

/** The stored schema, read over the API. */
async function readSchema(request: APIRequestContext, id: string): Promise<Record<string, any>> {
	const resp = await request.get(`${API}/schemas/${id}`, { headers: { Accept: 'application/json' } })
	expect(resp.status(), 'the schema is readable').toBe(200)
	return resp.json()
}

test.describe.configure({ mode: 'serial' })

test.describe('schema-property-form', () => {
	test.use({ storageState: STORAGE_STATE })
	test.setTimeout(120_000)

	let registerId: string | null = null
	let schemaId: string | null = null
	let objectId: string | null = null

	test.beforeAll(async ({ request }) => {
		const schema = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `Melding ${RUN_ID}`,
				slug: SCHEMA_SLUG,
				properties: { omschrijving: { type: 'string' } },
			},
		})
		expect(schema.status(), 'the fixture schema is accepted').toBeLessThan(300)
		const schemaBody = await schema.json()
		schemaId = String(schemaBody.id)

		const register = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: { title: `Meldingen ${RUN_ID}`, slug: REGISTER_SLUG, schemas: [Number(schemaId)] },
		})
		expect(register.status(), 'the fixture register is accepted').toBeLessThan(300)
		registerId = String((await register.json()).id)
	})

	test.afterAll(async ({ request }) => {
		if (objectId !== null && registerId !== null && schemaId !== null) {
			await request.delete(`${API}/objects/${registerId}/${schemaId}/${objectId}`)
		}
		if (registerId !== null) {
			await request.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== null) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
	})

	/** Open the schema page and the add-property form on it. */
	async function openAddPropertyForm(page): Promise<void> {
		await page.goto(`/index.php/apps/openregister/schemas/${schemaId}`, { waitUntil: 'domcontentloaded' })
		const add = page.locator('[data-testid="add-schema-property"]').first()
		if (!(await add.isVisible().catch(() => false))) {
			// The action sits in the page's actions menu on narrow layouts.
			await page.getByRole('button', { name: /actions/i }).first().click()
		}
		await add.click()
		await expect(page.locator('[data-testid="property-titles"]'), 'the property form opens').toBeVisible({ timeout: 30_000 })
	}

	/** The dialog the property form renders in. */
	function propertyDialog(page) {
		return page.getByRole('dialog', { name: /Add Property to/ })
	}

	/** Pick a type in the form's type select. */
	async function pickType(page, type: string): Promise<void> {
		const dialog = propertyDialog(page)
		const select = dialog.locator('.ASP-selectContainer').first().locator('.v-select').first()
		await select.click()
		await page.getByRole('option', { name: type, exact: true }).click()
	}

	// @e2e entity-management-modals::a-field-is-added-in-the-form
	test('a property added in the form is stored and usable on the next create', async ({ page, request }) => {
		await openAddPropertyForm(page)
		const dialog = propertyDialog(page)

		await dialog.getByLabel('Title*').fill('prioriteit')
		await pickType(page, 'string')

		const add = dialog.getByRole('button', { name: 'Add', exact: true })
		await expect(add, 'a named, typed property can be saved').toBeEnabled()
		await add.click()
		await expect(dialog.getByText('Property successfully added'), 'the form reports the save').toBeVisible({ timeout: 30_000 })

		const stored = await readSchema(request, String(schemaId))
		expect(Object.keys(stored.properties ?? {}), 'the schema lists prioriteit').toContain('prioriteit')
		expect(stored.properties.prioriteit.type).toBe('string')
		expect(Object.keys(stored.properties), 'the existing property is kept').toContain('omschrijving')

		const created = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
			headers: JSON_HEADERS,
			data: { omschrijving: 'Lantaarnpaal kapot', prioriteit: 'hoog' },
		})
		expect(created.status(), 'a melding with prioriteit is accepted').toBeLessThan(300)
		const body = await created.json()
		objectId = String(body.id ?? body['@self']?.id)

		const read = await request.get(`${API}/objects/${registerId}/${schemaId}/${objectId}`, { headers: { Accept: 'application/json' } })
		expect(read.status()).toBe(200)
		expect((await read.json()).prioriteit, 'the new property is stored on the object').toBe('hoog')
	})

	// @e2e entity-management-modals::an-invalid-property-is-not-saved
	test('a property without a name is not saved', async ({ page, request }) => {
		const before = await readSchema(request, String(schemaId))

		await openAddPropertyForm(page)
		const dialog = propertyDialog(page)
		await pickType(page, 'string')

		await expect(dialog.getByLabel('Title*'), 'the form marks the name as required').toBeVisible()
		await expect(dialog.getByLabel('Title*')).toHaveValue('')
		await expect(dialog.getByRole('button', { name: 'Add', exact: true }), 'an unnamed property cannot be saved').toBeDisabled()

		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).toBeHidden()

		const after = await readSchema(request, String(schemaId))
		expect(Object.keys(after.properties ?? {}).sort(), 'the schema is not saved').toEqual(Object.keys(before.properties ?? {}).sort())
	})
})
