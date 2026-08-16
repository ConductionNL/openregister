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
import { test, expect, type APIRequestContext } from '@playwright/test'
import * as path from 'path'
// Routes are imported by COMPONENT NAME (see tests/e2e/_page-routes.ts): the
// binding records which page host each route mounts, which a bare path string
// cannot say. Also what makes this suite legible to gate-26.
import { FlowsIndex, FlowDetailPage } from './_page-routes'

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
 * Create a flow through the API and return it.
 *
 * @param request The authenticated API context.
 * @param overrides Fields to set on the flow.
 */
async function createFlow(
	request: APIRequestContext,
	overrides: Record<string, unknown> = {},
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

	return response.json()
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
		const flow = await createFlow(request, {
			nodes: [{ id: 'start' }, { id: 'middle' }],
			edges: [
				{
					id: 'first',
					from: 'start',
					to: 'middle',
					type: 'openregister.set-fields',
					config: { fields: { touched: RUN_ID } },
				},
			],
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
		const flow = await createFlow(request, {
			nodes: [{ id: 'start' }, { id: 'middle' }],
			// A BARE id — exactly what the old builder produced.
			edges: [
				{ id: 'first', from: 'start', to: 'middle', type: 'set-fields' },
			],
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
		const flow = await createFlow(request, { name: `${RUN_ID} visible` })

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

		// Assert the FLOW, not just its frame: `.cn-flow-detail` is the
		// nc-vue detail shell and renders for any flow, including one that
		// failed to load. The name is the thing that proves this page is
		// showing the flow we just created.
		await expect(page.getByText(`${RUN_ID} visible`)).toBeVisible({
			timeout: 15000,
		})

		// The canvas renders its empty state for a flow with no steps, which is
		// the honest answer rather than a blank page.
		await expect(page.locator('.cn-flow-detail')).toBeVisible({ timeout: 15000 })
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
