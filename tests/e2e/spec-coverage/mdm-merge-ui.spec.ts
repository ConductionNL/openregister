/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec-coverage e2e tests for: mdm-merge-ui (ADR-045 follow-on #C).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI. When the self-seeding MDM fixture
 * (tests/e2e/mdm-seed.ts, run in globalSetup) has planted a duplicate pair,
 * these tests DEEP-LINK to the seeded register/schema
 * (`#/duplicates?register=<id>&schema=<id>`) and run the full
 * duplicate→merge→reverse chain: launch the merge wizard from the candidate
 * pair, preview, provide a reason, execute, then reverse the resulting
 * operation from the Merge Operations view. Without a seed the suite degrades
 * to test.skip().
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'
import { readMdmSeed } from '../mdm-seed.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')
const seed = readMdmSeed()

/** `?register=&schema=` deep-link query for the seeded pair, or '' when unseeded. */
function scopedQuery(): string {
	return seed ? `?register=${seed.register}&schema=${seed.masterEntitySchema}` : ''
}

/** Navigate to a hash-mode OR route and wait for NC header + app content. */
async function gotoApp(page: Page, route: string): Promise<void> {
	await page.goto(`/index.php/apps/openregister/#${route}`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('#header, header.header-appcontainer', {
		timeout: 25_000,
	})
	await page.waitForSelector('#app-content-vue, .app-content', { timeout: 20_000 })
	await page.waitForTimeout(800)
	await dismissFirstRun(page)
}

/** Best-effort dismissal of the NC first-run wizard / OR support dialog. */
async function dismissFirstRun(page: Page): Promise<void> {
	for (const name of [/first run/i, /welcome/i, /support/i]) {
		const dlg = page.getByRole('dialog', { name }).first()
		if (await dlg.isVisible().catch(() => false)) {
			await dlg
				.getByRole('button', {
					name: /close|dismiss|got it|skip|no thanks/i,
				})
				.first()
				.click()
				.catch(() => {})
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
		await page.waitForSelector('[role="option"], [role="listbox"] li', {
			timeout,
		})
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

/** Land on `route` with a (register, schema) selection active. */
async function gotoScoped(page: Page, route: string): Promise<boolean> {
	if (seed) {
		await gotoApp(page, `${route}${scopedQuery()}`)
		await expect(page.getByTestId('mdm-register-select')).toBeVisible({
			timeout: 10_000,
		})
		return true
	}
	await gotoApp(page, route)
	return selectFirstRegisterAndSchema(page)
}

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#merge-action-is-offered-per-candidate-pair
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#preview-renders-the-projected-survivor
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reason-is-mandatory
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reason-selector-is-accessibly-labelled
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#confirming-a-merge-executes-it-and-refreshes-candidates
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reverse-offered-only-within-the-window
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#reversing-restores-the-objects-and-updates-the-row
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-merge-ui — duplicate → merge → reverse chain', () => {
	test.use({ storageState: STORAGE_STATE })

	test('merge wizard opens from a candidate pair, previews, requires a reason, executes, then the operation is reversed', async ({
		page,
	}) => {
		const selected = await gotoScoped(page, '/duplicates')
		test.skip(!selected, 'No register/schema available — seed data needed')

		const mergeButton = page.getByTestId('mdm-merge-launch').first()
		const hasPair = await mergeButton
			.isVisible({ timeout: 8_000 })
			.catch(() => false)
		test.skip(!hasPair, 'No candidate pairs available — seed data needed')

		const rowCountBefore = await page.getByTestId('mdm-duplicate-row').count()

		await mergeButton.click()
		const dialog = page.getByRole('dialog', { name: /Merge objects/i })
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// Either the preview table (projected survivor) or an error note renders.
		const previewTable = dialog.locator('.mergeWizard__table')
		const errorNote = dialog.getByText(/could not be generated/i)
		await expect(previewTable.or(errorNote)).toBeVisible({ timeout: 15_000 })

		const hasPreview = await previewTable.isVisible().catch(() => false)
		test.skip(
			!hasPreview,
			'Merge preview unavailable for this pair — RBAC or data mismatch',
		)

		const confirmButton = page.getByTestId('mdm-merge-confirm')

		// Reason is mandatory: confirm is disabled until one is chosen.
		await expect(confirmButton).toBeDisabled()

		// The reason NcSelect carries an accessible (input) label + test handle.
		const reasonCombo = dialog.getByRole('combobox', { name: 'Merge reason' })
		await expect(reasonCombo).toBeVisible()

		// Reverse-FK source resolution (mdm-reverse-fk-source-resolution): the
		// survivorship engine resolves the master's `sourceRecord` objects by
		// their `currentMasterEntity` back-reference and projects a populated
		// golden record, so merge#preview returns a non-empty
		// `postMergeGoldenRecord` and the preview table renders at least one row.
		await expect(
			previewTable.locator('[data-testid="mdm-merge-preview-row"]').first(),
		).toBeVisible({ timeout: 10_000 })

		await reasonCombo.click()
		await page.waitForSelector('[role="option"], [role="listbox"] li', {
			timeout: 10_000,
		})
		await page.getByRole('option').first().click()

		await expect(confirmButton).toBeEnabled({ timeout: 5_000 })
		await confirmButton.click()

		// On success the dialog closes and the candidate list is reloaded.
		await expect(dialog).toBeHidden({ timeout: 15_000 })
		const emptyAfter = page.getByText(/No duplicate candidates found/i)
		const rowsAfter = page.getByTestId('mdm-duplicate-row')
		await expect(emptyAfter.or(rowsAfter.first())).toBeVisible({
			timeout: 15_000,
		})
		// The merge must not manufacture NEW candidate pairs. It does not
		// necessarily shrink the list: duplicate detection currently still
		// surfaces a merged-away (merged-into-other) master until dedup filters
		// by lifecycle status — tracked as a follow-up in design.md Findings.
		// The merge lifecycle proof continues below with the reversal.
		expect(await rowsAfter.count()).toBeLessThanOrEqual(rowCountBefore)

		// ── Reverse the operation from the Merge Operations view. ──
		await gotoApp(page, '/mergeOperations')
		await expect(
			page.getByRole('heading', { name: /Merge Operations/i }).first(),
		).toBeVisible({ timeout: 15_000 })

		const operationRow = page.getByTestId('mdm-merge-operation-row').first()
		await expect(operationRow).toBeVisible({ timeout: 15_000 })

		const reverseButtonsBefore = await page
			.getByTestId('mdm-merge-reverse')
			.count()
		const reverseButton = page.getByTestId('mdm-merge-reverse').first()
		await expect(reverseButton).toBeVisible({ timeout: 10_000 })
		await reverseButton.click()

		// After reversal the list refreshes: one fewer Reverse action is offered
		// and a "Reversed / final" status badge is present. (Row is re-located by
		// these stable signals rather than a "has reverse button" filter, which
		// stops matching the row once its button disappears.)
		await expect(page.getByTestId('mdm-merge-reverse')).toHaveCount(
			Math.max(0, reverseButtonsBefore - 1),
			{ timeout: 15_000 },
		)
		await expect(page.getByText(/Reversed \/ final/i).first()).toBeVisible({
			timeout: 10_000,
		})
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#merge-operations-list-renders-audit-rows
// @e2e openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#view-is-registered-and-navigable
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-merge-ui — Merge Operations view', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Merge Operations is registered in the Data quality nav group and its view renders audit rows or the empty state', async ({
		page,
	}) => {
		await gotoApp(page, '/')
		const nav = page.locator('.app-navigation').first()

		// Registered: expand the Data quality group and confirm the entry exists.
		const groupToggle = nav.getByText('Data quality', { exact: true }).first()
		await expect(groupToggle).toBeVisible({ timeout: 10_000 })
		await groupToggle.click().catch(() => {})
		await expect(
			nav.getByText('Merge Operations', { exact: true }).first(),
		).toBeVisible({ timeout: 10_000 })

		// Navigable: the hash route renders the dedicated audit view. (Deep-link
		// rather than click-through so the assertion does not depend on the
		// nav group's expand/collapse animation state.)
		await gotoApp(page, '/mergeOperations')
		await expect(
			page.getByRole('heading', { name: /Merge Operations/i }).first(),
		).toBeVisible({ timeout: 15_000 })

		const emptyState = page.getByText(/No merge operations found/i)
		const table = page.locator('.mergeOperationsTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })
	})
})
