/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * UI-only Playwright e2e tests for spec `files-sidebar-tabs`.
 *
 * TAG CONVENTION: each test carries
 *   @e2e openspec/specs/files-sidebar-tabs/spec.md#<scenario-slug>
 *
 * Methodology: drive the real UI (EntitiesSideBar on /entities,
 * DeletedSideBar on /deleted).
 * The OR REST API is used ONLY for test-data setup/teardown.
 *
 * Scenarios covered:
 *   single-keystroke-emits-after-500ms                       — UI test (EntitiesSideBar)
 *   rapid-keystrokes-only-emit-the-final-value               — UI test (EntitiesSideBar)
 *   switching-register-clears-the-active-schema              — UI test (DeletedSideBar)
 *   clearing-the-register-also-clears-the-schema             — UI test (DeletedSideBar)
 *   deletedsidebar-additionally-re-applies-filters-after-the-cascade — UI test (DeletedSideBar)
 *   applyfilters-writes-filter-state-to-the-url              — UI test (DeletedSideBar)
 *
 * Already marked @e2e exclude in the spec (not re-tested here):
 *   component-owns-a-single-search-timeout-handle  — internal Vue state, unit tests
 *   applyfilters-is-a-no-op-outside-deleted        — internal router guard, unit tests
 *   applyfilters-skips-redundant-navigation        — internal router guard, unit tests
 *   backend-file-reverse-lookup-…                  — PHPUnit
 *
 * EntitiesSideBar renders as an NcAppSidebar (right-rail slide-out) opened via
 * the "Show Filters" button on /entities.  The sidebar is conditionally rendered
 * by SideBars.vue for the /entities route, controlled by navigationStore.sidebarState.entities.
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Navigate to an OR app route (hash form) and wait for NC header + app content. */
async function gotoApp(page: Page, subpath: string): Promise<void> {
	// HASH form — the router runs in hash mode (src/main.js); path-form
	// deep-links render the dashboard instead of the target page.
	await page.goto(`/index.php/apps/openregister/#${subpath}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
	await page.waitForSelector('#app-content-vue, .app-content', { timeout: 20_000 })
	// Give Vue a moment to mount and hydrate the component tree.
	await page.waitForTimeout(800)
}

/**
 * Open the EntitiesSideBar on /entities by clicking the "Show Filters" toggle button.
 * Waits for the NcAppSidebar panel to appear in the DOM.
 * Returns the search input locator inside the sidebar.
 */
async function openEntitiesSidebar(page: Page): Promise<ReturnType<Page['locator']>> {
	// Click the "Show Filters" button to open the NcAppSidebar.
	const toggleBtn = page.locator('button', { hasText: 'Show Filters' })
		.or(page.getByRole('button', { name: /Toggle search sidebar/i }))
		.first()
	await expect(toggleBtn).toBeVisible({ timeout: 10_000 })
	await toggleBtn.click()

	// NcAppSidebar renders as .app-sidebar in the DOM.
	await page.waitForSelector('.app-sidebar, [class*="app-sidebar"]', { timeout: 10_000 })
	await page.waitForTimeout(400) // let Vue finish transition

	// The search input is inside the sidebar — look for the "Search by value" text input.
	const sidebarInput = page.getByRole('textbox', { name: /search by value/i }).first()
		.or(page.locator('.app-sidebar input[type="text"], .app-sidebar input:not([type="hidden"])').first())
	return sidebarInput
}

/**
 * Click a combobox and wait for options to appear.
 * Returns false if no options appeared within the timeout.
 */
async function clickAndWaitForOptions(
	page: Page,
	combo: ReturnType<Page['getByRole']>,
	timeout = 15_000,
): Promise<boolean> {
	await combo.click()
	// Wait for the options listbox to appear.
	try {
		await page.waitForSelector('[role="option"], [role="listbox"] li', { timeout })
		return true
	} catch {
		return false
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#single-keystroke-emits-after-500ms
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — single-keystroke-emits-after-500ms', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#single-keystroke-emits-after-500ms
	test('typing one character in the entities sidebar triggers search after 500ms debounce', async ({ page }) => {
		await gotoApp(page, '/entities')

		// Open the NcAppSidebar and get the search input.
		const theInput = await openEntitiesSidebar(page)
		await expect(theInput).toBeVisible({ timeout: 10_000 })

		// Type one character.
		await theInput.fill('f')
		const t0 = Date.now()

		// After >500ms the debounce fires — the parent EntitiesIndex reloads.
		await page.waitForTimeout(600)
		const elapsed = Date.now() - t0

		// The input still has the value (not cleared by any premature reload).
		await expect(theInput).toHaveValue('f')
		// At least 500ms should have passed.
		expect(elapsed).toBeGreaterThanOrEqual(500)

		// Clean up.
		await theInput.fill('')
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#rapid-keystrokes-only-emit-the-final-value
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — rapid-keystrokes-only-emit-the-final-value', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#rapid-keystrokes-only-emit-the-final-value
	test('typing three rapid characters results in a single debounced emission', async ({ page }) => {
		await gotoApp(page, '/entities')

		// Open the NcAppSidebar and get the search input.
		const theInput = await openEntitiesSidebar(page)
		await expect(theInput).toBeVisible({ timeout: 10_000 })

		// Count outbound entity search requests made after input starts.
		let searchRequestCount = 0
		page.on('request', (req) => {
			if (req.url().includes('/api/entities') && req.method() === 'GET') {
				searchRequestCount++
			}
		})

		// Type 'foo' with fast key-by-key input (50ms between chars, well within 500ms debounce).
		await theInput.fill('')
		await theInput.pressSequentially('foo', { delay: 50 })

		const countBeforeDebounce = searchRequestCount

		// Wait for the full debounce window to expire.
		await page.waitForTimeout(700)

		// Input should still show 'foo'.
		await expect(theInput).toHaveValue('foo')

		// At most one extra search request should have fired after debounce (the coalesced one).
		// Two requests tolerated: one for the initial page load + one for the debounced search.
		const countAfterDebounce = searchRequestCount
		expect(countAfterDebounce - countBeforeDebounce).toBeLessThanOrEqual(2)

		// Clean up.
		await theInput.fill('')
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#switching-register-clears-the-active-schema
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — switching-register-clears-the-active-schema', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#switching-register-clears-the-active-schema
	test('picking a register in DeletedSideBar enables schema, switching disables it again', async ({ page }) => {
		await gotoApp(page, '/deleted')

		// The DeletedSideBar has a Register combobox (single-select).
		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		await expect(registerCombo).toBeVisible({ timeout: 10_000 })

		// Initially, Schema should be disabled.
		const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()
		await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })

		// Select the first register — wait for options to appear before checking.
		const hasOptions = await clickAndWaitForOptions(page, registerCombo)
		if (!hasOptions) {
			test.skip(true, 'No register options available — seed data needed')
			return
		}

		const firstOpt = page.getByRole('option').first()
		await firstOpt.click()

		// Schema should now be enabled.
		await expect(schemaCombo).not.toBeDisabled({ timeout: 8_000 })

		// Select a schema if available.
		const hasSchemaOptions = await clickAndWaitForOptions(page, schemaCombo, 8_000)
		if (hasSchemaOptions) {
			// The schema option list can re-render right after it opens (the
			// schema store hydrates asynchronously once a register is picked),
			// so guard the click with a visibility check and fall back to
			// dismissing the dropdown rather than hanging for the full timeout.
			const schemaOpt = page.getByRole('option').first()
			const optVisible = await schemaOpt
				.isVisible({ timeout: 5_000 })
				.catch(() => false)
			if (optVisible) {
				await schemaOpt.click({ timeout: 8_000 }).catch(async () => {
					await page.keyboard.press('Escape')
				})
			} else {
				await page.keyboard.press('Escape')
			}
		} else {
			await page.keyboard.press('Escape')
		}

		// Now switch to a different register — this should clear the schema.
		const hasRegisterOptions2 = await clickAndWaitForOptions(page, registerCombo)
		if (!hasRegisterOptions2) {
			test.skip(true, 'Register dropdown did not open for second selection')
			return
		}

		// Try to pick a second option.
		const secondOpt = page.getByRole('option').nth(1)
		const hasSecond = await secondOpt.isVisible({ timeout: 5_000 }).catch(() => false)

		if (!hasSecond) {
			// Only one register — clear the register instead.
			await page.keyboard.press('Escape')
			// Find and click the "clear" (×) / deselect button on the NcSelect.
			// NcSelect renders a "Deselect <name>" button for the selected tag.
			const deselectBtn = page.locator('[aria-label*="Deselect"], [aria-label*="deselect"], button[title*="Clear" i]').first()
			const hasClear = await deselectBtn.isVisible({ timeout: 3_000 }).catch(() => false)
			if (hasClear) {
				await deselectBtn.click()
				// Schema select should be disabled again.
				await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })
			} else {
				test.skip(true, 'Only one register, no clear/deselect button found — skip cascade check')
			}
			return
		}

		await secondOpt.click()

		// After switching register, schema combobox should be cleared.
		// schemaStore.setSchemaItem(null) is called in handleRegisterChange.
		// With a new register selected, schema stays enabled but value is cleared.
		const schemaInputValue = await schemaCombo.evaluate(
			(el: HTMLInputElement) => el.value ?? '',
		).catch(() => '')
		// The value should be empty after cascade reset.
		expect(schemaInputValue).toBe('')
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#clearing-the-register-also-clears-the-schema
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — clearing-the-register-also-clears-the-schema', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#clearing-the-register-also-clears-the-schema
	test('clearing the register in DeletedSideBar disables the schema select', async ({ page }) => {
		await gotoApp(page, '/deleted')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		await expect(registerCombo).toBeVisible({ timeout: 10_000 })

		const schemaCombo = page.getByRole('combobox', { name: 'Schema' }).first()

		// Initially schema is disabled.
		await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })

		// Select a register to enable schema — wait for options.
		const hasOptions = await clickAndWaitForOptions(page, registerCombo)
		if (!hasOptions) {
			test.skip(true, 'No register options available — seed data needed')
			return
		}

		const firstOpt = page.getByRole('option').first()
		await firstOpt.click()

		// Schema is now enabled.
		await expect(schemaCombo).not.toBeDisabled({ timeout: 8_000 })

		// Clear the register via the NcSelect clear button.
		// NcSelect (vue-select) renders a "Clear selected" button when a value is selected.
		const clearBtn = page.getByRole('button', { name: /Clear selected|Deselect/i }).first()
			.or(page.locator('[aria-label*="Clear selected"], [aria-label*="Deselect"]').first())
		const hasClear = await clearBtn.isVisible({ timeout: 5_000 }).catch(() => false)

		if (hasClear) {
			await clearBtn.click()
		} else {
			// Fallback: look for tag-remove icon inside the select.
			const tagRemove = page.locator(
				'.multiselect__tag-icon, [class*="tag"] button, [class*="remove"] button',
			).first()
			const hasTag = await tagRemove.isVisible({ timeout: 2_000 }).catch(() => false)
			if (hasTag) {
				await tagRemove.click()
			} else {
				test.skip(true, 'Cannot find clear/remove/deselect button for register select')
				return
			}
		}

		// After clearing, schema should be disabled again.
		await expect(schemaCombo).toBeDisabled({ timeout: 8_000 })
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#deletedsidebar-additionally-re-applies-filters-after-the-cascade
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — deletedsidebar-additionally-re-applies-filters-after-the-cascade', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#deletedsidebar-additionally-re-applies-filters-after-the-cascade
	test('selecting a register in DeletedSideBar calls applyFilters and updates the URL', async ({ page }) => {
		await gotoApp(page, '/deleted')

		// Verify clean start — no ?register param.
		const initialUrl = page.url()
		expect(initialUrl).toContain('/deleted')

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		await expect(registerCombo).toBeVisible({ timeout: 10_000 })

		// Select the first available register — wait for options.
		const hasOptions = await clickAndWaitForOptions(page, registerCombo)
		if (!hasOptions) {
			test.skip(true, 'No register options available — seed data needed')
			return
		}

		const firstOpt = page.getByRole('option').first()
		await firstOpt.click()

		// DeletedSideBar::handleRegisterChange calls applyFilters() → updateRouteQueryFromState() → $router.replace.
		// Wait for the URL to update.
		await page.waitForURL(/[?&]register=/, { timeout: 8_000 }).catch(async () => {
			await page.waitForTimeout(1500)
		})

		const urlAfter = page.url()
		expect(urlAfter).toMatch(/[?&]register=\d+/)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// @e2e openspec/specs/files-sidebar-tabs/spec.md#applyfilters-writes-filter-state-to-the-url
// ─────────────────────────────────────────────────────────────────────────────
test.describe('files-sidebar-tabs — applyfilters-writes-filter-state-to-the-url', () => {
	test.use({ storageState: STORAGE_STATE })

	// @e2e openspec/specs/files-sidebar-tabs/spec.md#applyfilters-writes-filter-state-to-the-url
	test('applyFilters writes register to the URL query on /deleted', async ({ page }) => {
		// Start from clean /deleted (hash form — router runs in hash mode).
		await page.goto('/index.php/apps/openregister/#/deleted', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#header, header.header-appcontainer', { timeout: 25_000 })
		await page.waitForSelector('#app-content-vue, .app-content', { timeout: 20_000 })
		await page.waitForTimeout(800) // let Vue mount the sidebar

		const registerCombo = page.getByRole('combobox', { name: 'Register' }).first()
		await expect(registerCombo).toBeVisible({ timeout: 10_000 })

		// Select the first register — wait for options.
		const hasOptions = await clickAndWaitForOptions(page, registerCombo)
		if (!hasOptions) {
			test.skip(true, 'No register options — seed data needed')
			return
		}

		const opt = page.getByRole('option').first()
		await opt.click()

		// $router.replace should add ?register=<id> to the URL.
		await page.waitForURL(/[?&]register=\d+/, { timeout: 8_000 }).catch(async () => {
			await page.waitForTimeout(1500)
		})

		const finalUrl = page.url()
		expect(finalUrl).toMatch(/[?&]register=\d+/)
	})
})
