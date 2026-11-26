/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AN OUTSIDE DATABASE THAT STOPPED ANSWERING IS REFUSED AT ONCE, AND AN ADMINISTRATOR RESETS IT.
 *
 * Change `operate-load-shedding`, task 5.2. A `percelen` schema reads from an
 * outside database source that points at a port nothing listens on. Its list
 * is read until the source's breaker opens. From then on:
 *   - the list answers 503 within a second, with `Retry-After` and
 *     `dependency: source`, and no connection is attempted (the breaker's call
 *     count does not move);
 *   - one parcel answers 503, not 404;
 *   - an anonymous visitor gets the same 503, and the body names no host,
 *     database or user;
 *   - a signed-in caseworker who is not an administrator may not reset it (403);
 *   - an administrator opens the operations console, sees the source under
 *     "Dependencies" refusing calls, and presses Reset: the breaker reads
 *     closed and the audit trail holds a `dependency.reset` row naming them.
 *
 * NEEDS A DISTRIBUTED MEMCACHE. A breaker opens across requests only when its
 * state is shared (Redis or memcached); with Nextcloud's per-request fallback it
 * can never open. The first test checks `shared` on
 * `GET /api/operations/dependencies` and fails saying so, rather than passing
 * quietly or timing out.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own caseworker, source, register and
 * schema, resets the breaker and removes them in afterAll.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { randomBytes } from 'crypto'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

const RUN = randomBytes(4).toString('hex')
const API = '/index.php/apps/openregister/api'
const CASEWORKER = `e2e-shed-${RUN}`
const CASEWORKER_PASS = 'E2e-Shed-Pass-2026'
// Nothing listens on port 1, so every connection is refused.
const DEAD = { driver: 'pdo_mysql', host: '127.0.0.1', port: 1, dbname: `kadaster_${RUN}`, user: `reader_${RUN}` }

/** An API context for one account, or an anonymous one. */
async function contextFor(user?: string, password?: string): Promise<APIRequestContext> {
	const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
	if (user !== undefined && password !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}
	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

test.describe.configure({ mode: 'serial' })

test.describe('operate-load-shedding: an outside database that stopped answering', () => {
	let admin: APIRequestContext
	let caseworker: APIRequestContext
	let anonymous: APIRequestContext
	let sourceId = 0
	let registerId = 0
	let schemaId = 0
	let key = ''
	let userCreated = false

	/** The source's breaker as the operations API reports it, or undefined when it holds no state. */
	async function breaker(): Promise<Record<string, any> | undefined> {
		const res = await admin.get(`${API}/operations/dependencies`)
		expect(res.status(), await res.text()).toBe(200)
		return ((await res.json()).dependencies as Array<Record<string, any>>).find((entry) => entry.key === key)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		caseworker = await contextFor(CASEWORKER, CASEWORKER_PASS)
		anonymous = await contextFor()

		const user = await admin.post('/ocs/v1.php/cloud/users?format=json', { data: { userid: CASEWORKER, password: CASEWORKER_PASS } })
		expect(user.status(), await user.text()).toBe(200)
		userCreated = true

		const source = await admin.post(`${API}/sources`, { data: { title: `E2E dead database ${RUN}`, type: 'database', authConfig: DEAD } })
		expect(source.status(), await source.text()).toBeLessThanOrEqual(201)
		sourceId = (await source.json()).id
		key = `source:${sourceId}`

		const register = await admin.post(`${API}/registers`, { data: { slug: `e2e-kadaster-${RUN}`, title: `E2E kadaster ${RUN}`, source: String(sourceId) } })
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				slug: `e2e-percelen-${RUN}`,
				title: `E2E percelen ${RUN}`,
				properties: { id: { type: 'integer' }, naam: { type: 'string' } },
				authorization: { read: ['public'] },
				configuration: {
					'x-openregister-object-source': {
						provider: 'dbal-source',
						readOnly: true,
						config: { sourceId: String(sourceId), table: 'percelen', idColumn: 'id' },
					},
				},
			},
		})
		expect(schema.status(), await schema.text()).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { slug: `e2e-kadaster-${RUN}`, title: `E2E kadaster ${RUN}`, schemas: [schemaId] },
		})
		expect(linked.status(), await linked.text()).toBe(200)
	})

	test.afterAll(async () => {
		if (key) {
			await admin.post(`${API}/operations/dependencies/${encodeURIComponent(key)}/reset`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (sourceId) {
			await admin.delete(`${API}/sources/${sourceId}`)
		}
		if (userCreated) {
			await admin.delete(`/ocs/v1.php/cloud/users/${CASEWORKER}?format=json`)
		}
		for (const ctx of [admin, caseworker, anonymous]) {
			await ctx?.dispose()
		}
	})

	// @e2e load-shedding::an-unreachable-outside-database-answers-at-once
	test('the list is refused at once with Retry-After once the breaker is open, without a connection attempt', async () => {
		const state = await admin.get(`${API}/operations/dependencies`)
		expect((await state.json()).shared, 'breaker state is not shared: configure a distributed memcache (Redis) on this instance').toBe(true)

		const list = `${API}/objects/${registerId}/${schemaId}`
		let status = 0
		for (let read = 0; read < 15 && status !== 503; read++) {
			status = (await admin.get(list)).status()
			expect([502, 503], 'a dead outside database answers 502 or 503, never 500').toContain(status)
		}
		expect(status, 'the breaker opened within 15 reads').toBe(503)
		expect((await breaker())?.state).toBe('open')
		const callsBefore = (await breaker())?.calls

		const started = Date.now()
		const refused = await admin.get(list)
		const took = Date.now() - started

		expect(refused.status()).toBe(503)
		expect(took, 'an open breaker answers within a second').toBeLessThan(1000)
		expect(Number(refused.headers()['retry-after']), 'Retry-After in seconds').toBeGreaterThan(0)
		expect((await refused.json()).dependency).toBe('source')
		expect((await breaker())?.calls, 'no connection was attempted while open').toBe(callsBefore)
	})

	// @e2e load-shedding::a-record-on-a-dead-source-is-not-reported-as-missing
	test('one parcel on the dead source is 503, not 404', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${schemaId}/1`)
		expect(res.status()).toBe(503)
		expect((await res.json()).dependency).toBe('source')
	})

	// @e2e load-shedding::an-anonymous-visitor-learns-nothing-about-the-outside-system
	test('an anonymous visitor gets the same refusal and learns nothing about the outside system', async () => {
		const res = await anonymous.get(`${API}/objects/${registerId}/${schemaId}`)
		expect(res.status()).toBe(503)
		expect(Number(res.headers()['retry-after'])).toBeGreaterThan(0)
		const body = await res.text()
		expect(JSON.parse(body).dependency).toBe('source')
		for (const secret of [DEAD.host, DEAD.dbname, DEAD.user]) {
			expect(body, `the refusal names ${secret}`).not.toContain(secret)
		}
	})

	// @e2e load-shedding::a-caseworker-cannot-reset-a-breaker
	test('a caseworker who is not an administrator may not reset the breaker', async () => {
		const res = await caseworker.post(`${API}/operations/dependencies/${encodeURIComponent(key)}/reset`)
		expect(res.status()).toBe(403)
		expect((await breaker())?.state, 'the refused reset left the breaker open').toBe('open')
	})

	// @e2e load-shedding::an-administrator-resets-a-breaker-after-a-fix
	test.describe('the administrator', () => {
		test.use({ storageState: STORAGE_STATE })

		test('resets the breaker from the operations console, and the reset is audited', async ({ page }) => {
			const resets = async (): Promise<Array<Record<string, any>>> => {
				const audit = await admin.get(`${API}/audit-trails?action=dependency.reset&_limit=500`)
				expect(audit.status(), await audit.text()).toBe(200)
				return ((await audit.json()).results as Array<Record<string, any>>).filter((entry) => entry.action === 'dependency.reset')
			}
			const before = new Set((await resets()).map((entry) => entry.uuid))

			await page.goto(`${BASE}/index.php/apps/openregister/operations`)
			await expect(page.getByRole('heading', { name: 'Dependencies' })).toBeVisible({ timeout: 30000 })

			const row = page.getByRole('row').filter({ hasText: key })
			await expect(row).toBeVisible()
			await expect(row).toContainText('Refusing calls')
			await row.getByRole('button', { name: 'Reset' }).click()

			await expect.poll(async () => (await breaker())?.state ?? 'closed', { timeout: 10000 }).toBe('closed')

			const added = (await resets()).filter((entry) => !before.has(entry.uuid))
			expect(added.length, 'the reset wrote one dependency.reset row').toBe(1)
			expect(added[0].user, 'the row names the administrator').toBe(ADMIN)
		})
	})
})
