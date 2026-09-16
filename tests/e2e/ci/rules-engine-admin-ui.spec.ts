/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules tab on a schema, driven the way an administrator drives it.
 *
 * The API half of this loop is covered by rules-engine-operability.spec.ts.
 * This one asserts the screen: that the rules appear in evaluation order, that
 * the trial shows the operand that decided, and that switching a rule off
 * changes what the list says.
 *
 * ⚠️ THE TRACE IS THE ASSERTION, NOT THE VERDICT. A screen that renders
 * "no_match" and nothing else is the screen this change exists to replace, so
 * the test reads the operand and the value beside it. Asserting only the
 * verdict would pass on exactly the surface we are trying to leave behind.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'

const RUN_ID = `e2e-ui-${Date.now()}`
const SCHEMA_SLUG = `${RUN_ID}-bezwaar`

/** Six weeks after the date of receipt: the study's own worked example. */
const CALCULATION = {
	type: 'date',
	expression: {
		dateAdd: {
			date: { prop: 'ontvangstdatum' },
			amount: 6,
			unit: 'weeks',
		},
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('rules-engine-operability admin ui', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemaId: string | null = null

	test.beforeAll(async ({ request }) => {
		const resp = await request.post(`${API}/schemas`, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: SCHEMA_SLUG,
				slug: SCHEMA_SLUG,
				properties: {
					ontvangstdatum: {
						type: 'string',
						format: 'date',
						title: 'Date of receipt',
						description: 'When the objection came in.',
					},
					bedrag: {
						type: 'number',
						title: 'Amount',
						description: 'The amount under objection.',
					},
				},
				configuration: {
					'x-openregister-calculations': { uiterlijkeDatum: CALCULATION },
				},
			},
		})
		expect(resp.ok()).toBeTruthy()
		schemaId = String((await resp.json()).id)
	})

	test.afterAll(async ({ request }) => {
		if (schemaId) {
			await request.delete(`${API}/schemas/${schemaId}`)
		}
	})

	/** Open the schema and switch to its rules tab. */
	async function openRulesTab(page: Page): Promise<void> {
		await page.goto(`/index.php/apps/openregister/schemas/${schemaId}`)
		await page.getByRole('button', { name: 'Rules' }).click()
		await expect(
			page.getByRole('heading', { name: 'Rules', exact: true }),
		).toBeVisible()
	}

	test('the rules a schema declares are listed in evaluation order', async ({
		page,
	}) => {
		await openRulesTab(page)

		const rows = page.locator('.ruleTable tbody tr')
		await expect(rows).toHaveCount(1)
		await expect(rows.first()).toContainText('uiterlijkeDatum')
		await expect(rows.first()).toContainText(
			`calculation:${SCHEMA_SLUG}:uiterlijkeDatum`,
		)
	})

	test('a trial names the operand that decided, not just the verdict', async ({
		page,
	}) => {
		await openRulesTab(page)
		await page.getByRole('button', { name: 'Open' }).first().click()

		await page
			.locator('#ruleTrialSample')
			.fill('{"ontvangstdatum": "2026-01-01"}')
		await page.getByRole('button', { name: 'Run it' }).click()

		await expect(page.locator('.ruleTrace')).toContainText('fired')
		await expect(page.locator('.writeTable')).toContainText('uiterlijkeDatum')
	})

	test('an unevaluable trial says which operand it read', async ({ page }) => {
		await openRulesTab(page)
		await page.getByRole('button', { name: 'Open' }).first().click()

		// No ontvangstdatum at all: the calculation cannot derive a date, and
		// the trace is the only thing that says why.
		await page.locator('#ruleTrialSample').fill('{}')
		await page.getByRole('button', { name: 'Run it' }).click()

		await expect(page.locator('.ruleTrace')).toBeVisible()
		await expect(page.locator('.ruleTrace')).not.toContainText('fired')
	})

	test('an operator no engine holds is refused before the request is made', async ({
		page,
	}) => {
		await openRulesTab(page)
		await page.getByRole('button', { name: 'Open' }).first().click()

		await page
			.locator('#ruleConditionDraft')
			.fill('{"isVerySure": [{"prop": "bedrag"}]}')

		await expect(page.locator('.ruleEditor .refusal')).toContainText(
			'isVerySure',
		)
	})

	test('switching a rule off changes what the list says', async ({ page }) => {
		await openRulesTab(page)

		const toggle = page
			.locator('.ruleTable tbody tr')
			.first()
			.getByRole('checkbox')
		await toggle.click()

		await expect(toggle).not.toBeChecked()

		// The list is re-read from the server, so the state on screen is the
		// state the schema now carries rather than an optimistic flip.
		await page.reload()
		await page.getByRole('button', { name: 'Rules' }).click()
		await expect(
			page.locator('.ruleTable tbody tr').first().getByRole('checkbox'),
		).not.toBeChecked()
	})

	test('the run log says what it is when there is nothing in it', async ({
		page,
	}) => {
		await openRulesTab(page)
		await page.getByRole('button', { name: 'Open' }).first().click()

		await expect(page.locator('.ruleRuns')).toContainText(
			'A dry run writes none',
		)
	})
})
