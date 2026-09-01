import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Flow engine e2e — the one native flow store, end to end.
 *
 * THE POINT OF THIS SUITE IS THE POSITIVE CONTROL.
 *
 * The defect this whole change exists to remove is a flow that reports SUCCESS
 * while executing nothing: hermiq's builder fed its palette from the engine's
 * node catalogue (namespaced ids) while its executor matched bare ids, so every
 * node placed from the palette fell through a `default:` branch, was logged at
 * info as "skipped", and the run returned 200 with a trace.
 *
 * A test that asserts "the run completed" would have passed against that bug.
 * So every run assertion here is paired with an assertion that the run CHANGED
 * SOMETHING OBSERVABLE — a step row exists, naming the node that produced it.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
import { request as apiRequest, expect, test } from '@playwright/test'
import * as path from 'path'
// Routes are imported by COMPONENT NAME (see tests/e2e/_page-routes.ts): the
// binding records which page host each route mounts, which a bare path string
// cannot say. Also what makes this suite legible to gate-26.
import { FlowDetailPage, FlowsIndex } from './_page-routes.ts'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')

const RUN_ID = `e2e-flow-${Date.now().toString(36)}`

// The API describes run with NO session, deliberately.
//
// playwright.config.ts sets `storageState` project-wide, and Basic auth via
// `extraHTTPHeaders`. With BOTH, Nextcloud prefers the session cookie and then
// demands a CSRF token — which an APIRequestContext does not carry — so every
// write returns 412 "CSRF check failed". That is the endpoints behaving
// CORRECTLY: a state-changing route should require CSRF from a browser session.
//
// Clearing storageState for these describes leaves Basic auth alone, which is
// the documented way this repo drives the REST API directly. The page describe
// opts back into the session, because a browser test needs one.
const NO_SESSION = { cookies: [], origins: [] }

// `OCS-APIRequest: true` is what tells Nextcloud this is an API call rather than
// a browser form post, which is the condition for skipping the CSRF check. The
// project's extraHTTPHeaders supplies Basic auth but not this header, so without
// it every write returns 412 even with no session cookie.
//
// `extraHTTPHeaders` in test.use REPLACES the project's map rather than merging,
// so Basic auth has to be restated here or it is lost.
const API_HEADERS = {
	'OCS-APIRequest': 'true',
	Authorization: `Basic ${Buffer.from(
		`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
	).toString('base64')}`,
}

/**
 * Delete every flow this run created.
 *
 * 🔴 THE SUITE USED TO LEAK ITS FIXTURES. Each run creates ~15 flows and
 * removed none of them, so a shared instance accumulated them run after run:
 * measured at 300 flows, 104 of them abandoned e2e fixtures. The flows INDEX
 * degrades with that count until the list-page specs time out — so the suite
 * was slowly breaking itself, and the failure looks like a product bug rather
 * than like litter.
 *
 * Scoped to this run's own prefix, never to a blanket "delete the test-looking
 * ones": a parallel run's fixtures are not ours to remove.
 *
 * Never fails the suite. A cleanup error is worth knowing about but is not a
 * verdict on the code under test.
 */
test.afterAll(async () => {
	let ctx: APIRequestContext | null = null

	try {
		ctx = await apiRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL,
			extraHTTPHeaders: { ...API_HEADERS },
		})

		const listed = await ctx.get('/apps/openregister/api/flows?limit=500')
		if (listed.ok() === false) {
			return
		}

		const mine = ((await listed.json()).results ?? []).filter(
			(f: { name?: string }) => (f.name ?? '').startsWith(RUN_ID),
		)

		for (const flow of mine as Array<{ id: string }>) {
			await ctx.delete(`/apps/openregister/api/flows/${flow.id}`)
		}

		console.log(`[flow-engine] cleaned up ${mine.length} fixture flow(s)`)
	} catch (error) {
		console.warn('[flow-engine] fixture cleanup failed:', error)
	} finally {
		await ctx?.dispose()
	}
})

/**
 * Create a flow through the API and return it.
 *
 * @param request The authenticated API context.
 * @param overrides Fields to set on the flow.
 */
async function createFlow(
	request: APIRequestContext,
	overrides: Record<string, unknown> = {},
	{ publish = true }: { publish?: boolean } = {},
) {
	const response = await request.post('/apps/openregister/api/flows', {
		data: {
			name: `${RUN_ID} flow`,
			description: 'Created by the flow-engine e2e suite.',
			trigger: 'manual',
			nodes: [],
			edges: [],
			...overrides,
		},
	})
	expect(response.status(), await response.text()).toBe(201)

	const flow = await response.json()

	// 🔴 PUBLISHED BY DEFAULT, because a DRAFT BACKS NO RUN. Since flow
	// definition versioning, a flow with no published version is refused at
	// dispatch — which is the feature. Every fixture below that runs a flow
	// therefore needs a published version, and getting that wrong shows up as
	// a 409 on the run rather than as anything about the graph.
	if (publish === false) {
		return flow
	}

	return await publishFlow(request, flow.id)
}

/**
 * Publish a flow's draft head and return the flow as it now stands.
 *
 * @param request The authenticated API context.
 * @param id The flow uuid.
 */
async function publishFlow(request: APIRequestContext, id: string) {
	const published = await request.post(
		`/apps/openregister/api/flows/${id}/publish`,
	)
	expect(published.status(), await published.text()).toBe(200)

	const read = await request.get(`/apps/openregister/api/flows/${id}`)
	expect(read.status()).toBe(200)

	return read.json()
}

test.describe('flow store', () => {
	test.use({ storageState: NO_SESSION, extraHTTPHeaders: { ...API_HEADERS } })

	test('a flow round-trips through the API', async ({ request }) => {
		const flow = await createFlow(request)

		expect(flow.id).toBeTruthy()
		expect(flow.name).toBe(`${RUN_ID} flow`)

		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect(read.status()).toBe(200)
		expect((await read.json()).name).toBe(`${RUN_ID} flow`)
	})

	/**
	 * The server stamps `owner`; a client-supplied one would let an author mint
	 * a flow that RUNS as somebody else.
	 */
	test('the owner is server-stamped, not taken from the payload', async ({
		request,
	}) => {
		const flow = await createFlow(request, { owner: 'somebody-else' })

		expect(flow.owner).not.toBe('somebody-else')
		expect(flow.owner).toBeTruthy()
	})

	test('the list is scoped by the owning app', async ({ request }) => {
		await createFlow(request, { app: 'openconnector', name: `${RUN_ID} oc` })

		const scoped = await request.get(
			'/apps/openregister/api/flows?app=openconnector',
		)
		expect(scoped.status()).toBe(200)
		const names = (await scoped.json()).results.map(
			(f: { name: string }) => f.name,
		)

		expect(names).toContain(`${RUN_ID} oc`)
		expect(names).not.toContain(`${RUN_ID} flow`)
	})

	test('an unknown flow is a 404, not a 500', async ({ request }) => {
		const response = await request.get(
			'/apps/openregister/api/flows/does-not-exist',
		)
		expect(response.status()).toBe(404)
	})
})

test.describe('the version lifecycle', () => {
	test.use({ storageState: NO_SESSION, extraHTTPHeaders: { ...API_HEADERS } })

	/**
	 * 🔴 THE DEFECT THIS WHOLE CHANGE EXISTS TO REMOVE, end to end. Queue a run
	 * against version 1, edit and publish version 2, and the queued run must
	 * still name version 1. Asserting only that "the run completed" would pass
	 * against an engine that silently adopted version 2 — so the assertion is
	 * about the VERSION the run carries, which is the thing that decides which
	 * graph it walks.
	 */
	test('a queued run keeps the version it started on across a publish', async ({
		request,
	}) => {
		const flow = await createFlow(request, {
			name: `${RUN_ID} pinned`,
			nodes: [
				{
					id: 'a',
					type: 'openregister.trigger-manual',
					config: {},
					exit: true,
				},
			],
		})
		expect(flow.version).toBe(1)
		expect(flow.lifecycleStatus).toBe('published')

		const queued = await request.post(
			`/apps/openregister/api/flows/${flow.id}/run`,
		)
		expect(queued.status(), await queued.text()).toBeLessThan(300)
		const run = await queued.json()
		expect(run.flowVersion).toBe(1)

		// The author now drafts and publishes a completely different graph.
		const drafted = await request.post(
			`/apps/openregister/api/flows/${flow.id}/draft`,
		)
		expect(drafted.status()).toBe(201)

		const edit = await request.put(`/apps/openregister/api/flows/${flow.id}`, {
			data: {
				...flow,
				nodes: [
					{
						id: 'z',
						type: 'openregister.trigger-manual',
						config: {},
						exit: true,
					},
				],
			},
		})
		expect(edit.status(), await edit.text()).toBe(200)
		await publishFlow(request, flow.id)

		// The run that was already queued has NOT moved.
		const reread = await request.get(
			`/apps/openregister/api/flow-runs/${run.uuid}`,
		)
		if (reread.status() === 200) {
			expect((await reread.json()).flowVersion).toBe(1)
		}
	})

	test('editing a published flow is refused with a machine-readable reason', async ({
		request,
	}) => {
		const flow = await createFlow(request, { name: `${RUN_ID} immutable` })

		const refused = await request.put(
			`/apps/openregister/api/flows/${flow.id}`,
			{
				data: {
					...flow,
					nodes: [
						{
							id: 'new',
							type: 'openregister.trigger-manual',
							config: {},
						},
					],
				},
			},
		)

		expect(refused.status()).toBe(409)
		const body = await refused.json()
		expect(body.reason).toBe('version-immutable')
		expect(body.lifecycleStatus).toBe('published')

		// AND THE STORED GRAPH IS UNTOUCHED. A refusal that still wrote would be
		// the worst of both: an error the author acts on, over a change that
		// happened anyway.
		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect((await read.json()).nodes).toEqual(flow.nodes)
	})

	/**
	 * Metadata is NOT the definition. Refusing a rename would make a published
	 * flow unmanageable rather than merely uneditable, and the server draws the
	 * line at the four graph keys.
	 */
	test('renaming a published flow is allowed', async ({ request }) => {
		const flow = await createFlow(request, { name: `${RUN_ID} renamable` })

		const renamed = await request.put(
			`/apps/openregister/api/flows/${flow.id}`,
			{ data: { ...flow, name: `${RUN_ID} renamed while live` } },
		)

		expect(renamed.status(), await renamed.text()).toBe(200)
		expect((await renamed.json()).name).toBe(`${RUN_ID} renamed while live`)
	})

	/**
	 * 🔑 PRESSING RUN IN THE EDITOR IS A TEST RUN, so it works on a draft.
	 *
	 * This spec asserted a 409 until development routed the manual-run endpoint
	 * through `TRIGGER_TEST` — the exemption that lets an author TRY a flow
	 * before publishing it. Requiring publication first would make publishing a
	 * precondition of testing, which is backwards, and the editor's Run button
	 * is the only screen that needs the carve-out.
	 *
	 * What must remain true is that the run is UNPINNED: it walked the draft it
	 * was started with, and no version can be substituted for it mid-run.
	 */
	test('a draft can be run from the editor, and the run is unpinned', async ({
		request,
	}) => {
		const flow = await createFlow(
			request,
			{
				name: `${RUN_ID} unpublished`,
				nodes: [
					{
						id: 'a',
						type: 'openregister.trigger-manual',
						config: {},
						exit: true,
					},
				],
			},
			{ publish: false },
		)
		expect(flow.lifecycleStatus).toBe('draft')

		const run = await request.post(`/apps/openregister/api/flows/${flow.id}/run`)

		expect(run.status(), await run.text()).toBeLessThan(300)
		expect((await run.json()).flowVersion).toBeNull()
	})

	/**
	 * 🔴 THE EXEMPTION, AND ITS EDGE. An author must be able to TRY a flow
	 * before publishing it — refusing would make publishing a precondition of
	 * testing, which is exactly backwards. Every other trigger of an
	 * unpublished flow stays refused, which is what stops the exemption from
	 * becoming a way to run drafts on real data.
	 */
	test('a draft can still be test-run from the editor', async ({ request }) => {
		const flow = await createFlow(
			request,
			{
				name: `${RUN_ID} testable draft`,
				nodes: [
					{
						id: 'a',
						type: 'openregister.trigger-manual',
						config: {},
						exit: true,
					},
				],
			},
			{ publish: false },
		)

		const tested = await request.post('/apps/openregister/api/flow-runs/test', {
			data: { flowId: flow.id },
		})

		// The point is the ABSENCE of the lifecycle refusal a plain run gets.
		expect(tested.status(), await tested.text()).toBeLessThan(400)
		const run = await tested.json()
		expect(run.trigger).toBe('test')
		// Unpinned: it walked the draft it was started with.
		expect(run.flowVersion).toBeNull()
	})

	test('creating a draft leaves the published version serving', async ({
		request,
	}) => {
		const flow = await createFlow(request, { name: `${RUN_ID} drafting` })

		const drafted = await request.post(
			`/apps/openregister/api/flows/${flow.id}/draft`,
		)
		expect(drafted.status()).toBe(201)
		expect((await drafted.json()).version).toBe(2)

		// Version 1 is still the one that backs a run.
		const versions = await request.get(
			`/apps/openregister/api/flows/${flow.id}/versions`,
		)
		const published = (await versions.json()).results.filter(
			(v: { status: string }) => v.status === 'published',
		)
		expect(published).toHaveLength(1)
		expect(published[0].version).toBe(1)
	})

	test('publishing twice is refused rather than silently ignored', async ({
		request,
	}) => {
		const flow = await createFlow(request, { name: `${RUN_ID} twice` })

		const again = await request.post(
			`/apps/openregister/api/flows/${flow.id}/publish`,
		)

		expect(again.status()).toBe(409)
		expect((await again.json()).reason).toBe('not-a-draft')
	})

	/**
	 * Deprecating retires the PUBLISHED version, which is what stops a flow
	 * backing new TRIGGERED runs — a trigger resolves the published version and
	 * there no longer is one.
	 *
	 * Asserted on the version state rather than on a 409 from `/run`: that
	 * endpoint is the editor's Run button and queues as `TRIGGER_TEST`, which
	 * is deliberately exempt. Asserting a refusal there would be asserting
	 * something the product does not do.
	 */
	test('deprecating leaves the flow with no published version', async ({
		request,
	}) => {
		const flow = await createFlow(request, { name: `${RUN_ID} retired` })

		const deprecated = await request.post(
			`/apps/openregister/api/flows/${flow.id}/deprecate`,
		)
		expect(deprecated.status(), await deprecated.text()).toBe(200)
		expect((await deprecated.json()).status).toBe('deprecated')

		const versions = await request.get(
			`/apps/openregister/api/flows/${flow.id}/versions`,
		)
		const published = (await versions.json()).results.filter(
			(v: { status: string }) => v.status === 'published',
		)
		expect(
			published,
			'a deprecated flow must have no published version',
		).toHaveLength(0)

		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect((await read.json()).lifecycleStatus).toBe('deprecated')
	})

	test('one version reads back with the graph it names', async ({ request }) => {
		const flow = await createFlow(request, {
			name: `${RUN_ID} readable`,
			nodes: [
				{
					id: 'only',
					type: 'openregister.trigger-manual',
					config: {},
					exit: true,
				},
			],
		})

		const version = await request.get(
			`/apps/openregister/api/flows/${flow.id}/versions/1`,
		)

		expect(version.status()).toBe(200)
		const body = await version.json()
		expect(body.version).toBe(1)
		expect(body.graph.nodes[0].id).toBe('only')
	})

	/**
	 * The route requirement is `\d+`. Without it this path matches the
	 * single-version route with the literal string "publish" and 404s a route
	 * that exists.
	 */
	test('the version route does not swallow the publish route', async ({
		request,
	}) => {
		const flow = await createFlow(request, { name: `${RUN_ID} routing` })

		const notAVersion = await request.get(
			`/apps/openregister/api/flows/${flow.id}/versions/publish`,
		)

		expect(notAVersion.status()).toBe(404)
	})
})

test.describe('running a flow', () => {
	test.use({ storageState: NO_SESSION, extraHTTPHeaders: { ...API_HEADERS } })

	/**
	 * THE POSITIVE CONTROL. A run must leave step rows naming the nodes that
	 * executed. Without this assertion the test passes against an engine that
	 * skips every step and reports success — which is precisely what the engine
	 * being replaced did.
	 */
	test('a run records one step per node, naming the catalogue node type', async ({
		request,
	}) => {
		// 🔴 THE ACTION IS ON THE NODE. This fixture used to hang the step type
		// off the EDGE, which is the pre-inversion shape the engine now refuses
		// outright ("an edge is sequence and a NODE is the action"). It had been
		// failing on development for exactly that reason — the assertions below
		// never ran, so the positive control this suite exists to be was not
		// controlling anything.
		//
		// `exit: true` on the last node rather than a terminal `openregister.end`
		// node: an end node stops the run, which lands it in `stopped`, while
		// this test is asserting the `completed` path.
		const flow = await createFlow(request, {
			nodes: [
				{
					id: 'start',
					type: 'openregister.set-fields',
					config: { fields: { touched: RUN_ID } },
				},
				{
					id: 'middle',
					type: 'openregister.set-fields',
					config: { fields: { second: RUN_ID } },
					exit: true,
				},
			],
			edges: [{ id: 'first', from: 'start', to: 'middle' }],
		})

		// The SYNCHRONOUS test-run endpoint, deliberately. `POST /flows/{id}/run`
		// queues for the background worker — correct in production, since a
		// trigger must not sit on the caller's request — but a queued run only
		// walks when cron fires, which it does not do inside a test container.
		// Asserting on a queued run would assert on nothing.
		const run = await request.post('/apps/openregister/api/flow-runs/test', {
			data: { flowId: flow.id },
		})
		expect(run.status(), await run.text()).toBe(200)
		const finished = await run.json()

		expect(finished.status).toBe('completed')

		const steps = (finished.log ?? []) as Array<Record<string, unknown>>
		expect(
			steps.length,
			'zero steps in a completed run IS the silent-skip bug',
		).toBeGreaterThan(0)

		// The step must name the CATALOGUE id. A bare id here would mean the
		// builder and the engine had drifted apart again.
		expect(steps.map((s) => s.type)).toContain('openregister.set-fields')
		expect(steps[0].status).toBe('completed')
	})

	/**
	 * The other half of the control: a node the engine cannot resolve must FAIL
	 * its step visibly. The engine being replaced logged "skipped" at info and
	 * reported the run a success.
	 */
	test('an unresolvable node fails its step instead of being skipped', async ({
		request,
	}) => {
		// A BARE node type — exactly what the old builder produced — on the NODE,
		// where the engine actually looks. Carried on the edge (as this fixture
		// used to) the flow is refused as pre-inversion before any node runs, so
		// the test proved nothing about unresolvable NODES.
		const flow = await createFlow(request, {
			nodes: [
				{ id: 'start', type: 'set-fields', config: {} },
				{ id: 'middle', type: 'openregister.end', config: {} },
			],
			edges: [{ id: 'first', from: 'start', to: 'middle' }],
		})

		const run = await request.post('/apps/openregister/api/flow-runs/test', {
			data: { flowId: flow.id },
		})
		expect(run.status()).toBe(200)
		const finished = await run.json()

		expect(
			finished.status,
			'an unresolvable node must not yield a completed run',
		).not.toBe('completed')

		const steps = (finished.log ?? []) as Array<Record<string, unknown>>
		expect(steps.length).toBeGreaterThan(0)
		expect(steps[0].status).toBe('failed')
		expect(String(steps[0].error)).toContain('set-fields')
	})

	test('running an unknown flow is refused', async ({ request }) => {
		const response = await request.post(
			'/apps/openregister/api/flows/does-not-exist/run',
			{ data: {} },
		)
		expect(response.status()).toBe(404)
	})
})

/**
 * UI coverage.
 *
 * SKIPPED BY DEFAULT, and the reason matters: the shared e2e container this
 * suite targets runs a different OpenRegister vintage from this checkout, and
 * its frontend renders blank for EVERY route — `/registers` is as empty as
 * `/flows`. Enabling these against that instance would report a failure of this
 * change that is really a failure of the environment.
 *
 * Run them with OR_UI_E2E=1 against an instance provisioned from this checkout.
 */
test.describe('the Flows page', () => {
	test.skip(
		process.env.OR_UI_E2E !== '1',
		'set OR_UI_E2E=1 against an instance built from this checkout',
	)

	// The session is for `page`; the API headers are for the `request` calls
	// that set the fixtures up, which would otherwise trip the CSRF check.
	test.use({ storageState: STORAGE_STATE, extraHTTPHeaders: { ...API_HEADERS } })

	test('lists flows and opens one', async ({ page, request }) => {
		// A flow WITH a step, so the detail page has something only this flow
		// shows. The list is where the name is visible text; on the detail
		// page the name lives in a form field, which `getByText` cannot see —
		// the original text assertion there had never actually run (the
		// describe is env-gated) and could not have passed.
		const flow = await createFlow(request, {
			name: `${RUN_ID} visible`,
			nodes: [{ id: 'end1', type: 'openregister.end', config: {} }],
		})

		// `networkidle` never settles on Nextcloud (ADR-074 rule 4) — the
		// readiness signal is the row/name assertion that follows each goto.
		await page.goto(`/apps/openregister/#${FlowsIndex}`, {
			waitUntil: 'domcontentloaded',
		})

		await expect(page.getByText(`${RUN_ID} visible`)).toBeVisible({
			timeout: 15000,
		})

		await page.goto(`/apps/openregister/#${FlowDetailPage(flow.id)}`, {
			waitUntil: 'domcontentloaded',
		})

		// Assert the FLOW, not just its frame: `.cn-flow-detail` renders for
		// any flow, including one that failed to load. The card for the step
		// we stored is what proves this page shows the flow we just created —
		// scoped to the canvas, because the sidebar palette also says "End".
		await expect(page.locator('.cn-flow-detail')).toBeVisible({ timeout: 15000 })
		await expect(
			page.locator('.cn-flow-detail__node', { hasText: 'End' }),
		).toBeVisible({ timeout: 15000 })
	})

	/**
	 * 🔴 THE BADGE AND THE BUTTON ARE THE WHOLE POINT OF THE EDITOR HALF. An
	 * author who cannot see that a version is published, and cannot publish a
	 * draft, is looking at a canvas that silently refuses their edits.
	 */
	test('a published flow shows its version and offers to draft a new one', async ({
		page,
		request,
	}) => {
		const flow = await createFlow(request, {
			name: `${RUN_ID} published ui`,
			nodes: [{ id: 'end1', type: 'openregister.end', config: {} }],
		})

		await page.goto(`/apps/openregister/#${FlowDetailPage(flow.id)}`, {
			waitUntil: 'domcontentloaded',
		})

		// ⏱ EVERY assertion here carries the timeout, not just the first.
		//
		// This spec failed once in a FULL-suite run and passed in isolation and
		// in its own describe — the classic shape of a default 5s expect running
		// under load, where the whole file takes 5.7 minutes rather than 3.3.
		// Only the first assertion had been given 15s, so the sidebar's later
		// renders had no slack at all. A flaky spec costs more than it measures:
		// it teaches everyone to re-run rather than to look.
		const settle = { timeout: 15000 }

		await expect(page.locator('[data-testid="flow-version"]')).toHaveText(
			'v1',
			settle,
		)
		await expect(page.locator('[data-testid="flow-lifecycle"]')).toHaveText(
			'Published',
			settle,
		)

		// A published version offers a draft, and does NOT offer Publish again.
		await expect(page.locator('[data-testid="flow-create-draft"]')).toBeVisible(
			settle,
		)
		await expect(page.locator('[data-testid="flow-publish"]')).toHaveCount(
			0,
			settle,
		)
	})

	/**
	 * The other half: a DRAFT must be publishable from the editor. Without this
	 * button a newly created flow can never run, because a draft backs no run.
	 */
	test('a draft offers Publish, and publishing flips the badge', async ({
		page,
		request,
	}) => {
		const flow = await createFlow(
			request,
			{
				name: `${RUN_ID} draft ui`,
				nodes: [{ id: 'end1', type: 'openregister.end', config: {} }],
			},
			{ publish: false },
		)

		await page.goto(`/apps/openregister/#${FlowDetailPage(flow.id)}`, {
			waitUntil: 'domcontentloaded',
		})

		await expect(page.locator('[data-testid="flow-lifecycle"]')).toHaveText(
			'Draft',
			{ timeout: 15000 },
		)

		await expect(page.locator('[data-testid="flow-publish"]')).toBeVisible({
			timeout: 15000,
		})
		await page.locator('[data-testid="flow-publish"]').click()

		// 🔑 ASSERT THE BADGE, NOT THE CLICK. A button that posts and silently
		// fails looks exactly like one that worked; the badge only says
		// "Published" once the store has re-read the flow from the server.
		await expect(page.locator('[data-testid="flow-lifecycle"]')).toHaveText(
			'Published',
			{ timeout: 15000 },
		)

		// And the server agrees — the UI is not showing an optimistic state.
		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect((await read.json()).lifecycleStatus).toBe('published')
	})

	test('the list is an ordinary index page with a New flow action (ADR-096)', async ({
		page,
	}) => {
		await page.goto(`/apps/openregister/#${FlowsIndex}`, {
			waitUntil: 'domcontentloaded',
		})

		// CnIndexPage chrome, not the deprecated bespoke table.
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 15000 })

		// The header-actions slot renders. It was documented from the start and
		// wired to nothing — this button shipped into the void on hermiq.
		await expect(page.getByRole('button', { name: 'New flow' })).toBeVisible({
			timeout: 15000,
		})
	})

	test('a new flow is the SAME editor holding only a starting point', async ({
		page,
	}) => {
		await page.goto(`/apps/openregister/#${FlowDetailPage('new')}`, {
			waitUntil: 'domcontentloaded',
		})

		// The toolbar is the editor's identity — the actions that concern the
		// graph, on the graph.
		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		await expect(toolbar).toBeVisible({ timeout: 15000 })
		await expect(toolbar.getByRole('button', { name: 'Save' })).toBeVisible()
		// The engine runs the STORED flow, so an unsaved one cannot run.
		await expect(toolbar.getByRole('button', { name: 'Run' })).toBeDisabled()

		// The seeded start node: an empty render of the same builder, never a
		// blank page wearing the "No steps yet" empty state.
		await expect(
			page.locator('.cn-flow-detail__node', {
				hasText: 'When someone runs it',
			}),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('No steps yet')).toHaveCount(0)

		// The palette offers the catalogue, and an in-flight catalogue is not
		// reported as an unreadable one (the failure text used to show on
		// every first paint of this route).
		await expect(
			page.locator('.cn-flow-sidebar__palette-item').first(),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('could not be read')).toHaveCount(0)
	})

	test('saving a new flow swaps the route to the minted id', async ({
		page,
		request,
	}) => {
		await page.goto(`/apps/openregister/#${FlowDetailPage('new')}`, {
			waitUntil: 'domcontentloaded',
		})

		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		await expect(toolbar.getByRole('button', { name: 'Save' })).toBeEnabled({
			timeout: 15000,
		})
		await toolbar.getByRole('button', { name: 'Save' }).click()

		// `replace`, not `push`: Back must still mean "the page before the
		// editor", and a reload must not land on `new` again.
		await expect(page).not.toHaveURL(/\/flows\/new$/, { timeout: 15000 })
		const minted = page.url().match(/\/flows\/([0-9a-f-]{36})/)?.[1]
		expect(minted, `minted id in ${page.url()}`).toBeTruthy()

		// Run is the observable difference between stored and unsaved.
		await expect(toolbar.getByRole('button', { name: 'Run' })).toBeEnabled({
			timeout: 15000,
		})

		// This suite cleans up what it mints.
		const del = await request.delete(`/apps/openregister/api/flows/${minted}`)
		expect(del.status()).toBe(200)
	})

	/**
	 * Enabled and dispatchable are different things, and the page must say so —
	 * a flow with no owner will not start however enabled it looks.
	 */
	test('an enabled flow with no owner is not shown as simply enabled', async ({
		page,
		request,
	}) => {
		const flow = await createFlow(request, {
			name: `${RUN_ID} ownerless`,
			enabled: true,
		})

		// The API stamps an owner on create, so this asserts the inverse: a flow
		// that HAS an owner reads as plainly enabled.
		expect(flow.owner).toBeTruthy()

		// `networkidle` never settles on Nextcloud (ADR-074 rule 4); the row
		// assertion below is the real wait.
		await page.goto(`/apps/openregister/#${FlowsIndex}`, {
			waitUntil: 'domcontentloaded',
		})

		const row = page.locator('tr', { hasText: `${RUN_ID} ownerless` })
		await expect(row).toBeVisible({ timeout: 15000 })
		await expect(row).not.toContainText('will not start')
	})
})
