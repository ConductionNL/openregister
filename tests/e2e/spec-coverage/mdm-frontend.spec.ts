/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec-coverage e2e tests for: mdm-frontend (ADR-045 #3).
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI. The five MDM views live under the hash-mode
 * router (`/index.php/apps/openregister/#/<route>`); the shared
 * RegisterSchemaSelector is now route-scoped, so when the self-seeding MDM
 * fixture (tests/e2e/mdm-seed.ts, run in globalSetup) has planted data, these
 * tests DEEP-LINK straight to the seeded register/schema
 * (`#/quality?register=<id>&schema=<id>`) and assert populated surfaces.
 * Without a seed (no pipelinq instance) they fall back to driving the selector
 * and degrade to test.skip(), so the suite still runs everywhere.
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

/**
 * Land on `route` with a (register, schema) selection active. With a seed the
 * route query pre-selects it (no clicks); otherwise the selector is driven.
 * Returns whether a selection is active.
 */
async function gotoScoped(page: Page, route: string): Promise<boolean> {
	if (seed) {
		await gotoApp(page, `${route}${scopedQuery()}`)
		await expect(page.getByTestId('mdm-register-select')).toBeVisible({ timeout: 10_000 })
		return true
	}
	await gotoApp(page, route)
	return selectFirstRegisterAndSchema(page)
}

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#mdm-group-appears-in-the-app-navigation
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#each-mdm-route-renders-its-own-view-component
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — navigation group', () => {
	test.use({ storageState: STORAGE_STATE })

	test('Data quality nav group has five entries and each mounts its own view', async ({ page }) => {
		await gotoApp(page, '/')
		// The OR sidebar is `.app-navigation`; a bare `nav` selector can match the
		// header/user-menu nav first, so scope explicitly to the sidebar.
		const nav = page.locator('.app-navigation').first()

		const groupToggle = nav.getByText('Data quality', { exact: true }).first()
		await expect(groupToggle).toBeVisible({ timeout: 10_000 })
		await groupToggle.click().catch(() => {})

		// #C added a fifth entry ("Merge Operations") to the group.
		const entries = [
			{ label: 'Data Quality', heading: /Data Quality/i },
			{ label: 'Duplicate Candidates', heading: /Duplicate Candidates/i },
			{ label: 'Master entities', heading: /Master entities/i },
			{ label: 'Queue / sync health', heading: /Queue \/ sync health/i },
			{ label: 'Merge Operations', heading: /Merge Operations/i },
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
// @e2e openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-selects-expose-stable-test-handles
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — register/schema selector', () => {
	test.use({ storageState: STORAGE_STATE })

	test('schema combobox is disabled until a register is chosen, both expose accessible labels', async ({ page }) => {
		// Plain (un-scoped) navigation so the fresh selector is exercised.
		await gotoApp(page, '/quality')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()

		await expect(registerCombo).toBeVisible({ timeout: 10_000 })
		await expect(schemaCombo).toBeVisible({ timeout: 10_000 })
		await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })

		// The selects expose stable data-testids for robust targeting.
		await expect(page.getByTestId('mdm-register-select')).toBeVisible()
		await expect(page.getByTestId('mdm-schema-select')).toBeVisible()
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#selection-persists-across-mdm-views
// @e2e openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-deep-link-preselects-register-and-schema
// @e2e openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-selecting-a-register-and-schema-updates-the-url
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — selection persistence', () => {
	test.use({ storageState: STORAGE_STATE })

	test('register/schema selection on Data Quality persists on Duplicate Candidates', async ({ page }) => {
		const selected = await gotoScoped(page, '/quality')
		test.skip(!selected, 'No register/schema available — seed data needed')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		const selectedRegisterLabel = (await registerCombo.innerText().catch(() => '')) || ''

		// With a seed the URL now mirrors the selection (route-scoping).
		if (seed) {
			await expect(page).toHaveURL(new RegExp(`register=${seed.register}`))
		}

		// Navigate PLAIN (no query) — the shared store must carry the selection.
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
		const selected = await gotoScoped(page, '/quality')
		test.skip(!selected, 'No register/schema available — seed data needed')

		// Either the empty state (unscored schema) or the KPI + histogram
		// surface renders — never a blank panel.
		const emptyState = page.getByText(/No scored objects for this schema/i)
		const kpiRow = page.locator('.kpiRow')
		await expect(emptyState.or(kpiRow)).toBeVisible({ timeout: 15_000 })

		// With the seeded masterEntity schema the dashboard is populated.
		if (seed) {
			await expect(kpiRow).toBeVisible({ timeout: 15_000 })
		}

		const hasKpis = await kpiRow.isVisible().catch(() => false)
		if (hasKpis) {
			// Scope KPI labels to the .kpiRow container — "Good"/"Fair"/"Poor"
			// also render as qualityStatus cells in the lowest-quality table, so
			// an un-scoped getByText is a strict-mode violation (multiple hits).
			await expect(kpiRow.getByText(/Average score/i)).toBeVisible()
			await expect(kpiRow.getByText(/^Good$/i)).toBeVisible()
			await expect(kpiRow.getByText(/^Fair$/i)).toBeVisible()
			await expect(kpiRow.getByText(/^Poor$/i)).toBeVisible()

			// Histogram: either the chart widget or the bucket-table fallback.
			const histogramTable = page.locator('[data-testid="histogram-fallback-table"]')
			const chart = page.locator('.histogramSection').locator('svg, canvas, .apexcharts-canvas').first()
			await expect(histogramTable.or(chart)).toBeVisible({ timeout: 10_000 })

			// Lowest-quality listing is populated for the seeded schema.
			if (seed) {
				await expect(page.locator('.lowestQualityTable tbody tr').first()).toBeVisible({ timeout: 10_000 })
			}
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#candidate-pairs-render-with-score-and-matched-attributes
// NOTE: mdm-frontend's original "no-merge-or-write-action-is-present" scenario
// (DuplicatesIndex is strictly read-only) is superseded by mdm-merge-ui (#C),
// which adds the per-pair "Merge" action by design — see
// openspec/changes/mdm-merge-ui/specs/mdm-merge-ui/spec.md#scenario-merge-action-is-offered-per-candidate-pair.
// Delete/write actions beyond the merge wizard launch remain absent.
// ─────────────────────────────────────────────────────────────────────────────
test.describe('mdm-frontend — Duplicate Candidates', () => {
	test.use({ storageState: STORAGE_STATE })

	test('duplicate candidates render with a per-pair merge action and no delete/write action', async ({ page }) => {
		const selected = await gotoScoped(page, '/duplicates')
		test.skip(!selected, 'No register/schema available — seed data needed')

		const emptyState = page.getByText(/No duplicate candidates found/i)
		const table = page.locator('.duplicatesTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })

		// With the seeded duplicate pair, at least one candidate row + its
		// per-pair Merge launch renders.
		if (seed) {
			await expect(page.getByTestId('mdm-duplicate-row').first()).toBeVisible({ timeout: 15_000 })
			await expect(page.getByTestId('mdm-merge-launch').first()).toBeVisible()
		}

		// No delete/write control anywhere on the view beyond the merge launch.
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
		const selected = await gotoScoped(page, '/master-entities')
		test.skip(!selected, 'No register/schema available — seed data needed')

		const emptyState = page.getByText(/No master entities found/i)
		const table = page.locator('.masterEntitiesTable')
		await expect(emptyState.or(table)).toBeVisible({ timeout: 15_000 })

		if (seed) {
			await expect(page.getByTestId('mdm-master-entity-row').first()).toBeVisible({ timeout: 15_000 })
		}

		const hasRows = await table.isVisible().catch(() => false)
		if (hasRows) {
			await expect(table.locator('thead').getByText(/Quality score/i).first()).toBeVisible()
			await expect(table.locator('thead').getByText(/Quality status/i).first()).toBeVisible()

			// Open a SEEDED row (known to carry attribute provenance). Pre-existing
			// masterEntity rows may lack a materialised provenance map, so opening
			// "the first row" is not deterministic — target the seeded dup survivor.
			const viewButton = seed
				? page.getByTestId('mdm-master-entity-row').filter({ hasText: seed.dupPair[0] }).first().getByTestId('mdm-view-golden-record')
				: page.getByTestId('mdm-view-golden-record').first()
			await expect(viewButton).toBeVisible({ timeout: 10_000 })
			await viewButton.click()

			// The golden-record panel is an INLINE panel, not a modal/dialog.
			const panel = page.locator('.goldenRecordPanel')
			await expect(panel).toBeVisible({ timeout: 10_000 })
			await expect(panel).not.toHaveAttribute('role', 'dialog')

			const provenanceTable = panel.locator('[data-testid="provenance-table"]')
			const noProvenance = panel.getByText(/No golden-record provenance/i)
			await expect(provenanceTable.or(noProvenance)).toBeVisible({ timeout: 10_000 })

			// The seeded survivor carries a materialised attribute-provenance map.
			if (seed) {
				await expect(provenanceTable).toBeVisible({ timeout: 10_000 })
			}
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
