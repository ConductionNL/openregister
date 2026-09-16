import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENERATED IDENTIFIER — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/generated-identifier/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e computed-fields::two-creates-get-consecutive-identifiers
 * @e2e computed-fields::editing-the-identifier-is-refused
 *
 * WHAT THIS FILE CAN PROVE, AND WHY IT HAS TO BE THIS FILE.
 *
 * The number comes out of a row taken under a lock inside a transaction, and
 * the guard that freezes it runs on a dispatched event the controller answers
 * as 422. Neither is reachable from PHPUnit here: the counter needs a database,
 * and a mocked one would be asserting the mock. So the pieces only this layer
 * can see are the ones that matter most — that the listener is registered at
 * all, that two creates really do get consecutive numbers, that an edit is
 * refused rather than quietly applied, and that an ordinary edit still works.
 *
 * THAT LAST ONE IS NOT A FORMALITY. A guard that refused every update would
 * pass the refusal test and make every case with a number uneditable, and
 * nothing else here would catch it.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects and
 * removes all three. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/** A sequence name nothing else in the instance draws from. */
const SEQUENCE = `e2e-case-${RUN}`

const API = '/index.php/apps/openregister/api'

/** Build an API context authenticated as the admin. */
async function adminContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** The `{seq}` part of a rendered identifier, as a number. */
function seqOf(identifier: string): number {
	const match = /-(\d+)$/.exec(identifier)
	expect(match, `no sequence part in ${identifier}`).not.toBeNull()

	return Number(match[1])
}

test.describe.configure({ mode: 'serial' })

test.describe('generated identifier over HTTP', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	const uuids: string[] = []

	test.beforeAll(async () => {
		admin = await adminContext()

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e identifier register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e identifier schema ${RUN}`,
				description: 'e2e',
				properties: {
					title: { type: 'string', title: 'Title', maxLength: 255 },
					identifier: {
						type: 'string',
						title: 'Identifier',
						'x-openregister-generated': {
							sequence: SEQUENCE,
							format: 'Z-{year}-{seq:5}',
							resetOn: 'year',
						},
					},
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
	})

	test.afterAll(async () => {
		for (const uuid of uuids) {
			if (!uuid) {
				continue
			}

			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('two creates get consecutive identifiers', async () => {
		const issued: string[] = []

		for (const title of ['First case', 'Second case']) {
			const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: { title },
			})
			expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
			const body = await res.json()
			uuids.push(String(body['@self']?.id ?? body.id ?? body.uuid))

			const identifier = String(body.identifier ?? '')
			expect(
				identifier,
				'a declared identifier must be filled on create, not left empty',
			).toMatch(/^Z-\d{4}-\d{5,}$/)
			issued.push(identifier)
		}

		// Consecutive, not merely different. Two numbers that are unique but
		// not adjacent would mean the counter is being read rather than taken.
		expect(seqOf(issued[1])).toBe(seqOf(issued[0]) + 1)

		// The year in the value is the year the counter is keyed by.
		expect(issued[0].slice(0, 7)).toBe(issued[1].slice(0, 7))
	})

	test('editing the identifier is refused with 422 and the value is unchanged', async () => {
		const uuid = uuids[0]
		const before = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		const issued = String((await before.json()).identifier ?? '')
		expect(issued).toBeTruthy()

		const attempt = await admin.put(`${API}/objects/${registerId}/${schemaId}/${uuid}`, {
			data: { title: 'First case', identifier: 'Z-2026-00009' },
		})

		expect(
			attempt.status(),
			`changing a generated identifier should be refused, got ${attempt.status()}`,
		).toBe(422)

		const after = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(
			String((await after.json()).identifier ?? ''),
			'a refused update must leave the issued identifier in place',
		).toBe(issued)
	})

	test('an ordinary edit still works on a schema that numbers its records', async () => {
		// The control for the test above. A guard that refused every update
		// would pass the refusal assertion and make every numbered case
		// uneditable, with nothing else here to notice.
		const uuid = uuids[1]
		const before = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		const issued = String((await before.json()).identifier ?? '')

		const edit = await admin.put(`${API}/objects/${registerId}/${schemaId}/${uuid}`, {
			data: { title: 'Second case, retitled', identifier: issued },
		})
		expect(edit.ok(), `an ordinary edit was refused: ${await edit.text()}`).toBeTruthy()

		const after = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		const body = await after.json()
		expect(String(body.title ?? '')).toBe('Second case, retitled')
		expect(String(body.identifier ?? '')).toBe(issued)
	})

	test('a create that supplies its own value keeps it, and the counter moves past it', async () => {
		const year = new Date().getFullYear()
		const supplied = `Z-${year}-00120`

		const imported = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { title: 'Imported case', identifier: supplied },
		})
		expect(imported.ok(), `import create failed: ${await imported.text()}`).toBeTruthy()
		const importedBody = await imported.json()
		uuids.push(String(importedBody['@self']?.id ?? importedBody.id ?? importedBody.uuid))
		expect(
			String(importedBody.identifier ?? ''),
			'a supplied identifier must be kept, not overwritten',
		).toBe(supplied)

		const next = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { title: 'The one after the import' },
		})
		expect(next.ok(), `object create failed: ${await next.text()}`).toBeTruthy()
		const nextBody = await next.json()
		uuids.push(String(nextBody['@self']?.id ?? nextBody.id ?? nextBody.uuid))

		// The collision this guards against does not surface on the next
		// create, it surfaces on the hundred-and-twentieth. So the assertion is
		// on the number itself rather than on "no error".
		expect(seqOf(String(nextBody.identifier ?? ''))).toBe(121)
	})

	test('a schema declaring an unknown placeholder is refused at save', async () => {
		const bad = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e identifier bad schema ${RUN}`,
				description: 'e2e',
				properties: {
					identifier: {
						type: 'string',
						title: 'Identifier',
						'x-openregister-generated': {
							sequence: `${SEQUENCE}-bad`,
							format: 'Z-{jaar}-{seq:5}',
						},
					},
				},
			},
		})

		// A placeholder nothing renders would ship as its own literal text, so
		// every object would carry the same characters where its number goes.
		expect(
			bad.status(),
			`an unknown placeholder should be refused at save, got ${bad.status()}`,
		).toBe(422)

		if (bad.ok()) {
			// Only reached if the assertion above is ever relaxed; clean up so a
			// failure here does not leave a schema behind.
			await admin.delete(`${API}/schemas/${String((await bad.json()).id)}`)
		}
	})
})
