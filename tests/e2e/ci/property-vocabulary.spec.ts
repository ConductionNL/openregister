/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The property vocabulary, read the way an editor would read it.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * PHPUnit already pins the published list to the validator, the refusals and
 * the narrowing arithmetic. What it cannot see is the sentence the study ends
 * on: "the field vocabulary is eight types wide and OpenRegister already has
 * twenty". A vocabulary no endpoint answers with, and a declaration nothing
 * validates, look from every unit test exactly like a published contract.
 *
 * So this spec reads the vocabulary over HTTP the way a generated property
 * editor would, saves a schema that uses a constraint key from it, writes an
 * object that breaks that constraint and reads the refusal, tries a type the
 * vocabulary does not hold and reads the 422 that names it, and finally reads
 * a declared narrowing back and counts what the app's form leaves out.
 *
 * SELF-CLEANING. The schemas are created under a per-run slug and removed in
 * `afterAll`, re-resolving by slug when a mid-run failure lost the id.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const VOCABULARY = `${API}/schemas/property-vocabulary`
const EXTENDING_FORMS = `${API}/schemas/extending-forms`
const SCHEMAS = `${API}/schemas`

const RUN_ID = `e2e-${Date.now()}`
const SCHEMA_SLUG = `${RUN_ID}-zaaktype`

/**
 * The map the shipped consumer reads, copied from `DEFAULT_MAP` in
 * `propertiesFromDefinitions` (`@conduction/nextcloud-vue`): the vocabulary
 * role on the left, the app's own field name on the right.
 */
const SHIPPED_FORM = {
	app: 'dossiq',
	form: 'property-definition-management',
	definitions: 'caseTypeFieldDefinition',
	map: {
		title: 'name',
		description: 'description',
		type: 'propertyType',
		enum: 'enumValues',
		required: 'isRequired',
		default: 'defaultValue',
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('property-vocabulary', () => {
	test.use({ storageState: STORAGE_STATE })

	/** Resolve a schema of ours by slug, so an aborted run still tears down. */
	async function findBySlug(
		request: APIRequestContext,
		slug: string,
	): Promise<Record<string, any> | null> {
		const resp = await request.get(`${SCHEMAS}?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		if (!resp.ok()) return null
		const body = await resp.json()
		const rows = body.results ?? body ?? []
		return rows.find((row: any) => row.slug === slug) ?? null
	}

	test.afterAll(async ({ request }) => {
		for (const slug of [
			SCHEMA_SLUG,
			`${SCHEMA_SLUG}-refused`,
			`${SCHEMA_SLUG}-narrowed`,
			`${SCHEMA_SLUG}-bad-declaration`,
		]) {
			const existing = await findBySlug(request, slug)
			if (existing) {
				await request.delete(
					`${SCHEMAS}/${String(existing.id ?? existing.uuid)}`,
				)
			}
		}
	})

	// @e2e runtime-schema-api::an-editor-is-generated-rather-than-typed
	test('the vocabulary returns every type with its constraint keys and formats', async ({
		request,
	}) => {
		const resp = await request.get(VOCABULARY, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'the vocabulary is readable').toBe(200)

		const body = await resp.json()
		const types = body.types ?? []

		// The study counted eight types in a leaf editor against this layer.
		// Anything near eight here means the endpoint is answering a shorter
		// list than the validator holds, which is the bug it exists to close.
		expect(
			types.length,
			'the vocabulary is the full list, not an editor short list',
		).toBeGreaterThan(15)

		const names = types.map((row: any) => row.type)
		// One per family, so a vocabulary that lost a whole category fails here
		// rather than looking merely shorter.
		for (const type of [
			'string',
			'integer',
			'array',
			'object',
			'file',
			'geo',
			'color',
			'NcFile',
		]) {
			expect(names, `the vocabulary offers ${type}`).toContain(type)
		}

		for (const row of types) {
			expect(
				Array.isArray(row.constraints),
				`${row.type} declares its constraint keys`,
			).toBe(true)
			expect(
				row.constraints.length,
				`${row.type} takes at least one constraint key`,
			).toBeGreaterThan(0)
			expect(
				Array.isArray(row.formats),
				`${row.type} declares its formats`,
			).toBe(true)
			expect(
				['supported', 'conditional', 'unsupported'],
				`${row.type} says what converting a populated property to it costs`,
			).toContain(row.conversion)
			expect(
				String(row.description).length,
				`${row.type} carries a sentence`,
			).toBeGreaterThan(5)
		}

		const stringRow = types.find((row: any) => row.type === 'string')
		expect(
			stringRow.formats,
			'string offers the formats the validator takes',
		).toContain('bsn')
		expect(stringRow.constraints, 'string takes pattern').toContain('pattern')

		expect(body.keys, 'the flat key list is published too').toContain('pattern')
		expect(body.vendorExtensionPrefix, 'vendor extensions are named').toBe('x-')
	})

	// @e2e runtime-schema-api::a-typo-does-not-become-an-untyped-string
	test('a schema declaring a type the vocabulary does not hold is refused with 422', async ({
		request,
	}) => {
		const resp = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E zaaktype ${RUN_ID} (refused)`,
				slug: `${SCHEMA_SLUG}-refused`,
				properties: {
					naam: { type: 'sting' },
				},
			},
		})

		expect(
			resp.status(),
			'the save is refused, not silently stored as text',
		).toBe(422)
		const body = await resp.json()
		expect(
			JSON.stringify(body),
			'the refusal names the type that refused it',
		).toContain('sting')

		const stored = await findBySlug(request, `${SCHEMA_SLUG}-refused`)
		expect(stored, 'a refused schema is not stored').toBeNull()
	})

	// @e2e runtime-schema-api::an-editor-is-generated-rather-than-typed
	test('a property authored with a constraint key from the vocabulary enforces it', async ({
		request,
	}) => {
		const vocabulary = await (
			await request.get(VOCABULARY, {
				headers: { Accept: 'application/json' },
			})
		).json()

		// Author the property the way a generated editor would: take the key
		// from the published list rather than from a hand-written one.
		expect(vocabulary.keys, 'pattern is a key the editor may offer').toContain(
			'pattern',
		)

		const created = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E zaaktype ${RUN_ID}`,
				slug: SCHEMA_SLUG,
				properties: {
					kenmerk: {
						type: 'string',
						pattern: '^ZAAK-[0-9]{4}$',
						title: 'Kenmerk',
					},
				},
			},
		})
		expect(
			created.status(),
			'a property using a published key is accepted',
		).toBeLessThan(300)

		const objects = `${API}/objects/${SCHEMA_SLUG}/${SCHEMA_SLUG}`
		const refused = await request.post(objects, {
			headers: { 'Content-Type': 'application/json' },
			data: { kenmerk: 'niet-een-kenmerk' },
		})

		// The register wiring differs per instance. When the object endpoint is
		// not reachable at all the constraint is covered by the unit suite; a
		// 404 on the collection is not the refusal this test is looking for.
		test.skip(
			refused.status() === 404,
			'no register is bound to this schema on this instance',
		)

		expect(
			refused.status(),
			'a value that breaks the published constraint is refused',
		).toBeGreaterThanOrEqual(400)
		expect(
			JSON.stringify(await refused.json()),
			'the refusal names the property that refused it',
		).toContain('kenmerk')
	})

	// @e2e runtime-schema-api::a-narrower-editor-is-a-stated-narrowing
	test('a declared extending form is readable and its narrowing is countable', async ({
		request,
	}) => {
		const created = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E zaaktype ${RUN_ID} (narrowed)`,
				slug: `${SCHEMA_SLUG}-narrowed`,
				properties: {
					zaaktype: {
						type: 'string',
						title: 'Zaaktype',
						'x-openregister-extends-form': SHIPPED_FORM,
					},
				},
			},
		})
		expect(created.status(), 'a valid declaration is accepted').toBeLessThan(300)

		const resp = await request.get(EXTENDING_FORMS, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'the declared narrowings are readable').toBe(200)

		const body = await resp.json()
		const ours = (body.declarations ?? []).find(
			(row: any) => row.schema === `${SCHEMA_SLUG}-narrowed`,
		)
		expect(ours, 'our declaration is listed').toBeTruthy()

		expect(ours.forwards, 'the six forwarded keys are listed').toEqual([
			'title',
			'description',
			'type',
			'enum',
			'required',
			'default',
		])
		expect(ours.app, 'the declaration names the app that forwards').toBe(
			'dossiq',
		)

		// The narrowing is the number the study was counting.
		expect(ours.counts.forwards).toBe(6)
		expect(
			ours.counts.narrows,
			'what the form leaves out is derivable, not guessed',
		).toBe(ours.counts.vocabulary - 6)
		expect(
			ours.narrows,
			'pattern is one of the keys this form leaves out',
		).toContain('pattern')
	})

	// @e2e runtime-schema-api::a-narrower-editor-is-a-stated-narrowing
	test('a declaration forwarding a key nobody defines is refused with 422', async ({
		request,
	}) => {
		const resp = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E zaaktype ${RUN_ID} (bad declaration)`,
				slug: `${SCHEMA_SLUG}-bad-declaration`,
				properties: {
					zaaktype: {
						type: 'string',
						'x-openregister-extends-form': {
							app: 'dossiq',
							definitions: 'caseTypeFieldDefinition',
							map: { fieldKind: 'propertyType' },
						},
					},
				},
			},
		})

		expect(resp.status(), 'the declaration is refused, not stored').toBe(422)
		expect(
			JSON.stringify(await resp.json()),
			'the refusal names the role nobody defines',
		).toContain('fieldKind')
	})
})
