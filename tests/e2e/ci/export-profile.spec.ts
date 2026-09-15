import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * EXPORT AS ITS OWN RIGHT — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/export-as-its-own-right/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e authorization-rbac::a-reader-who-may-not-take-the-data
 * @e2e authorization-rbac::the-api-is-gated-too
 * @e2e data-import-export::the-monthly-aanlevering-has-a-fixed-shape
 * @e2e data-import-export::one-file-one-value-mode-stated
 * @e2e data-import-export::an-incident-can-be-reconstructed
 *
 * WHAT ONLY THIS LAYER CAN PROVE.
 *
 * A verb that holds in a service and not on the wire is not a control. The two
 * refusals below are taken by a real principal against the two endpoints an
 * integration actually calls: the plain object export and the profile run. A
 * mocked permission handler answers both with whatever it was told to.
 *
 * THE GRANTED CASE IS ASSERTED BESIDE EVERY REFUSAL, deliberately. A gate that
 * refuses everybody looks identical to a gate that works, and the export that
 * still has to run for a record manager is the half nobody notices breaking.
 *
 * WHAT IS DELIBERATELY NOT CLAIMED HERE. The scheduled run and the whole-set
 * bulk job both need a cron tick, so their scenarios carry their own
 * `@e2e exclude` and are covered by unit tests with a fake writer. Asserting
 * something adjacent here and anchoring it to those scenarios would be worse
 * than leaving them uncovered: it would read as coverage.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, objects and
 * profiles and removes all of them. It needs no `occ` and no docker, only the
 * two non-admin users `tests/e2e/ci/seed.sh` seeds.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/**
 * Seeded by `tests/e2e/ci/seed.sh`; both deliberately non-admin.
 * `e2e-other` is in the group `e2e-grantees`, `e2e-owner` is not. That single
 * difference is what separates a reader from an exporter below.
 */
const READER = 'e2e-owner'
const EXPORTER = 'e2e-other'
const EXPORT_GROUP = 'e2e-grantees'
const PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** The metadata line every CSV export opens with. */
const METADATA_PREFIX = '#openregister-export '

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

test.describe('export as its own right over HTTP', () => {
	let admin: APIRequestContext
	let reader: APIRequestContext
	let exporter: APIRequestContext
	let registerId: string
	let schemaId: string
	let storedProfileId: string
	let renderedProfileId: string
	const objectUuids: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		reader = await contextFor(READER, PASS)
		exporter = await contextFor(EXPORTER, PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e export right register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// read is granted to everybody signed in; export only to the group the
		// exporter is in. That is the whole control, written down.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e export right schema ${RUN}`,
				description: 'e2e',
				properties: {
					zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 64 },
					status: {
						type: 'string',
						title: 'Status',
						enum: ['open', 'afgerond'],
						enumNames: ['Open', 'Afgerond'],
					},
					toelichting: { type: 'string', title: 'Toelichting', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
					export: [EXPORT_GROUP],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		for (const row of [
			{ zaaknummer: 'Z-001', status: 'afgerond', toelichting: 'eerste' },
			{ zaaknummer: 'Z-002', status: 'open', toelichting: 'tweede' },
		]) {
			const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: row })
			expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
			objectUuids.push(uuidOf(await obj.json()))
		}
	})

	test.afterAll(async () => {
		for (const id of [storedProfileId, renderedProfileId]) {
			if (id) {
				await admin.delete(`${API}/export-profiles/${id}`)
			}
		}

		for (const uuid of objectUuids) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			}
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a reader who may not take the data is refused, and told which verb', async () => {
		// The reader can open the objects. That read is asserted first, because
		// a 403 on the export means nothing if the principal could not read
		// anything either.
		const list = await reader.get(`${API}/objects/${registerId}/${schemaId}`)
		expect(list.status(), `the reader cannot read, so the export refusal proves nothing: ${await list.text()}`).toBe(200)

		const refused = await reader.get(`${API}/objects/${registerId}/${schemaId}/export?format=csv`)

		expect(refused.status()).toBe(403)

		const body = await refused.json()
		expect(body.verb, 'the refusal must name the verb, not the record').toBe('export')
		expect(body.rule).toBe('export-right-missing')
		expect(String(body.message)).toContain('export right')
	})

	test('the holder of the export grant still gets the file', async () => {
		const allowed = await exporter.get(`${API}/objects/${registerId}/${schemaId}/export?format=csv`)

		expect(allowed.status(), `the export grant does not work: ${await allowed.text()}`).toBe(200)
		expect(allowed.headers()['content-type']).toContain('text/csv')
		expect(await allowed.text()).toContain('Z-001')
	})

	test('the API is gated too, on the profile run an integration calls', async () => {
		const created = await exporter.post(`${API}/export-profiles`, {
			data: {
				name: `e2e stored ${RUN}`,
				registerId: Number(registerId),
				schemaId: Number(schemaId),
				fields: ['status', 'zaaknummer'],
				valueMode: 'stored',
				format: 'csv',
			},
		})
		expect(created.status(), `profile create failed: ${await created.text()}`).toBe(201)
		storedProfileId = String((await created.json()).id)

		// The reader is refused on the profile run exactly as on the plain
		// export. Two endpoints, one verb, one answer.
		const refused = await reader.get(`${API}/export-profiles/${storedProfileId}/run`)
		expect(refused.status()).toBe(403)
		expect((await refused.json()).verb).toBe('export')
	})

	test('the monthly aanlevering has the profile shape, not the schema one', async () => {
		const run = await exporter.get(`${API}/export-profiles/${storedProfileId}/run`)
		expect(run.status(), `profile run failed: ${await run.text()}`).toBe(200)

		const lines = (await run.text()).split('\n')

		// The profile declares status first and zaaknummer second, and leaves
		// toelichting out. The schema declares the opposite order and carries
		// all three.
		expect(lines[1]).toBe('"status","zaaknummer"')
		expect(lines[1]).not.toContain('toelichting')
		expect(lines.slice(2).filter((l) => l.trim() !== '')).toHaveLength(2)
	})

	test('one file, one value mode, and the file says which', async () => {
		const created = await exporter.post(`${API}/export-profiles`, {
			data: {
				name: `e2e rendered ${RUN}`,
				registerId: Number(registerId),
				schemaId: Number(schemaId),
				fields: ['zaaknummer', 'status'],
				valueMode: 'rendered',
				format: 'csv',
			},
		})
		expect(created.status(), `profile create failed: ${await created.text()}`).toBe(201)
		renderedProfileId = String((await created.json()).id)

		const run = await exporter.get(`${API}/export-profiles/${renderedProfileId}/run`)
		expect(run.status(), `profile run failed: ${await run.text()}`).toBe(200)

		const text = await run.text()
		const lines = text.split('\n')

		expect(lines[0]).toContain(METADATA_PREFIX.trim())
		expect(lines[0]).toContain('valueMode=rendered')
		expect(run.headers()['x-openregister-export-value-mode']).toBe('rendered')

		// The code list value comes out as its administered label, which is the
		// difference a publication needs and a data extract must not have.
		expect(text).toContain('"Afgerond"')
		expect(text).not.toContain('"afgerond"')
	})

	test('the stored profile keeps the raw code, so the two modes really differ', async () => {
		const run = await exporter.get(`${API}/export-profiles/${storedProfileId}/run`)
		const text = await run.text()

		expect(text).toContain('"afgerond"')
		expect(text).not.toContain('"Afgerond"')
		expect(run.headers()['x-openregister-export-value-mode']).toBe('stored')
	})

	test('an incident can be reconstructed from the trail', async () => {
		const trail = await admin.get('/index.php/apps/openregister/api/audit-trails?limit=200')
		expect(trail.status(), `the audit trail is unreadable: ${await trail.text()}`).toBe(200)

		const body = await trail.json()
		const rows = (body.results ?? []) as Array<Record<string, unknown>>
		const ours = rows.filter((row) => String(row.action ?? '').startsWith('export.'))

		expect(ours.length, 'no export reached the audit trail').toBeGreaterThan(0)

		const completed = ours.filter((row) => row.action === 'export.completed')
		expect(completed.length).toBeGreaterThan(0)

		const summary = (completed[0].resultSummary ?? {}) as Record<string, unknown>
		expect(summary, 'the entry must name what left').toHaveProperty('profile')
		expect(summary).toHaveProperty('rowCount')
	})

	test('the published contract is what a consumer reads, not a hardcoded guess', async () => {
		const contract = await exporter.get(`${API}/export-profiles/contract`)
		expect(contract.status()).toBe(200)

		const body = await contract.json()
		expect(body.csvMetadataPrefix).toBe(METADATA_PREFIX)
		expect(body.valueModes).toEqual(['stored', 'rendered'])
		expect(body.headers.valueMode).toBe('X-OpenRegister-Export-Value-Mode')
	})
})
