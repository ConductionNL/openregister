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
 *
 * WHY IT WAS FLAKY, AND WHAT THAT UNCOVERED
 * -----------------------------------------
 * It failed on the FIRST attempt of 6 of the 8 CI runs that executed it (75%),
 * always at the same place — `waitForURL` after Save — and always at 23.9-24.6s,
 * i.e. ~4s of real work plus a 20s timeout expiring in full. Passing attempts
 * took 4.5-7.3s. A wait that always expires in FULL is not a slow round-trip;
 * it is a thing that was never going to happen.
 *
 * It never happened because the SAVE WAS REJECTED. `useFlowStore`'s initial
 * state is `emptyFlow()`, whose `name` is `''`; only `open('new')` gives the
 * flow a name, and `open()` runs at the tail of `load()`, behind
 * `await GET /api/flows` — a flow LIST that starting a blank flow does not
 * need. The sidebar is fully rendered and its buttons enabled well before that,
 * because `nodeCatalog` was already populated by the flows INDEX page's own
 * `load()`. So there is a window in which the editor invites a Save of a flow
 * with no name, `FlowController::create()` answers 400 "A flow needs a name.",
 * `store.save()` swallows it into `return null`, and `onSave()` therefore never
 * calls `$router.replace`.
 *
 * That window is a REAL DEFECT, not a test artefact: a user who clicks Save
 * quickly enough gets silence — no error, no toast, no log line, because
 * nothing renders `store.error` and a 400 JSONResponse is not an exception.
 * The same race also wipes a just-added step off the canvas when `open('new')`
 * lands a moment later. Both belong to `@conduction/nextcloud-vue`, and are
 * reported as ConductionNL/nextcloud-vue#607 — this spec does not paper over
 * them, it is what makes them detectable.
 *
 * AND THE GREEN RUNS WERE WORSE THAN THE RED ONES
 * -----------------------------------------------
 * Instrumenting what Save actually POSTs, over 14 local runs, split three ways:
 *
 *   POST /api/flows 400, name=""    -> red   (the race above)
 *   POST /api/flows 201, nodes=1    -> red   at step 4: POST .../run 500
 *   POST /api/flows 201, nodes=0    -> GREEN
 *
 * The spec passed ONLY when the flow it saved had no steps in it. A one-node
 * `set-fields` flow cannot run at all: since #2354 a path must end deliberately,
 * and `FlowRunService::queue()` refuses a node with no outgoing edge that is not
 * terminal. So every run that genuinely persisted the step failed step 4, and
 * every green run was green because the race had thrown the step away first.
 * The 20s poll dressed that up as "Run now did not produce a run".
 *
 * That is the failure mode this file's own header warns about — every layer
 * green while nothing is tested — so the fixture now builds the smallest flow
 * the app calls VALID (one terminal node) instead of one it is obliged to
 * refuse. See step 2.
 *
 * So this spec now waits for the state a save actually REQUIRES rather than for
 * a wall clock, and asserts the create and run RESPONSES rather than inferring
 * success from a route and a poll. The three 15-20s budgets it used to sit out
 * are gone, not widened: with the round-trips asserted directly, what remains
 * are 5s router and read-back assertions. If any of these defects returns, this
 * spec fails in about a second, quoting the server's own reason.
 */
import { test, expect } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const FLOWS_ROUTE = '/index.php/apps/openregister/#/flows'

// Whether the INSTALLED library carries the consolidated editor (toolbar +
// seeded start node). Feature-detected on the source the bundle was built
// from, not on a version number: the transition window installs 2.3.x from
// npm while dev instances may run a synced pre-release tree, and a version
// string cannot tell those apart. Self-clears on the lockfile bump.
const NEW_EDITOR = (() => {
	try {
		return fs
			.readFileSync(
				path.resolve(
					__dirname,
					'../../../node_modules/@conduction/nextcloud-vue/src/components/CnFlowDetail/CnFlowDetail.vue',
				),
				'utf8',
			)
			.includes('cn-flow-detail__toolbar')
	} catch {
		return false
	}
})()

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
 * so the assertions that the control is VISIBLE and ENABLED stay, here, and are
 * what would catch a control that is missing, covered or inert.
 *
 * `toBeEnabled()` is not decoration. A dispatched event reaches a Vue handler
 * whether or not the control is disabled, so without it this helper would
 * happily "click" a button the UI is refusing to offer and report the resulting
 * silence as a timeout somewhere else. Both flow actions really are gated —
 * Save on `store.saving`, Run now on `store.running || !store.flow.id`.
 *
 * @param locator The themed control to click.
 * @return {Promise<void>}
 */
async function clickThemed(
	locator: import('@playwright/test').Locator,
): Promise<void> {
	await expect(locator).toBeVisible()
	await expect(locator).toBeEnabled()
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
async function deleteFlow(
	page: import('@playwright/test').Page,
	uuid: string,
): Promise<void> {
	await page.evaluate(async (id) => {
		const meta = document.head.querySelector(
			'meta[name=csrf-token]',
		) as HTMLMetaElement | null
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

test('flow controls render, and a flow can be built, saved and run', async ({
	page,
}) => {
	let createdUuid: string | null = null

	// EVERY request the flow store makes is wrapped in a `catch` that logs
	// `cn-flow: could not …` and returns an empty result or null. Nothing
	// renders `store.error`, so a rejected save, a rejected run and a catalogue
	// that failed to load are all INVISIBLE — to the user, and to a test that
	// only watches the DOM. Collected here so a swallowed failure fails this
	// test by name, at the moment it happens, instead of surfacing seconds
	// later as a timeout that names something unrelated.
	const storeErrors: string[] = []
	page.on('console', (message) => {
		if (message.type() === 'error' && message.text().includes('cn-flow:')) {
			storeErrors.push(message.text())
		}
	})

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
			"the step palette did not render, so the empty state's own instruction cannot be followed",
		).toBeVisible()

		// Save and Run moved onto the canvas toolbar in the flow-editor
		// consolidation (@conduction/nextcloud-vue 2.4). During the transition
		// window the installed library may still be 2.3.x, whose Save/"Run now"
		// live in the sidebar's actions block — so the spec drives whichever
		// editor is actually installed, feature-detected at load. Both paths
		// assert the same loop; neither is a reduced version of the other.
		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		const actions = page.locator('.cn-flow-sidebar__actions')

		const saveButton = NEW_EDITOR
			? toolbar.getByRole('button', { name: 'Save', exact: true })
			: actions.getByRole('button', { name: 'Save', exact: true })
		const runButton = NEW_EDITOR
			? toolbar.getByRole('button', { name: 'Run', exact: true })
			: actions.getByRole('button', { name: 'Run now', exact: true })

		await expect(NEW_EDITOR ? toolbar : actions).toBeVisible()
		await expect(saveButton).toBeVisible()
		await expect(runButton).toBeVisible()

		// The palette is populated from /api/flow/node-catalog. An empty one
		// renders the same container, so assert it has entries.
		await expect
			.poll(async () => await palette.locator('> *').count(), {
				message:
					'the palette rendered but is empty — the node catalog did not load',
				timeout: 15_000,
			})
			.toBeGreaterThan(0)

		// ── THE EDITOR IS INTERACTIVE BEFORE IT IS INITIALISED ──────────────
		// This wait is the whole reason this spec used to fail 6 runs in 8.
		//
		// `useFlowStore`'s INITIAL state is `emptyFlow()`, whose `name` is `''`.
		// Only `open('new')` replaces it with a named flow, and `open()` runs at
		// the TAIL of `load()` — after `await GET /api/flows`, a flow LIST the
		// editor does not need in order to start a blank flow.
		//
		// The sidebar, meanwhile, is already rendered and its buttons already
		// enabled, because `nodeCatalog` was populated by the INDEX page's own
		// `load()` before this route was ever reached. So the controls invite a
		// Save during a window in which the flow still has no name — and
		// `FlowController::create()` answers 400 "A flow needs a name.", which
		// `store.save()` swallows into `return null`, which makes
		// `FlowDetailSidebar.onSave()` skip `$router.replace` entirely. Nothing
		// is logged server-side (a 400 JSONResponse is not an exception) and
		// nothing is shown client-side. The only symptom was this spec's
		// `waitForURL` sitting out its full 20s.
		//
		// So wait for the state the save actually requires, and say so. The
		// name field now lives behind the sidebar's Flow tab, but the toolbar's
		// Save button is disabled exactly while the flow has no name — so its
		// enablement IS the "editor initialised" signal, with no tab click.
		await expect(
			saveButton,
			'the editor never initialised — the flow store is still holding its '
				+ 'blank initial state, whose name is empty, and a flow with no name '
				+ 'is rejected by the server',
		).toBeEnabled()

		// 2. A STEP CAN BE ADDED.
		//
		// `stop`, and the choice is load-bearing. This used to add `set-fields`,
		// which made the saved flow UNRUNNABLE: since #2354 a path must end
		// deliberately, and `FlowRunService::queue()` refuses any node that has
		// no outgoing edge and is not terminal — which `set-fields` is not.
		// Only `EndNode` implements `IFlowEndNode`, so a lone `set-fields`
		// node is a dead end by construction and step 4 below could never pass
		// on it. Measured: every run of this spec that actually persisted the
		// step failed, and the only runs that went green were the ones where
		// the initialisation race above had silently WIPED the step, saving an
		// empty flow that trivially has no dead end. The spec was green exactly
		// when it had tested nothing.
		//
		// A single terminal node is the smallest flow this app calls valid, so
		// it is what the spec builds. `stop` has no side effects either, so this
		// still never reaches out of the instance. Drawing an edge from
		// `set-fields` to `stop` would cover more, but drag-to-connect on the
		// canvas is exactly the interaction this file already documents as
		// unreliable, and a fixture that flakes is how this started.
		//
		// THE LABEL IS 'End', NOT 'Stop'. The vocabulary moved twice in quick
		// succession — terminal -> stop (4eac3a3), then stop -> end (7ba3c21) —
		// and this locator was left on the middle spelling. That is the whole
		// reason the job went red: `EndNode::getLabel()` returns `t('End')`, and
		// the palette has carried no entry called "Stop" since that commit.
		await clickThemed(palette.getByText('End', { exact: true }).first())
		const endCard = page.locator('.cn-flow-detail__node', { hasText: 'End' })
		await expect(endCard, 'the step did not reach the canvas').toBeVisible()

		if (NEW_EDITOR) {
			// Connect the seeded start node to the End step, or the saved flow
			// has a dead end and `FlowRunService::queue()` refuses to run it. A
			// new flow opens with the manual-trigger start node already on the
			// canvas (flow-editor consolidation), and the canvas's KEYBOARD
			// connection path (`c` on the source, `c` on the target) is used
			// because drag-to-connect is exactly the interaction this file
			// already documents as unreliable. On 2.3.x there is no seeded node:
			// the flow is the single terminal step above, which is the smallest
			// flow the app calls valid, so there is nothing to connect.
			const startCard = page.locator('.cn-flow-detail__node', {
				hasText: 'When someone runs it',
			})
			await expect(startCard, 'the seeded start node is missing').toBeVisible()
			await startCard.click()
			await page.keyboard.press('c')
			await endCard.click()
			await page.keyboard.press('c')
			await expect(
				page.locator('.cn-flow-detail__edge').first(),
				'the connection did not reach the canvas',
			).toBeVisible()
		}

		// 3. SAVE PERSISTS.
		//
		// Asserted on the CREATE RESPONSE, not on the route. The route advancing
		// is a consequence of a successful save, so waiting on it made every
		// server-side rejection look like a navigation that was merely slow —
		// and burn 20 seconds before reporting the wrong thing. The response
		// carries the verdict and the reason, so a rejected save now fails here,
		// immediately, quoting the server.
		const created = page.waitForResponse(
			(response) =>
				response.request().method() === 'POST'
				&& new URL(response.url()).pathname.endsWith(
					'/apps/openregister/api/flows',
				),
			{ timeout: 10_000 },
		)

		await clickThemed(saveButton)

		const createResponse = await created
		expect(
			createResponse.status(),
			`the flow was not created — the server answered ${createResponse.status()}: `
				+ `${(await createResponse.text()).slice(0, 200)}`,
		).toBe(201)

		const uuid = String((await createResponse.json())?.id ?? '')
		expect(uuid, 'the created flow came back without a uuid').toMatch(
			/^[0-9a-f-]{8,}$/,
		)
		createdUuid = uuid

		// The route still has to catch up, or a reload lands back on `new`. With
		// the create already asserted above this is a pure router assertion with
		// no round-trip left in it, so it needs a fraction of the old budget.
		await page.waitForURL(new RegExp(`#/flows/${uuid}$`), { timeout: 5_000 })

		// 4. RUN NOW CREATES A RUN. Asserted against the API rather than the
		//    rendered status, because the status a run is DISPLAYED with
		//    depends on whether the worker has reached it yet.
		const queued = page.waitForResponse(
			(response) =>
				response.request().method() === 'POST'
				&& response.url().includes(`/api/flows/${uuid}/run`),
			{ timeout: 10_000 },
		)

		await clickThemed(runButton)

		const runResponse = await queued
		expect(
			runResponse.status(),
			`Run now was refused — the server answered ${runResponse.status()}: `
				+ `${(await runResponse.text()).slice(0, 200)}`,
		).toBe(201)

		// And the run is ATTRIBUTED to this flow — a separate claim from "a run
		// was created", and the one a client actually depends on to show
		// history. The row exists by now, so this is a read-back, not a wait.
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
					message:
						'the run was accepted but is not listed against this flow',
					timeout: 5_000,
				},
			)
			.toBeGreaterThan(0)

		// Backstop for every OTHER request the store swallows — the two
		// catalogues and the run history. None of them has a visible failure
		// state, so without this they fail silently and degrade the page.
		expect(
			storeErrors,
			'the flow store swallowed a request failure into an empty state',
		).toEqual([])
	} finally {
		if (createdUuid !== null) {
			await deleteFlow(page, createdUuid)
		}
	}
})
