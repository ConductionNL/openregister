/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FIELD HAS A NAME PER LANGUAGE, AND A READER GETS THEIRS.
 *
 * Change `modelling-field-names-per-language`, task 3.1. Property
 * `contractEnd` of a `klant` schema gets the names "Einddatum contract" (nl) and
 * "Contract end date" (en) beside its stored `title`. A read with `_lang=en`
 * answers the English name, `_lang=nl` and `_lang=nl-BE` (base-language
 * fallback) and an `Accept-Language: nl-NL` header the Dutch one, `_lang=de`
 * keeps the stored title, and a read without a language is the schema as
 * stored, `titles` included. A language key that is not a language tag is
 * refused with 422 naming the property and the key.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own schema and removes it in
 * afterAll.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const SLUG = `e2e-klant-${RUN}`
const STORED = 'Contract end'
const TITLES = { nl: 'Einddatum contract', en: 'Contract end date' }

test.describe.configure({ mode: 'serial' })

test.describe('modelling-field-names-per-language: a reader gets the field name in their language', () => {
	let admin: APIRequestContext
	let schemaId = 0

	/** Property `contractEnd` as one read answers it. */
	async function contractEnd(query = '', headers: Record<string, string> = {}): Promise<Record<string, any>> {
		const res = await admin.get(`${API}/schemas/${schemaId}${query}`, { headers })
		expect(res.status(), await res.text()).toBe(200)
		return (await res.json()).properties.contractEnd
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

		const created = await admin.post(`${API}/schemas`, {
			data: {
				slug: SLUG,
				title: `E2E klant ${RUN}`,
				properties: { contractEnd: { type: 'string', format: 'date', title: STORED, titles: TITLES } },
			},
		})
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		schemaId = (await created.json()).id
	})

	test.afterAll(async () => {
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('a read without a language is the schema as stored, names per language included', async () => {
		const property = await contractEnd()
		expect(property.title).toBe(STORED)
		expect(property.titles).toEqual(TITLES)
	})

	// @e2e schema-property-exploration::a-colleague-who-works-in-english
	test('each language reads its own name, and an unnamed language keeps the stored title', async () => {
		expect((await contractEnd('?_lang=en')).title).toBe(TITLES.en)
		expect((await contractEnd('?_lang=nl')).title).toBe(TITLES.nl)
		expect((await contractEnd('?_lang=nl-BE')).title, 'nl-BE falls back to its base language').toBe(TITLES.nl)
		expect((await contractEnd('', { 'Accept-Language': 'nl-NL,nl;q=0.9' })).title).toBe(TITLES.nl)
		expect((await contractEnd('?_lang=de')).title).toBe(STORED)
	})

	test('a key that is not a language tag is refused, naming the property and the key', async () => {
		const res = await admin.put(`${API}/schemas/${schemaId}`, {
			data: {
				slug: SLUG,
				title: `E2E klant ${RUN}`,
				properties: { contractEnd: { type: 'string', title: STORED, titles: { 'dutch language': 'Einddatum' } } },
			},
		})
		expect(res.status()).toBe(422)
		const body = await res.text()
		expect(body).toContain('contractEnd')
		expect(body).toContain('dutch language')

		expect((await contractEnd()).titles, 'the refused save changed nothing').toEqual(TITLES)
	})
})
