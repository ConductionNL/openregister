/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FLOW CONTROLS — the authoring surface renders, and the loop closes.
 *
 * WHY THIS EXISTS
 * ---------------
 * The flow page shipped with no way to save, run, enable, add a step or see
 * run history, while its own empty state read "Add a step from the sidebar".
 * Nothing was missing: CnFlowSidebar implemented the whole panel,
 * FlowDetailSidebar wired save/run to the store, the component was registered,
 * and the manifest declared `sidebarComponent: FlowDetailSidebar` — which
 * CnAppRoot does resolve.
 *
 * It could still never render. CnAppRoot falls back to the manifest's
 * sidebarComponent only as the DEFAULT content of its #sidebar slot, and this
 * app fills that slot itself, so consumer content wins by Vue's ordinary slot
 * mechanic. `SideBars` had no branch for /flows, so the flow route rendered no
 * controls at all and the manifest key was live config with no effect.
 *
 * Every layer was green throughout: the components exist, the routes exist,
 * unit tests pass, the manifest validates. The ONLY thing that could have
 * caught it is a test that opens the page and looks for the controls — which
 * is what this is.
 *
 * WHAT IT ASSERTS
 * ---------------
 *   1. the sidebar renders on a flow route, with its palette and actions
 *   2. a step can be added from the palette and reaches the canvas
 *   3. Save persists — the route advances from `new` to the server's uuid
 *   4. Run now creates a run against that flow
 *
 * It deliberately does NOT wait for the run to COMPLETE: execution is picked up
 * by FlowRunWorker on cron, which does not run in CI. Asserting completion here
 * would make the spec depend on a background job and go flaky; that the run is
 * created and attributed to this flow is the part the UI is responsible for.
 *
 * Hermetic: creates its own flow through the UI and deletes it afterwards, so
 * it leaves the instance as it found it (the CI floor's contract).
 */
import { test, expect } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const FLOWS_ROUTE = '/index.php/apps/openregister/#/flows'

test.use(fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {})

/**
 * Click a Nextcloud themed button.
 *
 * `.click()` is not reliable on these. Playwright reports the element visible,
 * enabled, stable and scrolled into view, and then the click action itself
 * times out — observed on CI against the "New flow" button (`button-vue
 * --legacy34`), twice in a row, burning the whole 45s test timeout while the
 * locator had resolved perfectly.
 *
 * `tests/e2e/global-setup.ts` hit the same wall on the login button and worked
 * around it the same way, noting that "on NC's themed login the styled submit
 * button can swallow the click".
 *
 * Dispatching the event directly drives the Vue `@click` handler, which is the
 * behaviour under test. It does trade away Playwright's actionability checks —
 * so the assertions that the control is VISIBLE stay, above, and are what
 * would catch a control that is missing or covered.
 *
 * @param locator The themed control to click.
 * @return {Promise<void>}
 */
async function clickThemed(locator: import('@playwright/test').Locator): Promise<void> {
	await expect(locator).toBeVisible()
	await locator.dispatchEvent('click')
}

/**
 * Delete a flow by uuid, through the app's own API in the page's session.
 *
 * Runs in the page context so it carries the session cookie and CSRF token;
 * a bare request context has neither.
 *
 * @param page The page whose session performs the delete.
 * @param uuid The flow to delete.
 * @return {Promise<void>}
 */
async function deleteFlow(page: import('@playwright/test').Page, uuid: string): Promise<void> {
	await page.evaluate(async (id) => {
		const meta = document.head.querySelector('meta[name=csrf-token]') as HTMLMetaElement | null
		await fetch(`/apps/openregister/api/flows/${id}`, {
			method: 'DELETE',
			headers: {
				requesttoken:
					// eslint-disable-next-line @typescript-eslint/no-explicit-any
					((window as any).OC && (window as any).OC.requestToken)
					|| (meta && meta.content)
					|| '',
				Accept: 'application/json',
			},
		})
	}, uuid)
}

test('flow controls render, and a flow can be built, saved and run', async ({ page }) => {
	let createdUuid: string | null = null

	try {
		await page.goto(FLOWS_ROUTE, { waitUntil: 'domcontentloaded' })

		// Reach the editor the way a user does. Direct navigation to
		// `#/flows/new` does not always hydrate the canvas.
		await clickThemed(page.getByRole('button', { name: 'New flow' }))

		// 1. THE CONTROLS EXIST. This is the assertion the original defect
		//    would have failed: before the fix `.cn-flow-sidebar` was absent
		//    from the DOM entirely on every flow route.
		const sidebar = page.locator('.cn-flow-sidebar')
		await expect(
			sidebar,
			'the flow sidebar did not render — the controls are unreachable again',
		).toBeVisible()

		const palette = page.locator('.cn-flow-sidebar__palette')
		await expect(
			palette,
			'the step palette did not render, so the empty state\'s own instruction cannot be followed',
		).toBeVisible()

		const actions = page.locator('.cn-flow-sidebar__actions')
		await expect(actions).toBeVisible()
		await expect(actions.getByText('Save', { exact: true })).toBeVisible()
		await expect(actions.getByText('Run now', { exact: true })).toBeVisible()

		// The palette is populated from /api/flow/node-catalog. An empty one
		// renders the same container, so assert it has entries.
		await expect
			.poll(async () => await palette.locator('> *').count(), {
				message: 'the palette rendered but is empty — the node catalog did not load',
				timeout: 15_000,
			})
			.toBeGreaterThan(0)

		// 2. A STEP CAN BE ADDED.
		//
		// `Stop` rather than something like `Edit fields`, and the reason is a
		// real rule rather than convenience: FlowRunService refuses to queue a
		// flow containing a node with no outgoing edge that does not end the
		// flow, because such a run "would stop there and still be reported as
		// completed". A lone `Edit fields` is exactly that shape, so it saves
		// and is then correctly refused a run.
		//
		// `Stop` is a terminal step type, so a single one is a complete,
		// runnable flow — and it has no side effects, so the spec never reaches
		// out of the instance.
		await clickThemed(palette.getByText('Stop', { exact: false }).first())
		await expect(
			page.locator('main').getByText('Stop', { exact: false }).first(),
			'the step did not reach the canvas',
		).toBeVisible()

		// 3. SAVE PERSISTS. The route advancing off `new` is the observable
		//    proof the server accepted it and returned a uuid.
		await clickThemed(actions.getByText('Save', { exact: true }))

		// Poll the URL rather than `waitForURL`. This app uses a HASH router, and
		// a hash-only change fires no navigation event — so `waitForURL`, which
		// defaults to `waitUntil: 'load'`, waits for a load that never comes and
		// times out on a save that in fact succeeded.
		await expect
			.poll(() => page.url(), {
				message: 'Save did not move the route off `new`, so the flow was not persisted',
				timeout: 20_000,
			})
			.toMatch(/#\/flows\/(?!new)[0-9a-f-]{8,}/)

		const uuid = page.url().split('/').pop() ?? ''
		expect(uuid, 'saved flow has no uuid in the route').toMatch(/^[0-9a-f-]{8,}$/)
		createdUuid = uuid

		// 4. RUN NOW CREATES A RUN. Asserted against the API rather than the
		//    rendered status, because the status a run is DISPLAYED with
		//    depends on whether the worker has reached it yet.
		await clickThemed(actions.getByText('Run now', { exact: true }))

		await expect
			.poll(
				async () =>
					await page.evaluate(async (id) => {
						const r = await fetch(
							`/apps/openregister/api/flow-runs?flowId=${id}&limit=25`,
							{ headers: { Accept: 'application/json' } },
						)
						if (!r.ok) {
							return -1
						}
						const body = await r.json()
						return (body?.results ?? []).length
					}, uuid),
				{
					message: 'Run now did not produce a run for this flow',
					timeout: 20_000,
				},
			)
			.toBeGreaterThan(0)
	} finally {
		if (createdUuid !== null) {
			await deleteFlow(page, createdUuid)
		}
	}
})
