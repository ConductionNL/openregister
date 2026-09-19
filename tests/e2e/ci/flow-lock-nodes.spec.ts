/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE LOCK STEPS ARE IN THE PALETTE, AND CAN BE PUT ON THE CANVAS.
 *
 * WHY THIS IS A BROWSER TEST AND NOT A REGISTRY ASSERTION
 * ------------------------------------------------------
 * Both nodes were registered with the engine, executable, unit-tested and
 * completely unusable: they named `core/img/actions/lock.svg` as their icon,
 * an image no shipped Nextcloud carries. `IURLGenerator::imagePath()` throws
 * for an image the server does not have, and `palette()` caught that and
 * dropped the WHOLE ENTRY rather than the icon. So the catalogue the editor
 * reads did not list them, and a step that is not in the palette cannot be
 * added to a flow at all — a total failure produced by a cosmetic cause.
 *
 * Every test that looked at the registry passed throughout, because the
 * registry was never wrong. The only thing that could have caught it is
 * something that reads the CATALOGUE and then puts the step on the canvas,
 * which is what this file does:
 *
 *   1. the catalogue serves both nodes, and each one's icon URL answers 200 —
 *      the exact condition that removed them;
 *   2. the editor's palette renders an entry for each;
 *   3. each entry, clicked, actually reaches the canvas.
 *
 * Assertion 1 alone would have caught the shipped defect, but not a variant of
 * it: a catalogue entry the palette filters out client-side is just as absent
 * to the person authoring the flow. Assertions 2 and 3 are what make this a
 * statement about the editor rather than about an endpoint.
 *
 * ADMITTED TO THE CI FLOOR (tests/e2e/ci/playwright.config.ts, `ci/*.spec.ts`)
 * ---------------------------------------------------------------------------
 * Against that config's four criteria:
 *   1. HERMETIC — no occ, no docker, no seeded data. It opens the editor on a
 *      freshly installed instance and reads the app's own catalogue.
 *   2. NON-MUTATING — it never saves. The steps are dropped on an unsaved
 *      canvas and the page is discarded, so no flow, run or object is created
 *      and there is nothing to clean up.
 *   3. NO CONDITIONAL-ASSERT DEGRADATION — every check is an unconditional
 *      `expect`. The only `.catch()` is on the support-dialog dismissal, which
 *      is scaffolding, not a check.
 *   4. NO test.skip AT ALL, so nothing here can quietly not run.
 *
 * The engine and API behaviour these steps implement — that a lock is held
 * while its run is parked, refuses a write with 423, and is released when the
 * run ends — is `tests/e2e/api-direct/flow-object-locking.spec.ts`, which
 * needs the worker and therefore cannot live on this floor.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md
 */
import type { Locator } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')
const FLOWS_ROUTE = '/index.php/apps/openregister/flows'
const API = '/index.php/apps/openregister/api'

/** The two steps under test, by catalogue id and by the label the palette shows. */
const LOCK_NODES = [
	{ id: 'openregister.lock-object', label: 'Lock an object' },
	{ id: 'openregister.unlock-object', label: 'Unlock an object' },
]

test.use(fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {})

/**
 * Click a Nextcloud themed control.
 *
 * Same reasoning as flow-controls.spec.ts: `.click()` times out on these even
 * when Playwright reports the element visible, enabled and stable, so the
 * event is dispatched directly. The visibility and enablement assertions stay,
 * because a dispatched event reaches a Vue handler whether or not the control
 * is one the UI is actually offering.
 *
 * @param locator The themed control.
 *
 * @return {Promise<void>} When the event has been dispatched.
 */
async function clickThemed(locator: Locator): Promise<void> {
	await expect(locator).toBeVisible()
	await expect(locator).toBeEnabled()
	await locator.dispatchEvent('click')
}

// ⚠️ TWO TESTS, NOT ONE, AND THAT IS THE POINT.
//
// They were one, with the catalogue read as a preamble to the editor
// assertions. Proving the file could fail — by restoring the `palette()`
// behaviour that dropped a node whose icon would not resolve — showed why that
// is wrong: the run stopped at the catalogue assertion, and the editor half
// was never reached. A test whose later half cannot be shown to fire has not
// been proven at all; it has only been proven to have a first half.
//
// Split, the editor test carries no catalogue pre-check, so the same
// neutralisation fails it at the palette, naming the palette.

// @e2e run-scoped-object-locking::the-lock-and-unlock-nodes-can-be-added-to-a-flow
test('the node catalogue serves both lock steps, each with an icon that resolves', async ({
	request,
}) => {
	const catalogue = await request.get(`${API}/flow/node-catalog`)
	expect(catalogue.status(), await catalogue.text()).toBe(200)
	const entries = ((await catalogue.json()).results ?? []) as Array<
		Record<string, any>
	>

	for (const node of LOCK_NODES) {
		const entry = entries.find((row) => row.id === node.id)
		expect(
			entry,
			`the node catalogue does not list ${node.id}, so it cannot be added to a flow at all`,
		).toBeTruthy()

		// The icon is the whole reason this test exists. Assert the URL the
		// editor will request ANSWERS, not merely that the field is a
		// non-empty string: the shipped defect was a syntactically perfect
		// icon path pointing at a file no Nextcloud version ships.
		expect(entry!.icon, `${node.id} carries no icon`).toBeTruthy()
		const icon = await request.get(String(entry!.icon))
		expect(
			icon.status(),
			`${node.id}'s icon (${entry!.icon}) does not resolve; an unresolvable icon is what `
				+ 'removed both of these steps from the palette entirely',
		).toBe(200)

		expect(String(entry!.displayName), `${node.id} display name`).toBe(
			node.label,
		)

		// The step is configurable, not a bare label: a palette entry with no
		// form is one an author can place and never aim at anything.
		expect(
			Array.isArray(entry!.configForm) && entry!.configForm.length > 0,
			`${node.id} offers no configuration form`,
		).toBe(true)
	}

	const lock = entries.find((row) => row.id === 'openregister.lock-object')!
	const keys = (lock.configForm as Array<Record<string, any>>).map(
		(field) => field.key,
	)
	for (const key of ['uuid', 'duration', 'waitSeconds']) {
		expect(keys, `the lock step's form is missing "${key}"`).toContain(key)
	}
})

// @e2e run-scoped-object-locking::the-lock-and-unlock-nodes-can-be-added-to-a-flow
test('both lock steps are offered in the editor palette and reach the canvas', async ({
	page,
}) => {
	// The support dialog is a first-open note that records its dismissal in
	// localStorage; a Playwright context starts empty, so CI meets it EVERY
	// run, centred over the editor, trapping focus. Suppressed by the flag the
	// dialog itself reads, matched on the prefix so a differing app slug does
	// not silently reintroduce it.
	await page.addInitScript(() => {
		const read = Storage.prototype.getItem
		Storage.prototype.getItem = function (key: string) {
			if (
				typeof key === 'string'
				&& key.startsWith('cn-support-dialog-shown:')
			) {
				return '1'
			}
			return read.call(this, key)
		}
	})

	await page.goto(FLOWS_ROUTE, { waitUntil: 'domcontentloaded' })

	const supportDialog = page.getByRole('dialog').filter({ hasText: 'Support' })
	if (await supportDialog.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await page.keyboard.press('Escape')
		await expect(supportDialog).toBeHidden({ timeout: 10_000 })
	}

	// Reach the editor the way a person does. Direct navigation to
	// `/flows/new` does not reliably hydrate the canvas.
	await clickThemed(page.getByRole('button', { name: 'New flow' }))

	// 🔴 THE PALETTE IS NOT IN THE SIDEBAR ANY MORE, since nextcloud-vue
	// 2.40.0. A live instance serves sixty-five step types, and a one-per-row
	// list that long in a 300px column is a scroll rather than a chooser, so it
	// became `CnFlowStepPickerModal`, opened from the toolbar, where the same
	// entries render as a grid. With it went the Steps tab and the tab strip.
	//
	// `.cn-flow-sidebar__palette` survives ONLY as dead CSS, so the old
	// assertion could never pass again — it read as "the palette did not
	// render" on a page that had simply moved it.
	//
	// PICKING A STEP CLOSES THE DIALOG (`add()` emits `close`), so the picker
	// is opened once per node rather than once for the loop.
	const openPicker = async () => {
		const addStep = page.locator('[data-testid="flow-add-step"]')
		await expect(
			addStep,
			'the toolbar offers no way to add a step, so nothing can be said about what is on offer',
		).toBeVisible()
		await clickThemed(addStep)

		const picker = page.locator('[data-testid="flow-step-picker"]')
		await expect(
			picker,
			'the step picker did not open, so nothing can be said about what it offers',
		).toBeVisible()

		// An empty picker renders the same dialog as a full one, so the
		// per-node assertions below would each fail with "not found" and none
		// of them would say that NOTHING loaded. Establish that first.
		await expect
			.poll(
				async () =>
					await picker
						.locator('[data-testid="flow-step-picker-item"]')
						.count(),
				{
					message:
						'the picker opened but offers nothing — the node catalogue never reached the editor, '
						+ 'so a missing lock step below would be reported as a lock bug rather than a load failure',
					timeout: 15_000,
				},
			)
			.toBeGreaterThan(0)

		return picker
	}

	// ── 3. PRESENT, AND ADDABLE ─────────────────────────────────────────────
	for (const node of LOCK_NODES) {
		const picker = await openPicker()
		// MATCH THE NAME EXACTLY. `hasText` is a case-insensitive SUBSTRING
		// match over the whole card — name, role word, description and
		// catalogue id — and "Lock an object" is a substring of "Unlock an
		// object", so the loose filter matched BOTH of the two steps this file
		// exists to tell apart.
		const offered = picker
			.locator('[data-testid="flow-step-picker-item"]')
			.filter({
				has: page.locator('.cn-step-picker__name', {
					hasText: new RegExp(`^${node.label}$`),
				}),
			})
			.first()
		await expect(
			offered,
			`the picker offers no "${node.label}" step, so a flow author cannot lock anything`,
		).toBeVisible()

		// "Listed" is not "usable". The failure mode being guarded against
		// removed the entry from the catalogue outright, but a step that
		// renders and does nothing when picked is the same thing to the person
		// authoring the flow.
		await clickThemed(offered)
		await expect(picker).toBeHidden()
		await expect(
			page.locator('.cn-flow-detail__node', { hasText: node.label }),
			`"${node.label}" is in the picker but never reached the canvas when it was picked`,
		).toBeVisible()
	}

	// Nothing is saved: the canvas is discarded with the page, so this spec
	// leaves no flow, run or object behind and is rerunnable as-is.
})
