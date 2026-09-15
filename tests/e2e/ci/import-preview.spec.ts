import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IMPORT PREVIEW AND CONFLICT POLICY, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/import-preview-and-conflict-policy/specs/`
 * is archived into `openspec/specs/`:
 *
 * @e2e data-import-export::a-monthly-correction-reuses-last-months-mapping
 * @e2e data-import-export::a-migration-is-inspected-before-it-lands
 * @e2e data-import-export::a-changed-file-is-refused
 * @e2e data-import-export::a-first-migration-refuses-an-unexpected-match
 * @e2e data-import-export::an-ambiguous-row-is-never-resolved-by-guessing
 *
 * WHAT THIS FILE PROVES. That the capability is reachable over HTTP and that
 * a preview is a rehearsal rather than a promise: the six routes are
 * registered, a preview of a file against a register that already holds some
 * of its rows reports what would be created and what would be updated while
 * the register is unchanged, the write that follows lands exactly those rows,
 * a commit naming a different file is refused with nothing written, a first
 * migration under create-only refuses the row that unexpectedly matched, and
 * a row whose key hits two objects is refused naming both.
 *
 * It does NOT drive the background phase (`async=true`). That needs cron to
 * have run between two HTTP calls, and nothing in this environment guarantees
 * it: an assertion here would be a timing race dressed up as coverage. The
 * background runner's own behaviour is asserted in
 * tests/Unit/Service/Import/ImportPreviewServiceTest.php, which drives the
 * same service methods the job calls.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, objects and
 * column mapping, and removes all of them. It needs no `occ` and no docker.
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

test.describe.configure({ mode: 'serial' })

test.describe('an import says what it would do before it writes', () => {
	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	let mappingId: string

	/** Every object uuid this spec creates or imports, for cleanup. */
	const created: string[] = []

	/** The column mapping's own slug, unique per run. */
	const mappingSlug = `e2e-import-preview-${RUN}`

	function csv(body: string): { name: string, mimeType: string, buffer: Buffer } {
		return { name: 'personen.csv', mimeType: 'text/csv', buffer: Buffer.from(body, 'utf8') }
	}

	async function createObject(bsn: string, naam: string): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { bsn, naam } })
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push(uuid)

		return uuid
	}

	async function countObjects(): Promise<number> {
		const res = await admin.get(`${API}/objects/${registerId}/${schemaId}?limit=200`)
		expect(res.ok(), `object listing failed: ${await res.text()}`).toBeTruthy()

		return ((await res.json()).results as unknown[]).length
	}

	/** Take a preview, and remember nothing: a preview creates no objects. */
	async function preview(
		file: string,
		policy: string,
		extra: Record<string, string> = {},
	): Promise<{ status: number, json: Record<string, unknown> }> {
		const res = await admin.post(`${API}/import-previews`, {
			multipart: {
				file: csv(file),
				register: registerId,
				schema: schemaId,
				policy,
				matchKey: 'bsn',
				...extra,
			},
		})

		return { status: res.status(), json: (await res.json()) as Record<string, unknown> }
	}

	async function commit(
		previewId: string,
		file: string,
	): Promise<{ status: number, json: Record<string, unknown> }> {
		const res = await admin.post(`${API}/import-previews/${previewId}/commit`, {
			multipart: { file: csv(file) },
		})

		return { status: res.status(), json: (await res.json()) as Record<string, unknown> }
	}

	async function rowsOf(previewId: string): Promise<Array<Record<string, unknown>>> {
		const res = await admin.get(`${API}/import-previews/${previewId}/rows?limit=500`)
		expect(res.ok(), `row listing failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()).results as Array<Record<string, unknown>>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e import preview register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e import preview schema ${RUN}`,
				description: 'e2e',
				properties: {
					bsn: { type: 'string', title: 'BSN', maxLength: 32 },
					naam: { type: 'string', title: 'Naam', maxLength: 255 },
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
		if (mappingId) {
			await admin.delete(`${API}/migration-packs/${mappingId}`)
		}

		for (const uuid of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('the catalogue names the four policies and the default', async () => {
		const res = await admin.get(`${API}/import-previews/policies`)
		expect(res.ok(), `the policy catalogue is not reachable: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const ids = (body.results as Array<Record<string, unknown>>).map((row) => String(row.id))

		expect(ids).toEqual(
			expect.arrayContaining(['create-only', 'update-only', 'upsert', 'refuse-on-conflict']),
		)
		expect(String(body.default), 'an import that declares no policy must still upsert').toBe('upsert')
	})

	test('a migration is inspected before it lands, and then lands', async () => {
		await createObject('111', 'Jansen')
		await createObject('222', 'De Vries')

		const before = await countObjects()

		const file = 'bsn,naam\n111,Jansen-Bakker\n222,De Vries\n333,Yilmaz\n'
		const taken = await preview(file, 'upsert')

		expect(taken.status, `preview failed: ${JSON.stringify(taken.json)}`).toBe(201)
		expect(taken.json.state).toBe('previewed')
		expect(taken.json.total).toBe(3)

		const counts = taken.json.counts as Record<string, number>
		expect(counts.updated, 'the two rows already in the register must read as updates').toBe(2)
		expect(counts.created, 'the row not in the register must read as a create').toBe(1)
		expect(counts.refused).toBe(0)

		// A preview writes nothing. Counted rather than assumed.
		expect(await countObjects(), 'the preview changed the register').toBe(before)

		const written = await commit(String(taken.json.id), file)
		expect(written.status, `commit failed: ${JSON.stringify(written.json)}`).toBe(200)
		expect(written.json.state).toBe('committed')
		expect(written.json.applied).toBe(3)

		expect(await countObjects(), 'the commit did not create the one new row').toBe(before + 1)

		const listed = await admin.get(`${API}/objects/${registerId}/${schemaId}?limit=200`)
		const rows = (await listed.json()).results as Array<Record<string, unknown>>
		for (const row of rows) {
			const uuid = String((row['@self'] as Record<string, unknown>)?.id ?? row.id)
			if (created.includes(uuid) === false) {
				created.push(uuid)
			}
		}

		const renamed = rows.find((row) => String(row.bsn) === '111')
		expect(String(renamed?.naam), 'the update in the preview did not reach the object').toBe('Jansen-Bakker')
	})

	test('a changed file is refused, and nothing is written', async () => {
		const file = 'bsn,naam\n444,Bakker\n'
		const taken = await preview(file, 'upsert')
		expect(taken.status).toBe(201)

		const before = await countObjects()

		const refused = await commit(String(taken.json.id), 'bsn,naam\n444,Bakker\n555,Someone Else\n')
		expect(refused.status, 'a commit of a different file was accepted').toBe(409)
		expect(String(refused.json.error)).toContain('changed since it was previewed')

		expect(await countObjects(), 'the refused commit wrote something').toBe(before)
	})

	test('a first migration refuses the row that unexpectedly matched', async () => {
		const taken = await preview('bsn,naam\n111,Jansen\n666,Nieuw\n', 'create-only')
		expect(taken.status).toBe(201)

		const counts = taken.json.counts as Record<string, number>
		expect(counts.refused, 'create-only accepted a row that matches an existing object').toBe(1)
		expect(counts.created).toBe(1)

		const refusals = (taken.json.report as Record<string, unknown>).refusals as Array<Record<string, unknown>>
		expect(refusals).toHaveLength(1)
		expect(String(refusals[0].reason)).toContain('create-only')
	})

	test('a row whose key hits two objects is refused naming both', async () => {
		// Two objects sharing one bsn is exactly the state a migration walks
		// into, and exactly the one no policy may resolve by picking a side.
		const first = await createObject('777', 'Dubbel Een')
		const second = await createObject('777', 'Dubbel Twee')

		const taken = await preview('bsn,naam\n777,Dubbel\n', 'upsert')
		expect(taken.status).toBe(201)

		const counts = taken.json.counts as Record<string, number>
		expect(counts.refused, 'an ambiguous row was resolved instead of refused').toBe(1)
		expect(counts.updated).toBe(0)

		const rows = await rowsOf(String(taken.json.id))
		expect(rows).toHaveLength(1)
		expect(String(rows[0].decision)).toBe('refuse')
		expect(rows[0].candidates as string[]).toEqual(expect.arrayContaining([first, second]))
		expect(String(rows[0].reason)).toContain('more than one object')
	})

	test('a monthly correction reuses last month\'s saved mapping', async () => {
		const definition = {
			id: mappingSlug,
			name: `e2e import preview mapping ${RUN}`,
			sourceFormat: 'csv',
			version: '1.0.0',
			fieldMappings: [
				{ source: 'Burgerservicenummer', target: 'bsn' },
				{ source: 'Achternaam', target: 'naam' },
			],
		}

		const saved = await admin.post(`${API}/migration-packs`, { data: definition })
		expect(saved.ok(), `mapping create failed: ${await saved.text()}`).toBeTruthy()
		mappingId = String((await saved.json()).id)

		// Two files, two months, the same columns and the same saved mapping:
		// the point of saving it is that the second one costs nothing.
		const january = 'Burgerservicenummer,Achternaam\n888,Januari\n'
		const february = 'Burgerservicenummer,Achternaam\n999,Februari\n'

		for (const file of [january, february]) {
			const taken = await preview(file, 'upsert', { mapping: mappingSlug })
			expect(taken.status, `preview through the saved mapping failed: ${JSON.stringify(taken.json)}`).toBe(201)

			const counts = taken.json.counts as Record<string, number>
			expect(counts.created, 'the saved mapping did not map the columns').toBe(1)
			expect(counts.refused).toBe(0)
		}
	})

	test('a mapping naming a property the schema does not have is refused', async () => {
		const strayed = {
			id: `${mappingSlug}-stray`,
			name: `e2e import preview stray mapping ${RUN}`,
			sourceFormat: 'csv',
			version: '1.0.0',
			fieldMappings: [{ source: 'BSN', target: 'burgerservicenummer' }],
		}

		const saved = await admin.post(`${API}/migration-packs`, { data: strayed })
		expect(saved.ok(), `mapping create failed: ${await saved.text()}`).toBeTruthy()
		const strayId = String((await saved.json()).id)

		const refused = await preview('BSN\n123\n', 'upsert', { mapping: `${mappingSlug}-stray` })
		expect(refused.status, 'a mapping onto a property the schema lacks was accepted').toBe(400)
		expect(String(refused.json.error)).toContain('burgerservicenummer')

		await admin.delete(`${API}/migration-packs/${strayId}`)
	})
})
