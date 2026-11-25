/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A COLLEAGUE WHO NEITHER OWNS A FLOW NOR HOLDS `flow.update` MAY NOT RUN IT.
 *
 * Change `flow-runs-honour-their-declaration`, task 5.2: the refusal over HTTP,
 * as two real accounts. `e2e-owner` owns the flow; `e2e-other` is an ordinary
 * signed-in colleague. The controls are the owner, the administrator and the
 * colleague once the right is granted to a group they are in.
 *
 * `flow.update` is narrowable and open to every signed-in user until an
 * administrator narrows it, so the spec narrows it to `admin` for the refusal
 * and puts back what it found afterwards. That is a global setting: the spec
 * runs its tests in order and restores it in afterAll, whatever happened.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own flow and removes it. The two
 * accounts and the `e2e-grantees` group (which holds `e2e-other`) come from
 * tests/e2e/ci/seed.sh.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const OWNER = process.env.E2E_OWNER_USER || 'e2e-owner'
const OTHER = process.env.E2E_OTHER_USER || 'e2e-other'
const PASS = process.env.E2E_SEED_PASSWORD || 'E2e-Share-Pass-123'
const GRANTEES = 'e2e-grantees'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const RIGHTS = `${API}/settings/action-rights`
const RIGHT = 'flow.update'

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

test.describe('flow-runs-honour-their-declaration: who may run somebody else\'s flow', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let flowId = ''
	let ownersRun = ''
	let rightBefore: string[] | null = null

	/** Set the groups that hold `flow.update`. */
	async function grantRightTo(groups: string[]): Promise<void> {
		const res = await admin.put(RIGHTS, { data: { groups: { [RIGHT]: groups } } })
		expect(res.status(), `setting ${RIGHT} failed: ${await res.text()}`).toBe(200)
	}

	/** Run the flow as one user. */
	async function run(ctx: APIRequestContext) {
		return ctx.post(`${API}/flows/${flowId}/run`, { data: {} })
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		for (const [ctx, uid] of [[owner, OWNER], [other, OTHER]] as const) {
			const res = await ctx.get(`${API}/flows`)
			expect(res.status(), `seeded account '${uid}' cannot authenticate; did seed.sh run?`).toBeLessThan(400)
		}

		const rights = await admin.get(RIGHTS)
		expect(rights.status(), await rights.text()).toBe(200)
		const entry = ((await rights.json()).actions as Array<{ action: string, groups: string[] }>)
			.find((a) => a.action === RIGHT)
		expect(entry, `${RIGHT} is not in the action-rights catalogue`).toBeTruthy()
		rightBefore = entry!.groups

		const created = await owner.post(`${API}/flows`, {
			data: {
				name: `e2e run refusal ${RUN}`,
				description: 'Created by flow-run-refusal.spec.ts',
				trigger: 'manual',
				enabled: true,
				nodes: [{ id: `end-${RUN}`, type: 'openregister.end', x: 120, y: 120, start: true, config: { message: 'done' } }],
				edges: [],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		const flow = await created.json()
		flowId = String(flow.id)
		expect(flow.owner).toBe(OWNER)
	})

	test.afterAll(async () => {
		if (rightBefore !== null) {
			await grantRightTo(rightBefore)
		}
		if (flowId) {
			await admin.delete(`${API}/flows/${flowId}`)
		}
	})

	test('with the right narrowed, the colleague is refused and told why; owner and administrator run', async () => {
		await grantRightTo(['admin'])

		const ownerRun = await run(owner)
		expect(ownerRun.status(), await ownerRun.text()).toBe(201)
		ownersRun = String((await ownerRun.json()).uuid)

		const refused = await run(other)
		expect(refused.status(), await refused.text()).toBe(403)
		const body = await refused.json()
		expect(String(body.detail ?? body.error ?? '')).toContain(RIGHT)

		const adminRun = await run(admin)
		expect(adminRun.status(), await adminRun.text()).toBe(201)
	})

	test('the colleague may not retry the owner\'s run either', async () => {
		const retried = await other.post(`${API}/flow-runs/${ownersRun}/retry`, { data: {} })
		expect([403, 404], await retried.text()).toContain(retried.status())
	})

	test('granted the right through a group, the same colleague runs it', async () => {
		await grantRightTo([GRANTEES])

		const allowed = await run(other)
		expect(allowed.status(), await allowed.text()).toBe(201)
	})
})
