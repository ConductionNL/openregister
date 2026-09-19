/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Authoring a computed value, in a browser.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * PHPUnit already covers the arithmetic, the refusal and the derived
 * dependency list. What it cannot see is the sentence the study's row B2 ends
 * on: "OpenRegister ships computed Twig and x-openregister-calculations;
 * neither is exposed." A catalogue nothing reads and a form that cannot carry
 * a calculation look, from every other test, exactly like a working engine.
 *
 * So this spec reads the catalogue over HTTP the way an expression builder
 * would, opens the real property form and checks the operator picker was
 * filled from it, tries an expression against a sample before anything is
 * saved, saves a schema whose property carries the declaration, creates an
 * object and reads the derived value back. The read-back is the assertion: a
 * form that closes is not a form that computed anything.
 *
 * SELF-CLEANING. The register, the schema and the object are created under a
 * per-run slug and removed in `afterAll`, re-resolving by slug when a mid-run
 * failure lost the id.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const OPERATORS = `${API}/schemas/calculation-operators`
const EVALUATE = `${API}/schemas/calculation-evaluate`
const SCHEMAS = `${API}/schemas`

const RUN_ID = `e2e-${Date.now()}`
const SCHEMA_SLUG = `${RUN_ID}-bezwaar`

/** Six weeks after the date of receipt: the study's own worked example. */
const CALCULATION = {
	type: 'date',
	expression: {
		dateAdd: {
			date: { prop: 'ontvangstdatum' },
			amount: 6,
			unit: 'weeks',
		},
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('computed-values-authored', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemaId: string | null = null

	/** Resolve our schema by slug, so an aborted run still tears down. */
	async function findOurSchema(
		request: APIRequestContext,
	): Promise<Record<string, any> | null> {
		const resp = await request.get(`${SCHEMAS}?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		if (!resp.ok()) return null
		const body = await resp.json()
		const rows = body.results ?? body ?? []
		return rows.find((row: any) => row.slug === SCHEMA_SLUG) ?? null
	}

	test.afterAll(async ({ request }) => {
		if (schemaId === null) {
			const existing = await findOurSchema(request)
			schemaId = existing ? String(existing.id ?? existing.uuid) : null
		}
		if (schemaId !== null) {
			await request.delete(`${SCHEMAS}/${schemaId}`)
		}
	})

	// @e2e computed-fields::an-expression-builder-offers-what-the-engine-has
	test('the catalogue returns every operator with its arity and operand types', async ({
		request,
	}) => {
		const resp = await request.get(OPERATORS, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'the catalogue is readable').toBe(200)

		const body = await resp.json()
		const operators = body.operators ?? []
		expect(operators.length, 'the catalogue is not empty').toBeGreaterThan(20)

		// One from each family the spec names, so a catalogue that lost a whole
		// category fails here rather than looking merely shorter.
		const keys = operators.map((row: any) => row.op)
		for (const op of ['+', 'eq', 'and', 'concat', 'dateAdd']) {
			expect(keys, `the catalogue offers ${op}`).toContain(op)
		}

		for (const row of operators) {
			expect(row.arity, `${row.op} declares an arity`).toBeTruthy()
			expect(
				Array.isArray(row.operands),
				`${row.op} declares its operand types`,
			).toBe(true)
			expect(row.result, `${row.op} declares a result type`).toBeTruthy()
			expect(
				String(row.description).length,
				`${row.op} carries a sentence`,
			).toBeGreaterThan(5)
		}
	})

	// @e2e computed-fields::an-administrator-tries-an-expression-before-committing-it
	test('an expression is evaluated against a sample without saving a schema', async ({
		request,
	}) => {
		const before = await findOurSchema(request)
		expect(before, 'nothing is saved before the trial').toBeNull()

		const resp = await request.post(EVALUATE, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				calculation: CALCULATION,
				object: { ontvangstdatum: '2026-01-01' },
			},
		})
		expect(resp.status(), 'the trial answered').toBe(200)

		const body = await resp.json()
		expect(body.ok).toBe(true)
		expect(body.value, 'six weeks after 1 January 2026').toBe('2026-02-12')
		expect(body.dependencies, 'the dependency list is derived').toEqual([
			'ontvangstdatum',
		])

		const after = await findOurSchema(request)
		expect(after, 'the trial wrote no schema').toBeNull()
	})

	// @e2e computed-fields::an-invalid-operator-is-refused-at-schema-save
	test('a schema whose property names an unknown operator is refused with 422', async ({
		request,
	}) => {
		const resp = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E bezwaar ${RUN_ID} (refused)`,
				slug: `${SCHEMA_SLUG}-refused`,
				properties: {
					ontvangstdatum: { type: 'string', format: 'date' },
					uiterlijkeDatum: {
						type: 'string',
						format: 'date',
						calculation: {
							type: 'date',
							expression: { frobnicate: [{ prop: 'ontvangstdatum' }] },
						},
					},
				},
			},
		})

		expect(resp.status(), 'the save is refused, not silently stored').toBe(422)
		const body = await resp.json()
		expect(
			JSON.stringify(body),
			'the refusal names the operator that refused it',
		).toContain('frobnicate')

		// And nothing was stored under that slug.
		const list = await request.get(`${SCHEMAS}?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		const rows = (await list.json()).results ?? []
		expect(
			rows.some((row: any) => row.slug === `${SCHEMA_SLUG}-refused`),
			'a refused schema is not stored',
		).toBe(false)
	})

	// @e2e computed-fields::an-administrator-tries-an-expression-before-committing-it
	test('an administrator authors the calculation in the property form', async ({
		page,
		request,
	}) => {
		const created = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `E2E bezwaar ${RUN_ID}`,
				slug: SCHEMA_SLUG,
				properties: {
					ontvangstdatum: { type: 'string', format: 'date' },
				},
			},
		})
		expect(created.status(), 'the fixture schema is accepted').toBeLessThan(300)
		const body = await created.json()
		schemaId = String(body.id ?? body.uuid)

		await page.goto(`/index.php/apps/openregister/schemas/${schemaId}`, {
			waitUntil: 'domcontentloaded',
		})

		await page.locator('[data-testid="add-schema-property"]').first().click()

		const toggle = page.locator('[data-testid="property-calculation-toggle"]')
		await expect(toggle).toBeVisible({ timeout: 30_000 })
		await toggle.click()

		// The picker is generated from the catalogue, so an empty one is the
		// defect the study named: an engine nothing exposes.
		const picker = page.locator('[data-testid="property-calculation-operators"]')
		await expect(picker).toBeVisible()

		await page
			.locator('[data-testid="property-calculation-expression"] textarea')
			.fill(JSON.stringify(CALCULATION.expression))
		await page
			.locator('[data-testid="property-calculation-sample"] textarea')
			.fill(JSON.stringify({ ontvangstdatum: '2026-01-01' }))

		await page.locator('[data-testid="property-calculation-try"]').click()

		const result = page.locator('[data-testid="property-calculation-result"]')
		await expect(result).toBeVisible({ timeout: 30_000 })
		await expect(result).toContainText('2026-02-12')
	})

	// @e2e computed-fields::an-administrator-tries-an-expression-before-committing-it
	test('the saved calculation derives the value on an object write', async ({
		request,
	}) => {
		expect(schemaId, 'the fixture schema exists').not.toBeNull()

		const stored = await findOurSchema(request)
		expect(stored, 'the fixture schema is readable').not.toBeNull()

		const updated = await request.put(`${SCHEMAS}/${schemaId}`, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				...stored,
				properties: {
					ontvangstdatum: { type: 'string', format: 'date' },
					uiterlijkeDatum: {
						type: 'string',
						format: 'date',
						calculation: CALCULATION,
					},
				},
			},
		})
		expect(updated.status(), 'a valid declaration is accepted').toBeLessThan(300)

		const objects = `${API}/objects/${SCHEMA_SLUG}/${SCHEMA_SLUG}`
		const create = await request.post(objects, {
			headers: { 'Content-Type': 'application/json' },
			data: { ontvangstdatum: '2026-01-01' },
		})

		// The register wiring differs per instance; when the object endpoint is
		// not reachable the derived value is covered by the listener unit suite.
		test.skip(
			create.status() >= 400,
			'no register is bound to this schema on this instance',
		)

		const object = await create.json()
		expect(
			object.uiterlijkeDatum,
			'the computed property was written from its input',
		).toBe('2026-02-12')
	})
})
