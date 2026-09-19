/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What kind of step each node is, and where an author finds it, over the live
 * catalogue.
 *
 * The unit tests prove the defaulting and the closed vocabularies against
 * doubles. What only a live instance can prove is the part that matters in
 * practice: that the nodes THIS app ships have actually declared, and that the
 * 38 contributed by other apps still appear rather than being dropped or
 * guessed at.
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */
import { expect, test } from '@playwright/test'

const CATALOG = '/index.php/apps/openregister/api/flow/node-catalog'

/** BPMN's element types. The list is closed and is not ours to extend. */
const KINDS = [
	'userTask',
	'serviceTask',
	'scriptTask',
	'businessRuleTask',
	'sendTask',
	'receiveTask',
	'manualTask',
	'gateway',
	'event',
	'subProcess',
]

/** The palette's groups, in the order a palette presents them. */
const CATEGORIES = [
	'triggers',
	'human',
	'objects',
	'logic',
	'messaging',
	'ai',
	'integrations',
	'other',
]

/**
 * Every catalogue entry.
 *
 * @param request The Playwright request context.
 */
async function catalog(request) {
	const response = await request.get(CATALOG, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(response.status(), await response.text()).toBe(200)

	const body = await response.json()
	const rows = Array.isArray(body) ? body : body.results || body.nodes || []
	expect(rows.length, 'the catalogue must not be empty').toBeGreaterThan(0)

	return rows
}

test.describe('flow-node-taxonomy: what each step is, and where to find it', () => {
	// @e2e flow-node-taxonomy::every-entry-carries-both
	test('every entry carries a kind and a category, both from the closed lists', async ({
		request,
	}) => {
		const rows = await catalog(request)

		for (const row of rows) {
			expect(KINDS, `${row.id} declared an unknown kind`).toContain(row.kind)
			expect(CATEGORIES, `${row.id} declared an unknown category`).toContain(
				row.category,
			)
		}
	})

	// @e2e flow-node-taxonomy::this-repo-declares
	test("every node this app ships has declared, so none sits in 'other'", async ({
		request,
	}) => {
		const rows = await catalog(request)
		const ours = rows.filter((row) => String(row.id).startsWith('openregister.'))

		expect(ours.length).toBeGreaterThan(20)
		// 🔴 THE GATE'S CLAIM, ASSERTED AGAINST THE RUNNING ENGINE. A node
		// declaring nothing is served as `other`, so an undeclared node this
		// repository owns shows up here rather than in a review nobody does.
		expect(
			ours.filter((row) => row.category === 'other').map((row) => row.id),
		).toEqual([])
	})

	// @e2e flow-node-taxonomy::contributed-nodes-survive
	test('a node contributed by another app still appears, under the defaults', async ({
		request,
	}) => {
		const rows = await catalog(request)
		const contributed = rows.filter(
			(row) => !String(row.id).startsWith('openregister.'),
		)

		// This is the load-bearing case: 43 of 64 step types on a measured
		// instance come from repositories this change cannot touch. If they
		// were refused registration or hidden, the palette would lose most of
		// itself the day this shipped.
		expect(contributed.length).toBeGreaterThan(0)
		for (const row of contributed) {
			expect(row.kind).toBeTruthy()
			expect(row.category).toBeTruthy()
		}
	})

	// @e2e flow-node-taxonomy::the-two-axes-are-independent
	test('the kind and the category are independent of one another', async ({
		request,
	}) => {
		const rows = await catalog(request)
		const byId = Object.fromEntries(rows.map((row) => [row.id, row]))

		// One kind, one category: both send steps are found in the same place.
		expect(byId['openregister.send-email'].kind).toBe('sendTask')
		expect(byId['openregister.send-notification'].kind).toBe('sendTask')
		expect(byId['openregister.send-email'].category).toBe('messaging')
		expect(byId['openregister.send-notification'].category).toBe('messaging')

		// One category, two kinds. `await-signal` waits for a system to call
		// back, so by kind it belongs with the integration steps — but it is
		// the machine half of a pair whose other half is "Ask a person", and an
		// author choosing between them looks in ONE place.
		expect(byId['openregister.user-task'].kind).toBe('userTask')
		expect(byId['openregister.await-signal'].kind).toBe('receiveTask')
		expect(byId['openregister.user-task'].category).toBe('human')
		expect(byId['openregister.await-signal'].category).toBe('human')
	})

	// @e2e flow-node-taxonomy::a-branch-is-a-gateway
	test('a branch is a gateway and a trigger is an event, whatever they are about', async ({
		request,
	}) => {
		const rows = await catalog(request)
		const byId = Object.fromEntries(rows.map((row) => [row.id, row]))

		// The kind describes what the step IS, not what it is about.
		expect(byId['openregister.switch'].kind).toBe('gateway')
		expect(byId['openregister.merge'].kind).toBe('gateway')
		expect(byId['openregister.trigger-manual'].kind).toBe('event')
		expect(byId['openregister.trigger-schedule'].kind).toBe('event')
		expect(byId['openregister.decision-table'].kind).toBe('businessRuleTask')
		expect(byId['openregister.sub-flow'].kind).toBe('subProcess')

		// Reading the register is a serviceTask. That it is OUR register rather
		// than a payment provider is the category's business.
		expect(byId['openregister.object-read'].kind).toBe('serviceTask')
		expect(byId['openregister.object-read'].category).toBe('objects')
	})
})
