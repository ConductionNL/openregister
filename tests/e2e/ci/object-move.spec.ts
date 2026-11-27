import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A MOVED OBJECT KEEPS WHO IT IS, AND ITS OLD ADDRESS SAYS WHERE IT WENT.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e objects-crud::the-number-and-the-history-come-along
 * @e2e objects-crud::a-bookmark-still-opens-the-case
 *
 * `POST .../move` takes an object to another schema keeping its uuid. This
 * spec moves one with a generated number, a create entry in its history and a
 * relation row, then reads it at the NEW address (same number, the history
 * carried with a `moved` entry, the row pointing at the new schema) and at
 * the OLD one, as a bookmark would.
 *
 * 🔴 THE OLD ADDRESS IS NOT A MISS. find() resolves a uuid across every table,
 * so the old URL came back 200 with the object and no word about the move,
 * which is why the unit tests (a mocked miss) passed while a bookmark was never
 * told to update. The second test is the one that caught it.
 *
 * HERMETIC. It creates its own register, two schemas and the object, and
 * removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
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

/** The same generated number on both schemas, so the move carries it. */
const NUMBERED = {
	titel: { type: 'string', title: 'Titel', maxLength: 128 },
	nummer: {
		type: 'string',
		title: 'Nummer',
		'x-openregister-generated': { sequence: `e2e-move-${RUN}`, format: '{year}-{seq:4}' },
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('an object moved to another schema', () => {
	let admin: APIRequestContext
	let registerId = ''
	let fromSchemaId = ''
	let toSchemaId = ''
	let uuid = ''
	let number = ''

	const at = (schemaId: string): string => `${API}/objects/${registerId}/${schemaId}/${uuid}`

	async function createSchema(title: string): Promise<string> {
		const res = await admin.post(`${API}/schemas`, {
			data: { title, description: 'e2e', properties: NUMBERED },
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

		return String((await res.json()).id)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e object move register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		fromSchemaId = await createSchema(`e2e object move intake ${RUN}`)
		toSchemaId = await createSchema(`e2e object move case ${RUN}`)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: {
				title: `e2e object move register ${RUN}`,
				schemas: [Number(fromSchemaId), Number(toSchemaId)],
			},
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		const obj = await admin.post(`${API}/objects/${registerId}/${fromSchemaId}`, {
			data: { titel: 'Melding geluidsoverlast' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		uuid = String(body['@self']?.id ?? body.id)
		number = String(body.nummer ?? '')
		expect(number, 'the platform minted a number').toMatch(/^\d{4}-\d{4}$/)

		const row = await admin.post(`${at(fromSchemaId)}/relation-rows`, {
			data: { url: 'https://example.org/melding', title: 'Melding' },
		})
		expect(row.status(), `relation row write failed: ${await row.text()}`).toBe(201)
	})

	test.afterAll(async () => {
		if (uuid !== '') {
			await admin.delete(at(toSchemaId))
			await admin.delete(at(fromSchemaId))
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		for (const schemaId of [toSchemaId, fromSchemaId]) {
			if (schemaId !== '') {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
	})

	test('the number, the history and the relation come along', async () => {
		const moved = await admin.post(`${at(fromSchemaId)}/move`, {
			data: { targetRegister: registerId, targetSchema: toSchemaId },
		})
		expect(moved.status(), `move failed: ${await moved.text()}`).toBe(200)
		const report = await moved.json()
		expect(report.moved).toBe(true)
		expect(report.uuid, 'a move keeps the uuid').toBe(uuid)

		const read = await admin.get(at(toSchemaId))
		expect(read.status()).toBe(200)
		const object = await read.json()
		expect(object.nummer, 'the number came along').toBe(number)
		expect(String(object['@self'].schema)).toBe(toSchemaId)

		const trail = await admin.get(`${at(toSchemaId)}/audit-trails?_limit=200`)
		expect(trail.ok(), `audit read failed: ${await trail.text()}`).toBeTruthy()
		const actions = ((await trail.json()).results as Array<Record<string, any>>).map((e) => e.action)
		expect(actions, 'the history from before the move came along').toContain('create')
		expect(actions, 'the move is in the history').toContain('moved')

		const rows = await admin.get(`${at(toSchemaId)}/relation-rows`)
		expect(rows.ok()).toBeTruthy()
		const found = (await rows.json()).results as Array<Record<string, any>>
		expect(found.map((r) => r.targetUrl)).toEqual(['https://example.org/melding'])
		expect(String(found[0].sourceSchema), 'the row points at the new address').toBe(toSchemaId)
	})

	test('the old address answers with the object and where it went', async () => {
		const old = await admin.get(at(fromSchemaId))
		expect(old.status(), `old address read failed: ${await old.text()}`).toBe(200)
		const body = await old.json()

		expect(body.nummer, 'the bookmark opens the same case').toBe(number)
		expect(body['@self'].movedTo, 'the old address names the new one').toMatchObject({
			register: registerId,
			schema: toSchemaId,
			id: uuid,
		})

		// Control: the new address carries no movedTo, it is where the object lives.
		const current = await admin.get(at(toSchemaId))
		expect((await current.json())['@self'].movedTo).toBeUndefined()
	})
})
