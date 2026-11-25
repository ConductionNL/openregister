import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A COPY TAKES ITS LINKS ALONG, AND LEAVES THE SOURCE ALONE.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e objects-crud::a-catalogue-editor-copies-an-application-with-its-links
 * @e2e objects-crud::a-failed-create-leaves-nothing-behind
 *
 * `POST /api/objects/{register}/{schema}/{id}/copy` creates a new object from
 * the source's fields and the overrides, and with `include` re-creates the
 * source's relation rows on the copy and adds the copy beside the source in
 * array references that point at it. The unit tests drive the controller and
 * the link copier with doubles; this spec reads the outcome back over HTTP,
 * from the rows and the referring object themselves rather than from the
 * copy's own report, because a report that says `copied` is only worth what
 * a fresh read confirms.
 *
 * It copies as an ordinary authenticated user, not as an administrator: every
 * link is written under the caller's own rights, and the one principal who
 * holds every right is the one that would prove the least.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and its
 * objects, and removes all of them. It needs no `occ` and no docker; the
 * non-admin account is the one the CI seed provisions.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded non-admin account, provisioned by the workflow's seed command. */
const EDITOR = 'e2e-other'
const EDITOR_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so repeated runs never collide. */
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

/** Every action open to any signed-in caller, so the copy runs on the editor's own rights. */
const OPEN_TO_EVERYONE = {
	read: ['authenticated'],
	create: ['authenticated'],
	update: ['authenticated'],
	delete: ['authenticated'],
}

/** The uuid of an object as the object API answers it. */
function uuidOf(body: Record<string, any>): string {
	return String(body['@self']?.id ?? body.id ?? body.uuid)
}

test.describe('a copy takes the links the caller chooses', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext
	let editor: APIRequestContext
	let registerId = ''
	let moduleSchemaId = ''
	let suiteSchemaId = ''
	let sourceUuid = ''
	let suiteUuid = ''
	const created: Array<[string, string]> = []

	const modules = () => `${API}/objects/${registerId}/${moduleSchemaId}`
	const suites = () => `${API}/objects/${registerId}/${suiteSchemaId}`

	async function createObject(
		schemaId: string,
		data: Record<string, unknown>,
	): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data,
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const uuid = uuidOf(await res.json())
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push([schemaId, uuid])

		return uuid
	}

	/** The external addresses of an object's relation rows, sorted. */
	async function rowUrls(uuid: string): Promise<string[]> {
		const res = await editor.get(`${modules()}/${uuid}/relation-rows`)
		expect(res.ok(), `relation rows read failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()

		return (body.results as Array<Record<string, any>>)
			.map((row) => {
				expect(row.sourceUuid, 'a row listed for an object hangs off that object').toBe(uuid)
				return String(row.targetUrl)
			})
			.sort()
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		editor = await contextFor(EDITOR, EDITOR_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e copy with links register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const module = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e copy with links module ${RUN}`,
				description: 'e2e',
				properties: {
					naam: { type: 'string', title: 'Naam', maxLength: 128 },
				},
				required: ['naam'],
				authorization: OPEN_TO_EVERYONE,
			},
		})
		expect(module.ok(), `module schema create failed: ${await module.text()}`).toBeTruthy()
		moduleSchemaId = String((await module.json()).id)

		// The suite lists its modules in an ARRAY reference: the only shape a
		// copy may join, because a single-valued one would have to be taken
		// away from the source to point at the copy.
		const suite = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e copy with links suite ${RUN}`,
				description: 'e2e',
				properties: {
					naam: { type: 'string', title: 'Naam', maxLength: 128 },
					applications: {
						type: 'array',
						items: {
							type: 'string',
							format: 'uuid',
							$ref: `#/components/schemas/${moduleSchemaId}`,
						},
					},
				},
				authorization: OPEN_TO_EVERYONE,
			},
		})
		expect(suite.ok(), `suite schema create failed: ${await suite.text()}`).toBeTruthy()
		suiteSchemaId = String((await suite.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: {
				title: `e2e copy with links register ${RUN}`,
				schemas: [Number(moduleSchemaId), Number(suiteSchemaId)],
			},
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		sourceUuid = await createObject(moduleSchemaId, { naam: 'Zaaksysteem A' })
		suiteUuid = await createObject(suiteSchemaId, {
			naam: 'Basis',
			applications: [sourceUuid],
		})

		for (const row of [
			{ url: 'https://example.org/handleiding', title: 'Handleiding' },
			{ url: 'https://example.org/contract', title: 'Contract', type: 'documentation' },
		]) {
			const res = await admin.post(`${modules()}/${sourceUuid}/relation-rows`, {
				data: row,
			})
			expect(res.status(), `relation row write failed: ${await res.text()}`).toBe(201)
		}
	})

	test.afterAll(async () => {
		// Referrers first, so no reference blocks the delete of what it names.
		for (const [schemaId, uuid] of [...created].reverse()) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		}
		for (const schemaId of [suiteSchemaId, moduleSchemaId]) {
			if (schemaId !== '') {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await editor?.dispose()
	})

	test('a copy the schema refuses writes no object, row or reference', async () => {
		const res = await editor.post(`${modules()}/${sourceUuid}/copy`, {
			data: {
				overrides: { naam: 5 },
				include: ['relationRows', 'incoming'],
			},
		})

		// The copy is created through the normal create path, so it is refused
		// exactly as that path refuses the same body.
		expect(res.ok(), 'a body the schema refuses is not a copy').toBeFalsy()
		const body = await res.json()
		expect(body.links, 'a refused create carries no copy envelope').toBeUndefined()

		const list = await editor.get(modules())
		expect(list.ok()).toBeTruthy()
		const names = ((await list.json()).results as Array<Record<string, any>>).map((o) => o.naam)
		expect(names, 'only the source module exists').toEqual(['Zaaksysteem A'])

		const basis = await editor.get(`${suites()}/${suiteUuid}`)
		expect((await basis.json()).applications, 'the suite still lists only the source').toEqual([
			sourceUuid,
		])
	})

	test('the copy arrives with both relation rows and a place in the suite', async () => {
		const res = await editor.post(`${modules()}/${sourceUuid}/copy`, {
			data: {
				overrides: { naam: 'Zaaksysteem B' },
				include: ['relationRows', 'incoming'],
			},
		})
		expect(res.status(), `copy failed: ${await res.text()}`).toBe(201)

		const body = await res.json()
		const copyUuid = uuidOf(body.object)
		created.push([moduleSchemaId, copyUuid])

		expect(copyUuid, 'the copy has its own uuid').not.toBe(sourceUuid)
		expect(body.object.naam).toBe('Zaaksysteem B')
		expect(
			(body.links.relationRows as Array<Record<string, any>>).map((l) => [l.title, l.outcome]).sort(),
		).toEqual([
			['Contract', 'copied'],
			['Handleiding', 'copied'],
		])
		expect(
			(body.links.incoming as Array<Record<string, any>>).map((l) => [l.id, l.outcome]),
		).toEqual([[suiteUuid, 'copied']])

		// Read back: the rows hang off the copy, the suite lists both modules.
		expect(await rowUrls(copyUuid)).toEqual([
			'https://example.org/contract',
			'https://example.org/handleiding',
		])

		const basis = await editor.get(`${suites()}/${suiteUuid}`)
		expect(basis.ok()).toBeTruthy()
		expect((await basis.json()).applications, 'the copy sits beside the source').toEqual([
			sourceUuid,
			copyUuid,
		])

		// The source is never changed by a copy.
		const source = await editor.get(`${modules()}/${sourceUuid}`)
		expect((await source.json()).naam).toBe('Zaaksysteem A')
		expect(await rowUrls(sourceUuid)).toEqual([
			'https://example.org/contract',
			'https://example.org/handleiding',
		])
	})
})
