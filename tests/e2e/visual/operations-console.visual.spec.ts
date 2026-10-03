/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baseline for the operations console
 * (admin-operations-console, hydra gate-26).
 *
 * OperationsConsoleIndex is the one new page component in that change, and it
 * is the kind of screen a baseline earns its keep on: three pane cards, three
 * tables and two warning notes, all of which lay out differently the moment a
 * pane reports nothing. The empty instance is a real state here rather than a
 * degenerate one, since a fresh install has run no bulk job.
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed. This
 * spec ships without one, the same way mdm-frontend.visual.spec.ts did: the
 * first `--update-snapshots` run on a seeded instance writes it. See
 * _visual-helpers.ts for the platform-rendering caveat.
 */
import { test } from '@playwright/test'
import { shootSurface } from './_visual-helpers.ts'

const APP = '/index.php/apps/openregister'

test.describe('operations console', () => {
	test('OperationsConsoleIndex', async ({ page }) => {
		await shootSurface(page, `${APP}/operations`, 'OperationsConsoleIndex.png')
	})
})
