import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AN EXPORT IS A FILE WITH A LIFE: the exports area over HTTP.
 *
 * @e2e data-import-export::a-handler-sees-their-own-exports
 *
 * What this layer proves: a profile run lands in `GET /api/exports` for the
 * person who ran it and for nobody else, and a run that produced no file
 * (served straight to the caller) offers no download. The scope is the half a
 * mocked right cannot prove, so it is taken by two real principals.
 *
 * Not claimed here: a run with a file comes from the scheduled runner or the
 * whole-set job, both on a cron tick, and expiry needs the clock to move. Those
 * scenarios are covered by ExportRunRecorderTest and ExportWholeSetActionTest.
 *
 * Hermetic: it creates its own register, schema, object and profile and
 * removes them. It needs the two non-admin users `tests/e2e/ci/seed.sh` seeds.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const MAKER = 'e2e-other'
const STRANGER = 'e2e-owner'
const EXPORT_GROUP = 'e2e-grantees'
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

test.describe.configure({ mode: 'serial' })

test.describe('the exports area over HTTP', () => {
	let admin: APIRequestContext
	let maker: APIRequestContext
	let stranger: APIRequestContext
	let registerId: string
	let schemaId: string
	let profileId: string
	let objectUuid: string
	const profileName = `e2e export life ${RUN}`

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		maker = await contextFor(MAKER, PASS)
		stranger = await contextFor(STRANGER, PASS)

		const reg = await admin.post(`${API}/registers`, { data: { title: `e2e export life register ${RUN}`, description: 'e2e' } })
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e export life schema ${RUN}`,
				description: 'e2e',
				properties: { zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 64 } },
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

		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { zaaknummer: 'Z-101' } })
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String((body['@self'] ?? {}).id ?? body.id ?? body.uuid)

		const created = await maker.post(`${API}/export-profiles`, {
			data: { name: profileName, registerId: Number(registerId), schemaId: Number(schemaId), fields: ['zaaknummer'], valueMode: 'stored', format: 'csv' },
		})
		expect(created.status(), `profile create failed: ${await created.text()}`).toBe(201)
		profileId = String((await created.json()).id)
	})

	test.afterAll(async () => {
		if (profileId) await admin.delete(`${API}/export-profiles/${profileId}`)
		if (objectUuid) await admin.delete(`${API}/objects/${registerId}/${schemaId}/${objectUuid}`)
		if (schemaId) await admin.delete(`${API}/schemas/${schemaId}`)
		if (registerId) await admin.delete(`${API}/registers/${registerId}`)
	})

	test('a handler sees their own export, and a stranger does not', async () => {
		const run = await maker.get(`${API}/export-profiles/${profileId}/run`)
		expect(run.status(), `the profile run failed: ${await run.text()}`).toBe(200)

		const mine = await maker.get(`${API}/exports?source=export-profile`)
		expect(mine.status()).toBe(200)
		const rows = ((await mine.json()).results ?? []) as Array<Record<string, unknown>>
		const row = rows.find((r) => String(r.filename ?? '').length > 0 && String(r.actor) === MAKER)
		expect(row, 'the maker does not see the run they just made').toBeTruthy()
		expect(Number(row?.downloadCount)).toBe(1)

		const theirs = await stranger.get(`${API}/exports?source=export-profile`)
		expect(theirs.status()).toBe(200)
		const strangerRows = ((await theirs.json()).results ?? []) as Array<Record<string, unknown>>
		expect(strangerRows.find((r) => r.uuid === row?.uuid), 'a stranger sees somebody else\'s export').toBeFalsy()

		// The run was served straight to the caller and left no file: the
		// register has nothing to hand back, and a stranger learns nothing.
		const download = await maker.get(`${API}/exports/${row?.uuid}/download`)
		expect(download.status()).toBe(410)
		const refused = await stranger.get(`${API}/exports/${row?.uuid}/download`)
		expect(refused.status()).toBe(404)
	})
})
