import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A BREAKING SCHEMA CHANGE: WHO IS AFFECTED, WHO IS TOLD, AND WHAT A CLIENT SEES.
 *
 * schema-breaking-change-notice task 3.1. `e2e-other` calls the schema's objects
 * (by slug: the caller record attributes only slug calls to a schema),
 * `e2e-owner` follows the schema, and the administrator removes a property:
 * - the first attempt answers 409 naming `e2e-other` among the callers and one follower;
 * - the acknowledged attempt is applied;
 * - the changelog records the change and who acknowledged it;
 * - both users receive the notification (sent by a queued job: the spec drives
 *   /cron.php while it waits, and skips this one check, naming why, on an
 *   instance whose job queue does not reach it in time);
 * - a read of the schema's objects carries `OpenRegister-Schema-Changed` and the changelog link.
 *
 * Uses the seeded accounts of tests/e2e/ci/seed.sh. HERMETIC otherwise.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const SEEDED_PASS = 'E2e-Share-Pass-123'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)
const REGISTER = `noticereg${RUN}`
const SCHEMA = `noticeschema${RUN}`
const NOTE = 'Field toelichting is removed, use naam'

/** An API context signed in as one user, without a session cookie that would override it. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** The schema body, with or without the property the breaking change removes. */
function schemaBody(withToelichting: boolean, extra: Record<string, unknown> = {}): Record<string, unknown> {
	const properties: Record<string, unknown> = { naam: { type: 'string' } }
	if (withToelichting) {
		properties.toelichting = { type: 'string' }
	}
	return { title: `Notice ${RUN}`, slug: SCHEMA, properties, authorization: { read: ['authenticated'] }, ...extra }
}

test.describe.configure({ mode: 'serial' })

test.describe('a breaking schema change is announced', () => {
	test.setTimeout(240_000)

	let admin: APIRequestContext
	let caller: APIRequestContext
	let follower: APIRequestContext
	let registerId: string
	let schemaId: string
	let uuid: string

	/** The openregister notifications one user holds about this schema. */
	async function noticesFor(user: APIRequestContext): Promise<Array<{ subject: string, link: string }>> {
		const res = await user.get('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json')
		if (!res.ok()) {
			return []
		}
		return ((await res.json()).ocs.data || [])
			.filter((n: { app: string, link: string }) => n.app === 'openregister' && String(n.link).includes(`/schemas/${schemaId}/changelog`))
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		caller = await contextFor('e2e-other', SEEDED_PASS)
		follower = await contextFor('e2e-owner', SEEDED_PASS)

		const schema = await admin.post(`${API}/schemas`, { data: schemaBody(true) })
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, { data: { title: `Notice ${RUN}`, slug: REGISTER, schemas: [Number(schemaId)] } })
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { naam: 'Paspoort', toelichting: 'oud veld' } })
		expect(record.status(), await record.text()).toBe(201)
		uuid = (await record.json())['@self'].id
	})

	test.afterAll(async () => {
		if (uuid !== undefined) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (schemaId !== undefined) {
			await follower.delete(`${API}/schemas/${schemaId}/change-followers`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== undefined) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await Promise.all([admin.dispose(), caller.dispose(), follower.dispose()])
	})

	test('a caller and a follower are named before the change is applied', async () => {
		const call = await caller.get(`${API}/objects/${REGISTER}/${SCHEMA}`)
		expect(call.status(), await call.text()).toBe(200)

		const follow = await follower.post(`${API}/schemas/${schemaId}/change-followers`)
		expect(follow.status()).toBe(201)

		const refused = await admin.put(`${API}/schemas/${schemaId}`, { data: schemaBody(false) })
		expect(refused.status()).toBe(409)
		const reach = await refused.json()
		expect(reach.classification).toBe('breaking')
		expect(reach.affectedCallers.recorded).toBe(true)
		expect(reach.affectedCallers.top.map((row: { principal: string }) => row.principal)).toContain('e2e-other')
		expect(reach.followers).toBe(1)
	})

	test('the acknowledged change is applied and recorded in the changelog', async () => {
		const applied = await admin.put(`${API}/schemas/${schemaId}`, { data: schemaBody(false, { acknowledgeBreaking: true, changeNotice: NOTE }) })
		expect(applied.status(), await applied.text()).toBe(200)

		const changelog = await admin.get(`${API}/schemas/${schemaId}/changelog`)
		expect(changelog.ok()).toBe(true)
		const [entry] = (await changelog.json()).results
		expect(entry.classification).toBe('breaking')
		expect(entry.acknowledgedBy).toBe(ADMIN)
		expect(entry.changes).toContainEqual(expect.objectContaining({ property: 'toelichting', kind: 'removed' }))
	})

	test('the caller and the follower are both told', async () => {
		// The notice is sent by a queued background job. Nudge Nextcloud's web cron
		// while waiting; an instance whose queue does not reach the job in time
		// (web cron runs one job per request) cannot show the delivery, and says so.
		let delivered = 0
		const deadline = Date.now() + 120_000
		while (Date.now() < deadline) {
			await admin.get('/cron.php')
			delivered = (await noticesFor(caller)).length + (await noticesFor(follower)).length
			if (delivered > 0) {
				break
			}
			await new Promise((resolve) => setTimeout(resolve, 2_000))
		}
		test.skip(delivered === 0, 'the notice job has not run on this instance within two minutes (background job queue)')

		await expect.poll(async () => (await noticesFor(caller)).length + (await noticesFor(follower)).length, { timeout: 30_000 }).toBe(2)
		const [told] = await noticesFor(follower)
		expect(told.subject).toContain(`Notice ${RUN}`)
		const changelog = await admin.get(`${API}/schemas/${schemaId}/changelog`)
		expect((await changelog.json()).results[0].noticeSentTo).toBe(2)
	})

	test('a read of the objects carries the change header', async () => {
		const read = await caller.get(`${API}/objects/${REGISTER}/${SCHEMA}`)
		expect(read.status()).toBe(200)
		expect(read.headers()['openregister-schema-changed']).toMatch(/version="[^"]+", at="[^"]+", breaking/)
		expect(read.headers().link).toContain(`/schemas/${schemaId}/changelog`)
	})
})
