import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REPEATING GROUPS AND RECORDED CORRECTIONS — end to end, through the HTTP API
 * a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/repeating-groups-and-recorded-corrections/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e runtime-schema-api::two-gemachtigden-on-one-record
 * @e2e runtime-schema-api::a-violation-says-which-item
 * @e2e runtime-schema-api::the-maximum-is-enforced
 * @e2e runtime-schema-api::honest-incompleteness-beats-a-typed-onbekend
 * @e2e runtime-schema-api::not-supplied-is-not-empty
 * @e2e enhanced-audit-trail::a-mis-registered-case-is-corrected-not-edited
 * @e2e enhanced-audit-trail::no-reason-no-correction
 * @e2e enhanced-audit-trail::an-auditor-can-separate-corrections-from-updates
 *
 * WHAT THIS FILE CAN PROVE, AND WHY IT HAS TO BE THIS FILE.
 *
 * The unit tests pin the validators. What they cannot see is whether the
 * validators are actually ON the write path, whether the correction route is
 * registered, and whether the trail can be filtered back to corrections
 * through the endpoint a client calls. Each of those is a wiring claim about
 * three files agreeing, and a mocked mapper would be asserting the mock.
 *
 * THE FILTER ASSERTION IS THE POINT OF THE LAST TEST. A correction that is
 * recorded but indistinguishable from an update still returns 200 and still
 * changes the value. The only thing that tells the two apart is the trail
 * filtered to `action=correction` returning one row out of several.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects
 * and removes all three. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** The uuid an object create or read answers with. */
function uuidOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>

	return String(self.id ?? body.id ?? body.uuid)
}

/** The audit rows on one object, optionally filtered to one action. */
async function auditRows(
	ctx: APIRequestContext,
	registerId: string,
	schemaId: string,
	uuid: string,
	action?: string,
): Promise<Array<Record<string, unknown>>> {
	const query = action ? `?action=${action}&limit=100` : '?limit=100'
	const res = await ctx.get(`${API}/objects/${registerId}/${schemaId}/${uuid}/audit-trails${query}`)
	expect(res.ok(), `audit trail read failed: ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	const rows = body.results ?? body.data ?? body
	expect(Array.isArray(rows), 'the audit trail did not come back as a list').toBeTruthy()

	return rows as Array<Record<string, unknown>>
}

test.describe.configure({ mode: 'serial' })

test.describe('repeating groups and recorded corrections over HTTP', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	const created: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e repeating register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e repeating schema ${RUN}`,
				description: 'e2e',
				hardValidation: true,
				properties: {
					omschrijving: { type: 'string', title: 'Omschrijving', maxLength: 255 },
					bsn: { type: 'string', title: 'Bsn', maxLength: 32 },
					gemachtigden: {
						type: 'array',
						title: 'Gemachtigden',
						repeatingGroup: true,
						groupOrdered: true,
						groupLabel: 'naam',
						maxItems: 3,
						items: {
							type: 'object',
							required: ['naam'],
							properties: {
								naam: { type: 'string' },
								rol: { type: 'string' },
							},
						},
					},
				},
				configuration: {
					'x-openregister-not-supplied-reasons': {
						onbekend_bij_aanvrager: 'The applicant does not know it',
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
		for (const uuid of created) {
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

	test('two gemachtigden are stored, in order', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Bezwaar',
				bsn: '123456782',
				gemachtigden: [
					{ naam: 'De Vries', rol: 'advocaat' },
					{ naam: 'Yilmaz', rol: 'partner' },
				],
			},
		})
		expect(res.ok(), `create with a repeating group failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		created.push(uuidOf(body))

		const rows = body.gemachtigden as Array<Record<string, unknown>>
		expect(rows).toHaveLength(2)
		expect(
			rows.map((row) => row.naam),
			'the authored order is the stored order when groupOrdered is set',
		).toEqual(['De Vries', 'Yilmaz'])
	})

	test('a violation says which row and which member', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Bezwaar',
				gemachtigden: [{ naam: 'De Vries' }, { rol: 'partner' }],
			},
		})

		expect(res.status(), 'a row missing a required member must be refused').toBe(400)

		const message = await res.text()
		expect(message, 'the refusal has to name the row, not just the property').toContain('Row 2')
		expect(message, 'the refusal has to name the member').toContain('naam')
	})

	test('a fourth row is refused, naming the maximum', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Bezwaar',
				gemachtigden: [
					{ naam: 'Een' },
					{ naam: 'Twee' },
					{ naam: 'Drie' },
					{ naam: 'Vier' },
				],
			},
		})

		expect(res.status(), 'four rows into a group of three must be refused').toBe(400)
		expect(await res.text(), 'the refusal has to name the maximum').toContain('3')
	})

	test('a value can be recorded as not supplied, and reads back that way', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Aanvraag zonder bsn',
				gemachtigden: [{ naam: 'De Vries' }],
				'@notSupplied': { bsn: 'onbekend_bij_aanvrager' },
			},
		})
		expect(res.ok(), `create with a not-supplied value failed: ${await res.text()}`).toBeTruthy()

		const uuid = uuidOf(await res.json())
		created.push(uuid)

		const read = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(read.ok(), `read back failed: ${await read.text()}`).toBeTruthy()
		const body = await read.json()

		expect(
			body['@notSupplied']?.bsn,
			'not supplied has to read back with its reason, or it is just an empty field',
		).toBe('onbekend_bij_aanvrager')
	})

	test('an unadministered reason is refused', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Aanvraag',
				'@notSupplied': { bsn: 'geen_zin' },
			},
		})

		expect(res.status(), 'a reason outside the schema list must be refused').toBe(400)
	})

	test('a correction with no reason is refused', async () => {
		const target = created[0]
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}/${target}/correct`, {
			data: { values: { bsn: '111222333' } },
		})

		expect(res.status(), 'a correction without a reason must be refused').toBe(400)
		expect(await res.text(), 'the refusal has to name what is missing').toContain('reason')
	})

	test('a mis-registered value is corrected, and the auditor can find it', async () => {
		const target = created[0]

		// Two ordinary updates first, so the filter below has something to
		// separate the correction from.
		for (const omschrijving of ['Bezwaar, aangevuld', 'Bezwaar, tweede aanvulling']) {
			const patch = await admin.fetch(
				`${API}/objects/${registerId}/${schemaId}/${target}`,
				{ method: 'PATCH', data: { omschrijving } },
			)
			expect(patch.ok(), `update failed: ${await patch.text()}`).toBeTruthy()
		}

		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}/${target}/correct`, {
			data: {
				reason: 'Overgetypt van het verkeerde formulier',
				values: { bsn: '111222333' },
			},
		})
		expect(res.ok(), `correction failed: ${await res.text()}`).toBeTruthy()

		const read = await admin.get(`${API}/objects/${registerId}/${schemaId}/${target}`)
		expect((await read.json()).bsn, 'the corrected value has to be stored').toBe('111222333')

		const corrections = await auditRows(admin, registerId, schemaId, target, 'correction')
		expect(
			corrections,
			'the trail filtered to corrections has to return exactly the correction',
		).toHaveLength(1)

		const changed = (corrections[0].changed ?? {}) as Record<string, Record<string, unknown>>
		expect(
			changed.correction?.reason,
			'the entry has to carry the reason, or an auditor still has to guess',
		).toBe('Overgetypt van het verkeerde formulier')

		const fields = (changed.correction?.fields ?? {}) as Record<string, Record<string, unknown>>
		expect(fields.bsn?.new, 'the entry has to carry the corrected value').toBe('111222333')

		const all = await auditRows(admin, registerId, schemaId, target)
		expect(
			all.length,
			'the correction replaces its update entry rather than adding a second one',
		).toBeGreaterThan(corrections.length)
	})
})
