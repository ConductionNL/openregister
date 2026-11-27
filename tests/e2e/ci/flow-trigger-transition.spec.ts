/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FLOW ON `object.transitioned` WITH `to: ["closed"]` RUNS ON A CLOSE, NOT ON A REOPEN.
 *
 * Change `flow-trigger-transitions-and-changed-fields`, task 3.1. A case schema
 * declares a lifecycle on `status` (open → closed through `sluiten`, closed →
 * open through `heropenen`). One published flow starts on
 * `object.transitioned` filtered to `to: ["closed"]`; a control flow starts on
 * every transition of the same schema. A case is closed and reopened through
 * `POST /api/objects/{id}/transition`: the filtered flow ran once, the control
 * twice. Without the control, "one run" could also mean the reopen never fired
 * the event at all.
 *
 * Both flows run `sync`, so each run happens inside the transition call and
 * the spec needs no background worker.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, flows and
 * case, and removes them in afterAll.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'

const LIFECYCLE = {
	field: 'status',
	initial: 'open',
	transitions: {
		sluiten: { from: ['open'], to: 'closed' },
		heropenen: { from: ['closed'], to: 'open' },
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('flow-trigger-transitions-and-changed-fields: a transition filter', () => {
	let admin: APIRequestContext
	let registerId = 0
	let schemaId = 0
	let onClose = ''
	let onAny = ''
	let caseId = ''

	/** Create and publish a flow that starts on this schema's transitions. */
	async function publishFlow(name: string, transition?: Record<string, string[]>): Promise<string> {
		const config: Record<string, unknown> = {
			event: 'object.transitioned',
			register: String(registerId),
			schema: String(schemaId),
		}
		if (transition !== undefined) {
			config.transition = transition
		}
		const created = await admin.post(`${API}/flows`, {
			data: {
				name: `${name} ${RUN}`,
				description: 'Created by flow-trigger-transition.spec.ts',
				enabled: true,
				executionMode: 'sync',
				nodes: [
					{ id: 'moved', type: 'openregister.trigger-object', config },
					{ id: 'end', type: 'openregister.end', config: {} },
				],
				edges: [{ id: 'a', from: 'moved', to: 'end' }],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		const id = String((await created.json()).id)

		const published = await admin.post(`${API}/flows/${id}/publish`, { data: {} })
		expect(published.status(), await published.text()).toBe(200)
		return id
	}

	/** How many runs one flow has about the case. */
	async function runsOf(flowId: string): Promise<number> {
		const res = await admin.get(`${API}/flow-runs?limit=200`)
		expect(res.status(), await res.text()).toBe(200)
		return ((await res.json()).results as Array<Record<string, any>>)
			.filter((run) => run.flowId === flowId && run.subjectUuid === caseId)
			.length
	}

	/** Move the case through one lifecycle action. */
	async function transition(action: string): Promise<void> {
		const res = await admin.post(`${API}/objects/${caseId}/transition`, { data: { action } })
		expect(res.status(), `${action}: ${await res.text()}`).toBe(200)
	}

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const register = await admin.post(`${API}/registers`, { data: { slug: `e2e-transition-${RUN}`, title: `E2E transition ${RUN}` } })
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				slug: `e2e-case-${RUN}`,
				title: `E2E case ${RUN}`,
				properties: { title: { type: 'string' }, status: { type: 'string' } },
				'x-openregister-lifecycle': LIFECYCLE,
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { slug: `e2e-transition-${RUN}`, title: `E2E transition ${RUN}`, schemas: [schemaId] },
		})
		expect(linked.status(), await linked.text()).toBe(200)

		onClose = await publishFlow('e2e on close', { to: ['closed'] })
		onAny = await publishFlow('e2e on any transition')

		const created = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title: `Case ${RUN}` } })
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		caseId = String((await created.json())['@self'].id)
	})

	test.afterAll(async () => {
		for (const flow of [onClose, onAny].filter(Boolean)) {
			await admin.delete(`${API}/flows/${flow}`)
		}
		if (caseId) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${caseId}`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin.dispose()
	})

	// @e2e flow-engine::a-makers-flow-starts-when-an-application-is-closed
	// @e2e flow-engine::a-transition-outside-the-filter-starts-nothing
	test('closing and reopening a case runs the close flow once and the control twice', async () => {
		expect(await runsOf(onClose), 'creating the case is not a transition').toBe(0)

		await transition('sluiten')
		await transition('heropenen')

		expect(await runsOf(onAny), 'both moves fired object.transitioned').toBe(2)
		expect(await runsOf(onClose), 'only the move to closed started the filtered flow').toBe(1)
	})
})
