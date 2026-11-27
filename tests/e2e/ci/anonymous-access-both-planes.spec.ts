import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ANONYMOUS ACCESS, MEASURED ON A RUNNING INSTANCE IN BOTH DIRECTIONS.
 *
 * @e2e consolidate-permission-handling::distinguishing-absence-from-refusal
 *
 * consolidate-permission-handling task 4: the unit pins prove the evaluator
 * in doubles; this proves the running stack. A guard nobody has watched refuse
 * is a guard nobody has tested, and a guard that refuses everything passes
 * every refusal assertion, so each refusal here is paired with a grant:
 * - an anonymous read of a schema granting `public` read returns its rows,
 *   and the same read of a schema without that grant returns none;
 * - an anonymous write to the public-read schema is refused (#1955: a write
 *   needs its own `public` grant), while the administrator's write succeeds;
 * - an anonymous update of the register is refused and leaves it unchanged.
 *
 * HERMETIC: it creates its own register, two schemas and their objects, and
 * removes them. It needs no `occ`.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)

/** An API context, signed in when credentials are given and anonymous otherwise. */
async function contextFor(credentials?: [string, string]): Promise<APIRequestContext> {
	const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
	if (credentials !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${credentials[0]}:${credentials[1]}`).toString('base64')}`
	}
	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

test.describe('anonymous access follows the public grant, both ways', () => {
	let admin: APIRequestContext
	let anonymous: APIRequestContext
	let registerId: string
	let openSchemaId: string
	let closedSchemaId: string
	const created: Array<[string, string]> = []

	test.beforeAll(async () => {
		admin = await contextFor([ADMIN, ADMIN_PASS])
		anonymous = await contextFor()

		const open = await admin.post(`${API}/schemas`, {
			data: {
				title: `Anon open ${RUN}`,
				slug: `anon-open-${RUN}`,
				properties: { title: { type: 'string' } },
				authorization: { read: ['public'] },
			},
		})
		expect(open.ok(), await open.text()).toBe(true)
		openSchemaId = String((await open.json()).id)

		const closed = await admin.post(`${API}/schemas`, {
			data: { title: `Anon closed ${RUN}`, slug: `anon-closed-${RUN}`, properties: { title: { type: 'string' } } },
		})
		expect(closed.ok(), await closed.text()).toBe(true)
		closedSchemaId = String((await closed.json()).id)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `Anon planes ${RUN}`, slug: `anon-planes-${RUN}`, schemas: [Number(openSchemaId), Number(closedSchemaId)] },
		})
		expect(reg.ok(), await reg.text()).toBe(true)
		registerId = String((await reg.json()).id)

		for (const schemaId of [openSchemaId, closedSchemaId]) {
			for (const title of ['een', 'twee', 'drie']) {
				const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title } })
				expect(res.status(), await res.text()).toBe(201)
				created.push([schemaId, (await res.json())['@self'].id])
			}
		}
	})

	test.afterAll(async () => {
		for (const [schemaId, uuid] of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const schemaId of [openSchemaId, closedSchemaId]) {
			if (schemaId !== undefined) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		await admin.dispose()
		await anonymous.dispose()
	})

	test('an anonymous read returns the rows of a public schema and none of a closed one', async () => {
		const open = await anonymous.get(`${API}/objects/${registerId}/${openSchemaId}`)
		expect(open.status()).toBe(200)
		expect((await open.json()).results).toHaveLength(3)

		const closed = await anonymous.get(`${API}/objects/${registerId}/${closedSchemaId}`)
		expect(closed.status()).toBe(200)
		expect((await closed.json()).results).toHaveLength(0)
	})

	test('an anonymous write to a public-read schema is refused, the administrator\'s is not', async () => {
		const refused = await anonymous.post(`${API}/objects/${registerId}/${openSchemaId}`, { data: { title: 'anoniem' } })
		expect(refused.status()).toBe(403)

		const listed = await admin.get(`${API}/objects/${registerId}/${openSchemaId}`)
		expect((await listed.json()).results.map((row: { title: string }) => row.title)).not.toContain('anoniem')

		const allowed = await admin.post(`${API}/objects/${registerId}/${openSchemaId}`, { data: { title: 'beheerder' } })
		expect(allowed.status()).toBe(201)
		created.push([openSchemaId, (await allowed.json())['@self'].id])
	})

	test('an anonymous register update is refused and leaves the register as it was', async () => {
		const refused = await anonymous.put(`${API}/registers/${registerId}`, { data: { title: 'Gekaapt' } })
		expect([401, 403]).toContain(refused.status())

		const register = await admin.get(`${API}/registers/${registerId}`)
		expect((await register.json()).title).toBe(`Anon planes ${RUN}`)
	})
})
