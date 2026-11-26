import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A DESTRUCTION LIST SAYS WHICH LINKED RECORD OUTLIVES AN ENTRY, AND A
 * REVIEWER WHO DESTROYS IT ANYWAY SAYS SO.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e archival-destruction-workflow::a-case-with-a-permanently-kept-decision-is-flagged
 * @e2e archival-destruction-workflow::a-reviewer-is-stopped-and-shown-what-they-would-override
 * @e2e archival-destruction-workflow::an-acknowledged-destroy-is-recorded-with-what-it-overrode
 *
 * A case nominated `vernietigen` is on a destruction list; a decision
 * nominated `bewaren` references it. The list names that decision as a
 * `linked-kept-permanently` conflict on the case's entry; destroying the case
 * without `acknowledgeConflicts` is 422 and writes no decision; destroying it
 * with the acknowledgement records the conflict under `overriddenConflicts`,
 * and approving the list records `destroyedOverConflict`.
 *
 * 🔴 ON A MAGIC TABLE THE LIST SHOWED NO CONFLICT, AND THIS SPEC IS WHAT FOUND
 * IT. The finder matched each referencing record to an entry by its relations
 * column and judged it on its retention block; the batched relation query and
 * the plain batch read both hand back rows without either, so the decision was
 * matched to nothing, and when matched, read as `linked-date-unknown`. The
 * unit tests built full records by hand.
 *
 * HERMETIC AND SELF-CLEANING, INCLUDING THE INSTANCE SETTING. The archival
 * settings point at this run's list register and are put back afterwards.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

const LIST_PROPERTIES = {
	status: { type: 'string' },
	objects: { type: 'array' },
	excluded: { type: 'array' },
	approvals: { type: 'array' },
	decisions: { type: 'array' },
	createdBy: { type: 'string' },
	createdAt: { type: 'string' },
}

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

test.describe.configure({ mode: 'serial' })

test.describe('a destruction list entry with a permanently kept link', () => {
	let admin: APIRequestContext
	const registers: string[] = []
	const schemas: string[] = []
	const objects: Array<[string, string, string]> = []
	let previousArchival: Record<string, unknown> | null = null
	let caseUuid = ''
	let decisionUuid = ''
	let listId = ''

	const list = (): string => `${API}/archival/destruction-lists/${listId}`
	const decide = (): string => `${list()}/entries/${caseUuid}/decision`

	async function created(path: string, data: Record<string, unknown>): Promise<Record<string, any>> {
		const res = await admin.post(path, { data })
		expect(res.ok(), `create at ${path} failed: ${await res.text()}`).toBeTruthy()

		return res.json()
	}

	async function register(title: string, schemaIds: string[]): Promise<string> {
		const id = String((await created(`${API}/registers`, { title, description: 'e2e' })).id)
		registers.push(id)
		const attach = await admin.put(`${API}/registers/${id}`, {
			data: { title, schemas: schemaIds.map(Number) },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		return id
	}

	async function schema(data: Record<string, unknown>): Promise<string> {
		const id = String((await created(`${API}/schemas`, { description: 'e2e', ...data })).id)
		schemas.push(id)

		return id
	}

	async function object(registerId: string, schemaId: string, data: Record<string, unknown>): Promise<string> {
		const uuid = String((await created(`${API}/objects/${registerId}/${schemaId}`, data))['@self'].id)
		objects.push([registerId, schemaId, uuid])

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const caseSchema = await schema({
			title: `e2e linked conflict zaak ${RUN}`,
			properties: { zaaknummer: { type: 'string', maxLength: 64 } },
			archive: { enabled: true, defaultNominatie: 'vernietigen', defaultBewaartermijn: 'P1D' },
		})
		const decisionSchema = await schema({
			title: `e2e linked conflict besluit ${RUN}`,
			properties: {
				besluitnummer: { type: 'string', maxLength: 64 },
				zaak: { type: 'string', format: 'uuid', $ref: `#/components/schemas/${caseSchema}` },
			},
			archive: { enabled: true, defaultNominatie: 'bewaren' },
		})
		const listSchema = await schema({ title: `e2e linked conflict list ${RUN}`, properties: LIST_PROPERTIES })

		const caseRegister = await register(`e2e linked conflict zaken ${RUN}`, [caseSchema, decisionSchema])
		const listRegister = await register(`e2e linked conflict lists ${RUN}`, [listSchema])

		caseUuid = await object(caseRegister, caseSchema, { zaaknummer: 'Z-2019-0042' })
		decisionUuid = await object(caseRegister, decisionSchema, { besluitnummer: 'B-2019-0007', zaak: caseUuid })

		const before = await admin.get(`${API}/settings/archival`)
		expect(before.status(), 'read the archival settings').toBe(200)
		previousArchival = await before.json()
		const patched = await admin.patch(`${API}/settings/archival`, {
			data: { ...previousArchival, destructionListRegister: Number(listRegister), destructionListSchema: Number(listSchema) },
		})
		expect(patched.status(), 'point the settings at the fixture register').toBeLessThan(300)

		listId = await object(listRegister, listSchema, {
			status: 'in_review',
			createdBy: 'e2e',
			createdAt: new Date().toISOString(),
			objects: [{ uuid: caseUuid, title: 'Z-2019-0042', reviewer: null }],
			excluded: [],
			approvals: [],
			decisions: [],
		})

		const assigned = await admin.put(`${list()}/entries/${caseUuid}/reviewer`, { data: { reviewer: ADMIN } })
		expect(assigned.status(), `reviewer assignment failed: ${await assigned.text()}`).toBe(200)
	})

	test.afterAll(async () => {
		if (previousArchival !== null) {
			await admin.patch(`${API}/settings/archival`, { data: previousArchival }).catch(() => {})
		}
		for (const [registerId, schemaId, uuid] of [...objects].reverse()) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		for (const id of [...schemas].reverse()) {
			await admin.delete(`${API}/schemas/${id}`)
		}
		for (const id of registers) {
			await admin.delete(`${API}/registers/${id}`)
		}
		await admin?.dispose()
	})

	test('the list names the kept decision on the case entry', async () => {
		const res = await admin.get(list())
		expect(res.status(), await res.text()).toBe(200)
		const entry = ((await res.json()).objects as Array<Record<string, any>>).find((e) => e.uuid === caseUuid)
		expect(entry, 'the case is on the list').toBeTruthy()

		expect(entry?.linkConflicts).toEqual([
			expect.objectContaining({
				kind: 'linked-kept-permanently',
				direction: 'incoming',
				uuid: decisionUuid,
				archiefnominatie: 'bewaren',
			}),
		])
	})

	test('destroying without an acknowledgement is refused and writes nothing', async () => {
		const refused = await admin.post(decide(), { data: { answer: 'destroy', reason: 'Termijn verstreken' } })
		expect(refused.status(), await refused.text()).toBe(422)
		const body = await refused.json()
		expect((body.linkConflicts as Array<Record<string, any>>).map((c) => c.uuid)).toEqual([decisionUuid])

		const after = await (await admin.get(list())).json()
		expect(after.decisions, 'the decision history is unchanged').toEqual([])
	})

	test('an acknowledged destroy records what it overrode, and the approval counts it', async () => {
		const decided = await admin.post(decide(), {
			data: { answer: 'destroy', reason: 'Besluit bevat de zaakgegevens zelf', acknowledgeConflicts: true },
		})
		expect(decided.status(), await decided.text()).toBe(200)

		const history = (await (await admin.get(list())).json()).decisions as Array<Record<string, any>>
		expect(history).toHaveLength(1)
		expect(history[0].answer).toBe('destroy')
		expect((history[0].overriddenConflicts as Array<Record<string, any>>).map((c) => [c.kind, c.uuid])).toEqual([
			['linked-kept-permanently', decisionUuid],
		])

		const approved = await admin.post(`${list()}/approve`, { data: {} })
		expect(approved.status(), await approved.text()).toBe(200)
		const approvals = (await (await admin.get(list())).json()).approvals as Array<Record<string, any>>
		expect(approvals.at(-1)?.destroyedOverConflict).toBe(1)
	})
})
