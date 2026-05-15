/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright config for Open Register.
 *
 * Two projects:
 *
 *   - `chromium`     — the default regression project. Currently runs
 *                      the API smoke spec; excludes the docs capture
 *                      spec so PR pipelines don't reshoot screenshots
 *                      on every push.
 *   - `docs-capture` — the journeydoc screenshot capture project (ADR-030).
 *                      Opt-in: `npx playwright test --project docs-capture`.
 *                      Output lands in
 *                      `docs/static/screenshots/tutorials/{user,admin}/`.
 *
 * Point at a running Nextcloud with NEXTCLOUD_URL (default
 * http://localhost:8080). `globalSetup` logs in once (admin/admin by
 * default; override with NC_ADMIN_USER / NC_ADMIN_PASS) and persists
 * the session to `tests/e2e/.auth/admin.json`; every spec reuses it via
 * `use.storageState`.
 *
 * The existing API smoke spec (`api-smoke.spec.ts`) drives the OR REST
 * API directly with Basic auth via `extraHTTPHeaders`. Storage state is
 * additive — the smoke spec keeps working as-is.
 */
import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 30_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		extraHTTPHeaders: {
			// Basic auth used by api-smoke.spec.ts; UI specs override
			// auth via `storageState` below.
			Authorization: `Basic ${Buffer.from(
				`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
			).toString('base64')}`,
		},
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
			timeout: 90_000,
		},
	],

	testIgnore: [
		'**/node_modules/**',
		'**/custom_apps/**',
		'**/.claude/**',
	],
})
