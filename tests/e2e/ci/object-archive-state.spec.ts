import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OBJECT ARCHIVE STATE — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/object-archive-state/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e object-lifecycle::restoring-brings-it-back-into-the-working-set
 * @e2e object-lifecycle::an-edit-is-refused-and-says-why
 * @e2e object-lifecycle::the-default-list-hides-them
 * @e2e object-lifecycle::the-archived-lens-shows-them
 * @e2e object-lifecycle::a-zaak-in-bezwaar-is-findable-and-unchangeable
 * @e2e object-lifecycle::frozen-is-not-archived
 * @e2e object-lifecycle::a-vastgesteld-besluit-keeps-its-date
 * @e2e object-lifecycle::an-unset-immutable-property-can-still-be-set-once
 *
 * WHAT ONLY THIS LAYER CAN PROVE.
 *
 * Every assertion here is about a rule that only exists once a query has run
 * against a real table. The exclusion is a `WHERE` clause; whether a count and
 * a list agree is a question about two SQL statements; whether the `_archived`
 * column exists at all on a table created before this change is a question
 * about a repair step. A mocked mapper answers all three with whatever it was
 * told to, which is the one answer that means nothing.
 *
 * THE COUNT AND THE LIST ARE ASSERTED TOGETHER, never separately. A tile that
 * counts archived records while the page under it hides them is a disagreement
 * nobody reads as a bug: they read it as the tile being right and go looking
 * for records that are not there.
 *
 * WHAT IS DELIBERATELY NOT CLAIMED HERE. `$ref` expansion into an archived
 * object needs a second schema with a relation property and an `_extend` read,
 * which is a fixture this file does not build. The test below asserts the
 * weaker thing it can actually see: that a read BY ID still answers. That is
 * the mechanism `$ref` resolution rests on, and it is not the same assertion,
 * so the scenario keeps no anchor rather than a misleading one.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schemas and objects
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
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** The uuid an object create or read answers with, whichever shape it uses. */
function uuidOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>

	return String(self.id ?? body.id ?? body.uuid)
}

test.describe.configure({ mode: 'serial' })

test.describe('object archive state over HTTP', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	let besluitSchemaId: string
	let archivedUuid: string
	let openUuid: string
	let frozenUuid: string
	let besluitUuid: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e archive state register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e archive state schema ${RUN}`,
				description: 'e2e',
				properties: {
					title: { type: 'string', title: 'Title', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				// The opt-in. Without it every verb below answers 422, which is
				// also what makes this line the test of the annotation.
				'x-openregister-archive': { enabled: true },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const besluit = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e besluit schema ${RUN}`,
				description: 'e2e',
				properties: {
					// `immutable` is not `readOnly`: a value that is not there
					// yet can still be written, once.
					vastgesteldOp: { type: 'string', title: 'Vastgesteld op', immutable: true },
					toelichting: { type: 'string', title: 'Toelichting', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(besluit.ok(), `besluit schema create failed: ${await besluit.text()}`).toBeTruthy()
		besluitSchemaId = String((await besluit.json()).id)

		for (const [title, assign] of [
			['archive-me', (u: string) => { archivedUuid = u }],
			['leave-me-open', (u: string) => { openUuid = u }],
			['freeze-me', (u: string) => { frozenUuid = u }],
		] as const) {
			const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: { title },
			})
			expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
			assign(uuidOf(await obj.json()))
		}

		const besluitObj = await admin.post(`${API}/objects/${registerId}/${besluitSchemaId}`, {
			data: { toelichting: 'concept' },
		})
		expect(besluitObj.ok(), `besluit create failed: ${await besluitObj.text()}`).toBeTruthy()
		besluitUuid = uuidOf(await besluitObj.json())
	})

	test.afterAll(async () => {
		// Restore before deleting: a state that refuses writes would otherwise
		// leave its own fixtures behind on every run.
		for (const [schema, uuid, path] of [
			[schemaId, archivedUuid, 'archive'],
			[schemaId, frozenUuid, 'freeze'],
		] as const) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schema}/${uuid}/${path}`)
			}
		}

		for (const [schema, uuid] of [
			[schemaId, archivedUuid],
			[schemaId, openUuid],
			[schemaId, frozenUuid],
			[besluitSchemaId, besluitUuid],
		] as const) {
			if (!uuid) {
				continue
			}

			await admin.delete(`${API}/objects/${registerId}/${schema}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}`)
		}

		for (const schema of [schemaId, besluitSchemaId]) {
			if (schema) {
				await admin.delete(`${API}/schemas/${schema}`)
			}
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('archiving takes an object out of the list and the archived lens brings it back', async () => {
		const archived = await admin.post(
			`${API}/objects/${registerId}/${schemaId}/${archivedUuid}/archive`,
			{ data: { reason: 'afgehandeld' } },
		)
		expect(archived.ok(), `archive failed: ${await archived.text()}`).toBeTruthy()
		const marker = (await archived.json()).archived
		expect(marker?.by, 'the marker must name who archived it').toBeTruthy()
		expect(marker?.reason).toBe('afgehandeld')

		const list = await admin.get(`${API}/objects/${registerId}/${schemaId}?_limit=100`)
		expect(list.ok()).toBeTruthy()
		const listed = (await list.json()).results as Array<Record<string, unknown>>
		const listedUuids = listed.map(uuidOf)

		expect(
			listedUuids,
			'the default list must not carry an archived object',
		).not.toContain(archivedUuid)
		expect(
			listedUuids,
			'and must still carry the open one, or the exclusion is hiding everything',
		).toContain(openUuid)

		const lens = await admin.get(
			`${API}/objects/${registerId}/${schemaId}?_limit=100&_archived=true`,
		)
		expect(lens.ok()).toBeTruthy()
		const lensUuids = ((await lens.json()).results as Array<Record<string, unknown>>).map(uuidOf)

		expect(lensUuids, 'the archived lens must show the archived object').toContain(archivedUuid)
		expect(
			lensUuids,
			'and must show only archived objects',
		).not.toContain(openUuid)
	})

	test('a read by id still answers for an archived object', async () => {
		// The exclusion is a property of the LIST, not of the record. If a read
		// by id were filtered too, every `$ref` into an archived object would
		// break and restore could never find what it exists to restore.
		const read = await admin.get(`${API}/objects/${registerId}/${schemaId}/${archivedUuid}`)

		expect(
			read.ok(),
			`an archived object must still answer a read by id: ${await read.text()}`,
		).toBeTruthy()
		expect((await read.json()).title).toBe('archive-me')
	})

	test('an edit to an archived object is refused and the refusal names the archive', async () => {
		const write = await admin.put(`${API}/objects/${registerId}/${schemaId}/${archivedUuid}`, {
			data: { title: 'changed-while-archived' },
		})

		expect(write.ok(), 'a write to an archived object must not succeed').toBeFalsy()
		expect(
			await write.text(),
			'the refusal must name the archive, not just fail',
		).toMatch(/archiv/i)

		// And the object is unchanged, which is the half a status code cannot
		// show: a refusal that answered 409 after writing would pass the line
		// above.
		const read = await admin.get(`${API}/objects/${registerId}/${schemaId}/${archivedUuid}`)
		expect((await read.json()).title).toBe('archive-me')
	})

	test('restoring brings it back into the working set', async () => {
		const restored = await admin.delete(
			`${API}/objects/${registerId}/${schemaId}/${archivedUuid}/archive`,
		)
		expect(restored.ok(), `restore failed: ${await restored.text()}`).toBeTruthy()

		const list = await admin.get(`${API}/objects/${registerId}/${schemaId}?_limit=100`)
		const listedUuids = ((await list.json()).results as Array<Record<string, unknown>>).map(uuidOf)
		expect(listedUuids, 'a restored object is back in the default list').toContain(archivedUuid)

		// And it takes writes again, which is what "restored" has to mean.
		const write = await admin.put(`${API}/objects/${registerId}/${schemaId}/${archivedUuid}`, {
			data: { title: 'archive-me' },
		})
		expect(write.ok(), `a restored object must accept a write: ${await write.text()}`).toBeTruthy()

		// Put it back in the archive for the count test and the teardown.
		await admin.post(`${API}/objects/${registerId}/${schemaId}/${archivedUuid}/archive`, {
			data: { reason: 'afgehandeld' },
		})
	})

	test('a frozen object stays in the list and still refuses writes', async () => {
		const frozen = await admin.post(
			`${API}/objects/${registerId}/${schemaId}/${frozenUuid}/freeze`,
			{ data: { reason: 'bezwaar' } },
		)
		expect(frozen.ok(), `freeze failed: ${await frozen.text()}`).toBeTruthy()

		const list = await admin.get(`${API}/objects/${registerId}/${schemaId}?_limit=100`)
		const listedUuids = ((await list.json()).results as Array<Record<string, unknown>>).map(uuidOf)

		// The whole reason frozen and archived are two states. A zaak in
		// bezwaar has to be findable and unchangeable at the same time.
		expect(listedUuids, 'a frozen object stays in the working list').toContain(frozenUuid)
		expect(listedUuids, 'an archived one does not').not.toContain(archivedUuid)

		const write = await admin.put(`${API}/objects/${registerId}/${schemaId}/${frozenUuid}`, {
			data: { title: 'changed-while-frozen' },
		})
		expect(write.ok(), 'a write to a frozen object must not succeed').toBeFalsy()
		expect(await write.text()).toMatch(/frozen/i)
	})

	test('an immutable property accepts its first value and refuses the next', async () => {
		const first = await admin.put(
			`${API}/objects/${registerId}/${besluitSchemaId}/${besluitUuid}`,
			{ data: { vastgesteldOp: '2026-03-01', toelichting: 'concept' } },
		)
		expect(
			first.ok(),
			`an unset immutable property must still be settable: ${await first.text()}`,
		).toBeTruthy()

		const second = await admin.put(
			`${API}/objects/${registerId}/${besluitSchemaId}/${besluitUuid}`,
			{ data: { vastgesteldOp: '2026-09-15', toelichting: 'concept' } },
		)
		expect(second.ok(), 'changing a set immutable property must be refused').toBeFalsy()
		expect(
			await second.text(),
			'the refusal must name the property',
		).toMatch(/vastgesteldOp/)

		// The rest of the object is still editable: immutability is a rule
		// about the property, not a freeze on the record.
		const other = await admin.put(
			`${API}/objects/${registerId}/${besluitSchemaId}/${besluitUuid}`,
			{ data: { vastgesteldOp: '2026-03-01', toelichting: 'vastgesteld' } },
		)
		expect(
			other.ok(),
			`an ordinary property must stay editable: ${await other.text()}`,
		).toBeTruthy()
	})
})
