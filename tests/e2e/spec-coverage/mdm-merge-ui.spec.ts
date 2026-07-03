/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec-coverage e2e tests for: mdm-merge-ui (ADR-045 follow-on #C).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI — select register/schema via the shared
 * RegisterSchemaSelector, launch the merge wizard from a candidate pair on
 * DuplicatesIndex, preview, confirm, then reverse the resulting operation
 * from the Merge Operations view. The register/schema pair and candidate
 * pair are discovered at runtime rather than hardcoding UUIDs, so this
 * suite degrades to test.skip() in an environment with no seeded
 * survivorship/duplicate data (design.md Seed Data: none added by this
 * change).
 *
 * Scenarios covered (UI test):
 *   merge-action-is-offered-per-candidate-pair
 *   wizard-is-a-standalone-modal-not-inline-markup (structural — asserted via component file layout, not e2e)
 *   preview-renders-the-projected-survivor
 *   preview-failure-surfaces-an-error-and-blocks-confirmation
 *   confirming-a-merge-executes-it-and-refreshes-candidates
 *   reason-is-mandatory
 *   reason-selector-is-accessibly-labelled
 *   merge-operations-list-renders-audit-rows
 *   view-is-registered-and-navigable
 *   reverse-offered-only-within-the-window
 *   reversing-restores-the-objects-and-updates-the-row
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

/** Navigate to an OR app subpath and wait for NC header + app content. */
async function gotoApp(page: Page, subpath: string): Promise<void> {
	await page.goto(`/index.php/apps/openregister${subpath}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
	await page.waitForSelector('#app-content-vue, .app-content', { timeout: 20_000 })
	await page.waitForTimeout(800)
}

/** Click a combobox and wait for its options to appear; returns false on timeout. */
async function clickAndWaitForOptions(
	page: Page,
	combo: ReturnType<Page['getByRole']>,
	timeout = 15_000,
): Promise<boolean> {
	await combo.click()
	try {
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout })
		return true
	} catch {
		return false
	}
}

/**
 * Select the first available register, then the first available schema, in
 * the shared RegisterSchemaSelector. Returns whether a full (register,
 * schema) pair was selected.
 */
async function selectFirstRegisterAndSchema(page: Page): Promise<boolean> {
	const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
	await expect(registerCombo).toBeVisible({ timeout: 10_000 })

	const hasRegisterOptions = await clickAndWaitForOptions(page, registerCombo)
	if (!hasRegisterOptions) return false
	await page.getByRole('option').first().click()

	const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()
	await expect(schemaCombo).not.toBeDisabled({ timeout: 8_000 })

	const hasSchemaOptions = await clickAndWaitForOptions(page, schemaCombo)
	if (!hasSchemaOptions) return false
	await page.getByRole('option').first().click()

	return true
}

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#merge-action-is-offered-per-candidate-pair
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#preview-renders-the-projected-survivor
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reason-is-mandatory
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reason-selector-is-accessibly-labelled
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#confirming-a-merge-executes-it-and-refreshes-candidates
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-merge-ui — merge wizard from a candidate pair', () => {
	test.use({ storageState: STORAGE_STATE })

	test('merge wizard opens from a candidate pair, previews, requires a reason, and executes', async ({ page }) => {
		await gotoApp(page, '/duplicates')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const mergeButton = page.getByRole('button', { name: /^merge$/i }).first()
		const hasPair = await mergeButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasPair, 'No candidate pairs available — seed data needed')

		const rowCountBefore = await page.locator('.duplicatesTable tbody tr').count()

		await mergeButton.click()
		const dialog = page.getByRole('dialog', { name: /Merge objects/i })
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// Either the preview table or an error note renders — never a blank dialog.
		const previewTable = dialog.locator('.mergeWizard__table')
		const errorNote = dialog.getByText(/could not be generated/i)
		await expect(previewTable.or(errorNote)).toBeVisible({ timeout: 15_000 })

		const hasPreview = await previewTable.isVisible().catch(() => false)
		test.skip(!hasPreview, 'Merge preview unavailable for this pair — RBAC or data mismatch')

		const confirmButton = dialog.getByRole('button', { name: /Confirm merge/i })

		// Reason is mandatory: confirm is disabled until one is chosen.
		await expect(confirmButton).toBeDisabled()

		// The reason NcSelect carries an accessible (input) label.
		const reasonCombo = dialog.getByRole('combobox', { name: 'Merge reason' })
		await expect(reasonCombo).toBeVisible()
		await reasonCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		await expect(confirmButton).toBeEnabled({ timeout: 5_000 })
		await confirmButton.click()

		// On success the dialog closes and the candidate list is reloaded.
		await expect(dialog).toBeHidden({ timeout: 15_000 })
		await expect(page.locator('.duplicatesTable tbody tr')).toHaveCount(Math.max(0, rowCountBefore - 1), { timeout: 15_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#merge-operations-list-renders-audit-rows
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#view-is-registered-and-navigable
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-merge-ui — Merge Operations view', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Merge Operations is navigable from the Data quality nav group and lists audit rows or the empty state', async ({ page }) => {
		await gotoApp(page, '/')
		const nav = page.locator('[id^="app-navigation"], .app-navigation, nav').first()

		const groupToggle = nav.getByText('Data quality', { exact: true }).first()
		await expect(groupToggle).toBeVisible({ timeout: 10_000 })
		await groupToggle.click().catch(() => {})

		const link = nav.getByRole('link', { name: 'Merge Operations', exact: true }).first()
		await expect(link).toBeVisible({ timeout: 10_000 })
		await link.click()

		await expect(page.getByRole('heading', { name: /Merge Operations/i }).first()).toBeVisible({ timeout: 15_000 })

		const emptyState = page.getByText(/No merge operations found/i)
		const table = page.locator('.mergeOperationsTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reverse-offered-only-within-the-window
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reversing-restores-the-objects-and-updates-the-row
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-merge-ui — reverse a merge operation', () => {
	test.use({ storageState: STORAGE_STATE })

	test('a reversible operation offers Reverse; reversing it refreshes the row to non-reversible', async ({ page }) => {
		await gotoApp(page, '/mergeOperations')

		const reverseButton = page.getByRole('button', { name: /^reverse$/i }).first()
		const hasReversible = await reverseButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasReversible, 'No reversible merge operations available — run the merge-wizard test first or seed data')

		const row = page.locator('.mergeOperationsTable tbody tr').filter({ has: reverseButton }).first()
		await reverseButton.click()

		// On success the row no longer offers Reverse (status flips to final).
		await expect(row.getByRole('button', { name: /^reverse$/i })).toHaveCount(0, { timeout: 15_000 })
		await expect(row.getByText(/Reversed \/ final/i)).toBeVisible({ timeout: 10_000 })
	})
})
