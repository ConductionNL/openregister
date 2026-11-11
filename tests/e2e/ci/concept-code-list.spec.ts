/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A property takes its choices from a concept scheme, in either spelling.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The factory, the guard and the option builder are all covered by PHPUnit
 * against fixtures, and every one of those fixtures is a property somebody
 * wrote by hand in a test. What no unit test can see is whether the binding
 * SURVIVES A REAL SCHEMA SAVE: whether the key the vocabulary publishes is the
 * key the save path stores, and whether the property that comes back out of the
 * schemas API is still bound to the scheme it went in with.
 *
 * That is the exact failure `property-code-list-from-concept-scheme` was opened
 * for. The binding was accepted by the save path for months, because
 * `assertKeysAreInTheVocabulary()` skips every `x-` key, and it could not be
 * FORWARDED because the vocabulary did not publish it. A mocked repository
 * cannot tell those apart: it returns whatever the test put in.
 *
 * 🔑 BOTH SPELLINGS ARE WRITTEN, ON TWO PROPERTIES OF ONE SCHEMA.
 * `conceptScheme` is the published modifier an app forwards through an
 * extending form; `x-openregister-concepts` is the same binding with the branch
 * and depth a hierarchy needs. They are one declaration to the factory, and a
 * spec that only wrote one of them would pass while the other silently stopped
 * being read.
 *
 * SELF-CLEANING. Everything is created under a per-run uri prefix and removed
 * in `afterAll`. Nothing here touches the seeded TOOI schemes, which other
 * suites read.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const VOCAB_SCHEMES = `${API}/objects/vocabulary/conceptScheme`
const VOCAB_CONCEPTS = `${API}/objects/vocabulary/concept`
const SCHEMAS = `${API}/schemas`
const VOCABULARY = `${API}/schemas/property-vocabulary`

const RUN_ID = `e2e-${Date.now()}`
const SCHEME_URI = `urn:e2e:${RUN_ID}:wijken`
const URI_CENTRUM = `urn:e2e:${RUN_ID}:centrum`
const URI_NOORD = `urn:e2e:${RUN_ID}:noord`

const JSON_HEADERS = {
	Accept: 'application/json',
	'Content-Type': 'application/json',
}

test.describe.configure({ mode: 'serial' })

test.describe('concept-code-list', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemeId: string | null = null
	let schemaId: number | null = null
	const conceptIds: Record<string, string> = {}

	/** Create one object and return it. */
	async function create(
		request: APIRequestContext,
		url: string,
		body: Record<string, unknown>,
	): Promise<Record<string, any>> {
		const resp = await request.post(url, { headers: JSON_HEADERS, data: body })
		expect(resp.status(), await resp.text()).toBeLessThan(300)
		return await resp.json()
	}

	test.beforeAll(async ({ request }) => {
		const scheme = await create(request, VOCAB_SCHEMES, {
			uri: SCHEME_URI,
			title: `E2E wijken ${RUN_ID}`,
			publisher: 'e2e',
			version: '1.0.0',
			source: SCHEME_URI,
		})
		schemeId = scheme.id ?? scheme['@self']?.id ?? scheme.uuid

		for (const [uri, label] of [
			[URI_CENTRUM, 'Centrum'],
			[URI_NOORD, 'Noord'],
		]) {
			const made = await create(request, VOCAB_CONCEPTS, {
				inScheme: schemeId,
				uri,
				prefLabel: { nl: label },
			})
			conceptIds[uri] = made.id ?? made['@self']?.id ?? made.uuid
		}
	})

	test.afterAll(async ({ request }) => {
		if (schemaId !== null) {
			await request.delete(`${SCHEMAS}/${schemaId}`)
		}
		for (const id of Object.values(conceptIds)) {
			await request.delete(`${VOCAB_CONCEPTS}/${id}`)
		}
		if (schemeId !== null) {
			await request.delete(`${VOCAB_SCHEMES}/${schemeId}`)
		}
	})

	test('the vocabulary publishes the binding, so a form can forward it', async ({
		request,
	}) => {
		// The control, and it runs first. Every assertion below is about a key
		// this endpoint has to name; if it does not, the rest is measuring a
		// key that only this spec believes in.
		const resp = await request.get(VOCABULARY, { headers: JSON_HEADERS })
		expect(resp.ok(), await resp.text()).toBeTruthy()

		const body = await resp.json()
		expect(body.keys).toContain('conceptScheme')

		const modifier = (body.modifiers ?? []).find(
			(row: { key: string }) => row.key === 'conceptScheme',
		)
		expect(
			modifier,
			'conceptScheme must be published as a modifier',
		).toBeTruthy()
		expect(modifier.value).toBe('string')
	})

	test('a schema saves with the binding in both spellings, and reads it back', async ({
		request,
	}) => {
		const saved = await create(request, SCHEMAS, {
			title: `E2E coded ${RUN_ID}`,
			slug: `e2e-coded-${RUN_ID}`,
			properties: {
				// The published modifier: what an app forwards through an
				// extending form, and what a case-type editor writes.
				wijk: {
					type: 'string',
					title: 'Wijk',
					conceptScheme: SCHEME_URI,
				},
				// The same binding with the options a hierarchy needs.
				buurt: {
					type: 'string',
					title: 'Buurt',
					'x-openregister-concepts': { scheme: SCHEME_URI, store: 'uri' },
				},
				// A plain property beside them, so an assertion that the schema
				// saved at all cannot be satisfied by the bindings alone.
				omschrijving: { type: 'string', title: 'Omschrijving' },
			},
		})
		schemaId = saved.id ?? saved['@self']?.id ?? saved.uuid
		expect(schemaId, 'the schema must have been created').toBeTruthy()

		const read = await request.get(`${SCHEMAS}/${schemaId}`, {
			headers: JSON_HEADERS,
		})
		expect(read.ok(), await read.text()).toBeTruthy()

		const properties = (await read.json()).properties ?? {}
		// 🔴 THE BINDING HAS TO COME BACK OUT. A save that accepts the key and
		// drops it is the exact shape this change exists to close, and it looks
		// identical to a working one until somebody opens the form.
		expect(properties.wijk?.conceptScheme).toBe(SCHEME_URI)
		expect(properties.buurt?.['x-openregister-concepts']?.scheme).toBe(
			SCHEME_URI,
		)
		expect(properties.omschrijving?.type).toBe('string')
	})

	test('the options of a bound property are the concepts of its scheme', async ({
		request,
	}) => {
		// The route is `/api/vocabulary/options`, read out of appinfo/routes.php
		// rather than guessed from the controller method name: the method is
		// `propertyOptions` and the URL is not.
		const resp = await request.get(
			`${API}/vocabulary/options?schema=${schemaId}&property=wijk`,
			{ headers: JSON_HEADERS },
		)
		// An instance whose OpenRegister predates the option builder answers
		// 404 here. That is not this app being broken, so it skips rather than
		// reddening and saying something untrue about the change under test.
		test.skip(
			resp.status() === 404,
			'this build has no property-options endpoint',
		)
		expect(resp.ok(), await resp.text()).toBeTruthy()

		const body = await resp.json()
		const rows = body.options ?? body.results ?? body
		const labels = JSON.stringify(rows)

		expect(labels).toContain('Centrum')
		expect(labels).toContain('Noord')
	})

	test('a property naming two schemes is refused, naming both spellings', async ({
		request,
	}) => {
		const resp = await request.post(SCHEMAS, {
			headers: JSON_HEADERS,
			data: {
				title: `E2E competing ${RUN_ID}`,
				slug: `e2e-competing-${RUN_ID}`,
				properties: {
					wijk: {
						type: 'string',
						conceptScheme: SCHEME_URI,
						'x-openregister-concepts': { scheme: `${SCHEME_URI}-other` },
					},
				},
			},
		})

		expect(resp.status(), await resp.text()).toBe(422)
		const text = await resp.text()
		// The sentence has to name both, or an author reads "declared twice"
		// and cannot tell which two.
		expect(text).toContain('conceptScheme')
		expect(text).toContain('x-openregister-concepts')
	})

	test('a scheme beside a literal enum is refused', async ({ request }) => {
		const resp = await request.post(SCHEMAS, {
			headers: JSON_HEADERS,
			data: {
				title: `E2E two sources ${RUN_ID}`,
				slug: `e2e-two-sources-${RUN_ID}`,
				properties: {
					wijk: {
						type: 'string',
						conceptScheme: SCHEME_URI,
						enum: ['Centrum', 'Noord'],
					},
				},
			},
		})

		expect(resp.status(), await resp.text()).toBe(422)
	})
})
