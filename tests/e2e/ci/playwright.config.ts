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
 * HOW THE SET GROWS — `testDir: '..'` + AN EXPLICIT `testMatch` ALLOW-LIST
 * -----------------------------------------------------------------------
 * The floor used to be "whatever file sits in `tests/e2e/ci/`", which meant
 * growing it required MOVING files and rewriting their relative imports. It is
 * now an explicit allow-list rooted at `tests/e2e`, so a spec is admitted by
 * naming it here — a one-line, reviewable diff that says exactly which file
 * started running and can be reverted just as precisely.
 *
 * The shared workflow runs `npx playwright test --config="$CONFIG"` with NO
 * positional path filter (quality.yml, "Run Playwright tests"), so this
 * config's `testDir` + `testMatch` are the only things deciding what executes.
 * `playwright-test-path` selects the config and asserts the directory is
 * non-empty; it does not scope the run.
 *
 * ⚠️ ADMISSION CRITERIA — all four, checked per file, and the last two are the
 * ones that matter most because a suite can be GREEN and still assert nothing:
 *
 *   1. Hermetic: no `occ`, no docker, no pre-seeded fixtures.
 *   2. Non-mutating, or self-cleaning: no `.fill()`/save/delete path that
 *      leaves rows behind. All four files admitted below only navigate, open a
 *      modal, and read — they write nothing.
 *   3. NO UNCONDITIONAL-PASS DEGRADATION. A spec that wraps its assertions in
 *      `if (await x.isVisible().catch(() => false)) { … }` with no `else`
 *      PASSES WITHOUT ASSERTING when the data is absent. That is worse than a
 *      skip, because gate-19 counts its `@e2e` refs as live coverage and the
 *      run list shows a ✓. `tests/e2e/workflows/dsar-cases.spec.ts` (21 refs)
 *      is built that way and is deliberately NOT admitted.
 *   4. NO SEED-DEPENDENT `test.skip()`. The `spec-coverage/mdm-*.spec.ts` files
 *      state in their own headers that they "degrade to test.skip()" without a
 *      seed, and CI's own log confirms it every run:
 *        `pipelinq/masterEntity not found — MDM fixture skipped; MDM specs
 *         will self-skip.`
 *      Admitting them would raise the executed count while covering nothing.
 *
 * ⚠️ `baseURL` comes from the shared resolver, which accepts CI's `BASE_URL`
 * and has NO localhost default — a default would silently target the shared dev
 * container. See tests/e2e/base-url.ts.
 */
import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url'

export default defineConfig({
	// Rooted at `tests/e2e` so the allow-list below can admit a spec without
	// moving it (and without rewriting its `../.auth` / `../base-url` imports).
	testDir: '..',
	// EXPLICIT ALLOW-LIST — nothing runs unless it is named here. This is what
	// keeps `testDir: '..'` from silently pulling in the ~59 other spec files,
	// including `api-direct/**` (Newman's job, not Playwright's), `visual/**`
	// (opt-in project, host-font-specific baselines) and `docs-screenshots`
	// (journeydoc capture).
	testMatch: [
		// The original floor.
		'ci/*.spec.ts',
		// Admitted 2026-08-10. Genuine behavioural UI specs that assert named
		// items — a page heading by text, a primary action button by
		// accessible name — not just "a container rendered". Each was checked
		// against all four criteria above: zero `test.skip`, zero
		// conditional-assert guards, and zero write paths.
		'manifest-shell.spec.ts',
		'spec-coverage/core-list-pages.spec.ts',
		'spec-coverage/admin-settings-pages.spec.ts',
		'spec-coverage/feature-pages.spec.ts',
		// Admitted 2026-08-11. Behavioural (not render-only) specs for the
		// Features & Roadmap surface. Checked against all four criteria: no
		// `test.skip`, no conditional-assert guards, and its ONE write (the
		// `features_roadmap_enabled` app-config key) is made and removed inside
		// a single test's `finally`. Its outbound GitHub call is stubbed with
		// `page.route()`, so admitting it adds no external network dependency.
		'spec-coverage/features-roadmap-surface.spec.ts',
	],
	globalSetup: path.resolve(__dirname, '../global-setup.ts'),
	timeout: 45_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	// One retry in CI absorbs a genuinely slow first paint on a cold runner
	// without hiding a real failure — a broken assertion fails twice.
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// This is the config the shared workflow actually loads (it resolves
	// `${playwright-test-path}/playwright.config.ts` — here `tests/e2e/ci` —
	// before falling back to the app-root one), so the timeout belongs here.
	// The job is `timeout-minutes: 45`, and a job cancelled by that cap
	// produces NO verdict: Playwright never prints its tally, the
	// `if: failure()` trace upload never fires, and the `if: always()` report
	// upload does not run on a cancelled job either — which would undo the
	// reporter and outputDir fixes noted just below, since a cancelled job
	// uploads nothing however correct the paths are. Measured overhead before
	// `Run Playwright tests` starts is 2.0-2.4 min and the uploads after it
	// take seconds, so 38m keeps ~7 min of margin.
	globalTimeout: 38 * 60_000,
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
