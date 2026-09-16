/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Field rules per status, over the API.
 *
 * A case type says what a status hides, freezes and demands. This asserts the
 * half no unit test can: that the refusal reaches a client through the real
 * save path, with the field and the status named, and that the object it
 * refused is still in the status it started in.
 *
 * ⚠️ THE REFUSAL IS THE ASSERTION, NOT THE STATUS CODE ALONE. A save can fail
 * for a dozen reasons, and a test that only reads `not ok` passes when the
 * schema failed to install. Every refusal below is read for the field it
 * names, and every allowed save is read back to prove the write landed.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json' }

const RUN = `e2e-${Date.now()}`

/**
 * The case type the scenarios are written against.
 *
 * `besloten` is reachable three ways on purpose: one entry condition has to
 * guard all three, and a test that only walked one edge could not tell an
 * entry condition from a transition condition on that one edge.
 */
const LIFECYCLE = {
	field: 'status',
	initial: 'open',
	transitions: {
		besluiten: { from: ['open'], to: 'besloten' },
		besluitenNaBezwaar: { from: ['bezwaar'], to: 'besloten' },
		besluitenNaHerstel: { from: ['herstel'], to: 'besloten' },
		sluiten: { from: ['open', 'besloten'], to: 'gesloten' },
	},
	states: {
		open: {
			fields: {
				required: [
					{
						fields: ['motivering'],
						when: { '>': [{ var: 'object.bedrag' }, 50000] },
					},
				],
			},
		},
		besloten: {
			entry: { '!!': { var: 'object.besluit' } },
		},
		gesloten: {
			fields: {
				required: [{ fields: ['outcome'] }],
			},
		},
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('field rules by state', () => {
	test.use({ storageState: STORAGE_STATE })

	let registerId = ''
	let schemaId = ''
	const created: string[] = []

	/** Create an object and remember it for teardown. */
	async function create(
		request: APIRequestContext,
		data: Record<string, unknown>,
	): Promise<Record<string, any>> {
		const resp = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
			headers: JSON_HEADERS,
			data,
		})
		expect(resp.ok(), `object create failed: ${await resp.text()}`).toBeTruthy()
		const body = await resp.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		created.push(uuid)
		return { uuid, body }
	}

	test.beforeAll(async ({ request }) => {
		const reg = await request.post(`${API}/registers`, {
			headers: JSON_HEADERS,
			data: { title: `field rules register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `field rules schema ${RUN}`,
				description: 'e2e',
				properties: {
					status: {
						type: 'string',
						title: 'Status',
						enum: ['open', 'bezwaar', 'herstel', 'besloten', 'gesloten'],
					},
					onderwerp: {
						type: 'string',
						title: 'Onderwerp',
						maxLength: 255,
					},
					bedrag: { type: 'number', title: 'Bedrag' },
					motivering: {
						type: 'string',
						title: 'Motivering',
						maxLength: 255,
					},
					besluit: { type: 'string', title: 'Besluit', maxLength: 255 },
					outcome: { type: 'string', title: 'Outcome', maxLength: 255 },
				},
				'x-openregister-lifecycle': LIFECYCLE,
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of created) {
			if (!uuid) continue
			await request.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await request.delete(`${API}/deleted/${uuid}`)
		}

		if (schemaId) await request.delete(`${API}/schemas/${schemaId}`)
		if (registerId) await request.delete(`${API}/registers/${registerId}`)
	})

	// @e2e row-field-level-security::closing-without-an-outcome-is-refused
	test('closing without an outcome is refused and the object stays open', async ({
		request,
	}) => {
		const { uuid } = await create(request, {
			status: 'open',
			onderwerp: 'Zonder resultaat sluiten',
			bedrag: 100,
		})

		const close = await request.put(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: {
					status: 'gesloten',
					onderwerp: 'Zonder resultaat sluiten',
					bedrag: 100,
				},
			},
		)

		expect(close.status(), 'the close was refused').toBe(422)
		const refusal = await close.text()
		expect(refusal, 'the refusal names the field').toContain('outcome')
		expect(refusal, 'the refusal names the status').toContain('gesloten')

		const readBack = await request.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(readBack.ok()).toBeTruthy()
		expect((await readBack.json()).status, 'the object stayed open').toBe('open')
	})

	// @e2e row-field-level-security::closing-without-an-outcome-is-refused
	test('closing with an outcome lands', async ({ request }) => {
		const { uuid } = await create(request, {
			status: 'open',
			onderwerp: 'Met resultaat sluiten',
			bedrag: 100,
		})

		const close = await request.put(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: {
					status: 'gesloten',
					onderwerp: 'Met resultaat sluiten',
					bedrag: 100,
					outcome: 'toegekend',
				},
			},
		)
		expect(close.ok(), `the close failed: ${await close.text()}`).toBeTruthy()

		const readBack = await request.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect((await readBack.json()).status, 'the object closed').toBe('gesloten')
	})

	// @e2e row-field-level-security::a-field-becomes-required-because-of-a-value
	test('a field becomes required because of another field value', async ({
		request,
	}) => {
		const { uuid } = await create(request, {
			status: 'open',
			onderwerp: 'Klein bedrag',
			bedrag: 400,
		})

		const raise = await request.put(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: { status: 'open', onderwerp: 'Groot bedrag', bedrag: 60000 },
			},
		)

		expect(raise.status(), 'the save above the threshold was refused').toBe(422)
		expect(await raise.text(), 'the refusal names the field').toContain(
			'motivering',
		)
	})

	// @e2e row-field-level-security::the-same-field-is-not-required-below-the-threshold
	test('the same field is not required below the threshold', async ({
		request,
	}) => {
		const { uuid } = await create(request, {
			status: 'open',
			onderwerp: 'Onder de drempel',
			bedrag: 400,
		})

		const readBack = await request.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(readBack.ok(), `the save below the threshold failed`).toBeTruthy()

		const body = await readBack.json()
		expect(body.bedrag, 'the write landed').toBe(400)

		// The published hint agrees with the refusal that did not happen. This
		// is the half a form reads, so a hint that said `motivering` here would
		// make every form under the threshold ask for a field nothing wants.
		const required = body['@self']?.fieldRules?.required ?? []
		expect(required, 'the hint does not demand motivering').not.toContain(
			'motivering',
		)
	})

	// @e2e object-lifecycle::one-rule-guards-every-path-into-a-state
	test('one entry condition guards every path into the state', async ({
		request,
	}) => {
		for (const from of ['open', 'bezwaar', 'herstel']) {
			const { uuid } = await create(request, {
				status: from,
				onderwerp: `Vanuit ${from}`,
				bedrag: 100,
			})

			const move = await request.put(
				`${API}/objects/${registerId}/${schemaId}/${uuid}`,
				{
					headers: JSON_HEADERS,
					data: {
						status: 'besloten',
						onderwerp: `Vanuit ${from}`,
						bedrag: 100,
					},
				},
			)

			expect(move.status(), `the move from ${from} was refused`).toBe(422)
			expect(
				await move.text(),
				`the refusal from ${from} names the missing value`,
			).toContain('besluit')

			const withBesluit = await request.put(
				`${API}/objects/${registerId}/${schemaId}/${uuid}`,
				{
					headers: JSON_HEADERS,
					data: {
						status: 'besloten',
						onderwerp: `Vanuit ${from}`,
						bedrag: 100,
						besluit: 'toegekend',
					},
				},
			)
			expect(
				withBesluit.ok(),
				`the move from ${from} with a besluit failed: ${await withBesluit.text()}`,
			).toBeTruthy()
		}
	})

	// @e2e row-field-level-security::closing-without-an-outcome-is-refused
	test('a schema saved with a rule naming an unknown field is refused', async ({
		request,
	}) => {
		const resp = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				title: `field rules bad schema ${RUN}`,
				description: 'e2e',
				properties: {
					status: { type: 'string', title: 'Status', enum: ['open'] },
				},
				'x-openregister-lifecycle': {
					field: 'status',
					initial: 'open',
					transitions: { blijven: { from: ['open'], to: 'open' } },
					states: {
						open: { fields: { required: [{ fields: ['outcome'] }] } },
					},
				},
			},
		})

		expect(resp.status(), 'the schema save was refused').toBe(422)
		const refusal = await resp.text()
		expect(refusal, 'the refusal names the field').toContain('outcome')
		expect(refusal, 'the refusal names the status').toContain('open')
	})
})
