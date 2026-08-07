/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI PLAYWRIGHT CONFIG — a deliberately small, always-green spec set.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The root config points `testDir` at the whole of `tests/e2e`, which is 58
 * spec files. Several of them write fixtures, shell out to `occ`, or capture
 * documentation screenshots. Turning `enable-playwright` on against that set
 * would have produced a red job on the first run, and a gate that is red on
 * arrival is a gate nobody turns on — which is how this repo ended up with an
 * E2E job that had never succeeded.
 *
 * So the job starts from a floor that CAN be green, and the floor grows. The
 * shared workflow resolves `<playwright-test-path>/playwright.config.ts` first
 * and only falls back to the root one, so pointing `playwright-test-path` at
 * `tests/e2e/ci` selects THIS config and therefore this `testDir`.
 *
 * WHAT BELONGS HERE
 * -----------------
 * A spec belongs here when it is hermetic against a freshly-installed
 * Nextcloud: it creates whatever it needs, cleans up after itself, needs no
 * `occ`, no docker, and no pre-seeded data. Anything else stays in the root
 * suite and is run deliberately.
 *
 * ⚠️ `baseURL` comes from the shared resolver, which accepts CI's `BASE_URL`
 * and has NO localhost default — a default would silently target the shared dev
 * container. See tests/e2e/base-url.ts.
 */
import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url'

export default defineConfig({
	testDir: '.',
	globalSetup: path.resolve(__dirname, '../global-setup.ts'),
	timeout: 45_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	// One retry in CI absorbs a genuinely slow first paint on a cold runner
	// without hiding a real failure — a broken assertion fails twice.
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['list'],
		// The shared workflow uploads `tests/e2e/playwright-report/` as the
		// `playwright-report` artifact. With `list` as the only reporter that
		// directory was never created, so the artifact was empty on every run.
		['html', { open: 'never', outputFolder: path.resolve(__dirname, '../playwright-report') }],
	],
	// ⚠️ MUST stay `tests/e2e/test-results`. This was `../test-results-ci`, and
	// the shared workflow's "Upload Playwright traces" step globs exactly
	// `tests/e2e/test-results/` — so Playwright wrote trace.zip, the failure
	// screenshot and error-context.md on every red run, and the upload matched
	// nothing and said so quietly (`if-no-files-found: ignore`). Six flaky and
	// two hard-failing runs of flow-controls.spec.ts were diagnosed from job
	// logs alone because the traces that existed on the runner were discarded.
	outputDir: path.resolve(__dirname, '../test-results'),

	use: {
		baseURL: resolveBaseUrl(),
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(
				`${process.env.ADMIN_USER || process.env.OR_USER || 'admin'}:${
					process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
				}`,
			).toString('base64')}`,
		},
		// `retain-on-failure`, not `on-first-retry`. With `retries: 1` a FLAKE is
		// exactly the case where attempt 1 fails and attempt 2 passes — and
		// `on-first-retry` traces the RETRY, so the only attempt it ever
		// captured was the one that worked. flow-controls.spec.ts failed its
		// first attempt on 6 of 8 runs and every trace on disk was of a green
		// run. This keeps the trace of whichever attempt actually failed.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
