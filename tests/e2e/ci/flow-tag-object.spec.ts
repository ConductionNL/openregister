/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FLOW LABELS A LARGE LEAD, AND ONLY THAT ONE, AND ONLY ONCE.
 *
 * Change `flow-tag-object-step`, task 3.1. A sales manager's flow starts on a
 * lead being created (and on it being updated), keeps the items whose `value`
 * is above 10000, and adds the tag "Large deal" in colour d94c3d. Two leads are
 * created, one over and one under the threshold: only the first carries the
 * tag, read back through `GET /api/objects/{register}/{schema}/{id}/tags`, and
 * the tag Nextcloud created carries the colour. Updating the large lead runs
 * the flow a second time; that run's tag step reaches the lead and adds
 * nothing, so the lead still carries the one tag.
 *
 * The flow runs `sync`, so each run happens inside the write that triggers it
 * and the spec needs no background worker.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, flow and tag
 * (named per run, so a colour from an earlier run cannot satisfy the colour
 * assertion) and removes them in afterAll, whatever happened.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const TAG = `Large deal ${RUN}`
const COLOUR = 'd94c3d'
const THRESHOLD = 10000

/** An object id from either the `@self` envelope or a flat body. */
function idOf(body: Record<string, any>): string {
	return String(body?.['@self']?.id ?? body?.id ?? '')
}

/** The system tags Nextcloud holds, by name, with their colour and id. */
async function systemTags(ctx: APIRequestContext): Promise<Map<string, { id: string, color: string }>> {
	const res = await ctx.fetch('/remote.php/dav/systemtags/', {
		method: 'PROPFIND',
		headers: { Depth: '1', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			+ '<d:prop><oc:id/><oc:display-name/><nc:color/></d:prop></d:propfind>',
	})
	expect(res.status(), 'PROPFIND systemtags').toBe(207)
	const xml = await res.text()
	const tags = new Map<string, { id: string, color: string }>()
	for (const block of xml.split(/<d:response>/).slice(1)) {
		const name = /<oc:display-name>([^<]*)<\/oc:display-name>/.exec(block)?.[1]
		const id = /<oc:id>([^<]*)<\/oc:id>/.exec(block)?.[1]
		if (name !== undefined && id !== undefined) {
			tags.set(name, { id, color: /<nc:color>([^<]*)<\/nc:color>/.exec(block)?.[1] ?? '' })
		}
	}
	return tags
}

test.describe.configure({ mode: 'serial' })

test.describe('flow-tag-object-step: a flow labels a large lead', () => {
	let admin: APIRequestContext
	let registerId = 0
	let schemaId = 0
	let flowId = ''
	let largeLead = ''
	let smallLead = ''

	/** The tags of one lead. */
	async function tagsOf(lead: string): Promise<string[]> {
		const res = await admin.get(`${API}/objects/${registerId}/${schemaId}/${lead}/tags`)
		expect(res.status(), await res.text()).toBe(200)
		return res.json()
	}

	/** Create one lead through the object API. */
	async function createLead(title: string, value: number): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title, value } })
		expect(res.status(), await res.text()).toBeLessThanOrEqual(201)
		const id = idOf(await res.json())
		expect(id, 'the created lead has an id').not.toBe('')
		return id
	}

	/** This flow's runs about one lead, newest first. */
	async function runsAbout(lead: string): Promise<Array<Record<string, any>>> {
		const res = await admin.get(`${API}/flow-runs?limit=200`)
		expect(res.status(), await res.text()).toBe(200)
		return ((await res.json()).results as Array<Record<string, any>>)
			.filter((run) => run.flowId === flowId && run.subjectUuid === lead)
	}

	/** The tag step's entry in a run's log. */
	function tagStep(run: Record<string, any>): Record<string, any> | undefined {
		return (run.log as Array<Record<string, any>>).find((step) => step.type === 'openregister.tag-object')
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

		const register = await admin.post(`${API}/registers`, { data: { slug: `e2e-tag-${RUN}`, title: `E2E tag ${RUN}` } })
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				slug: `e2e-lead-${RUN}`,
				title: `E2E lead ${RUN}`,
				properties: { title: { type: 'string' }, value: { type: 'number' } },
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { slug: `e2e-tag-${RUN}`, title: `E2E tag ${RUN}`, schemas: [schemaId] },
		})
		expect(linked.status(), await linked.text()).toBe(200)

		const on = (event: string) => ({ event, register: String(registerId), schema: String(schemaId) })
		const created = await admin.post(`${API}/flows`, {
			data: {
				name: `e2e large deal ${RUN}`,
				description: 'Created by flow-tag-object.spec.ts',
				enabled: true,
				executionMode: 'sync',
				nodes: [
					{ id: 'created', type: 'openregister.trigger-object', config: on('object.created') },
					{ id: 'updated', type: 'openregister.trigger-object', config: on('object.updated') },
					{ id: 'large', type: 'openregister.filter', config: { condition: { '>': [{ var: 'json.value' }, THRESHOLD] } } },
					{ id: 'tag', type: 'openregister.tag-object', config: { operation: 'add', tag: TAG, color: COLOUR } },
					{ id: 'end', type: 'openregister.end', config: {} },
				],
				edges: [
					{ id: 'a', from: 'created', to: 'large' },
					{ id: 'b', from: 'updated', to: 'large' },
					{ id: 'c', from: 'large', to: 'tag' },
					{ id: 'd', from: 'tag', to: 'end' },
				],
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
		for (const lead of [largeLead, smallLead].filter(Boolean)) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${lead}`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		const tag = (await systemTags(admin)).get(TAG)
		if (tag) {
			await admin.fetch(`/remote.php/dav/systemtags/${tag.id}`, { method: 'DELETE' })
		}
		await admin.dispose()
	})

	// @e2e flow-engine::a-large-lead-is-labelled-by-a-rule
	test('only the lead over the threshold is tagged, in the step\'s colour', async () => {
		largeLead = await createLead(`Large lead ${RUN}`, 25000)
		smallLead = await createLead(`Small lead ${RUN}`, 4000)

		expect(await tagsOf(largeLead)).toEqual([TAG])
		expect(await tagsOf(smallLead)).toEqual([])

		const tag = (await systemTags(admin)).get(TAG)
		expect(tag, `Nextcloud holds no tag named "${TAG}"`).toBeTruthy()
		expect(tag!.color.toLowerCase()).toBe(COLOUR)
	})

	test('a second run reaches the lead and adds nothing', async () => {
		const before = await runsAbout(largeLead)
		expect(before.length, 'the create ran the flow once for the large lead').toBe(1)

		const updated = await admin.put(`${API}/objects/${registerId}/${schemaId}/${largeLead}`, {
			data: { title: `Large lead ${RUN}, again`, value: 26000 },
		})
		expect(updated.status(), await updated.text()).toBe(200)

		const after = await runsAbout(largeLead)
		expect(after.length, 'the update ran the flow a second time').toBe(2)
		const second = after.find((run) => !before.some((earlier) => earlier.uuid === run.uuid))!
		expect(tagStep(second)?.status).toBe('completed')
		expect(tagStep(second)?.itemsIn, 'the second run\'s tag step reached the lead').toBe(1)

		expect(await tagsOf(largeLead)).toEqual([TAG])
	})
})
