/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENUINE behavioural e2e for the Features & Roadmap surface — the parts of
 * `openspec/specs/features-roadmap-menu/spec.md` a browser can actually decide.
 *
 * WHY A NEW FILE RATHER THAN MORE CASES IN feature-pages.spec.ts
 * -------------------------------------------------------------
 * `feature-pages.spec.ts` asserts that each feature page RENDERS (heading +
 * primary actions). These three tests assert that it BEHAVES: a form that
 * refuses to submit until it is valid, an empty state that is a real render of
 * a live panel, and an admin kill-switch whose backend actually flips. Mixing
 * "the page mounted" with "the page decided something" in one file makes it too
 * easy to read a green tally as more than it is.
 *
 * ⚠️ EVERY TEST HERE CARRIES ITS OWN CONTROL, ON PURPOSE.
 * Each assertion below could otherwise be satisfied by a page that failed to
 * mount: a disabled button is disabled when it does not exist, an "empty state"
 * is empty when nothing rendered, and a route "renders normally" if you never
 * looked. So each test pairs its requirement with a state the SAME locator must
 * distinguish — enabled vs disabled, features panel vs roadmap panel, 403 vs
 * not-403. A broken page produces neither side of those pairs, so it cannot
 * pass by accident.
 *
 * ADMISSION CRITERIA (tests/e2e/ci/playwright.config.ts) — checked per test:
 *   1. Hermetic: no `occ`, no docker, no pre-seeded fixtures. The roadmap's
 *      outbound GitHub call is STUBBED via `page.route()` so the suite makes no
 *      external network request and does not depend on github.com being up.
 *   2. Self-cleaning: the only mutation is the `features_roadmap_enabled`
 *      app-config key, written and removed inside one test, in a `finally`.
 *   3. No unconditional-pass degradation: no `if (visible) { … }` guards.
 *   4. No `test.skip()`.
 *
 * @e2e openspec/specs/features-roadmap-menu/spec.md#empty-features-manifest
 * @e2e openspec/specs/features-roadmap-menu/spec.md#submit-requires-title-and-body
 * @e2e openspec/specs/features-roadmap-menu/spec.md#default-behavior
 */
import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

/** The route the app's own roadmap tab calls. Observed live, not guessed. */
const ROADMAP_API = '**/apps/openregister/api/github/issues*'

/** OCS provisioning path for the admin kill-switch this spec exercises. */
const FLAG_PATH = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/openregister/features_roadmap_enabled'

/**
 * Navigate to the Features & Roadmap route and wait for the view nc-vue
 * actually renders (`CnFeaturesAndRoadmapView`), not for a generic container.
 *
 * HASH form: the router runs in hash mode (src/main.js), so a path-form
 * deep-link renders the dashboard instead of the target page.
 */
async function gotoRoadmapRoute(page: Page): Promise<void> {
	await page.goto('/index.php/apps/openregister/#/features-roadmap',
		{ waitUntil: 'domcontentloaded' })
	await page.locator('.cn-features-and-roadmap-view')
		.waitFor({ state: 'visible', timeout: 30_000 })
}

/** Stub the roadmap proxy so no test in this file reaches github.com. */
async function stubRoadmap(page: Page, body: Record<string, unknown>): Promise<void> {
	await page.route(ROADMAP_API, async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(body),
		})
	})
}

test.describe('features-roadmap — behaviour, not just render', () => {
	test.use({ storageState: STORAGE_STATE })

	/**
	 * The Features panel's empty state is a REAL render of a LIVE panel.
	 *
	 * The requirement: with no documented features, the Features tab shows
	 * "No features documented yet" rather than a technical error.
	 *
	 * ⚠️ On its own this is an absence-shaped claim and a page that never
	 * mounted would satisfy it for free. The control is the panel TOGGLE: the
	 * same `<main class="…__panel">` must swap `.cn-features-tab` for
	 * `.cn-roadmap-tab` and the heading must change "Features" -> "Roadmap",
	 * then swap back. A dead page cannot produce that difference, and a panel
	 * that can produce it is a panel that really rendered the empty state.
	 *
	 * The roadmap side is stubbed with an EMPTY item list, so the control
	 * asserts a second, distinct empty state ("No roadmap items yet") in the
	 * same slot — two different strings from one live component.
	 *
	 * ⚠️ NOT ANCHORED to `#pat-not-configured-roadmap`, deliberately. The
	 * shipped `CnRoadmapTab` renders "Roadmap not yet configured" for the
	 * `github_pat_not_configured` hint, while the spec's THEN clause names
	 * "Roadmap currently unavailable". The mechanism is implemented; the
	 * wording has diverged, so crediting that scenario here would report a
	 * stated outcome as verified when it was not. Filed separately.
	 *
	 * @e2e openspec/specs/features-roadmap-menu/spec.md#empty-features-manifest
	 */
	test('an empty features manifest renders the documented empty state in a demonstrably live panel', async ({ page }) => {
		await stubRoadmap(page, { items: [] })
		await gotoRoadmapRoute(page)

		const panel = page.locator('.cn-features-and-roadmap-view__panel')
		const title = page.locator('.cn-features-and-roadmap-view__title')

		// The requirement.
		await expect(panel.locator('.cn-features-tab')).toBeVisible()
		await expect(panel.locator('.cn-features-tab .empty-content__name'))
			.toHaveText('No features documented yet')
		await expect(title).toHaveText('Features')

		// CONTROL, direction 1 — the same panel renders something else.
		await page.getByRole('button', { name: /Show roadmap/i }).click()
		await expect(panel.locator('.cn-roadmap-tab')).toBeVisible({ timeout: 20_000 })
		await expect(panel.locator('.cn-features-tab')).toHaveCount(0)
		await expect(title).toHaveText('Roadmap')
		// A DIFFERENT empty state, from the same slot, on the stubbed payload.
		await expect(panel.locator('.cn-roadmap-tab .empty-content__name'))
			.toHaveText('No roadmap items yet')

		// CONTROL, direction 2 — and back, so the first state was not a one-way
		// render that happened to be showing when we looked.
		await page.getByRole('button', { name: /Show features/i }).click()
		await expect(panel.locator('.cn-features-tab')).toBeVisible({ timeout: 20_000 })
		await expect(panel.locator('.cn-roadmap-tab')).toHaveCount(0)
		await expect(title).toHaveText('Features')
	})

	/**
	 * The Suggest-feature form refuses to submit until it is valid, and issues
	 * no request while invalid.
	 *
	 * The requirement's THEN is "the Submit button SHALL be disabled OR SHALL
	 * show inline validation errors AND no POST SHALL be issued". This asserts
	 * the disabled branch, on a FOUR-STATE gradient measured live against
	 * `CnSuggestFeatureModal.canSubmit`:
	 *
	 *     empty                       -> disabled
	 *     title only                  -> disabled
	 *     all four text fields        -> disabled   (priority still unset)
	 *     + priority selected         -> ENABLED
	 *
	 * The final transition is the control: the same locator must flip. A modal
	 * that failed to render, or a locator that matches nothing, is "disabled"
	 * in all four states and therefore CANNOT pass this test.
	 *
	 * ⚠️ Asserts on "Continue on <forge>", not "Send to Conduction". Both are
	 * submit buttons, but the Conduction one is additionally gated on
	 * `conductionSubmitEnabled`, which is false on a stock instance — so it
	 * stays disabled even when the form is valid and would make the enable
	 * transition unobservable. The forge button is gated on `canSubmit` alone,
	 * which is exactly what this scenario is about.
	 *
	 * ⚠️ Nothing is ever submitted: a real submission opens a real issue on a
	 * real forge. `posts` asserts that, rather than trusting it.
	 *
	 * @e2e openspec/specs/features-roadmap-menu/spec.md#submit-requires-title-and-body
	 */
	test('the suggest-feature form stays unsubmittable until every required field is filled, and issues no POST', async ({ page }) => {
		await stubRoadmap(page, { items: [] })

		// Any POST at all to the issue endpoint fails the test — asserted at
		// the end, so an early submit cannot slip through unnoticed.
		const posts: string[] = []
		page.on('request', (r) => {
			if (r.method() === 'POST' && r.url().includes('/api/github/issues')) {
				posts.push(r.url())
			}
		})

		await gotoRoadmapRoute(page)
		await page.getByRole('button', { name: /^\s*Suggest feature\s*$/i }).click()

		const modal = page.locator('[data-testid-modal="cn-suggest-feature-modal"]')
		await expect(modal).toBeVisible({ timeout: 20_000 })

		const submit = page.getByRole('button', { name: /Continue on/i })
		const cancel = page.getByRole('button', { name: /^Cancel$/i })

		// LIVENESS CONTROL: a sibling button in the same footer IS enabled, so
		// "disabled" below is a decision about this control, not a symptom of a
		// modal that never rendered.
		await expect(cancel).toBeEnabled()

		await expect(submit).toBeDisabled()

		await page.getByRole('textbox', { name: /^Title$/i })
			.fill('Coverage: assert the suggest-feature validation gradient')
		await expect(submit).toBeDisabled()

		await page.getByRole('textbox', { name: /^Problem$/i })
			.fill('The submit gate was never exercised by a browser test.')
		await page.getByRole('textbox', { name: /Proposed solution/i })
			.fill('Drive the four-state gradient and assert the final flip.')
		await page.getByRole('textbox', { name: /Who benefits/i })
			.fill('Maintainers reading gate-19 numbers.')
		// Still incomplete: `canSubmit` also requires a priority.
		await expect(submit).toBeDisabled()

		// Keyboard, not a click. The dropdown list is inside the dialog subtree,
		// which intercepts pointer events on the option `<li>` and turns a click
		// into a 60 s timeout that reads as a slow page rather than a wrong
		// gesture. Arrow+Enter is a real user gesture and is not intercepted.
		const combo = page.locator('.v-select input[type="search"], .v-select .vs__search').first()
		await combo.click()
		await combo.press('ArrowDown')
		await combo.press('Enter')

		// THE CONTROL: the same locator flips.
		await expect(submit).toBeEnabled({ timeout: 15_000 })

		await cancel.click()
		await expect(modal).toHaveCount(0)

		expect(posts, `the form issued ${posts.length} POST(s) to the issue endpoint: ${posts.join(', ')}`)
			.toHaveLength(0)
	})

	/**
	 * With `openregister::features_roadmap_enabled` absent, the feature is on —
	 * and "on" is asserted against the component that actually enforces the
	 * flag, not just against a page that rendered.
	 *
	 * `GitHubGuards` (lib/Service/Configuration/GitHubGuards.php) is the only
	 * enforcement point: it reads the key with a `true` default and answers 403
	 * `{"error":"feature_disabled"}` when it is false. So the honest test of
	 * "the default is true" is that the guard says not-403 when the key is
	 * absent, AND says 403 when it is false — the second half being the control
	 * that proves the first half is a real decision rather than an endpoint
	 * that answers not-403 no matter what.
	 *
	 * Self-cleaning: the key is deleted in `finally`, and deletion (not
	 * "set back to true") is what restores ABSENCE, which is the state under
	 * test. Writing `true` would leave the instance in a different state from
	 * the one this test started in.
	 *
	 * @e2e openspec/specs/features-roadmap-menu/spec.md#default-behavior
	 */
	test('the features_roadmap_enabled default is true, and the guard that enforces it can still refuse', async ({ page, request }) => {
		const ocs = { 'OCS-APIRequest': 'true' }
		const probeGuard = async () =>
			request.get('/index.php/apps/openregister/api/github/issues')

		// Start from the absent state this scenario is about.
		await request.delete(FLAG_PATH, { headers: ocs })

		// The requirement: absent key => the feature behaves as enabled.
		const whenAbsent = await probeGuard()
		expect(whenAbsent.status(),
			`guard answered ${whenAbsent.status()} with the key ABSENT; the default must be enabled`)
			.not.toBe(403)

		// ...and the route renders rather than showing a disabled notice.
		await stubRoadmap(page, { items: [] })
		await gotoRoadmapRoute(page)
		await expect(page.getByRole('button', { name: /^\s*Suggest feature\s*$/i })).toBeVisible()

		try {
			// THE CONTROL: flip the key and the same probe must refuse.
			const set = await request.post(FLAG_PATH, { headers: ocs, form: { value: 'false' } })
			expect(set.status(), 'could not write the app-config key').toBe(200)

			const whenFalse = await probeGuard()
			expect(whenFalse.status()).toBe(403)
			expect(await whenFalse.text()).toContain('feature_disabled')
		} finally {
			await request.delete(FLAG_PATH, { headers: ocs })
		}

		// And the flip is reversible, so the control did not leave the
		// instance changed for whatever runs next.
		const whenRestored = await probeGuard()
		expect(whenRestored.status(),
			'the key was not restored to ABSENT — a later test would inherit a disabled feature')
			.not.toBe(403)
	})
})
