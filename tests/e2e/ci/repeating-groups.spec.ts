import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REPEATING GROUPS AND RECORDED CORRECTIONS — end to end, through the HTTP API
 * a real client uses.
 *
 * WHAT THIS FILE CAN PROVE, AND WHY IT HAS TO BE THIS FILE.
 *
 * The unit tests pin the validators. What they cannot see is whether the
 * validators are actually ON the write path, whether the routes are
 * registered, whether the controller's body filter lets `@notSupplied`
 * through, and whether the trail can be filtered back to corrections. Each of
 * those is a wiring claim about several files agreeing, and a mocked mapper
 * would be asserting the mock.
 *
 * THE FILTER ASSERTION IS THE POINT OF THE CORRECTION TESTS. A correction that
 * is recorded but indistinguishable from an update still returns 200 and still
 * changes the value. The only thing that tells the two apart is the trail
 * filtered to `action=correction` returning one row out of several.
 *
 * ⚠️ THE AGGREGATION TESTS WRITE AN INSTANCE-WIDE SETTING. They set the window
 * and put it back to zero, which is the shipped default, in the test that set
 * it and again in `afterAll`. The Playwright config pins one worker and no
 * parallelism, so nothing else is mid-write while the window is up.
 *
 * HERMETIC BY CONSTRUCTION otherwise. It creates its own register, schema and
 * objects and removes all three. It needs no `occ` and no docker.
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

test.describe.configure({ mode: 'serial' })

test.describe('repeating groups and recorded corrections over HTTP', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	const created: string[] = []

	/** The audit rows on one object, optionally filtered to one action. */
	async function auditRows(
		uuid: string,
		action?: string,
	): Promise<Array<Record<string, unknown>>> {
		const query = action ? `?action=${action}&limit=100` : '?limit=100'
		const res = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}/audit-trails${query}`,
		)
		expect(res.ok(), `audit trail read failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const rows = body.results ?? body.data ?? body
		expect(Array.isArray(rows), 'the audit trail did not come back as a list').toBeTruthy()

		return rows as Array<Record<string, unknown>>
	}

	/** Create one object and remember it for teardown. */
	async function createObject(data: Record<string, unknown>): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data })
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const uuid = uuidOf(await res.json())
		created.push(uuid)

		return uuid
	}

	/** Set the instance-wide aggregation window, in seconds. */
	async function setWindow(seconds: number): Promise<void> {
		const res = await admin.fetch(`${API}/settings/audit-aggregation`, {
			method: 'PATCH',
			data: { windowSeconds: seconds },
		})
		expect(res.ok(), `setting the aggregation window failed: ${await res.text()}`).toBeTruthy()
		expect(
			(await res.json()).windowSeconds,
			'the answer has to say what was stored, not what was asked for',
		).toBe(seconds)
	}

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
				required: ['bsn'],
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
		// The window is instance-wide. Put it back whatever happened above.
		await admin.fetch(`${API}/settings/audit-aggregation`, {
			method: 'PATCH',
			data: { windowSeconds: 0 },
		})

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

	// @e2e runtime-schema-api::two-gemachtigden-on-one-record
	test('two gemachtigden are stored, in order, each validated', async () => {
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

	// @e2e runtime-schema-api::a-violation-says-which-item
	test('a violation says which row and which member', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Bezwaar',
				bsn: '123456782',
				gemachtigden: [{ naam: 'De Vries' }, { rol: 'partner' }],
			},
		})

		expect(res.status(), 'a row missing a required member must be refused').toBe(400)

		const message = await res.text()
		expect(message, 'the refusal has to name the row, not just the property').toContain('Row 2')
		expect(message, 'the refusal has to name the member').toContain('naam')
	})

	// @e2e runtime-schema-api::the-maximum-is-enforced
	test('a fourth row is refused, naming the maximum', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Bezwaar',
				bsn: '123456782',
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

	// @e2e runtime-schema-api::honest-incompleteness-beats-a-typed-onbekend
	test('a required value can be recorded as not supplied, and reads back with its reason', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: {
				omschrijving: 'Aanvraag zonder bsn',
				'@notSupplied': { bsn: 'onbekend_bij_aanvrager' },
			},
		})
		expect(
			res.ok(),
			`bsn is required, so this create proves not supplied satisfies the rule: ${await res.text()}`,
		).toBeTruthy()

		const uuid = uuidOf(await res.json())
		created.push(uuid)

		const read = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(read.ok(), `read back failed: ${await read.text()}`).toBeTruthy()

		expect(
			(await read.json())['@notSupplied']?.bsn,
			'not supplied has to read back with its reason, or it is just an empty field',
		).toBe('onbekend_bij_aanvrager')
	})

	// @e2e runtime-schema-api::not-supplied-is-not-empty
	test('an empty value and a not-supplied one are distinguishable', async () => {
		const empty = await createObject({ omschrijving: 'Leeg', bsn: '' })
		const marked = await createObject({
			omschrijving: 'Niet geleverd',
			'@notSupplied': { bsn: 'onbekend_bij_aanvrager' },
		})

		const emptyBody = await (
			await admin.get(`${API}/objects/${registerId}/${schemaId}/${empty}`)
		).json()
		const markedBody = await (
			await admin.get(`${API}/objects/${registerId}/${schemaId}/${marked}`)
		).json()

		expect(
			emptyBody['@notSupplied'],
			'an empty field carries no record, because nobody decided anything',
		).toBeFalsy()
		expect(
			markedBody['@notSupplied']?.bsn,
			'a marked field carries the reason somebody chose',
		).toBe('onbekend_bij_aanvrager')
	})

	test('a reason the schema does not administer is refused', async () => {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { omschrijving: 'Aanvraag', '@notSupplied': { bsn: 'geen_zin' } },
		})

		expect(res.status(), 'a reason outside the schema list must be refused').toBe(400)
	})

	// @e2e enhanced-audit-trail::no-reason-no-correction
	test('a correction with no reason is refused, naming the requirement', async () => {
		const target = created[0]
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}/${target}/correct`, {
			data: { values: { bsn: '111222333' } },
		})

		expect(res.status(), 'a correction without a reason must be refused').toBe(400)
		expect(await res.text(), 'the refusal has to name what is missing').toContain('reason')
	})

	// @e2e enhanced-audit-trail::a-mis-registered-case-is-corrected-not-edited
	// @e2e enhanced-audit-trail::an-auditor-can-separate-corrections-from-updates
	test('a mis-registered value is corrected, and an auditor can find it', async () => {
		await setWindow(0)
		const target = created[0]

		// Ordinary updates first, so the filter below has something to
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

		const corrections = await auditRows(target, 'correction')
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
		expect(
			(await auditRows(target)).length,
			'the correction replaces its update entry rather than adding a second one',
		).toBeGreaterThan(corrections.length)
	})

	// @e2e enhanced-audit-trail::order-is-not-merged-away-by-default
	test('two edits a moment apart are two entries when no window is set', async () => {
		await setWindow(0)
		const target = await createObject({ omschrijving: 'Ongemerged', bsn: '123456782' })

		const before = (await auditRows(target, 'update')).length

		for (const omschrijving of ['Eerste wijziging', 'Tweede wijziging']) {
			const patch = await admin.fetch(
				`${API}/objects/${registerId}/${schemaId}/${target}`,
				{ method: 'PATCH', data: { omschrijving } },
			)
			expect(patch.ok(), `update failed: ${await patch.text()}`).toBeTruthy()
		}

		expect(
			(await auditRows(target, 'update')).length - before,
			'the shipped default records every edit separately, in order',
		).toBe(2)
	})

	// @e2e enhanced-audit-trail::a-set-window-says-what-it-merged
	test('three edits inside a set window are one entry, naming three', async () => {
		const target = await createObject({ omschrijving: 'Gemerged', bsn: '123456782' })
		const before = (await auditRows(target, 'update')).length

		await setWindow(300)

		try {
			for (const omschrijving of ['Een', 'Twee', 'Drie']) {
				const patch = await admin.fetch(
					`${API}/objects/${registerId}/${schemaId}/${target}`,
					{ method: 'PATCH', data: { omschrijving } },
				)
				expect(patch.ok(), `update failed: ${await patch.text()}`).toBeTruthy()
			}

			const updates = await auditRows(target, 'update')
			expect(
				updates.length - before,
				'three edits inside the window are one entry, not three',
			).toBe(1)

			const changed = (updates[0].changed ?? {}) as Record<string, Record<string, unknown>>
			expect(
				changed.aggregation?.edits,
				'a merged entry that does not say it merged is a lie of omission',
			).toBe(3)
		} finally {
			await setWindow(0)
		}
	})

	// @e2e enhanced-audit-trail::tidying-a-dossier-before-it-goes-out
	// @e2e enhanced-audit-trail::nothing-changed-nothing-recorded
	test('six files, three renamed together, three audit entries and no more', async () => {
		const target = await createObject({ omschrijving: 'Dossier', bsn: '123456782' })

		const fileIds: number[] = []
		for (let i = 0; i < 6; i++) {
			const res = await admin.post(`${API}/objects/${registerId}/${schemaId}/${target}/files`, {
				data: { name: `scan000${i}.txt`, content: `bestand ${i}` },
			})
			expect(res.ok(), `file create failed: ${await res.text()}`).toBeTruthy()
			fileIds.push(Number((await res.json()).id))
		}

		const form = [
			{ fileId: fileIds[0], name: 'Aanvraag.txt' },
			{ fileId: fileIds[1], name: 'Bijlage 1.txt' },
			{ fileId: fileIds[2], name: 'Bijlage 2.txt' },
			{ fileId: fileIds[3] },
			{ fileId: fileIds[4] },
			{ fileId: fileIds[5] },
		]

		const saved = await admin.fetch(
			`${API}/objects/${registerId}/${schemaId}/${target}/files/metadata`,
			{ method: 'PUT', data: { files: form } },
		)
		expect(saved.ok(), `the metadata form failed: ${await saved.text()}`).toBeTruthy()

		const result = await saved.json()
		expect(result.changed, 'three badly-named files were renamed').toHaveLength(3)
		expect(result.unchanged, 'the three the form left alone changed nothing').toHaveLength(3)
		expect(result.failed, 'nothing should have been refused').toHaveLength(0)

		const entries = await auditRows(target, 'file.metadata_corrected')
		expect(entries, 'one entry per file actually changed, and no more').toHaveLength(3)

		// Nothing changed, nothing recorded: the same form saved again.
		const again = await admin.fetch(
			`${API}/objects/${registerId}/${schemaId}/${target}/files/metadata`,
			{ method: 'PUT', data: { files: form } },
		)
		expect(again.ok(), `the second save failed: ${await again.text()}`).toBeTruthy()
		expect((await again.json()).changed, 'a form saved with no edits changes nothing').toHaveLength(0)

		expect(
			(await auditRows(target, 'file.metadata_corrected')).length,
			'saved without editing is not an event, so it writes no entry',
		).toBe(3)
	})
})
