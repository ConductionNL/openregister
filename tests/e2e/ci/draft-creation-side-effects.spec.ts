/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FLOW ON "CREATED" STAYS SILENT WHILE AN OBJECT IS A DRAFT AND RUNS ONCE WHEN IT LEAVES DRAFT.
 *
 * Change `draft-creation-side-effects`, task 5.2 (the flow half; the webhook
 * half rides the background delivery job, see tasks.md). A published flow
 * starts on `object.created` for one schema. An object is created as a draft
 * (`@self.status: draft`, a required field still empty) and autosaved twice:
 * the flow does not run. Its owner activates it through
 * `POST /api/objects/{register}/{schema}/{id}/activate`: the flow runs once,
 * about that object. A later update of the active object starts nothing more,
 * and an object created directly as active runs the flow once, the control
 * that shows the trigger is live at all.
 *
 * The flow runs `sync`, so each run happens inside the call that causes it and
 * the spec needs no background worker.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, flow and
 * objects and removes them in afterAll.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'

test.describe.configure({ mode: 'serial' })

test.describe('draft-creation-side-effects: a created-flow and a draft', () => {
	let admin: APIRequestContext
	let registerId = 0
	let schemaId = 0
	let flowId = ''
	let draft = ''
	let direct = ''

	/** This flow's runs about one object. */
	async function runsAbout(uuid: string): Promise<number> {
		const res = await admin.get(`${API}/flow-runs?limit=200`)
		expect(res.status(), await res.text()).toBe(200)
		return ((await res.json()).results as Array<Record<string, any>>)
			.filter((run) => run.flowId === flowId && run.subjectUuid === uuid)
			.length
	}

	/** Patch the draft as an autosave would. */
	async function autosave(fields: Record<string, string>): Promise<void> {
		const res = await admin.patch(`${API}/objects/${registerId}/${schemaId}/${draft}`, { data: fields })
		expect(res.status(), await res.text()).toBe(200)
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

		const register = await admin.post(`${API}/registers`, { data: { slug: `e2e-draft-${RUN}`, title: `E2E draft ${RUN}` } })
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				slug: `e2e-melding-${RUN}`,
				title: `E2E melding ${RUN}`,
				properties: { titel: { type: 'string' }, omschrijving: { type: 'string' } },
				required: ['titel', 'omschrijving'],
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { slug: `e2e-draft-${RUN}`, title: `E2E draft ${RUN}`, schemas: [schemaId] },
		})
		expect(linked.status(), await linked.text()).toBe(200)

		const created = await admin.post(`${API}/flows`, {
			data: {
				name: `e2e on created ${RUN}`,
				description: 'Created by draft-creation-side-effects.spec.ts',
				enabled: true,
				executionMode: 'sync',
				nodes: [
					{ id: 'created', type: 'openregister.trigger-object', config: { event: 'object.created', register: String(registerId), schema: String(schemaId) } },
					{ id: 'end', type: 'openregister.end', config: {} },
				],
				edges: [{ id: 'a', from: 'created', to: 'end' }],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		flowId = String((await created.json()).id)

		const published = await admin.post(`${API}/flows/${flowId}/publish`, { data: {} })
		expect(published.status(), await published.text()).toBe(200)
	})

	test.afterAll(async () => {
		if (flowId) {
			await admin.delete(`${API}/flows/${flowId}`)
		}
		for (const uuid of [draft, direct].filter(Boolean)) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin.dispose()
	})

	test('a draft and its autosaves start nothing', async () => {
		const created = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel: `Concept ${RUN}`, '@self': { status: 'draft' } } })
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		const body = await created.json()
		expect(body['@self'].status, 'the object is saved as a draft, its required omschrijving still empty').toBe('draft')
		draft = String(body['@self'].id)

		await autosave({ titel: `Concept ${RUN}, bijgewerkt` })
		await autosave({ omschrijving: 'Bijna klaar' })

		expect(await runsAbout(draft)).toBe(0)
	})

	test('activating the draft runs the flow once, and a later update adds no run', async () => {
		const activated = await admin.post(`${API}/objects/${registerId}/${schemaId}/${draft}/activate`, { data: {} })
		expect(activated.status(), await activated.text()).toBe(200)
		expect((await activated.json())['@self'].status).toBe('active')
		expect(await runsAbout(draft)).toBe(1)

		const updated = await admin.patch(`${API}/objects/${registerId}/${schemaId}/${draft}`, { data: { omschrijving: 'Na activatie' } })
		expect(updated.status(), await updated.text()).toBe(200)
		expect(await runsAbout(draft), 'an update is not a creation').toBe(1)
	})

	test('an object created as active runs the flow once', async () => {
		const created = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel: `Direct ${RUN}`, omschrijving: 'Meteen' } })
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		direct = String((await created.json())['@self'].id)

		expect(await runsAbout(direct)).toBe(1)
	})
})
