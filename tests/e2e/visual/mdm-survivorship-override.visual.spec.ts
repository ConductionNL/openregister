/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Visual-regression baseline for the mdm-survivorship-override change
 * (ADR-045 follow-on #E, hydra gate-26). Covers the one new surface:
 *   - MdmConflictResolutionModal (src/modals/mdm/MdmConflictResolutionModal.vue),
 *     opened from GoldenRecordDetail on Master Entities.
 *
 * Run:   npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test, expect, type Page } from '@playwright/test'
import { dismissSupportDialog, waitForContentReady, freezePage, SHOT_OPTIONS, dynamicMasks } from './_visual-helpers'

const APP = '/index.php/apps/openregister'

/** Click a combobox and wait for its options to appear; returns false on timeout. */
async function clickAndWaitForOptions(page: Page, combo: ReturnType<Page['getByRole']>, timeout = 15_000): Promise<boolean> {
	await combo.click()
	try {
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout })
		return true
	} catch {
		return false
	}
}

/** Select the first available register + schema on the current MDM view, if any exist. */
async function selectFirstRegisterAndSchema(page: Page): Promise<boolean> {
	const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
	if (!(await registerCombo.isVisible().catch(() => false))) return false
	if (!(await clickAndWaitForOptions(page, registerCombo))) return false
	await page.getByRole('option').first().click()

	const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()
	await schemaCombo.waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {})
	if (!(await clickAndWaitForOptions(page, schemaCombo))) return false
	await page.getByRole('option').first().click()
	return true
}

test.describe('mdm-survivorship-override — visual baselines', () => {
	// MdmConflictResolutionModal — opened from a master entity's golden record.
	test('MdmConflictResolutionModal', async ({ page }) => {
		// Manifest route is kebab-case '/master-entities' (src/manifest.json);
		// '#/masterEntities' hits the catch-all and redirects to the dashboard.
		await page.goto(`${APP}/#/master-entities`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		await waitForContentReady(page)

		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const viewButton = page.getByRole('button', { name: /view golden record/i }).first()
		const hasEntity = await viewButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasEntity, 'No master entities available — seed data needed')
		await viewButton.click()

		const resolveButton = page.getByRole('button', { name: /resolve conflicts/i })
		await resolveButton.waitFor({ state: 'visible', timeout: 10_000 })
		await resolveButton.click()

		await page.getByRole('dialog', { name: /Resolve conflicts/i }).waitFor({ state: 'visible', timeout: 10_000 })
		await page.waitForTimeout(500)
		await freezePage(page)
		await expect(page).toHaveScreenshot('MdmConflictResolutionModal.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
