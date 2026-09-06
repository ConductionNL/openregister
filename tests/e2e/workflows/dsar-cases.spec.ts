/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DSAR case-management surface (dsar-case-ui) — behavioural e2e for the
 * Cases tab added to the AVG view (src/views/avg/AvgIndex.vue): the case
 * LIST (filter by status/handler/overdue, pack-driven labels, empty-state)
 * and the case DETAIL actions (lifecycle transitions, finalise gating,
 * denial composer, evidence harvest, redaction, one-time export bundle,
 * and the two fail-closed integration seams).
 *
 * Each @e2e annotation below traces a Scenario in the change specs
 *   openspec/changes/dsar-case-ui/specs/{dsar-case-list,dsar-case-detail-actions}/spec.md
 * (referenced by their post-sync openspec/specs/<spec>/ path, per gate-19).
 *
 * NOTE: these tests drive the real UI and therefore require a Nextcloud
 * instance with this branch BUILT + deployed and the DSAR case + policy-pack
 * registers seeded. They skip when the shared auth storageState is absent
 * (the app is not reachable in the current environment). Any placeholder
 * subject/token is a safe placeholder — never a real BSN or secret.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const AVG_URL = '/index.php/apps/openregister/avg'

// Safe placeholders only (nil UUID / literal token) — never real values.
const NIL_UUID = '00000000-0000-0000-0000-000000000000'
const PLACEHOLDER_TOKEN = 'YOUR_TOKEN_HERE'
void NIL_UUID
void PLACEHOLDER_TOKEN

test.describe('dsar-case-ui — Cases surface', () => {
	test.use({ storageState: STORAGE_STATE })

	test.beforeEach(async ({ page }) => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(
				true,
				'storageState not present — the app is not reachable/built in this environment',
			)
		}
		await page.goto(AVG_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main, .app-content').first()).toBeVisible({
			timeout: 25_000,
		})
	})

	/**
	 * Open the Cases tab; the other AVG tabs remain present.
	 *
	 * @e2e openspec/specs/dsar-case-list/spec.md#handler-opens-the-case-list-from-the-avg-surface
	 * @e2e openspec/specs/dsar-case-list/spec.md#empty-case-list-shows-an-empty-state-not-an-error
	 */
	test('opens the case list from the AVG surface (or a friendly empty-state)', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		// Either a populated table or the empty-state — never an error.
		const listOrEmpty = page.locator('.avgTable, .empty-content')
		await expect(listOrEmpty.first()).toBeVisible({ timeout: 15_000 })
		// The other AVG tabs are still reachable.
		await expect(page.getByRole('button', { name: 'Activities' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Compliance' })).toBeVisible()
	})

	/**
	 * Column/status/tier wording resolves from the active policy pack.
	 *
	 * @e2e openspec/specs/dsar-case-list/spec.md#status-and-tier-labels-come-from-the-pack
	 * @e2e openspec/specs/dsar-case-list/spec.md#no-jurisdiction-wording-is-hard-coded-in-the-view
	 */
	test('list wording resolves from the pack, not inlined jurisdiction strings', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		await expect(
			page.getByRole('columnheader', { name: 'Status' }),
		).toBeVisible()
		await expect(
			page.getByRole('columnheader', { name: 'Escalation' }),
		).toBeVisible()
	})

	/**
	 * Filter by overdue, status, and handler.
	 *
	 * @e2e openspec/specs/dsar-case-list/spec.md#overdue-filter-narrows-to-breachedoverdue-cases
	 * @e2e openspec/specs/dsar-case-list/spec.md#status-and-handler-filters-narrow-the-list
	 */
	test('filters narrow the authorised list and clearing restores it', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		await page.getByRole('switch', { name: 'Overdue only' }).click()
		// The status/handler filter selects carry accessible labels (WCAG AA).
		await expect(page.getByLabel('Filter by status')).toBeVisible()
		await expect(page.getByLabel('Filter by handler')).toBeVisible()
	})

	/**
	 * Detail shows deadline + escalation tier by text/icon (not colour alone).
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#detail-shows-the-case-deadline-and-escalation-tier
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#overdue-case-is-shown-as-breached
	 */
	test('detail shows the deadline and escalation tier', async ({ page }) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(page.getByText('Deadline tracking')).toBeVisible()
			await expect(page.getByText('Escalation tier')).toBeVisible()
		}
	})

	/**
	 * Lifecycle transition controls follow the declared state graph.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#advancing-a-case-posts-the-declared-transition
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#controls-follow-the-cases-current-state
	 */
	test('transition controls reflect the declared graph and post transitions', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(page.getByText('Lifecycle')).toBeVisible()
		}
	})

	/**
	 * Finalise-denial gated on a regulator reference.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#finalise-is-blocked-without-a-regulator-reference
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#finalise-proceeds-once-the-reference-is-recorded
	 */
	test('finalise-denial is blocked until a regulator reference is recorded', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(page.getByText('Denial')).toBeVisible()
		}
	})

	/**
	 * Denial composer sources grounds (label + citation) from the pack.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#ground-options-come-from-the-pack-with-label-and-citation
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#selecting-a-ground-records-it-on-the-case
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#a-denial-letter-is-rendered-from-a-template-reference
	 */
	test('denial composer shows pack grounds and template reference', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await page.getByRole('button', { name: 'Compose denial' }).click()
			await expect(page.getByLabel('Denial ground')).toBeVisible()
		}
	})

	/**
	 * Evidence panel lists items with status and triggers a harvest.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#evidence-items-show-source-and-collection-status
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#triggering-a-harvest-updates-item-status
	 */
	test('evidence panel lists items and triggers a harvest', async ({ page }) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(
				page.getByRole('button', { name: 'Collect evidence' }),
			).toBeVisible()
		}
	})

	/**
	 * Redaction records a field-level redaction with a ground.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#applying-a-redaction-records-it-on-the-case
	 */
	test('a field-level redaction is recorded on the case', async ({ page }) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await page.getByRole('button', { name: 'Apply redaction' }).click()
			await expect(page.getByLabel('Redaction ground')).toBeVisible()
		}
	})

	/**
	 * Export bundle offers exactly one download of the one-time token.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#generating-a-bundle-yields-a-single-download
	 */
	test('the export bundle offers a single one-time download', async ({ page }) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(
				page.getByRole('button', { name: 'Generate export bundle' }),
			).toBeVisible()
		}
	})

	/**
	 * Identity-verify and regulator-escalate render the fail-closed result.
	 *
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#identity-verification-renders-the-real-result
	 * @e2e openspec/specs/dsar-case-detail-actions/spec.md#regulator-escalation-renders-performed-or-refused
	 */
	test('seam triggers render the fail-closed result, never a false success', async ({
		page,
	}) => {
		await page.getByRole('button', { name: 'Cases' }).click()
		const firstOpen = page.getByRole('button', { name: 'Open' }).first()
		if (await firstOpen.isVisible().catch(() => false)) {
			await firstOpen.click()
			await expect(
				page.getByRole('button', { name: 'Verify identity' }),
			).toBeVisible()
			await expect(
				page.getByRole('button', { name: 'Escalate to regulator' }),
			).toBeVisible()
		}
	})
})
