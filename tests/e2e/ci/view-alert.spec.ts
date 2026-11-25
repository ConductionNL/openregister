import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SAVED VIEW COUNT ALERT: the declaration over HTTP.
 *
 * @e2e saved-search-views::a-malformed-alert-is-refused
 *
 * What this layer proves: an alert is written through the view's own save
 * path and stored in its normalised form (the default interval filled in, no
 * state until a sweep has looked), a malformed alert is refused with the
 * field that is wrong and nothing is written, `alert: null` clears it, and a
 * reader the view is not shared with cannot set one on it.
 *
 * Not claimed here: the crossing. A notification needs ViewAlertSweepJob to
 * have run, and nothing in this environment guarantees cron runs between two
 * HTTP calls; an assertion on it would be a timing race. The crossing rule
 * (fire once, stay quiet over a standing backlog, re-arm silently) is pinned
 * in tests/Unit/BackgroundJob/ViewAlertSweepJobTest.php and was walked live
 * on 25 Nov 2026 with the job forced between steps (see the change's tasks.md).
 *
 * Hermetic: it creates its own register, schema, objects and view and
 * removes them. It needs the non-admin user `tests/e2e/ci/seed.sh` seeds.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const OTHER = 'e2e-other'
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

/** The view in a response, wrapped or not. */
function viewOf(body: Record<string, unknown>): Record<string, unknown> {
	return (body.view ?? body) as Record<string, unknown>
}

test.describe.configure({ mode: 'serial' })

test.describe('a count alert on a saved view, over HTTP', () => {
	let admin: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string
	let viewUuid: string

	const alert = { operator: 'gte', threshold: 3, recipients: [`user:${ADMIN}`], channels: ['nc-notification'] }

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		other = await contextFor(OTHER, PASS)

		const reg = await admin.post(`${API}/registers`, { data: { title: `e2e view alert ${RUN}`, slug: `e2e-view-alert-${RUN}` } })
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: { title: `e2e view alert ticket ${RUN}`, slug: `e2e-view-alert-ticket-${RUN}`, properties: { title: { type: 'string' } } },
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e view alert ${RUN}`, slug: `e2e-view-alert-${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `attaching the schema failed: ${await attach.text()}`).toBeTruthy()

		for (const title of ['one', 'two']) {
			const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title } })
			expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		}
	})

	test.afterAll(async () => {
		if (viewUuid) await admin.delete(`${API}/views/${viewUuid}`)
		if (registerId) await admin.delete(`${API}/registers/${registerId}`)
	})

	test('a view is saved with its alert, normalised, with no state yet', async () => {
		const created = await admin.post(`${API}/views`, {
			data: { name: `e2e backlog ${RUN}`, query: { registers: [Number(registerId)], schemas: [Number(schemaId)] }, alert },
		})
		expect(created.status(), `view create failed: ${await created.text()}`).toBeLessThan(300)
		const view = viewOf(await created.json())
		viewUuid = String(view.uuid)

		expect(view.alert).toEqual({ ...alert, every: 300 })
		expect(view.alertState ?? null).toBeNull()

		const read = viewOf(await (await admin.get(`${API}/views/${viewUuid}`)).json())
		expect(read.alert).toEqual({ ...alert, every: 300 })
	})

	test('a malformed alert is refused naming the field, and nothing is written', async () => {
		const cases: Array<[Record<string, unknown>, string]> = [
			[{ ...alert, operator: 'above' }, 'alert.operator'],
			[{ ...alert, recipients: [] }, 'alert.recipients'],
			[{ ...alert, every: 10 }, 'alert.every'],
		]
		for (const [bad, field] of cases) {
			const refused = await admin.patch(`${API}/views/${viewUuid}`, { data: { alert: bad } })
			expect(refused.status(), `${field} was accepted: ${await refused.text()}`).toBe(422)
			expect((await refused.json()).field).toBe(field)
		}

		const read = viewOf(await (await admin.get(`${API}/views/${viewUuid}`)).json())
		expect(read.alert).toEqual({ ...alert, every: 300 })
	})

	test('somebody the view is not shared with cannot set an alert on it', async () => {
		const refused = await other.patch(`${API}/views/${viewUuid}`, { data: { alert: { ...alert, threshold: 1 } } })
		expect(refused.status()).toBeGreaterThanOrEqual(403)
		expect(refused.status()).toBeLessThan(500)

		const read = viewOf(await (await admin.get(`${API}/views/${viewUuid}`)).json())
		expect((read.alert as Record<string, unknown>).threshold).toBe(3)
	})

	test('alert: null clears it', async () => {
		const cleared = await admin.patch(`${API}/views/${viewUuid}`, { data: { alert: null } })
		expect(cleared.status(), `clearing failed: ${await cleared.text()}`).toBeLessThan(300)

		const read = viewOf(await (await admin.get(`${API}/views/${viewUuid}`)).json())
		expect(read.alert ?? null).toBeNull()
	})
})
