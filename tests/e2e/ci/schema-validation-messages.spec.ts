/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A property's own validation message, written in the property editor and
 * returned on a refused save (modelling-validation-messages 3.2), in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The unit tests pin the declaration check, the message lookup and the form's
 * rows separately. This spec runs the whole path a functional administrator
 * takes: add `postcode` with a pattern in the property form, write a Dutch
 * message for `pattern` in the form's "Validation messages" section, save,
 * read the stored `x-error-messages` back, then save a melding with postcode
 * `ABCD` as a Dutch client and read the declared message on the refusal, next
 * to the keyword and the property. A property without a message keeps the
 * generated one.
 *
 * SELF-CLEANING. The register and the schema are created under a per-run slug
 * and removed in `afterAll`.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e${Date.now()}`
const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' }
const DUTCH = 'Vul een postcode in zoals 1234 AB.'

/** Create one entity over the API and return its id. */
async function create(request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(url, { headers: JSON_HEADERS, data })
	expect(resp.status(), `POST ${url} is accepted`).toBeLessThan(300)
	return String((await resp.json()).id)
}

/** Every error message, keyword and property in a refusal body, whatever its nesting. */
function errorEntries(body: unknown): Array<Record<string, unknown>> {
	const found: Array<Record<string, unknown>> = []
	const walk = (node: unknown): void => {
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node !== null && typeof node === 'object') {
			const entry = node as Record<string, unknown>
			if (typeof entry.message === 'string' || typeof entry.keyword === 'string') {
				found.push(entry)
			}
			Object.values(entry).forEach(walk)
		}
	}
	walk(body)
	return found
}

test.describe.configure({ mode: 'serial' })

test.describe('schema-validation-messages', () => {
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
	test.setTimeout(120_000)

	let schemaId: string
	let registerId: string

	test.beforeAll(async ({ request }) => {
		schemaId = await create(request, `${API}/schemas`, {
			title: `Meldingen ${RUN_ID}`,
			slug: `${RUN_ID}-meldingen`,
			properties: { omschrijving: { type: 'string', minLength: 10 } },
		})
		registerId = await create(request, `${API}/registers`, {
			title: `Meldingen ${RUN_ID}`,
			slug: `${RUN_ID}-register`,
			schemas: [Number(schemaId)],
		})
	})

	test.afterAll(async ({ request }) => {
		if (registerId) {
			await request.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
	})

	// @e2e schema-validation-messages::a-functional-administrator-writes-a-dutch-and-english-message
	test('the property editor stores a Dutch message for a pattern', async ({ page, request }) => {
		await page.goto(`/index.php/apps/openregister/schemas/${schemaId}`, { waitUntil: 'domcontentloaded' })
		await page.getByRole('button', { name: 'Actions', exact: true }).first().click()
		await page.locator('[data-testid="add-schema-property"]').first().click()

		const dialog = page.getByRole('dialog', { name: /Add Property to/ })
		await expect(dialog.locator('[data-testid="property-error-messages"]'), 'the form has a validation messages section').toBeVisible({ timeout: 30_000 })

		await dialog.getByLabel('Title*').fill('postcode')
		await dialog.locator('.ASP-selectContainer').first().locator('.v-select').first().click()
		await page.getByRole('option', { name: 'string', exact: true }).click()
		await dialog.getByLabel('Pattern (regex)').fill('^[1-9][0-9]{3} ?[A-Z]{2}$')

		await dialog.locator('[data-testid="property-error-message-add"]').click()
		const row = dialog.locator('[data-testid="property-error-message-row"]').first()
		await expect(row.getByLabel('Language code')).toHaveValue('nl')
		await row.getByLabel('Message').fill(DUTCH)

		await dialog.getByRole('button', { name: 'Add', exact: true }).click()
		await expect(dialog.getByText('Property successfully added')).toBeVisible({ timeout: 30_000 })

		const stored = await (await request.get(`${API}/schemas/${schemaId}`, { headers: { Accept: 'application/json' } })).json()
		expect(stored.properties.postcode['x-error-messages'], 'the message is stored under its keyword and language').toEqual({ pattern: { nl: DUTCH } })
	})

	// @e2e schema-validation-messages::a-caseworker-sees-the-dutch-message
	test('a Dutch client saving a bad postcode reads the declared message', async ({ request }) => {
		const refused = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
			headers: { ...JSON_HEADERS, 'Accept-Language': 'nl' },
			data: { omschrijving: 'Lantaarnpaal kapot bij de school', postcode: 'ABCD' },
		})
		expect(refused.status(), 'the save is refused').toBeGreaterThanOrEqual(400)
		expect(refused.status()).toBeLessThan(500)

		const entries = errorEntries(await refused.json())
		const postcode = entries.find((entry) => entry.message === DUTCH)
		expect(postcode, `the declared message is returned (got ${JSON.stringify(entries).slice(0, 400)})`).toBeTruthy()
		expect(postcode?.keyword).toBe('pattern')
	})

	// @e2e schema-validation-messages::a-field-without-a-message-keeps-the-generated-one
	test('a property without a message keeps the generated one', async ({ request }) => {
		const refused = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
			headers: { ...JSON_HEADERS, 'Accept-Language': 'nl' },
			data: { omschrijving: 'kort', postcode: '1234 AB' },
		})
		expect(refused.status()).toBeGreaterThanOrEqual(400)
		const entries = errorEntries(await refused.json())
		expect(entries.some((entry) => entry.message === DUTCH), 'no postcode message on a valid postcode').toBe(false)
		expect(entries.some((entry) => typeof entry.message === 'string' && entry.message !== ''), 'a generated message for omschrijving').toBe(true)
	})
})
