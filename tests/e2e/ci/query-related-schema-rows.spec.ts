/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Filtering a list by rows of ANOTHER schema that point at it.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The parser, the EXISTS clause and the applier are all covered by PHPUnit, and
 * every one of those tests asserts the SQL STRING or a mocked query builder.
 * That is precisely the level at which this change's three worst defects were
 * invisible:
 *
 *   - `object ->> 'value' >= '100'` matched a stored `50`, because the JSON
 *     operator yields text and '50' sorts after '100'. The SQL was exactly what
 *     a renderer test would have asserted.
 *   - the guarded numeric cast still failed, because Postgres folds constant
 *     expressions before any CASE arm runs.
 *   - the clause rendered against a table the search path does not read.
 *
 * None of those is reachable from a test that never executes the query. This
 * spec runs it against a real register, through the real API, and asserts on
 * WHICH ROWS COME BACK.
 *
 * 🔑 THE NEGATIVE IS THE POINT, AND IT HAS A CONTROL. A filter that is silently
 * dropped answers the unfiltered set, which looks like a working filter as long
 * as you only check that the matching case is present. So every assertion below
 * pairs "the matching case is there" with "the non-matching case is NOT", and
 * the unfiltered request is asserted to return BOTH, so a suite that returns
 * nothing at all cannot read as a pass.
 *
 * SELF-CLEANING. Everything is created under a per-run register and removed in
 * `afterAll`.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const REGISTERS = `${API}/registers`
const SCHEMAS = `${API}/schemas`

const RUN_ID = `e2e-${Date.now()}`

const JSON_HEADERS = {
	Accept: 'application/json',
	'Content-Type': 'application/json',
}

test.describe.configure({ mode: 'serial' })

test.describe('query-related-schema-rows', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId: number | null = null
	let caseSchemaId: number | null = null
	let propertySchemaId: number | null = null
	let caseA: string | null = null
	let caseB: string | null = null

	/** Create one thing and return it. */
	async function create(
		request: APIRequestContext,
		url: string,
		body: Record<string, unknown>,
	): Promise<Record<string, any>> {
		const resp = await request.post(url, { headers: JSON_HEADERS, data: body })
		expect(resp.status(), await resp.text()).toBeLessThan(300)
		return await resp.json()
	}

	/** The uuids a list response carries, whatever shape it uses. */
	function uuidsOf(body: Record<string, any>): string[] {
		const rows = (body.results ?? body.data ?? body.objects ?? []) as Record<string, any>[]
		return rows.map((row) => row['@self']?.id ?? row.id ?? row.uuid).filter(Boolean)
	}

	test.beforeAll(async ({ request }) => {
		const register = await create(request, REGISTERS, {
			title: `E2E related ${RUN_ID}`,
			description: 'Related-row filtering.',
		})
		registerId = register.id ?? register['@self']?.id

		const caseSchema = await create(request, SCHEMAS, {
			title: `E2E case ${RUN_ID}`,
			properties: { name: { type: 'string' } },
		})
		caseSchemaId = caseSchema.id ?? caseSchema['@self']?.id

		const propertySchema = await create(request, SCHEMAS, {
			title: `E2E caseProperty ${RUN_ID}`,
			properties: {
				case: { type: 'string' },
				propertyDefinition: { type: 'string' },
				// A NUMBER, deliberately. The whole class of defect this change
				// carried was an ordering comparison silently done as text, and
				// a string column here would hide it again.
				value: { type: 'number' },
			},
		})
		propertySchemaId = propertySchema.id ?? propertySchema['@self']?.id

		const cases = `${API}/objects/${registerId}/${caseSchemaId}`
		const made = await create(request, cases, { name: `A ${RUN_ID}` })
		caseA = made['@self']?.id ?? made.id ?? made.uuid
		const madeB = await create(request, cases, { name: `B ${RUN_ID}` })
		caseB = madeB['@self']?.id ?? madeB.id ?? madeB.uuid

		const properties = `${API}/objects/${registerId}/${propertySchemaId}`
		// Case A carries 150. Case B carries 50.
		//
		// 🔴 THOSE TWO NUMBERS ARE CHOSEN, NOT ARBITRARY. Under text ordering
		// '50' >= '100' is TRUE, because '5' sorts after '1'. So a filter of
		// `gte 100` returning case B is the exact live symptom of the first
		// defect, and a filter returning only case A is the proof it is fixed.
		await create(request, properties, {
			case: caseA,
			propertyDefinition: 'pd-7',
			value: 150,
		})
		await create(request, properties, {
			case: caseB,
			propertyDefinition: 'pd-7',
			value: 50,
		})
	})

	test.afterAll(async ({ request }) => {
		for (const [url, id] of [
			[SCHEMAS, caseSchemaId],
			[SCHEMAS, propertySchemaId],
			[REGISTERS, registerId],
		] as [string, number | null][]) {
			if (id !== null) {
				await request.delete(`${url}/${id}`, { headers: JSON_HEADERS })
			}
		}
	})

	test('the control: unfiltered, both cases come back', async ({ request }) => {
		const resp = await request.get(`${API}/objects/${registerId}/${caseSchemaId}`, {
			headers: JSON_HEADERS,
		})
		expect(resp.status(), await resp.text()).toBe(200)

		const uuids = uuidsOf(await resp.json())
		expect(uuids).toContain(caseA)
		expect(uuids).toContain(caseB)
	})

	test('a related row narrows the list, and 50 does not answer "at least 100"', async ({ request }) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}`
			+ `?_related[${propertySchemaId}][case][propertyDefinition][eq]=pd-7`
			+ `&_related[${propertySchemaId}][case][value][gte]=100`,
			{ headers: JSON_HEADERS },
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const uuids = uuidsOf(await resp.json())
		expect(uuids).toContain(caseA)
		expect(
			uuids,
			'Case B carries 50. If it is here, the ordering comparison is being done as text.',
		).not.toContain(caseB)
	})

	test('the other side of the boundary returns the other case', async ({ request }) => {
		// The mirror of the test above, so "case B is absent" cannot be passing
		// because case B is absent from everything.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}`
			+ `?_related[${propertySchemaId}][case][value][lt]=100`,
			{ headers: JSON_HEADERS },
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const uuids = uuidsOf(await resp.json())
		expect(uuids).toContain(caseB)
		expect(uuids).not.toContain(caseA)
	})

	test('a misspelt schema is refused, not quietly dropped', async ({ request }) => {
		// 🔑 THE REFUSAL IS THE FEATURE. A dropped block answers every case in
		// the register, presented as the answer to a narrow question, and the
		// response is indistinguishable from a correctly filtered one.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}`
			+ `?_related[casePropertyy][case][value][gte]=100`,
			{ headers: JSON_HEADERS },
		)

		expect(
			resp.status(),
			'A schema nobody can name must end the request, not widen it.',
		).toBeGreaterThanOrEqual(400)
	})

	test('facet counts describe the filtered set, not the register', async ({ request }) => {
		// A facet count that ignores a filter the list honours is worse than no
		// count: the numbers answer a different question and nothing says so.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}`
			+ `?_related[${propertySchemaId}][case][value][gte]=100`
			+ `&_facets[@self][register][type]=terms`,
			{ headers: JSON_HEADERS },
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const body = await resp.json()
		const facets = body.facets ?? body['@self']?.facets ?? {}
		const buckets = facets['@self']?.register?.buckets ?? []
		const total = buckets.reduce(
			(sum: number, bucket: Record<string, any>) => sum + Number(bucket.count ?? 0),
			0,
		)

		if (buckets.length > 0) {
			expect(
				total,
				'The filtered list holds one case, so a facet total above it is counting the unfiltered set.',
			).toBeLessThanOrEqual(1)
		}
	})
})
