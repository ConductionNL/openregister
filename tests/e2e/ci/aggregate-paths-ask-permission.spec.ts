import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A SUMMARY OF A PROPERTY IS A READ OF THAT PROPERTY.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e rbac-scopes::the-value-set-is-not-readable-to-someone-the-values-are-not
 * @e2e rbac-scopes::a-sum-is-a-value
 * @e2e rbac-scopes::a-column-heading-is-a-value
 * @e2e rbac-scopes::an-ungoverned-property-is-unaffected
 *
 * Three paths summarise a property without returning a record: a facet's
 * buckets, an aggregation's figure and a kanban board's column headings. Each
 * is driven over HTTP by the seeded colleague, who may list the schema but may
 * not read two of its properties, with the administrator as the control that
 * the same request DOES answer, so a refusal is the rule and not a broken
 * fixture.
 *
 * HERMETIC. It creates its own register, schema, two claims and the views,
 * and removes them. The colleague is the one the CI seed provisions.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded non-admin account. */
const COLLEAGUE = 'e2e-other'
const COLLEAGUE_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** Values distinctive enough that finding one anywhere in an answer is a leak. */
const VERDICTS = [`fraudevermoed-${RUN}`, `inorde-${RUN}`]
const AMOUNTS = [137, 4211]

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

/** Readable only by the administrators. */
const ADMIN_ONLY = { read: ['admin'], update: ['admin'] }

test.describe.configure({ mode: 'serial' })

test.describe('aggregates over a property ask that property', () => {
	let admin: APIRequestContext
	let colleague: APIRequestContext
	let registerId = ''
	let schemaId = ''
	const objects: string[] = []
	const views: Array<[APIRequestContext, string]> = []

	const list = (): string => `${API}/objects/${registerId}/${schemaId}`
	const aggregations = (): string => `${API}/objects/aggregations/${registerId}/${schemaId}`

	/** The facets answer for a set of terms facets. */
	async function facets(ctx: APIRequestContext, fields: string[]): Promise<{ text: string; facets: Record<string, any> }> {
		const params = fields.map((f) => `_facets[${f}][type]=terms`).join('&')
		const res = await ctx.get(`${list()}?${params}`)
		expect(res.ok(), `facet read failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()

		return { text: JSON.stringify(body.facets ?? {}), facets: body.facets ?? {} }
	}

	/** A kanban view grouped on one field, owned by the caller. */
	async function board(ctx: APIRequestContext, field: string): Promise<string> {
		const res = await ctx.post(`${API}/views`, {
			data: {
				name: `e2e aggregate board ${field} ${RUN}`,
				query: { registers: [registerId], schemas: [schemaId] },
				presentation: { viewType: 'kanban', kanban: { groupByField: field } },
			},
		})
		expect(res.status(), `view create failed: ${await res.text()}`).toBe(201)
		const uuid = String((await res.json()).view.uuid)
		views.push([ctx, uuid])

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		colleague = await contextFor(COLLEAGUE, COLLEAGUE_PASS)

		const seeded = await colleague.get(`${API}/registers`)
		expect(seeded.status(), `seeded account '${COLLEAGUE}' cannot authenticate`).toBeLessThan(400)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e aggregate register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e aggregate claim ${RUN}`,
				description: 'e2e',
				properties: {
					soort: { type: 'string', facetable: true, enum: ['a', 'b'] },
					bedrag: { type: 'number', facetable: true, authorization: ADMIN_ONLY },
					oordeel: { type: 'string', facetable: true, enum: VERDICTS, authorization: ADMIN_ONLY },
				},
				authorization: { read: ['authenticated'], create: ['admin'], update: ['admin'], delete: ['admin'] },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e aggregate register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		for (const [i, soort] of ['a', 'b'].entries()) {
			const res = await admin.post(list(), {
				data: { soort, bedrag: AMOUNTS[i], oordeel: VERDICTS[i] },
			})
			expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
			const body = await res.json()
			objects.push(String(body['@self']?.id ?? body.id))
		}
	})

	test.afterAll(async () => {
		for (const [ctx, uuid] of views) {
			await ctx.delete(`${API}/views/${uuid}`)
		}
		for (const uuid of objects) {
			await admin.delete(`${list()}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await colleague?.dispose()
	})

	test('a facet over a governed property has no buckets for the colleague', async () => {
		const control = await facets(admin, ['soort', 'bedrag', 'oordeel'])
		expect(Object.keys(control.facets).sort(), 'control: the administrator gets all three').toEqual(
			['bedrag', 'oordeel', 'soort'],
		)
		expect(control.text).toContain(VERDICTS[0])

		const asked = await facets(colleague, ['soort', 'bedrag', 'oordeel'])
		expect(Object.keys(asked.facets), 'only the ungoverned facet is computed').toEqual(['soort'])
		for (const value of [...VERDICTS, ...AMOUNTS.map(String)]) {
			expect(asked.text, `${value} is in no bucket`).not.toContain(value)
		}
	})

	// 📌 A DECLARED GAP, NOT A PASS. The scenario also asks that the withheld
	// facet be NAMED under `withheld`, so a caller can tell "no values" from
	// "not yours". The list answer carries no `withheld` key today: the facet is
	// dropped at the facetable-field source and nothing reports it. Kept as a
	// fixme so it shows in the skip column until the facet path names it.
	test.fixme('a withheld facet is named under withheld', async () => {
		const res = await colleague.get(`${list()}?_facets[soort][type]=terms&_facets[oordeel][type]=terms`)
		expect(res.ok()).toBeTruthy()
		const body = await res.json()
		expect(JSON.stringify(body.withheld ?? body.facets?.withheld ?? null)).toContain('oordeel')
	})

	test('a sum and a grouping over a governed property are refused, not zeroed', async () => {
		const sum = await admin.get(`${aggregations()}/value?metric=sum&field=bedrag`)
		expect(sum.status(), 'control: the administrator gets the sum').toBe(200)
		expect((await sum.json()).value).toBe(AMOUNTS[0] + AMOUNTS[1])

		const refusedSum = await colleague.get(`${aggregations()}/value?metric=sum&field=bedrag`)
		expect(refusedSum.status()).toBe(403)
		expect(await refusedSum.text()).not.toContain(String(AMOUNTS[0] + AMOUNTS[1]))

		const refusedGroups = await colleague.get(`${aggregations()}/grouped?metric=count&groupBy=oordeel`)
		expect(refusedGroups.status()).toBe(403)
		const text = await refusedGroups.text()
		for (const verdict of VERDICTS) {
			expect(text, `${verdict} is not a group key`).not.toContain(verdict)
		}
	})

	test('an ungoverned property is summarised as before', async () => {
		const count = await colleague.get(`${aggregations()}/grouped?metric=count&groupBy=soort`)
		expect(count.status(), await count.text()).toBe(200)
		const groups = ((await count.json()).groups as Array<Record<string, any>>)
			.map((g) => [g.key, g.value])
			.sort()
		expect(groups).toEqual([
			['a', 1],
			['b', 1],
		])
	})

	test('a board grouped on a governed property draws no columns for the colleague', async () => {
		const control = await admin.get(`${API}/views/${await board(admin, 'oordeel')}/kanban`)
		expect(control.status(), 'control: the administrator gets the board').toBe(200)
		const columns = JSON.stringify((await control.json()).columns)
		expect(columns).toContain(VERDICTS[0])

		const refused = await colleague.get(`${API}/views/${await board(colleague, 'oordeel')}/kanban`)
		expect(refused.ok(), 'the colleague gets no board').toBeFalsy()
		const text = await refused.text()
		for (const verdict of VERDICTS) {
			expect(text, `${verdict} is not a column heading`).not.toContain(verdict)
		}
	})
})
