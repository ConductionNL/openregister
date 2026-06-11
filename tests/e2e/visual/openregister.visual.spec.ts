/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Visual-regression baselines for Open Register's key surfaces (GAP-5).
 *
 * Run:   npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test } from '@playwright/test'
import { shootSurface, shootByNav } from './_visual-helpers'

const APP = '/index.php/apps/openregister'

test.describe('Open Register — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})

	// NOTE: OpenRegister's in-app sidebar routes do not switch the rendered
	// view in this deployed build (the dashboard component stays mounted), and
	// the admin-settings page streams async "Loading version information…" +
	// live system statistics that never settle deterministically. Per GAP-5's
	// "a flaky baseline is worse than none", the dashboard is the single stable
	// baselined surface for OpenRegister. Re-add list views once the router
	// quirk is resolved.
})
