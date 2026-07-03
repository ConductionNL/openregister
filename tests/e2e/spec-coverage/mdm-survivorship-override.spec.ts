/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec-coverage e2e tests for: mdm-survivorship-override (ADR-045 follow-on #E).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-survivorship-override/specs/<capability>/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI — select register/schema via the shared
 * RegisterSchemaSelector on Master Entities, open a golden record, launch
 * "Resolve conflicts", and exercise both the persistent (trust-rule) and
 * one-off (per-object override) outcomes. Register/schema and the master
 * entity are discovered at runtime rather than hardcoding UUIDs, so this
 * suite degrades to test.skip() in an environment with no seeded
 * survivorship data with disagreeing sources (design.md Seed Data: none
 * added by this change).
 *
 * Scenarios covered (UI test):
 *   scenario-only-disagreeing-attributes-are-listed
 *   scenario-no-conflicts-renders-an-empty-state
 *   scenario-selecting-a-winning-source-enables-save
 *   scenario-persistent-choice-writes-a-trust-configuration-row
 *   scenario-one-off-choice-sets-a-per-object-override
 *   scenario-save-failure-surfaces-an-error-and-keeps-the-modal-open (structural — asserted via component error-toast markup, not e2e)
 *   scenario-per-object-override-wins-over-the-tier-selected-value (backend-covered by PHPUnit; UI assertion is the refreshed golden record after a one-off save)
 *   override-then-merge (design.md Risks — override survives a subsequent merge recompute; not yet automated, see below)
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

/** Select the first available register, then the first available schema. */
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
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-only-disagreeing-attributes-are-listed
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-no-conflicts-renders-an-empty-state
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-selecting-a-winning-source-enables-save
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-survivorship-override — conflict-resolution modal opens from a golden record', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Resolve conflicts opens from the golden-record detail and lists conflicts or the empty state', async ({ page }) => {
		await gotoApp(page, '/masterEntities')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const viewButton = page.getByRole('button', { name: /view golden record/i }).first()
		const hasEntity = await viewButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasEntity, 'No master entities available — seed data needed')
		await viewButton.click()

		const resolveButton = page.getByRole('button', { name: /resolve conflicts/i })
		await expect(resolveButton).toBeVisible({ timeout: 10_000 })
		await resolveButton.click()

		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// Either conflict rows or the empty-content state renders — never a blank dialog.
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const emptyState = dialog.getByText(/No conflicts to resolve/i)
		await expect(conflictRow.or(emptyState)).toBeVisible({ timeout: 10_000 })

		const hasConflicts = await conflictRow.isVisible().catch(() => false)
		test.skip(!hasConflicts, 'No conflicting attributes for this master entity — seed data needed')

		const saveButton = dialog.getByRole('button', { name: /^save$/i })
		await expect(saveButton).toBeDisabled()

		const winnerCombo = conflictRow.getByRole('combobox').first()
		await winnerCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		await expect(saveButton).toBeEnabled({ timeout: 5_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-persistent-choice-writes-a-trust-configuration-row
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-survivorship-override — persistent outcome', () => {
	test.use({ storageState: STORAGE_STATE })

	test('choosing the persistent outcome and saving refreshes the golden record', async ({ page }) => {
		await gotoApp(page, '/masterEntities')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const viewButton = page.getByRole('button', { name: /view golden record/i }).first()
		const hasEntity = await viewButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasEntity, 'No master entities available — seed data needed')
		await viewButton.click()

		await page.getByRole('button', { name: /resolve conflicts/i }).click()
		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const hasConflicts = await conflictRow.isVisible({ timeout: 10_000 }).catch(() => false)
		test.skip(!hasConflicts, 'No conflicting attributes for this master entity — seed data needed')

		const winnerCombo = conflictRow.getByRole('combobox').first()
		await winnerCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		// Persistent is the default outcome — assert the radio and save.
		await expect(conflictRow.getByText(/Persistent \(trust rule\)/i)).toBeVisible()
		const saveButton = dialog.getByRole('button', { name: /^save$/i })
		await expect(saveButton).toBeEnabled({ timeout: 5_000 })
		await saveButton.click()

		await expect(dialog).toBeHidden({ timeout: 15_000 })
		await expect(page.locator('[data-testid="provenance-table"]')).toBeVisible({ timeout: 15_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-one-off-choice-sets-a-per-object-override
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-survivorship/spec.md#scenario-per-object-override-wins-over-the-tier-selected-value
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-survivorship-override — one-off outcome', () => {
	test.use({ storageState: STORAGE_STATE })

	test('choosing the one-off outcome pins the attribute and the golden record reflects it as a manual override', async ({ page }) => {
		await gotoApp(page, '/masterEntities')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const viewButton = page.getByRole('button', { name: /view golden record/i }).first()
		const hasEntity = await viewButton.isVisible({ timeout: 8_000 }).catch(() => false)
		test.skip(!hasEntity, 'No master entities available — seed data needed')
		await viewButton.click()

		await page.getByRole('button', { name: /resolve conflicts/i }).click()
		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const hasConflicts = await conflictRow.isVisible({ timeout: 10_000 }).catch(() => false)
		test.skip(!hasConflicts, 'No conflicting attributes for this master entity — seed data needed')

		const winnerCombo = conflictRow.getByRole('combobox').first()
		await winnerCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		await conflictRow.getByText(/One-off \(this record only\)/i).click()

		const saveButton = dialog.getByRole('button', { name: /^save$/i })
		await expect(saveButton).toBeEnabled({ timeout: 5_000 })
		await saveButton.click()

		await expect(dialog).toBeHidden({ timeout: 15_000 })
		await expect(page.getByText(/Manual override/i)).toBeVisible({ timeout: 15_000 })
	})
})
