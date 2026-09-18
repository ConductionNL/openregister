/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reference property narrows its choices with a query over the record.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * The declaration, the resolver and the reader are covered by PHPUnit against
 * properties somebody wrote by hand in a test. What no unit test can see is
 * whether the ENDPOINT exists, is routed, is reachable to a non-admin, and
 * returns what the resolver decided.
 *
 * That gap is not hypothetical here. An earlier spec in this change guessed the
 * URL `/api/vocabulary/property-options` from a controller method name; the real
 * route was `/api/vocabulary/options`, and the wrong URL would have 404'd and
 * been skipped by the suite's own old-build guard, reporting green while
 * asserting nothing. So this spec asserts the STATUS of every call.
 *
 * 🔑 THE CENTRAL ASSERTION IS THE NEGATIVE ONE. "No options" must never become
 * "every option": with no organisation chosen, the picker must return an EMPTY
 * list and name what it needs, not the whole contact register. The control is
 * the same request WITH an organisation, which must return the one contact that
 * belongs to it and not the one that does not.
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

test.describe('reference-options', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId: number | null = null
	let contactSchemaId: number | null = null
	let caseSchemaId: number | null = null
	let orgA: string | null = null
	let contactInA: string | null = null
	let contactInB: string | null = null
	let caseId: string | null = null

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
			title: `E2E reference options ${RUN_ID}`,
			description: 'Filtered reference options.',
		})
		registerId = register.id ?? register['@self']?.id

		const contactSchema = await create(request, SCHEMAS, {
			title: `E2E contact ${RUN_ID}`,
			properties: {
				name: { type: 'string' },
				organisation: { type: 'string' },
			},
		})
		contactSchemaId = contactSchema.id ?? contactSchema['@self']?.id

		const caseSchema = await create(request, SCHEMAS, {
			title: `E2E case ${RUN_ID}`,
			properties: {
				organisation: { type: 'string' },
				contact: {
					type: 'string',
					$ref: String(contactSchemaId),
					'x-openregister-reference-filter': [
						{ field: 'organisation', op: 'eq', from: 'organisation' },
					],
				},
			},
		})
		caseSchemaId = caseSchema.id ?? caseSchema['@self']?.id

		const contacts = `${API}/objects/${registerId}/${contactSchemaId}`
		orgA = `org-a-${RUN_ID}`
		const a = await create(request, contacts, { name: 'Ada', organisation: orgA })
		contactInA = a['@self']?.id ?? a.id ?? a.uuid
		const b = await create(request, contacts, { name: 'Bob', organisation: `org-b-${RUN_ID}` })
		contactInB = b['@self']?.id ?? b.id ?? b.uuid

		// A case with NO organisation chosen yet: the state a picker opens in.
		const made = await create(request, `${API}/objects/${registerId}/${caseSchemaId}`, {})
		caseId = made['@self']?.id ?? made.id ?? made.uuid
	})

	test.afterAll(async ({ request }) => {
		for (const [url, id] of [
			[SCHEMAS, caseSchemaId],
			[SCHEMAS, contactSchemaId],
			[REGISTERS, registerId],
		] as [string, number | null][]) {
			if (id !== null) {
				await request.delete(`${url}/${id}`, { headers: JSON_HEADERS })
			}
		}
	})

	test('the endpoint exists and is routed', async ({ request }) => {
		// Asserted on its own, because a 404 from a wrong URL would otherwise be
		// indistinguishable from a filter that returned nothing.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseId}/reference-options?property=contact`,
			{ headers: JSON_HEADERS },
		)

		expect(resp.status(), await resp.text()).toBe(200)
	})

	test('no organisation chosen means no options, and it says what it needs', async ({ request }) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseId}/reference-options?property=contact`,
			{ headers: JSON_HEADERS },
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const body = await resp.json()

		expect(
			uuidsOf(body),
			'No options must never become every option: an unresolved filter cannot offer the whole register.',
		).toEqual([])
		expect(body.needs).toContain('organisation')
	})

	test('with an organisation, only its contacts are offered', async ({ request }) => {
		// The control for the test above. Without it, "empty" could be passing
		// because the endpoint never returns anything.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseId}/reference-options`
			+ `?property=contact&_draft[organisation]=${encodeURIComponent(String(orgA))}`,
			{ headers: JSON_HEADERS },
		)
		expect(resp.status(), await resp.text()).toBe(200)

		const body = await resp.json()
		const uuids = uuidsOf(body)

		expect(uuids).toContain(contactInA)
		expect(
			uuids,
			'A contact of another organisation was offered, so the filter was not applied.',
		).not.toContain(contactInB)
		expect(body.needs).toEqual([])
	})

	test('naming no property is refused rather than answered', async ({ request }) => {
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseId}/reference-options`,
			{ headers: JSON_HEADERS },
		)

		expect(resp.status()).toBe(400)
	})

	test('a property that is not on the schema is refused', async ({ request }) => {
		// Answering "no options" for a typo reads exactly like a filter waiting
		// on an operand, and would send somebody looking for the wrong bug.
		const resp = await request.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseId}/reference-options?property=nope`,
			{ headers: JSON_HEADERS },
		)

		expect(resp.status()).toBe(422)
	})

	test('a write outside the filter is still refused, so the picker and the save agree', async ({ request }) => {
		// The two halves of one rule. If this ever diverges from the options
		// above, one of them is a second evaluator.
		const resp = await request.post(
			`${API}/objects/${registerId}/${caseSchemaId}`,
			{
				headers: JSON_HEADERS,
				data: { organisation: orgA, contact: contactInB },
			},
		)

		expect(
			resp.status(),
			'The picker would not have offered this contact, so the save must not accept it.',
		).toBeGreaterThanOrEqual(400)
	})
})
