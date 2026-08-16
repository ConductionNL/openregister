/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI PLAYWRIGHT CONFIG — a deliberately small, always-green spec set.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The root config points `testDir` at the whole of `tests/e2e`, which is 64
 * spec files / 383 tests (measured 2026-08-16 with `playwright test --list`
 * from `git archive HEAD`; the prose said 58 and had gone stale, which is why
 * this line now names the command that produced it). Several of them write
 * fixtures, shell out to `occ`, or capture
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
 * ⚠️ CRITERION 2 HAS TWO BRANCHES AND ONLY ONE HAD EVER BEEN USED.
 * ---------------------------------------------------------------
 * "Non-mutating, OR self-cleaning" — every file admitted before 2026-08-16 took
 * the first branch, and the sentence that followed it ("All four files admitted
 * below only navigate, open a modal, and read — they write nothing") described
 * that accident as though it were the rule. It is not the rule: a spec that
 * seeds what it needs and removes it again satisfies criterion 2 by its second
 * branch, and refusing those made the whole write path unmeasurable.
 *
 * That mattered, because it is not a small corner. Measured on `development`
 * `cbb0c813` with `playwright test --list` run from `git archive HEAD` (which
 * reproduces CI's `Running N tests` exactly): the repo holds **64 spec files /
 * 383 tests**, and this allow-list ran **9 files / 44 tests — 14 % of the files
 * and 11 % of the tests**. openregister is the foundation repo for 18 apps, and
 * a green E2E column that speaks for an eighth of the suite is worth less than
 * it looks. Every remaining create/update/delete assertion in the repo sat on
 * the far side of a criterion that had been read one branch too narrowly.
 *
 * ⚠️ AND THE INSTANCE IS DISPOSABLE, WHICH IS THE OTHER HALF.
 * The objection to admitting a fixture-writing spec is that it corrupts the
 * instance for whatever runs next. That is true of the SHARED dev container on
 * :8080 — which is why these specs cannot be rehearsed there — and it is not
 * true here: `quality.yml`'s `playwright` job runs on its own `ubuntu-latest`
 * runner with its own `postgres:16` service container and its own Nextcloud
 * checkout, created and destroyed per run. Nothing outside the job can see what
 * a spec writes. The constraint that kept these files out was a constraint on
 * REHEARSING them locally, not on running them in CI, and the two were being
 * treated as one.
 *
 * `tests/e2e/_fixtures.ts` already implements the discipline criterion 2's
 * second branch asks for: every entity is namespaced `e2e-<Date.now()>` so two
 * runs cannot collide, and `afterAll` deletes exactly what `beforeAll` seeded.
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
	// keeps `testDir: '..'` from silently pulling in the other 52 spec files,
	// including `api-direct/**` (25 files / 199 tests — Newman's job, not
	// Playwright's), `visual/**` (5 files, opt-in project, host-font-specific
	// baselines) and `docs-screenshots` (journeydoc capture).
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
		// Admitted 2026-08-16. The first specs here to take criterion 2's SECOND
		// branch — they write, and they clean up after themselves. Until now the
		// CI floor could not fail on a create, an update or a delete at all: the
		// nine files above navigate and read, so persistence was the one thing
		// the E2E column never spoke about. These three assert it end to end
		// (POST → fresh GET returns the submitted values → the row renders →
		// DELETE → GET is >=400 and the row is gone), which is the guarantee the
		// shell specs cannot reach.
		//
		// Checked per file against all four criteria:
		//   1. Hermetic — each seeds its own register/schema/object through the
		//      documented REST controllers. No `occ`, no docker, no pre-seeded
		//      data. (`core-crud.spec.ts` is NOT admitted for exactly this
		//      reason: it hard-codes "the larpingapp register (id=8, schema=18)".)
		//   2. Self-cleaning — `_fixtures.ts` namespacing plus an `afterAll` that
		//      re-resolves by slug when a mid-run failure lost the id, so an
		//      aborted run still tears its fixtures down.
		//   3. No conditional-assert guards — zero `isVisible().catch(() => false)`
		//      in all three files.
		//   4. Their `test.skip()`s are NOT seed-dependent. Each is
		//      `test.skip(<id> === null)` inside a `mode: 'serial'` describe: a
		//      cascade guard that can only fire when the CREATE step in the same
		//      file already failed, which is a red run, not a quiet one. No skip
		//      here is reachable on a healthy instance.
		//
		// 📌 `object-crud.spec.ts` carries one pre-existing `test.fixme` — the
		// Add Object modal's CodeMirror form is not deterministically fillable
		// headlessly. It is admitted WITH that fixme visible in the skip column
		// rather than deleted: a declared gap that reports as a non-pass is the
		// honest shape, and hiding the file to keep the skip count at zero would
		// be the invisible pass this config exists to refuse.
		'crud/register-crud.spec.ts',
		'crud/schema-crud.spec.ts',
		'crud/object-crud.spec.ts',
		// Admitted 2026-08-16, in a SEPARATE commit so the three files above keep
		// an isolated CI verdict.
		//
		// ⚠️ THIS FILE WAS FIRST REFUSED ON THE STRENGTH OF ITS OWN HEADER, AND
		// THE HEADER IS STALE. The refusal read: "it carries `test.fixme` blocks
		// for real defects — see its BUG LIST". The file contains **zero**
		// `test.fixme` calls (`grep -cE '^\s*test\.fixme\(' -> 0`). Its BUG LIST
		// describes three defects — soft-deleted objects never appearing in
		// GET /api/deleted, restore returning 200 while restoring nothing, and
		// `_includeDeleted=true` returning 500 — that are each annotated
		// `// BUG-N (FIXED)` in the code beside the test, with the root cause
		// (DeletedController.index() searching without a register/schema context,
		// so it never reached the per-register/schema magic tables). The three
		// tests are LIVE REGRESSION LOCKS on that fix, not disabled reports of it.
		//
		// Reading the prose instead of the code is the failure mode this repo has
		// paid for repeatedly: an explanation of a pattern matches the pattern.
		// The correction is recorded here rather than quietly applied.
		//
		// Against the four criteria: hermetic and self-seeding through
		// `_fixtures.ts` (register + schema + object per describe block);
		// self-cleaning, including a hard `DELETE /api/deleted/{uuid}` in
		// `afterAll` so a soft-deleted fixture cannot survive the run; zero
		// conditional-assert guards. Its single `test.skip` —
		// `test.skip(trails.length === 0, 'no audit trail entries to render')` —
		// sits downstream of two tests in the same file that assert the "create"
		// and "update" audit entries EXIST unconditionally, so a broken audit
		// trail fails loudly before this skip is ever reachable.
		'workflows/object-lifecycle-workflows.spec.ts',
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
		[
			'html',
			{
				open: 'never',
				outputFolder: path.resolve(__dirname, '../playwright-report'),
			},
		],
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
