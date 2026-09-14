/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Retiring a code-list value, and filtering a list by a branch of it.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The window arithmetic, the branch walk and the refusal messages are all
 * covered by PHPUnit against fixtures. What no unit test can see is whether
 * the two halves of this change agree ACROSS a real write: whether the value
 * a picker stops offering is the same value the record beside it still reads
 * back with its label, through the real register, the real magic table and
 * the real event listeners.
 *
 * That pair is the whole design (design.md D-1) and it is exactly the pair a
 * mocked repository cannot break: a mock returns whatever both halves asked
 * for. Here the concept is written once, through the objects API, and then
 * one half is asked to refuse it while the other is asked to resolve it.
 *
 * SELF-CLEANING. Everything is created under a per-run uri prefix and removed
 * in `afterAll`, re-resolving by that prefix when a mid-run failure lost an
 * id. Nothing here touches the seeded TOOI schemes, which other suites read.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const VOCAB_SCHEMES = `${API}/objects/vocabulary/conceptScheme`
const VOCAB_CONCEPTS = `${API}/objects/vocabulary/concept`
const SCHEMAS = `${API}/schemas`

const RUN_ID = `e2e-${Date.now()}`
const SCHEME_URI = `urn:e2e:${RUN_ID}:vergunningen`

/** Concept uris, all carrying the run id so a stray row is recognisable. */
const URI_VERGUNNING = `urn:e2e:${RUN_ID}:vergunning`
const URI_KAP = `urn:e2e:${RUN_ID}:kapvergunning`
const URI_INGETROKKEN = `urn:e2e:${RUN_ID}:ingetrokken`
const URI_SPOED = `urn:e2e:${RUN_ID}:spoed`
const URI_REGULIER = `urn:e2e:${RUN_ID}:regulier`
const URI_SYSTEM = `urn:e2e:${RUN_ID}:systeem`

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' }

test.describe.configure({ mode: 'serial' })

test.describe('code-list-lifecycle', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemeId: string | null = null
	const conceptIds: Record<string, string> = {}
	let caseSchemaId: number | null = null
	const objectIds: string[] = []

	/** Create one object and remember its id for teardown. */
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
		// The scheme, declaring the shape its concepts carry.
		const scheme = await create(request, VOCAB_SCHEMES, {
			uri: SCHEME_URI,
			title: `E2E vergunningen ${RUN_ID}`,
			publisher: 'e2e',
			version: '1.0.0',
			source: SCHEME_URI,
			exclusiveGroups: ['urgentie'],
		})
		schemeId = scheme.id ?? scheme['@self']?.id ?? scheme.uuid

		const concept = async (body: Record<string, unknown>) => {
			const made = await create(request, VOCAB_CONCEPTS, {
				inScheme: schemeId,
				...body,
			})
			conceptIds[String(body.uri)] = made.id ?? made['@self']?.id ?? made.uuid
			return made
		}

		await concept({
			uri: URI_VERGUNNING,
			prefLabel: { nl: 'Vergunning' },
			notation: 'V',
			narrower: [],
		})
		await concept({
			uri: URI_KAP,
			prefLabel: { nl: 'Kapvergunning' },
			notation: 'VK',
			broader: [conceptIds[URI_VERGUNNING]],
			contexts: ['bezwaar'],
		})
		// Created with an OPEN window on purpose. The record below is written
		// while the value is live, and the value is retired afterwards: that
		// is the real sequence a gemeente performs, and it is the only one
		// that produces a record holding a value nobody may choose today.
		await concept({
			uri: URI_INGETROKKEN,
			prefLabel: { nl: 'Ingetrokken' },
			notation: 'ING',
			validFrom: '2019-01-01',
		})
		await concept({ uri: URI_SPOED, prefLabel: { nl: 'Spoed' }, exclusiveGroup: 'urgentie' })
		await concept({ uri: URI_REGULIER, prefLabel: { nl: 'Regulier' }, exclusiveGroup: 'urgentie' })
		await concept({ uri: URI_SYSTEM, prefLabel: { nl: 'Systeemwaarde' }, systemDefined: true })

		// A schema whose properties bind to that scheme, declare a semantic
		// role, and declare both uniqueness actions.
		const created = await create(request, `${SCHEMAS}?register=vocabulary`, {
			title: `E2E zaak ${RUN_ID}`,
			properties: {
				onderwerp: { type: 'string', 'x-openregister-role': 'title' },
				fase: { type: 'string', 'x-openregister-role': 'status' },
				categorie: {
					type: 'string',
					'x-openregister-concepts': {
						scheme: SCHEME_URI,
						store: 'uri',
						contextProperty: 'zaaktype',
					},
				},
				urgentie: {
					type: 'array',
					'x-openregister-concepts': { scheme: SCHEME_URI, store: 'uri' },
				},
				zaaktype: { type: 'string' },
				besluit: { type: 'string' },
				indiener: { type: 'string' },
				email: { type: 'string' },
			},
			configuration: {
				uniqueConstraints: [
					{ name: 'een-bezwaar', properties: ['besluit', 'indiener'], action: 'refuse' },
					{ name: 'dubbel-adres', properties: ['email'], action: 'report' },
				],
			},
		})
		caseSchemaId = created.id
	})

	test.afterAll(async ({ request }) => {
		for (const id of objectIds) {
			await request
				.delete(`${API}/objects/vocabulary/${caseSchemaId}/${id}`, { headers: JSON_HEADERS })
				.catch(() => undefined)
		}
		for (const id of Object.values(conceptIds)) {
			await request
				.delete(`${VOCAB_CONCEPTS}/${id}`, { headers: JSON_HEADERS })
				.catch(() => undefined)
		}
		if (schemeId) {
			await request
				.delete(`${VOCAB_SCHEMES}/${schemeId}`, { headers: JSON_HEADERS })
				.catch(() => undefined)
		}
		if (caseSchemaId) {
			await request
				.delete(`${SCHEMAS}/${caseSchemaId}`, { headers: JSON_HEADERS })
				.catch(() => undefined)
		}
	})

	// @e2e skos-concept-registers::a-retired-value-keeps-working-on-old-records
	test('a record written while the value was live keeps reading it back', async ({ request }) => {
		const objects = `${API}/objects/vocabulary/${caseSchemaId}`

		// 1. The value is live, so the record can be written. Asserted, not
		//    assumed: if this write were refused the rest of the test would
		//    still pass while proving nothing.
		const before = await request.post(objects, {
			headers: JSON_HEADERS,
			data: { onderwerp: `Oud dossier ${RUN_ID}`, categorie: URI_INGETROKKEN },
		})
		expect(before.status(), await before.text()).toBeLessThan(300)
		const oldRecord = await before.json()
		const oldRecordId = oldRecord.id ?? oldRecord['@self']?.id
		objectIds.push(oldRecordId)

		// ... and it IS offered while it is live, which is the control that
		// separates "retired" below from "never offered at all".
		const whileLive = await request.get(
			`${API}/vocabulary/options?schema=${caseSchemaId}&property=categorie`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(whileLive.ok()).toBeTruthy()
		expect(
			((await whileLive.json()).results ?? []).map((row: any) => row.value),
		).toContain(URI_INGETROKKEN)

		// 2. The gemeente retires the value by closing its window.
		const retire = await request.put(`${VOCAB_CONCEPTS}/${conceptIds[URI_INGETROKKEN]}`, {
			headers: JSON_HEADERS,
			data: {
				uri: URI_INGETROKKEN,
				prefLabel: { nl: 'Ingetrokken' },
				notation: 'ING',
				inScheme: schemeId,
				validFrom: '2019-01-01',
				validUntil: '2020-12-31',
			},
		})
		expect(retire.status(), await retire.text()).toBeLessThan(300)

		// 3. The old record still reads the value back, with its label.
		const readBack = await request.get(`${objects}/${oldRecordId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(readBack.ok()).toBeTruthy()
		expect((await readBack.json()).categorie).toBe(URI_INGETROKKEN)

		const resolved = await request.get(
			`${API}/vocabulary/concept?uri=${encodeURIComponent(URI_INGETROKKEN)}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resolved.ok(), 'a retired value still resolves').toBeTruthy()
		expect((await resolved.json()).prefLabel.nl).toBe('Ingetrokken')

		// 4. ... and the picker beside it no longer offers it.
		const afterRetiring = await request.get(
			`${API}/vocabulary/options?schema=${caseSchemaId}&property=categorie`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(afterRetiring.ok()).toBeTruthy()
		const offered = ((await afterRetiring.json()).results ?? []).map((row: any) => row.value)
		expect(offered).not.toContain(URI_INGETROKKEN)
		expect(offered, 'and the rest of the scheme is untouched').toContain(URI_KAP)
	})

	// @e2e skos-concept-registers::a-retired-value-cannot-be-written-today
	test('a retired value cannot be written today', async ({ request }) => {
		const resp = await request.post(`${API}/objects/vocabulary/${caseSchemaId}`, {
			headers: JSON_HEADERS,
			data: { onderwerp: 'Nieuw dossier', categorie: URI_INGETROKKEN },
		})

		expect(resp.status()).toBe(422)
		const body = await resp.text()
		expect(body).toContain('Ingetrokken')
		expect(body).toContain('2020-12-31')
	})

	// @e2e skos-concept-registers::two-concepts-of-one-exclusive-group-are-refused
	test('two values of one exclusive group are refused', async ({ request }) => {
		const resp = await request.post(`${API}/objects/vocabulary/${caseSchemaId}`, {
			headers: JSON_HEADERS,
			data: { onderwerp: 'Beide', urgentie: [URI_SPOED, URI_REGULIER] },
		})

		expect(resp.status()).toBe(422)
		const body = await resp.text()
		expect(body).toContain('urgentie')
		expect(body).toContain('Spoed')
		expect(body).toContain('Regulier')
	})

	// @e2e skos-concept-registers::filtering-by-a-branch-finds-the-leaves
	test('filtering a list by a branch returns the objects holding a narrower value', async ({
		request,
	}) => {
		const objects = `${API}/objects/vocabulary/${caseSchemaId}`
		const made = await create(request, objects, {
			onderwerp: `Kapzaak ${RUN_ID}`,
			categorie: URI_KAP,
		})
		objectIds.push(made.id ?? made['@self']?.id)

		const listed = await request.get(
			`${objects}?categorie[branch]=${encodeURIComponent(URI_VERGUNNING)}&_limit=50`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(listed.ok()).toBeTruthy()
		const body = await listed.json()
		const subjects = (body.results ?? []).map((row: any) => row.onderwerp)
		expect(subjects).toContain(`Kapzaak ${RUN_ID}`)
	})

	// @e2e skos-concept-registers::one-property-serves-two-case-types-with-different-values
	test('one property serves two case types with different values', async ({ request }) => {
		const read = async (context: string) => {
			const resp = await request.get(
				`${API}/vocabulary/options?schema=${caseSchemaId}&property=categorie&context=${context}`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(resp.ok()).toBeTruthy()
			const body = await resp.json()
			return (body.results ?? []).map((row: any) => row.value)
		}

		// `categorie` binds its subset to the value of `zaaktype`, and
		// `kapvergunning` is the one concept that names a context.
		const forBezwaar = await read('bezwaar')
		const forMelding = await read('melding')

		expect(forBezwaar).toContain(URI_KAP)
		expect(forMelding).not.toContain(URI_KAP)
		expect(
			forMelding,
			'a concept naming no context still serves every context',
		).toContain(URI_VERGUNNING)
	})

	// @e2e runtime-schema-api::one-list-component-serves-an-unknown-schema
	test('the schema read returns which property means the title and the status', async ({
		request,
	}) => {
		const resp = await request.get(`${SCHEMAS}/${caseSchemaId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.ok()).toBeTruthy()
		const body = await resp.json()

		expect(body['@self'].roles).toEqual(
			expect.objectContaining({ title: 'onderwerp', status: 'fase' }),
		)
	})

	// @e2e runtime-schema-api::a-second-bezwaar-on-one-besluit-is-refused
	test('a second bezwaar on one besluit is refused', async ({ request }) => {
		const objects = `${API}/objects/vocabulary/${caseSchemaId}`
		const first = await create(request, objects, {
			onderwerp: `Bezwaar ${RUN_ID}`,
			besluit: `B-${RUN_ID}`,
			indiener: `P-${RUN_ID}`,
		})
		objectIds.push(first.id ?? first['@self']?.id)

		const second = await request.post(objects, {
			headers: JSON_HEADERS,
			data: {
				onderwerp: `Bezwaar twee ${RUN_ID}`,
				besluit: `B-${RUN_ID}`,
				indiener: `P-${RUN_ID}`,
			},
		})

		expect(second.status()).toBe(422)
		const body = await second.text()
		expect(body).toContain('besluit')
		expect(body).toContain('indiener')
	})

	// @e2e runtime-schema-api::a-reporting-constraint-does-not-block-the-intake
	test('a reporting constraint does not block the intake and records the breach', async ({
		request,
	}) => {
		const objects = `${API}/objects/vocabulary/${caseSchemaId}`
		const email = `dubbel-${RUN_ID}@example.org`

		const first = await create(request, objects, { onderwerp: 'Eerste', email })
		objectIds.push(first.id ?? first['@self']?.id)

		const second = await request.post(objects, {
			headers: JSON_HEADERS,
			data: { onderwerp: 'Tweede', email },
		})
		expect(second.status(), 'a report constraint never blocks the intake').toBeLessThan(300)

		const created = await second.json()
		const secondId = created.id ?? created['@self']?.id
		objectIds.push(secondId)

		const readBack = await request.get(`${objects}/${secondId}`, {
			headers: { Accept: 'application/json' },
		})
		expect(readBack.ok()).toBeTruthy()
		const body = await readBack.json()
		const breaches = body['@self']?.validation?.uniqueness ?? []
		expect(breaches.length, 'the breach is recorded where it can be read').toBeGreaterThan(0)
		expect(breaches[0].constraint).toBe('dubbel-adres')
	})

	// @e2e runtime-schema-api::an-administrator-sees-what-a-conversion-would-cost
	test('an administrator sees what a conversion would cost', async ({ request }) => {
		const resp = await request.post(`${SCHEMAS}/${caseSchemaId}/conversions/preview`, {
			headers: JSON_HEADERS,
			data: { property: 'onderwerp', to: 'number' },
		})

		expect(resp.ok()).toBeTruthy()
		const body = await resp.json()
		expect(body.supported).toBe(true)
		expect(body.total).toBeGreaterThan(0)
		expect(body.rejected, 'the subjects written above are not numeric').toBeGreaterThan(0)
		expect(Array.isArray(body.samples)).toBe(true)
		expect(body.samples.length, 'and the ones that do not convert are named').toBeGreaterThan(0)
	})

	// @e2e skos-concept-registers::a-system-defined-value-cannot-be-deleted
	test('a system-defined value cannot be deleted', async ({ request }) => {
		const resp = await request.delete(`${VOCAB_CONCEPTS}/${conceptIds[URI_SYSTEM]}`, {
			headers: JSON_HEADERS,
		})

		expect(resp.status()).toBeGreaterThanOrEqual(400)
		const body = await resp.text()
		expect(body).toContain('validity window')
	})

	// @e2e skos-concept-registers::a-value-in-use-cannot-be-deleted-and-the-refusal-names-the-count
	test('a value in use cannot be deleted, and the refusal names the count', async ({
		request,
	}) => {
		// The kapvergunning object created above still holds this value.
		const resp = await request.delete(`${VOCAB_CONCEPTS}/${conceptIds[URI_KAP]}`, {
			headers: JSON_HEADERS,
		})

		expect(resp.status()).toBeGreaterThanOrEqual(400)
		const body = await resp.text()
		expect(body).toMatch(/held by \d+ object/)
		expect(body).toContain('validity window')
	})
})
