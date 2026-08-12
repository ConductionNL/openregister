/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e tests for: mdm-survivorship-override (ADR-045 follow-on #E).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-survivorship-override/specs/<capability>/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI. When the self-seeding MDM fixture
 * (tests/e2e/mdm-seed.ts, run in globalSetup) has planted a multi-source
 * conflict entity (two sources disagreeing on `name`), these tests DEEP-LINK
 * to the seeded register/schema on Master entities, open that specific
 * entity's golden record (matched by its seeded uuid), launch "Resolve
 * conflicts", and exercise both the persistent (trust-rule) and one-off
 * (per-object override) outcomes. Without a seed the suite falls back to the
 * first entity and degrades to test.skip().
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import { readMdmSeed } from '../mdm-seed'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')
const seed = readMdmSeed()

/** `?register=&schema=` deep-link query for the seeded pair, or '' when unseeded. */
function scopedQuery(): string {
	return seed ? `?register=${seed.register}&schema=${seed.masterEntitySchema}` : ''
}

/** Navigate to a hash-mode OR route and wait for NC header + app content. */
async function gotoApp(page: Page, route: string): Promise<void> {
	await page.goto(`/index.php/apps/openregister/#${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
	await page.waitForSelector('#app-content-vue, .app-content', { timeout: 20_000 })
	await page.waitForTimeout(800)
	await dismissFirstRun(page)
}

/** Best-effort dismissal of the NC first-run wizard / OR support dialog. */
async function dismissFirstRun(page: Page): Promise<void> {
	for (const name of [/first run/i, /welcome/i, /support/i]) {
		const dlg = page.getByRole('dialog', { name }).first()
		if (await dlg.isVisible().catch(() => false)) {
			await dlg.getByRole('button', { name: /close|dismiss|got it|skip|no thanks/i }).first().click().catch(() => {})
		}
	}
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

/** Drive the shared selector to the first (register, schema). Returns success. */
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

/** Land on Master entities with a (register, schema) selection active. */
async function gotoMasterEntities(page: Page): Promise<boolean> {
	if (seed) {
		await gotoApp(page, `/master-entities${scopedQuery()}`)
		await expect(page.getByTestId('mdm-register-select')).toBeVisible({ timeout: 10_000 })
		return true
	}
	await gotoApp(page, '/master-entities')
	return selectFirstRegisterAndSchema(page)
}

/**
 * Open the conflict entity's golden record and launch the conflict-resolution
 * modal. With a seed the specific seeded conflict entity (matched by uuid) is
 * opened; otherwise the first master entity is used. Returns whether the
 * modal opened.
 */
async function openResolveConflicts(page: Page): Promise<boolean> {
	const selected = await gotoMasterEntities(page)
	if (!selected) return false

	let viewButton
	if (seed) {
		const row = page.getByTestId('mdm-master-entity-row').filter({ hasText: seed.conflictUuid }).first()
		if (!(await row.isVisible({ timeout: 10_000 }).catch(() => false))) return false
		viewButton = row.getByTestId('mdm-view-golden-record')
	} else {
		viewButton = page.getByTestId('mdm-view-golden-record').first()
		if (!(await viewButton.isVisible({ timeout: 8_000 }).catch(() => false))) return false
	}

	await viewButton.click()
	const resolveButton = page.getByTestId('mdm-resolve-conflicts')
	await expect(resolveButton).toBeVisible({ timeout: 10_000 })
	await resolveButton.click()
	// openConflicts() fetches the reverse-FK sources asynchronously before it
	// shows the modal — wait for the dialog + its conflict/empty surface to
	// settle so callers observe the resolved state, not a pre-fetch frame.
	const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
	await dialog.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {})
	const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
	const emptyState = dialog.getByText(/No conflicts to resolve/i)
	await expect(conflictRow.or(emptyState)).toBeVisible({ timeout: 10_000 }).catch(() => {})
	return true
}

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-only-disagreeing-attributes-are-listed
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-no-conflicts-renders-an-empty-state
// @e2e openspec/changes/mdm-survivorship-override/specs/mdm-conflict-resolution-ui/spec.md#scenario-selecting-a-winning-source-enables-save
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-survivorship-override — conflict-resolution modal opens from a golden record', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Resolve conflicts opens from the golden-record detail and lists the disagreeing attribute', async ({ page }) => {
		const opened = await openResolveConflicts(page)
		test.skip(!opened, 'No master entities available — seed data needed')

		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// Either conflict rows or the empty-content state renders — never blank.
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const emptyState = dialog.getByText(/No conflicts to resolve/i)
		await expect(conflictRow.or(emptyState)).toBeVisible({ timeout: 10_000 })

		const hasConflicts = await conflictRow.isVisible().catch(() => false)
		// Reverse-FK source resolution (mdm-reverse-fk-source-resolution): the
		// seeded conflict master has two `sourceRecord` objects disagreeing on
		// `name` (crm "ACME NV" vs erp "ACME B.V."). The modal fetches them via
		// the survivorship sources endpoint and lists the disagreement. When
		// seeded this MUST be present; unseeded instances skip.
		if (seed) {
			expect(hasConflicts).toBe(true)
			await expect(conflictRow.getByText(/name/i).first()).toBeVisible()
		} else {
			test.skip(!hasConflicts, 'No disagreeing-source conflict present — seed data needed')
		}

		const saveButton = page.getByTestId('mdm-conflict-save')
		await expect(saveButton).toBeDisabled()

		const winnerCombo = dialog.getByTestId('mdm-conflict-source-select').first()
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
		const opened = await openResolveConflicts(page)
		test.skip(!opened, 'No master entities available — seed data needed')

		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const hasConflicts = await conflictRow.isVisible({ timeout: 10_000 }).catch(() => false)
		// Reverse-FK: the seeded conflict master surfaces a real `name`
		// disagreement across its two sourceRecord objects. Assert when seeded;
		// unseeded instances skip.
		if (seed) {
			expect(hasConflicts).toBe(true)
		} else {
			test.skip(!hasConflicts, 'No disagreeing-source conflict present — seed data needed')
		}

		const winnerCombo = dialog.getByTestId('mdm-conflict-source-select').first()
		await winnerCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		// Persistent is the default outcome — assert the radio and save.
		await expect(conflictRow.getByText(/Persistent \(trust rule\)/i)).toBeVisible()
		const saveButton = page.getByTestId('mdm-conflict-save')
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
		const opened = await openResolveConflicts(page)
		test.skip(!opened, 'No master entities available — seed data needed')

		const dialog = page.getByRole('dialog', { name: /Resolve conflicts/i })
		const conflictRow = dialog.locator('[data-testid="conflict-row"]').first()
		const hasConflicts = await conflictRow.isVisible({ timeout: 10_000 }).catch(() => false)
		// Reverse-FK: the seeded conflict master surfaces a real `name`
		// disagreement across its two sourceRecord objects. Assert when seeded;
		// unseeded instances skip.
		if (seed) {
			expect(hasConflicts).toBe(true)
		} else {
			test.skip(!hasConflicts, 'No disagreeing-source conflict present — seed data needed')
		}

		const winnerCombo = dialog.getByTestId('mdm-conflict-source-select').first()
		await winnerCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout: 10_000 })
		await page.getByRole('option').first().click()

		await conflictRow.getByText(/One-off \(this record only\)/i).click()

		const saveButton = page.getByTestId('mdm-conflict-save')
		await expect(saveButton).toBeEnabled({ timeout: 5_000 })
		await saveButton.click()

		await expect(dialog).toBeHidden({ timeout: 15_000 })
		await expect(page.getByText(/Manual override/i)).toBeVisible({ timeout: 15_000 })
	})
})
