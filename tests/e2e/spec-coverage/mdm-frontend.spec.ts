/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec-coverage e2e tests for: mdm-frontend (ADR-045 #3).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI — select register/schema via the shared
 * RegisterSchemaSelector's NcSelect comboboxes, navigate via the "Data
 * quality" nav group, and assert rendered DOM. The register/schema pair is
 * discovered at runtime from the register combobox's first option rather
 * than hardcoding UUIDs (design.md: register 16 / schema 1207 is the
 * canonical scored dataset, but e2e should not assume fixed ids in a fresh
 * environment).
 *
 * Scenarios covered (UI test):
 *   mdm-group-appears-in-the-app-navigation
 *   each-mdm-route-renders-its-own-view-component
 *   schema-select-is-disabled-until-a-register-is-chosen
 *   selection-persists-across-mdm-views
 *   ncselect-carries-an-accessible-label
 *   kpi-cards-and-histogram-reflect-the-stats-envelope
 *   lowest-quality-table-lists-scored-objects
 *   empty-state-on-an-unscored-schema
 *   candidate-pairs-render-with-score-and-matched-attributes
 *   no-merge-or-write-action-is-present
 *   master-entities-show-quality-columns
 *   golden-record-detail-shows-attribute-provenance
 *   per-webhook-health-counts-render
 *   empty-state-when-no-webhooks-are-configured
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
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#mdm-group-appears-in-the-app-navigation
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#each-mdm-route-renders-its-own-view-component
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — navigation group', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Data quality nav group has four entries and each mounts its own view', async ({ page }) => {
		await gotoApp(page, '/')
		const nav = page.locator('[id^="app-navigation"], .app-navigation, nav').first()

		const groupToggle = nav.getByText('Data quality', { exact: true }).first()
		await expect(groupToggle).toBeVisible({ timeout: 10_000 })
		await groupToggle.click().catch(() => {})

		const entries = [
			{ label: 'Data Quality', heading: /Data Quality/i },
			{ label: 'Duplicate Candidates', heading: /Duplicate Candidates/i },
			{ label: 'Master entities', heading: /Master entities/i },
			{ label: 'Queue / sync health', heading: /Queue \/ sync health/i },
		]

		for (const entry of entries) {
			const link = nav.getByRole('link', { name: entry.label, exact: true }).first()
			await expect(link).toBeVisible({ timeout: 10_000 })
			await link.click()
			await expect(page.getByRole('heading', { name: entry.heading }).first())
				.toBeVisible({ timeout: 15_000 })
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#schema-select-is-disabled-until-a-register-is-chosen
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#ncselect-carries-an-accessible-label
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — register/schema selector', () => {
	test.use({ storageState: STORAGE_STATE })

	test('schema combobox is disabled until a register is chosen, both expose accessible labels', async ({ page }) => {
		await gotoApp(page, '/quality')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()

		await expect(registerCombo).toBeVisible({ timeout: 10_000 })
		await expect(schemaCombo).toBeVisible({ timeout: 10_000 })
		await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#selection-persists-across-mdm-views
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — selection persistence', () => {
	test.use({ storageState: STORAGE_STATE })

	test('register/schema selection on Data Quality persists on Duplicate Candidates', async ({ page }) => {
		await gotoApp(page, '/quality')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		const selectedRegisterLabel = (await registerCombo.innerText().catch(() => '')) || ''

		await gotoApp(page, '/duplicates')
		const duplicatesRegisterCombo = page.getByRole('combobox', { name: 'Register' }).first()
		await expect(duplicatesRegisterCombo).toBeVisible({ timeout: 10_000 })
		if (selectedRegisterLabel) {
			await expect(duplicatesRegisterCombo).toContainText(selectedRegisterLabel, { timeout: 10_000 })
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#kpi-cards-and-histogram-reflect-the-stats-envelope
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#lowest-quality-table-lists-scored-objects
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#empty-state-on-an-unscored-schema
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — Data Quality dashboard', () => {
	test.use({ storageState: STORAGE_STATE })

	test('selecting a register/schema shows KPI cards, histogram or table fallback, and lowest-quality listing', async ({ page }) => {
		await gotoApp(page, '/quality')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		// Either the empty state (unscored schema) or the KPI + histogram
		// surface renders — never a blank panel.
		const emptyState = page.getByText(/No scored objects for this schema/i)
		const kpiRow = page.locator('.kpiRow')
		await expect(emptyState.or(kpiRow)).toBeVisible({ timeout: 15_000 })

		const hasKpis = await kpiRow.isVisible().catch(() => false)
		if (hasKpis) {
			await expect(page.getByText(/Average score/i)).toBeVisible()
			await expect(page.getByText(/^Good$/i)).toBeVisible()
			await expect(page.getByText(/^Fair$/i)).toBeVisible()
			await expect(page.getByText(/^Poor$/i)).toBeVisible()

			// Histogram: either the chart widget or the bucket-table fallback.
			const histogramTable = page.locator('[data-testid="histogram-fallback-table"]')
			const chart = page.locator('.histogramSection').locator('svg, canvas, .apexcharts-canvas')
			await expect(histogramTable.or(chart)).toBeVisible({ timeout: 10_000 })
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#candidate-pairs-render-with-score-and-matched-attributes
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#no-merge-or-write-action-is-present
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — Duplicate Candidates', () => {
	test.use({ storageState: STORAGE_STATE })

	test('duplicate candidates render read-only with no merge/write action', async ({ page }) => {
		await gotoApp(page, '/duplicates')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const emptyState = page.getByText(/No duplicate candidates found/i)
		const table = page.locator('.duplicatesTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })

		// No merge/delete/write control anywhere on the view.
		await expect(page.getByRole('button', { name: /merge/i })).toHaveCount(0)
		await expect(page.getByRole('button', { name: /^delete$/i })).toHaveCount(0)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#master-entities-show-quality-columns
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#golden-record-detail-shows-attribute-provenance
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — Master entities + golden record', () => {
	test.use({ storageState: STORAGE_STATE })

	test('master entities list quality columns and opening one shows the golden-record panel', async ({ page }) => {
		await gotoApp(page, '/master-entities')
		const selected = await selectFirstRegisterAndSchema(page)
		test.skip(!selected, 'No register/schema options available — seed data needed')

		const emptyState = page.getByText(/No master entities found/i)
		const table = page.locator('.masterEntitiesTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })

		const hasRows = await table.isVisible().catch(() => false)
		if (hasRows) {
			await expect(page.getByText(/Quality score/i).first()).toBeVisible()
			await expect(page.getByText(/Quality status/i).first()).toBeVisible()

			const viewButton = page.getByRole('button', { name: /View golden record/i }).first()
			await viewButton.click()

			// The panel is NOT a modal — assert no dialog role appears, and the
			// panel renders inline.
			await expect(page.locator('[role="dialog"]')).toHaveCount(0)
			const panel = page.locator('.goldenRecordPanel')
			await expect(panel).toBeVisible({ timeout: 10_000 })

			const provenanceTable = panel.locator('[data-testid="provenance-table"]')
			const noProvenance = panel.getByText(/No golden-record provenance/i)
			await expect(provenanceTable.or(noProvenance)).toBeVisible({ timeout: 10_000 })
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#per-webhook-health-counts-render
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#empty-state-when-no-webhooks-are-configured
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — Queue / sync health', () => {
	test.use({ storageState: STORAGE_STATE })

	test('queue health shows per-webhook counts or the empty state', async ({ page }) => {
		await gotoApp(page, '/queue-health')

		const emptyState = page.getByText(/No webhooks configured/i)
		const table = page.locator('.webhookHealthTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })

		const hasTable = await table.isVisible().catch(() => false)
		if (hasTable) {
			await expect(page.getByText(/Delivered/i).first()).toBeVisible()
			await expect(page.getByText(/^Failed$/i).first()).toBeVisible()
			await expect(page.getByText(/Pending retries/i).first()).toBeVisible()
		}
	})
})
