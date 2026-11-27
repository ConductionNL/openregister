import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RESTORING A VERSION IS A SAVE, AND THE HISTORY SAYS SO.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e content-versioning::a-record-editor-restores-an-earlier-version
 * @e2e content-versioning::a-version-the-schema-no-longer-accepts-is-not-restored
 *
 * A record is edited twice and then restored to its first version through
 * `POST .../revert`, the route the history surface calls. The restore lands
 * as a NEW version holding the first version's values, with a `revert` entry
 * in the audit trail naming the version restored to; the later edits stay in
 * the history. A version the schema has since stopped accepting is refused
 * with 422 and the record is left as it was.
 *
 * WHY THE ROUTE AND NOT A TAB. The history tab with its restore button is
 * nextcloud-vue's `CnVersionHistory`, mounted by the apps that show a record's
 * history; OpenRegister's own object page has no such tab. What this change
 * built is the save path behind the button, so that is what is driven here.
 *
 * HERMETIC. It creates its own register, schema and record and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

const PROPERTIES = {
	naam: { type: 'string', title: 'Naam', maxLength: 128 },
	bedrag: { type: 'number', title: 'Bedrag' },
	einddatum: { type: 'string', title: 'Einddatum', format: 'date' },
}

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

test.describe('a version restored through the save path', () => {
	let admin: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let schemaTitle = ''
	let uuid = ''
	let firstVersion = ''

	const record = (): string => `${API}/objects/${registerId}/${schemaId}/${uuid}`

	/** The record's write entries, newest first, without the reads. */
	async function writes(): Promise<Array<Record<string, any>>> {
		const res = await admin.get(`${record()}/audit-trails?_limit=200`)
		expect(res.ok(), `audit read failed: ${await res.text()}`).toBeTruthy()

		return ((await res.json()).results as Array<Record<string, any>>).filter((entry) => entry.action !== 'read')
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e revert register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		schemaTitle = `e2e revert contract ${RUN}`
		const sch = await admin.post(`${API}/schemas`, {
			data: { title: schemaTitle, description: 'e2e', properties: PROPERTIES },
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e revert register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		const created = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { naam: 'Contract 7', bedrag: 100 },
		})
		expect(created.ok(), `object create failed: ${await created.text()}`).toBeTruthy()
		const body = await created.json()
		uuid = String(body['@self'].id)
		firstVersion = String(body['@self'].version)
	})

	test.afterAll(async () => {
		if (uuid !== '') {
			await admin.delete(record())
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
	})

	test('two edits, then the first version restored as a new one', async () => {
		for (const data of [
			{ naam: 'Contract 7', bedrag: 200, einddatum: '2027-01-31' },
			{ naam: 'Contract 7 (verlengd)', bedrag: 300, einddatum: '2027-12-31' },
		]) {
			const res = await admin.put(record(), { data })
			expect(res.ok(), `edit failed: ${await res.text()}`).toBeTruthy()
		}
		const beforeRevert = String((await (await admin.get(record())).json())['@self'].version)
		expect(beforeRevert).not.toBe(firstVersion)

		const reverted = await admin.post(`${record()}/revert`, { data: { version: firstVersion } })
		expect(reverted.status(), await reverted.text()).toBe(200)

		const now = await (await admin.get(record())).json()
		expect(now.naam).toBe('Contract 7')
		expect(now.bedrag).toBe(100)
		expect(now.einddatum ?? null, 'a value the first version did not have is gone').toBeNull()
		expect(String(now['@self'].version), 'the restore is a new version').not.toBe(beforeRevert)
		expect(String(now['@self'].version)).not.toBe(firstVersion)

		const history = await writes()
		const revert = history.find((entry) => entry.action === 'revert')
		expect(revert, 'the audit trail has a revert entry').toBeTruthy()
		expect(revert?.changed?.revertedToVersion).toBe(firstVersion)
		expect(
			history.filter((entry) => entry.action === 'update'),
			'the two edits stay in the history',
		).toHaveLength(2)
	})

	test('a version the schema no longer accepts is refused and nothing changes', async () => {
		const tightened = await admin.put(`${API}/schemas/${schemaId}`, {
			data: {
				title: schemaTitle,
				description: 'e2e',
				properties: PROPERTIES,
				required: ['naam', 'einddatum'],
				// Making a field required is a breaking change, and the schema API
				// asks for it to be acknowledged.
				acknowledgeBreaking: true,
			},
		})
		expect(tightened.ok(), `schema update failed: ${await tightened.text()}`).toBeTruthy()

		const before = await (await admin.get(record())).json()
		const writesBefore = (await writes()).length

		const refused = await admin.post(`${record()}/revert`, { data: { version: firstVersion } })
		expect(refused.status(), await refused.text()).toBe(422)
		expect(await refused.text()).toContain('einddatum')

		const after = await (await admin.get(record())).json()
		expect(after['@self'].version).toBe(before['@self'].version)
		expect(after.naam).toBe(before.naam)
		expect((await writes()).length, 'the refused restore wrote no entry').toBe(writesBefore)
	})
})
