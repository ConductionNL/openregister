import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for the mdm-frontend change (ADR-045 #3,
 * hydra gate-26). Each of the four new "Data quality" views —
 * QualityIndex, DuplicatesIndex, MasterEntitiesIndex, QueueHealthIndex —
 * plus the GoldenRecordDetail panel gets a baseline here, matched by
 * component stem per the visual-coverage gate.
 *
 * Run:   npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { expect, test } from '@playwright/test'
import {
	dismissSupportDialog,
	dynamicMasks,
	freezePage,
	shootSurface,
	SHOT_OPTIONS,
	waitForContentReady,
} from './_visual-helpers.ts'

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

test.describe('mdm-frontend — visual baselines', () => {
	// QualityIndex (Data Quality dashboard).
	test('QualityIndex', async ({ page }) => {
		await shootSurface(page, `${APP}/quality`, 'QualityIndex.png')
	})

	// DuplicatesIndex (Duplicate Candidates, read-only).
	test('DuplicatesIndex', async ({ page }) => {
		await shootSurface(page, `${APP}/duplicates`, 'DuplicatesIndex.png')
	})

	// MasterEntitiesIndex (master-entity list).
	test('MasterEntitiesIndex', async ({ page }) => {
		await shootSurface(page, `${APP}/master-entities`, 'MasterEntitiesIndex.png')
	})

	// GoldenRecordDetail (opened from MasterEntitiesIndex — not a standalone
	// route, so this baseline drives the panel open before shooting).
	test('GoldenRecordDetail', async ({ page }) => {
		await page.goto(`${APP}/master-entities`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page)
		await waitForContentReady(page)

		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(
			!selected,
			'No register/schema options available — seed data needed',
		)

		const viewButton = page
			.getByRole('button', { name: /View golden record/i })
			.first()
		const hasRow = await viewButton
			.isVisible({ timeout: 8_000 })
			.catch(() => false)
		test.skip(!hasRow, 'No master-entity rows available — seed data needed')

		await viewButton.click()
		await page
			.locator('.goldenRecordPanel')
			.waitFor({ state: 'visible', timeout: 10_000 })
		await freezePage(page)
		await expect(page).toHaveScreenshot('GoldenRecordDetail.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})

	// QueueHealthIndex (Queue / sync health).
	test('QueueHealthIndex', async ({ page }) => {
		await shootSurface(page, `${APP}/queue-health`, 'QueueHealthIndex.png')
	})
})
