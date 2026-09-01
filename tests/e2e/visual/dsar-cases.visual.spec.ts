/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baseline for the DSAR case-management surface
 * (dsar-case-ui) — the Cases tab + list added to the AVG view.
 *
 * Run:    npx playwright test --project visual tests/e2e/visual/dsar-cases.visual.spec.ts
 * Update: npx playwright test --project visual --update-snapshots \
 *           tests/e2e/visual/dsar-cases.visual.spec.ts
 *
 * The baseline PNG lives in dsar-cases.visual.spec.ts-snapshots/ and is only
 * committed once captured against a BUILT + deployed instance with the DSAR
 * registers seeded. Per the GAP-5 platform caveat (see _visual-helpers.ts),
 * a baseline captured on a dev host will not byte-match a CI runner, so until
 * a baseline is committed this spec is non-gating (first run generates it).
 */
import { test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { shootSurface } from './_visual-helpers.ts'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const APP = '/index.php/apps/openregister'

test.describe('Open Register — DSAR cases visual baseline', () => {
	test.use({ storageState: STORAGE_STATE })

	test('cases surface', async ({ page }) => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(
				true,
				'storageState not present — the app is not reachable/built in this environment',
			)
		}
		// The AVG view opens on the Activities tab; the Cases tab is client-side.
		await shootSurface(page, `${APP}/#/avg`, 'avg-cases.png')
	})
})
