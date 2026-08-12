/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
import { resolveBaseUrl } from './tests/e2e/base-url'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 30_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		// ⚠️ No `|| 'http://localhost:8080'` fallback — that literal is the
		// shared dev container, which bind-mounts other people's checkouts.
		// See tests/e2e/base-url.ts.
		baseURL: resolveBaseUrl(),
		extraHTTPHeaders: {
			// Basic auth used by api-smoke.spec.ts; UI specs override
			// auth via `storageState` below.
			Authorization: `Basic ${Buffer.from(
				`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
			).toString('base64')}`,
		},
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			// NOTE: a project-level testIgnore REPLACES the top-level
			// testIgnore for this project (Playwright does not merge them),
			// so the api-direct exclusion must be repeated here. These
			// specs are API/contract assertions covered by the Newman suite
			// (tests/integration/*.postman_collection.json), not UI tests —
			// gate-19: API-direct → Newman.
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/api-direct/**',
				// Visual specs run only under the opt-in `visual` project.
				'**/visual/**',
			],
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
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI on first run before it
		// can gate. See tests/e2e/visual/_visual-helpers.ts.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
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
		// API-direct specs are API/contract assertions (Newman-equivalent),
		// not real UI-driving Playwright tests. They live here for reference
		// but are excluded from the UI test run (gate-19: API-direct → Newman).
		'**/api-direct/**',
	],
})
