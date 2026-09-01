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
 * ⚠️ THAT TABLE IS HISTORY, NOT A LIVE REPRODUCTION. It describes the DEAD-END
 * 500 fixed above, and it has already been read once as though it explained a
 * later, unrelated 500 — sending the reader looking for something the run path
 * does per NODE. It does not: the second 500 was `FlowLifecycleRefused`
 * escaping `FlowController::run()` because a freshly created flow is a DRAFT
 * and a draft backs no run. Node count had nothing to do with it. Read this
 * table as a record of what step 2 fixed, and nothing else.
 *
 * So this spec now waits for the state a save actually REQUIRES rather than for
 * a wall clock, and asserts the create and run RESPONSES rather than inferring
 * success from a route and a poll. The three 15-20s budgets it used to sit out
 * are gone, not widened: with the round-trips asserted directly, what remains
 * are 5s router and read-back assertions. If any of these defects returns, this
 * spec fails in about a second, quoting the server's own reason.
 */
import type { Locator } from '@playwright/test'
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const FLOWS_ROUTE = '/index.php/apps/openregister/flows'

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
async function clickThemed(locator: Locator): Promise<void> {
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
async function deleteFlow(page: Page, uuid: string): Promise<void> {
	await page.evaluate(async (id) => {
		const meta = document.head.querySelector(
			'meta[name=csrf-token]',
		) as HTMLMetaElement | null
		await fetch(`/apps/openregister/api/flows/${id}`, {
			method: 'DELETE',
			headers: {
				requesttoken:
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

	// THE SUPPORT DIALOG COVERS THE CANVAS, AND CI ALWAYS GETS IT.
	//
	// `CnSupportDialog` is a first-open note from the founder. It records its
	// dismissal in `localStorage` under `cn-support-dialog-shown:<appSlug>`, so
	// a human sees it once — but every Playwright context starts with empty
	// storage, so CI sees it EVERY run, centred over the editor.
	//
	// It was never noticed because the sidebar and palette sit outside its
	// backdrop: adding a step kept working, and the canvas assertions that
	// would have caught it were gated behind a `NEW_EDITOR` feature-detect that
	// was false on every installed 2.3.x. The first canvas interaction to
	// actually run met a modal that traps focus — the click timed out and the
	// keypress went to the dialog, so no edge was ever drawn.
	//
	// Suppressed by the flag the dialog itself reads, matched on the PREFIX so
	// this does not silently miss if the app's slug differs from its id. This
	// hides nothing under test: the dialog is not this spec's subject, and a
	// spec whose subject is the canvas must be able to reach the canvas.
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

	try {
		// Collected for the failure message below; the resolver's own warning
		// about an unknown named source is the single fact this spec has been
		// unable to report.
		const consoleLines: string[] = []
		page.on('console', (msg) => {
			const type = msg.type()
			if (type === 'warning' || type === 'error') {
				consoleLines.push(`[${type}] ${msg.text()}`)
			}
		})

		await page.goto(FLOWS_ROUTE, { waitUntil: 'domcontentloaded' })

		// Belt and braces: if the dialog still made it through (a server-backed
		// dismissal mode would not read localStorage), close it rather than
		// letting it swallow every canvas interaction that follows.
		const supportDialog = page.getByRole('dialog').filter({ hasText: 'Support' })
		if (await supportDialog.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await page.keyboard.press('Escape')
			await expect(supportDialog).toBeHidden({ timeout: 10_000 })
		}

		// Reach the editor the way a user does. Direct navigation to
		// `#/flows/new` does not always hydrate the canvas.
		//
		// If the button is absent, say WHY rather than just that it is missing.
		// This assertion has been failing on development (#2957) and the bare
		// "element(s) not found" told us nothing: the label is correct, the
		// package ships the registry, and showAdd defaults true — all verified.
		// What could not be seen from outside a failing run is whether
		// `resolveIndexSource('flows')` returned the source or null, and the
		// resolver announces that on the console. So capture it and put it in
		// the failure.
		const addButton = page.getByRole('button', { name: 'New flow' })
		if (!(await addButton.isVisible().catch(() => false))) {
			const primary = page.locator('[data-testid="cn-cta-primary"]')
			const primaryText = (await primary.count())
				? await primary
						.first()
						.innerText()
						.catch(() => '<unreadable>')
				: '<no cn-cta-primary button on the page>'
			throw new Error(
				'The "New flow" button never rendered on the flows index.\n'
					+ `  primary CTA present: ${await primary.count()} `
					+ `(text: ${primaryText})\n`
					+ '  A named source supplies this label; an unresolved one degrades to\n'
					+ '  an ordinary index whose CTA reads "Add {type}". The text above\n'
					+ '  distinguishes those two cases.\n'
					+ '  console (warn/error):\n'
					+ (consoleLines.length
						? consoleLines.map((l) => `    ${l}`).join('\n')
						: '    <nothing captured>'),
			)
		}
		await clickThemed(addButton)

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

		// Save and Run live on the canvas toolbar (flow-editor consolidation):
		// the actions that concern the graph, on the graph.
		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		const saveButton = toolbar.getByRole('button', {
			name: 'Save',
			exact: true,
		})
		const runButton = toolbar.getByRole('button', {
			name: 'Run',
			exact: true,
		})

		await expect(toolbar).toBeVisible()
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
		const endCard = page.locator('.cn-flow-detail__node', {
			hasText: 'End',
		})
		await expect(endCard, 'the step did not reach the canvas').toBeVisible()

		// Connect the seeded start node to the End step, or the saved flow has
		// a dead end and `FlowRunService::queue()` refuses to run it. A new
		// flow opens with the manual-trigger start node already on the canvas
		// (flow-editor consolidation), and the canvas's KEYBOARD connection
		// path (`c` on the source, `c` on the target) is used because
		// drag-to-connect is exactly the interaction this file already
		// documents as unreliable.
		// DRIVE THE ELEMENT THAT OWNS THE HANDLER, NOT THE CARD INSIDE IT.
		//
		// `onNodeKeydown` is bound to the node wrapper — the element carrying
		// `tabindex="0"`. `.cn-flow-detail__node` is the card CnFlowDetail
		// renders into that wrapper's slot, and it is not focusable. Clicking
		// the card and then pressing a key globally relies on the browser
		// walking up to focus the ancestor, which is exactly the ambiguity that
		// made this fail: the click landed, the keydown went to the body, and
		// no edge appeared.
		//
		// THE WRAPPER'S CLASS MOVED IN nextcloud-vue 2.15.0.
		//
		// Up to 2.11.1 the canvas was a bespoke SVG implementation and the
		// wrapper was `.cn-graph-canvas__node`. 2.15.0 rewrote it on Vue Flow
		// and the wrapper became `CnFlowNode.vue`'s `.cn-flow-node`. Nothing
		// announced it, and the old class survives in the shipped CSS and
		// source maps, so from outside the contract looked untouched — this
		// locator simply matched nothing and the canvas assertions failed while
		// the canvas itself rendered fine.
		//
		// `.cn-flow-node` is the honest selector: it is what 2.15.0 emits, and
		// nextcloud-vue#752 keeps `.cn-graph-canvas__node` on the same element
		// as a compatibility alias, so this holds before and after that lands.
		//
		// `locator.press()` focuses its element and dispatches the key there,
		// so the interaction under test is the one the component implements.
		// Verified at the unit level in @conduction/nextcloud-vue
		// (tests/components/CnFlowKeyboardConnect.spec.js): keydown `c` on each
		// wrapper produces the edge.
		//
		// NB this assertion is NEW IN PRACTICE. It has always been written, but
		// it sat behind a `NEW_EDITOR` feature-detect that read the INSTALLED
		// library for a 2.4+ marker — false on every 2.3.x — so it never ran
		// and reported green by not executing.
		const startNode = page.locator('.cn-flow-node', {
			hasText: 'When someone runs it',
		})
		await expect(startNode, 'the seeded start node is missing').toBeVisible()
		const endNode = page.locator('.cn-flow-node', {
			hasText: 'End',
		})
		await expect(endNode, 'the End step is missing').toBeVisible()

		await startNode.press('c')
		await endNode.press('c')

		// COUNT THE EDGE, DO NOT ASK WHETHER IT IS "VISIBLE".
		//
		// The edge is an SVG <path>. Auto-layout stacks these two steps in one
		// column, so the route between them can be a STRAIGHT VERTICAL LINE — a
		// bounding box of zero width. Playwright treats a zero-area element as
		// not visible, so `toBeVisible()` fails on an edge that is on screen and
		// plainly drawn (the failure screenshot shows the arrow). Presence is
		// the honest assertion here, and it is the one that fails if the
		// connection genuinely never happened.
		//
		// THE EDGE STOPPED BEING OURS IN nextcloud-vue 2.15.0.
		//
		// CnFlowDetail used to hand-draw edges into a `#edge` slot with its own
		// `edgePath()`, classed `.cn-flow-detail__edge`. 2.15.0 hands routing to
		// Vue Flow, whose own comment says it plainly: "Edges are Vue Flow's
		// now. The hand-drawn `#edge` slot and its orthogonal `edgePath()` are
		// gone." So the class is no longer rendered by anything and this
		// locator counted zero — the connection was being made, nothing was
		// counting it.
		//
		// `.vue-flow__edge` is what actually carries an edge now. Same trap as
		// the node wrapper above, and the same tell: the old class still has a
		// live style rule in CnFlowDetail, so grepping the library for it finds
		// it and the contract looks intact.
		await expect(
			page.locator('.vue-flow__edge'),
			'the connection did not reach the canvas',
		).toHaveCount(1)

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
		await page.waitForURL(new RegExp(`/flows/${uuid}$`), {
			timeout: 5_000,
		})

		// 4. PUBLISH. A DRAFT BACKS NO RUN — this is a step in the journey, not
		//    a workaround for one.
		//
		// `flow-definition-versioning` made a flow's graph a versioned document,
		// and a flow is CREATED as a draft: "A run SHALL be queued against the
		// flow's `published` version. A `draft` or `deprecated` version SHALL
		// NOT back a newly queued run." So from that change onward the author's
		// journey is build → save → PUBLISH → run, and a spec that skipped the
		// third step was asserting a contract the app deliberately no longer
		// offers.
		//
		// It surfaced as `POST .../run` answering 500, because
		// `FlowLifecycleRefused` escaped `FlowController::run()` unhandled. That
		// half is a real defect and is fixed separately — the refusal is now a
		// 409 naming `no-published-version`. But 409 is not 201 either: making
		// this spec pass by relaxing step 5 to "409 is fine" would delete the
		// only end-to-end proof that a flow can actually be RUN from the editor.
		//
		// The opposite shortcut — letting `/run` quietly fall back to a draft
		// test run — was considered and rejected: `tests/e2e/flow-engine.spec.ts`
		// asserts in two places that this exact endpoint refuses a draft AND a
		// deprecated flow with `no-published-version`, and silently running a
		// retired process is a worse defect than the one being fixed.
		//
		// So publish, through the editor's own control, the way an author does.
		// Asserted on the BADGE rather than on the click, for the reason the
		// lifecycle specs already give: a button that posts and silently fails
		// looks exactly like one that worked, and the badge only reads
		// "Published" once the store has re-read the flow from the server.
		const publishButton = page.locator('[data-testid="flow-publish"]')
		await expect(
			publishButton,
			'the editor offers no Publish control, so a flow built here can never '
				+ 'be run: a draft backs no run, and publishing is the only thing '
				+ 'that changes that. Needs @conduction/nextcloud-vue >= 2.24.0.',
		).toBeVisible({ timeout: 10_000 })

		await clickThemed(publishButton)

		await expect(
			page.locator('[data-testid="flow-lifecycle"]'),
			'Publish was pressed but the flow never became published — the store '
				+ 'still shows the draft it started as, so the POST was rejected or '
				+ 'swallowed',
		).toHaveText('Published', { timeout: 10_000 })

		// 5. RUN NOW CREATES A RUN. Asserted against the API rather than the
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
