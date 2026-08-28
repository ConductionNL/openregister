/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for the mdm-merge-ui change (ADR-045 follow-on
 * #C, hydra gate-26). Covers the two new surfaces:
 *   - MergeOperationsIndex (src/views/quality/MergeOperationsIndex.vue)
 *   - MdmMergeWizardModal (src/modals/mdm/MdmMergeWizardModal.vue), opened
 *     from a candidate pair on DuplicatesIndex.
 *
 * Run:   npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test, expect, type Page } from '@playwright/test'
import {
	shootSurface,
	waitForContentReady,
	dismissSupportDialog,
	freezePage,
	SHOT_OPTIONS,
	dynamicMasks,
} from './_visual-helpers'

const APP = '/index.php/apps/openregister'

/** Click a combobox and wait for its options to appear; returns false on timeout. */
async function clickAndWaitForOptions(
	page: Page,
	combo: ReturnType<Page['getByRole']>,
	timeout = 15_000,
): Promise<boolean> {
	await combo.click()
	try {
		await page.waitForSelector('[role="option"], [role="listbox"] li', {
			timeout,
		})
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

test.describe('mdm-merge-ui — visual baselines', () => {
	// MergeOperationsIndex (Merge Operations audit list).
	test('MergeOperationsIndex', async ({ page }) => {
		await shootSurface(
			page,
			`${APP}/#/mergeOperations`,
			'MergeOperationsIndex.png',
		)
	})

	// MdmMergeWizardModal — opened from a candidate pair on DuplicatesIndex.
	test('MdmMergeWizardModal', async ({ page }) => {
		await page.goto(`${APP}/#/duplicates`, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		await waitForContentReady(page)

		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(
			!selected,
			'No register/schema options available — seed data needed',
		)

		const mergeButton = page.getByRole('button', { name: /^merge$/i }).first()
		const hasPair = await mergeButton
			.isVisible({ timeout: 8_000 })
			.catch(() => false)
		test.skip(!hasPair, 'No candidate pairs available — seed data needed')

		await mergeButton.click()
		await page
			.getByRole('dialog', { name: /Merge objects/i })
			.waitFor({ state: 'visible', timeout: 10_000 })
		// Wait for the preview request to settle (either content or an error note).
		await page.waitForTimeout(1_000)
		await freezePage(page)
		await expect(page).toHaveScreenshot('MdmMergeWizardModal.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
