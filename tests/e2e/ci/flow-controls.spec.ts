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
 * Delete a flow by uuid, through the app's own API in the page's session.
 *
 * Runs in the page context so it carries the session cookie and CSRF token;
 * a bare request context has neither.
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
		const newFlow = page.getByRole('button', { name: 'New flow' })
		await expect(newFlow).toBeVisible()
		await newFlow.click()

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

		// 2. A STEP CAN BE ADDED. `set-fields` has no side effects, so the
		//    spec never reaches out of the instance.
		await palette.getByText('Edit fields', { exact: false }).first().click()
		await expect(
			page.locator('main').getByText('Edit fields', { exact: false }).first(),
			'the step did not reach the canvas',
		).toBeVisible()

		// 3. SAVE PERSISTS. The route advancing off `new` is the observable
		//    proof the server accepted it and returned a uuid.
		await actions.getByText('Save', { exact: true }).click()
		await page.waitForURL(/#\/flows\/(?!new)[0-9a-f-]{8,}/, { timeout: 20_000 })

		const uuid = page.url().split('/').pop() ?? ''
		expect(uuid, 'saved flow has no uuid in the route').toMatch(/^[0-9a-f-]{8,}$/)
		createdUuid = uuid

		// 4. RUN NOW CREATES A RUN. Asserted against the API rather than the
		//    rendered status, because the status a run is DISPLAYED with
		//    depends on whether the worker has reached it yet.
		await actions.getByText('Run now', { exact: true }).click()

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
