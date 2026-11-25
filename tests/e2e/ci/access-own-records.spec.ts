import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OWN RECORDS: a schema whose read rule is `@creator`, over HTTP.
 *
 * @e2e authorization-rbac::each-user-sees-only-the-records-they-created
 *
 * Two real non-admin principals each create records in a schema with
 * `read: ["@creator"]`; each lists only their own, the total counts only their
 * own, and a read of the other's record answers like a missing one. The
 * administrator, the control, sees all of them: a scope that hid everything
 * from everybody would pass the first assertions alone.
 *
 * Hermetic: it creates its own register, schema and records and removes them.
 * It needs the two non-admin users `tests/e2e/ci/seed.sh` seeds.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const ANNA = 'e2e-owner'
const BRAM = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'
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

/** The titles and total of a list response. */
async function listing(ctx: APIRequestContext, path: string): Promise<{ titles: string[], total: number }> {
	const response = await ctx.get(path)
	expect(response.status(), `listing failed: ${await response.text()}`).toBe(200)
	const body = await response.json()
	const results = (body.results ?? []) as Array<Record<string, unknown>>

	return { titles: results.map((r) => String(r.title)).sort(), total: Number(body.total) }
}

test.describe.configure({ mode: 'serial' })

test.describe('a schema whose read rule is @creator', () => {
	let admin: APIRequestContext
	let anna: APIRequestContext
	let bram: APIRequestContext
	let registerId: string
	let schemaId: string
	const ids: Record<string, string> = {}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anna = await contextFor(ANNA, PASS)
		bram = await contextFor(BRAM, PASS)

		const reg = await admin.post(`${API}/registers`, { data: { title: `e2e own records ${RUN}`, slug: `e2e-own-${RUN}` } })
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e verzoek ${RUN}`,
				slug: `e2e-verzoek-${RUN}`,
				properties: { title: { type: 'string' } },
				authorization: { read: ['@creator'], create: ['authenticated'], update: ['@creator'], delete: ['@creator'] },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e own records ${RUN}`, slug: `e2e-own-${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `attaching the schema failed: ${await attach.text()}`).toBeTruthy()

		for (const [who, ctx] of [['anna', anna], ['bram', bram]] as const) {
			for (const n of [1, 2]) {
				const created = await ctx.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title: `${who} ${n}` } })
				expect(created.status(), `${who} could not create: ${await created.text()}`).toBe(201)
				ids[`${who} ${n}`] = String((await created.json()).id)
			}
		}
	})

	test.afterAll(async () => {
		for (const [title, id] of Object.entries(ids)) {
			await (title.startsWith('anna') ? anna : bram).delete(`${API}/objects/${registerId}/${schemaId}/${id}`)
		}
		if (schemaId) await admin.delete(`${API}/schemas/${schemaId}`)
		if (registerId) await admin.delete(`${API}/registers/${registerId}`)
	})

	test('each user lists only the records they created, and the total agrees', async () => {
		expect(await listing(anna, `${API}/objects/${registerId}/${schemaId}`)).toEqual({ titles: ['anna 1', 'anna 2'], total: 2 })
		expect(await listing(bram, `${API}/objects/${registerId}/${schemaId}`)).toEqual({ titles: ['bram 1', 'bram 2'], total: 2 })
	})

	test('a read of somebody else\'s record answers like a missing one', async () => {
		const theirs = await anna.get(`${API}/objects/${registerId}/${schemaId}/${ids['bram 1']}`)
		expect([403, 404]).toContain(theirs.status())

		const mine = await anna.get(`${API}/objects/${registerId}/${schemaId}/${ids['anna 1']}`)
		expect(mine.status()).toBe(200)
	})

	test('the administrator, the control, sees every record', async () => {
		const all = await listing(admin, `${API}/objects/${registerId}/${schemaId}`)
		expect(all).toEqual({ titles: ['anna 1', 'anna 2', 'bram 1', 'bram 2'], total: 4 })
	})
})
