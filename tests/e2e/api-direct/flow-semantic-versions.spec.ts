/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Semantic versions, end to end over the live HTTP API.
 *
 * The unit tests prove the comparison and the arithmetic in isolation. What
 * only a live instance can prove is that the two are WIRED: that publishing
 * actually derives a version, stores it on both rows, and that the ordinal it
 * sits beside is untouched.
 *
 * That last one is the point of the whole design. `version` is what a RUN
 * PINS, for its whole life including a suspension of weeks, and a derivation
 * that quietly moved it would not mislabel a version — it would repoint a run.
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' }

/** A three-step flow: a way in, a step, and a way out. */
const GRAPH = {
	nodes: [
		{ id: 'start', type: 'openregister.trigger-manual', position: { x: 80, y: 200 }, config: {} },
		{ id: 'middle', type: 'openregister.set-fields', position: { x: 360, y: 200 }, config: { set: { a: 1 }, note: 'keep' } },
		{ id: 'done', type: 'openregister.end', position: { x: 640, y: 200 }, config: {} },
	],
	edges: [
		{ id: 'e1', from: 'start', to: 'middle' },
		{ id: 'e2', from: 'middle', to: 'done' },
	],
}

/**
 * Create a flow and publish it, so there is a previous version to diff against.
 */
async function publishedFlow(request: APIRequestContext, name: string) {
	const created = await request.post(`${API}/flows`, {
		headers: JSON_HEADERS,
		data: { name, description: 'semver e2e', trigger: 'manual', ...GRAPH },
	})
	expect(created.status(), await created.text()).toBe(201)
	const flow = await created.json()

	const first = await request.post(`${API}/flows/${flow.uuid}/publish`, { headers: JSON_HEADERS })
	expect(first.status(), await first.text()).toBe(200)

	return { uuid: flow.uuid as string, first: await first.json() }
}

/** Open a draft and replace its graph, then publish with an optional bump. */
async function reviseAndPublish(
	request: APIRequestContext,
	uuid: string,
	graph: object,
	bump: string | null = null,
) {
	// 201, not 200: opening a draft CREATES a version row. Asserting 200 here
	// cost five red tests that had nothing to do with the versions they were
	// checking.
	const draft = await request.post(`${API}/flows/${uuid}/draft`, { headers: JSON_HEADERS })
	expect(draft.status(), await draft.text()).toBe(201)

	const saved = await request.put(`${API}/flows/${uuid}`, { headers: JSON_HEADERS, data: graph })
	expect(saved.status(), await saved.text()).toBe(200)

	return request.post(`${API}/flows/${uuid}/publish`, {
		headers: JSON_HEADERS,
		data: (bump === null) ? {} : { bump },
	})
}

test.describe('flow-semantic-versions: what a publish is called', () => {
	const created: string[] = []

	test.afterAll(async ({ request }) => {
		for (const uuid of created) {
			await request.delete(`${API}/flows/${uuid}`).catch(() => {})
		}
	})

	// @e2e flow-semantic-versions::the-first-publish-is-one-zero-zero
	test('the first publish is 1.0.0, and the ordinal is untouched', async ({ request }) => {
		const { uuid, first } = await publishedFlow(request, `semver first ${Date.now()}`)
		created.push(uuid)

		expect(first.semver).toBe('1.0.0')
		expect(first.semverSource).toBe('derived')
		// THE POINT OF THE DESIGN: the ordinal is what a run pins, and it is
		// still an integer that counts.
		expect(first.version).toBe(1)
	})

	// @e2e flow-semantic-versions::adding-is-minor
	test('adding a step is a MINOR bump', async ({ request }) => {
		const { uuid } = await publishedFlow(request, `semver minor ${Date.now()}`)
		created.push(uuid)

		// ⚠️ THE ADDED STEP MUST STILL REACH AN END. A dangling node is refused
		// by the dead-end guard before the version is ever derived, which is
		// the guard working — but it made this test fail for a reason that had
		// nothing to do with versions.
		const grown = JSON.parse(JSON.stringify(GRAPH))
		grown.nodes.push({ id: 'extra', type: 'openregister.filter', position: { x: 360, y: 360 }, config: { when: 'x' } })
		grown.edges.push({ id: 'e3', from: 'middle', to: 'extra' })
		grown.edges.push({ id: 'e4', from: 'extra', to: 'done' })

		const response = await reviseAndPublish(request, uuid, grown)
		expect(response.status(), await response.text()).toBe(200)

		const published = await response.json()
		expect(published.semver).toBe('1.1.0')
		expect(published.version).toBe(2)
	})

	// @e2e flow-semantic-versions::removing-a-step-is-major
	test('removing a step is a MAJOR bump', async ({ request }) => {
		const { uuid } = await publishedFlow(request, `semver major ${Date.now()}`)
		created.push(uuid)

		// Drop `middle` and reconnect around it, so the flow still ends.
		const shrunk = {
			nodes: GRAPH.nodes.filter((n) => n.id !== 'middle'),
			edges: [{ id: 'e1', from: 'start', to: 'done' }],
		}

		const response = await reviseAndPublish(request, uuid, shrunk)
		expect(response.status(), await response.text()).toBe(200)

		const published = await response.json()
		expect(published.semver).toBe('2.0.0')
	})

	// @e2e flow-semantic-versions::a-removed-config-key-is-major
	test('removing a config key from a surviving step is MAJOR', async ({ request }) => {
		const { uuid } = await publishedFlow(request, `semver key ${Date.now()}`)
		created.push(uuid)

		const stripped = JSON.parse(JSON.stringify(GRAPH))
		delete stripped.nodes[1].config.note

		const response = await reviseAndPublish(request, uuid, stripped)
		expect(response.status(), await response.text()).toBe(200)

		expect((await response.json()).semver).toBe('2.0.0')
	})

	// @e2e flow-semantic-versions::an-author-may-raise
	test('an author may RAISE a minor to a major', async ({ request }) => {
		const { uuid } = await publishedFlow(request, `semver raise ${Date.now()}`)
		created.push(uuid)

		// Only a VALUE changes, which the diff cannot see as breaking.
		const retitled = JSON.parse(JSON.stringify(GRAPH))
		retitled.nodes[1].config.note = 'changed'

		const response = await reviseAndPublish(request, uuid, retitled, 'major')
		expect(response.status(), await response.text()).toBe(200)

		expect((await response.json()).semver).toBe('2.0.0')
	})

	// @e2e flow-semantic-versions::an-author-may-not-lower
	test('an author may NOT publish a removal as minor, and is told what went', async ({ request }) => {
		const { uuid } = await publishedFlow(request, `semver lower ${Date.now()}`)
		created.push(uuid)

		const shrunk = {
			nodes: GRAPH.nodes.filter((n) => n.id !== 'middle'),
			edges: [{ id: 'e1', from: 'start', to: 'done' }],
		}

		const response = await reviseAndPublish(request, uuid, shrunk, 'minor')
		expect(response.status()).toBe(409)

		const body = await response.json()
		expect(body.kind).toBe('version-bump-refused')
		// The refusal NAMES what was removed. A bare "cannot be minor" is the
		// version of this that teaches nobody anything.
		expect(body.error).toContain('middle')
	})
})
