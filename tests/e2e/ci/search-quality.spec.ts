/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Search quality: the missing-value facet and the boolean term, over HTTP.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * PHPUnit pins the grammar and the SQL those two features generate. What it
 * cannot see is whether the numbers are true of real rows. The missing bucket
 * in particular is counted by the database in the same GROUP BY as the value
 * buckets, and a double that hands back rows cannot tell you whether the query
 * that asked for them was right. Neither can a unit test tell you that
 * selecting the bucket returns exactly the objects it counted.
 *
 * So this spec seeds seven objects of which two hold no result type, reads the
 * facet, clicks the bucket, and then searches the descriptions with an
 * exclusion and with a wildcard.
 *
 * SELF-CLEANING. The register, the schema and every object are created under a
 * per-run slug and removed in afterAll, deleted rows purged.
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'

const RUN = `e2e-search-quality-${Date.now()}`

test.describe.configure({ mode: 'serial' })

test.describe('search quality', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId = ''
	let schemaId = ''
	const created: string[] = []
	const withoutResultType: string[] = []

	/** Read the ids out of a list response, whatever shape the row carries. */
	function idsOf(body: any): string[] {
		return (body.results ?? []).map(
			(row: any) => String(row['@self']?.id ?? row.id ?? row.uuid),
		)
	}

	test.beforeAll(async ({ request }) => {
		const reg = await request.post(`${API}/registers`, {
			data: { title: `E2E search quality ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await request.post(`${API}/schemas`, {
			data: {
				title: `E2E zaak ${RUN}`,
				description: 'e2e',
				properties: {
					resultType: { type: 'string', title: 'Result type', maxLength: 255 },
					omschrijving: { type: 'string', title: 'Description', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		// Five objects carry a result type, two do not. The descriptions carry
		// the words the two term scenarios search for.
		const rows = [
			{ resultType: 'toegekend', omschrijving: 'dakkapel vergunning verleend' },
			{ resultType: 'toegekend', omschrijving: 'dakkapel aanbouw' },
			{ resultType: 'toegekend', omschrijving: 'dakkapel serre' },
			{ resultType: 'afgewezen', omschrijving: 'dakkapel geweigerd' },
			{ resultType: 'afgewezen', omschrijving: 'dakkapel geweigerd namens college' },
			{ omschrijving: 'vergunning' },
			{ omschrijving: 'vergunningaanvraag' },
		]

		for (const row of rows) {
			const obj = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: row,
			})
			expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
			const body = await obj.json()
			const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
			expect(uuid, 'no uuid came back from the object create').toBeTruthy()
			created.push(uuid)
			if (row.resultType === undefined) {
				withoutResultType.push(uuid)
			}
		}
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of created) {
			await request.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await request.delete(`${API}/deleted/${uuid}`)
		}

		if (schemaId !== '') {
			await request.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId !== '') {
			await request.delete(`${API}/registers/${registerId}`)
		}
	})

	// @e2e faceting-configuration::the-cases-with-no-result-type-are-counted-and-clickable
	test('the objects with no result type are counted in the facet and selectable', async ({
		request,
	}) => {
		const faceted = await request.get(
			`${API}/objects/${registerId}/${schemaId}?_limit=50&_facets[resultType][type]=terms`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(faceted.status(), 'the faceted list is readable').toBe(200)

		const body = await faceted.json()
		const facet = body.facets?.resultType
		expect(facet, 'the resultType facet came back').toBeTruthy()

		// The complement of the value buckets, counted in the same query.
		expect(facet.missing?.results, 'two objects hold no result type').toBe(2)

		const valueKeys = (facet.buckets ?? []).map((bucket: any) => bucket.key)
		expect(valueKeys, 'toegekend is still a value bucket').toContain('toegekend')
		expect(valueKeys, 'toegekend is still a value bucket').toContain('afgewezen')
		expect(valueKeys, 'the absent value is not also a value bucket').not.toContain(null)

		// Selecting the bucket returns exactly the objects it counted. Both
		// spellings of the filter mean the same thing here.
		for (const query of [
			'resultType_isnull=true',
			'filter[resultType][isnull]=true',
		]) {
			const selected = await request.get(
				`${API}/objects/${registerId}/${schemaId}?_limit=50&${query}`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(selected.status(), `${query} is readable`).toBe(200)

			const ids = idsOf(await selected.json())
			expect(ids.length, `${query} returns the two the facet counted`).toBe(2)
			expect(ids.sort(), `${query} returns exactly those two`).toEqual(
				[...withoutResultType].sort(),
			)
		}
	})

	// @e2e zoeken-filteren::a-caseworker-excludes-a-word
	test('a term with AND NOT excludes the word instead of searching for it', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${schemaId}?_limit=50&_search=${encodeURIComponent('dakkapel AND NOT geweigerd')}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'the search is readable').toBe(200)

		const body = await resp.json()
		const descriptions = (body.results ?? []).map(
			(row: any) => String(row.omschrijving ?? ''),
		)

		expect(descriptions.length, 'the three dakkapel rows that are not geweigerd').toBe(3)
		for (const description of descriptions) {
			expect(description, 'every row matches dakkapel').toContain('dakkapel')
			expect(description, 'no row matches geweigerd').not.toContain('geweigerd')
		}
	})

	// @e2e zoeken-filteren::a-wildcard-matches-a-stem
	test('a trailing wildcard matches the stem and what follows it', async ({ request }) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${schemaId}?_limit=50&_search=${encodeURIComponent('vergunning*')}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'the wildcard search is readable').toBe(200)

		const descriptions = ((await resp.json()).results ?? []).map(
			(row: any) => String(row.omschrijving ?? ''),
		)

		expect(descriptions, 'the exact stem is returned').toContain('vergunning')
		expect(descriptions, 'the longer word is returned too').toContain('vergunningaanvraag')

		// The row whose description merely CONTAINS the word is not returned:
		// a trailing wildcard anchors the start of the value, and without that
		// anchor this test would pass over the old substring behaviour too.
		expect(
			descriptions,
			'a value that only contains the stem is not a prefix match',
		).not.toContain('dakkapel vergunning verleend')
	})

	// @e2e zoeken-filteren::an-unbalanced-bracket-is-refused
	test('an unbalanced bracket is refused with the position, and holds no results', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${schemaId}?_search=${encodeURIComponent('dakkapel AND (geweigerd')}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'a term the parser cannot read is refused').toBe(400)

		const body = await resp.json()
		expect(body.position, 'the refusal names the position of the fault').toBe(14)
		expect(String(body.error), 'the message names it too').toContain('position 14')
		expect(body.results, 'a refusal holds no results').toBeUndefined()
	})
})
