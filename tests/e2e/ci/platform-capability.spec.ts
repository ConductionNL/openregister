import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE INSTANCE TELLS A CLIENT WHAT IT SERVES BEFORE THE CLIENT TRIES.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e deep-link-registry::a-client-learns-the-limits-before-it-tries
 * @e2e deep-link-registry::capabilities-are-not-an-enumeration-of-the-data-model
 * @e2e deep-link-registry::one-source-one-answer
 *
 * `PlatformCapability` publishes `openregister.platform` in Nextcloud's own
 * capabilities answer (`/ocs/v2.php/cloud/capabilities`). The unit tests build
 * the block from doubles; this spec reads it the way an integrator does,
 * through OCS and as an anonymous client, because it is an
 * `IPublicCapability` and a block that only appears for a signed-in caller
 * would be no help to a client deciding whether to sign in at all.
 *
 * The per-app block (`apps`) is read for its shape only. A claiming app is an
 * app that registers deep links in its own boot, and the hermetic CI instance
 * runs OpenRegister alone, so the scenario that names a leaf app by its id
 * stays with the unit tests (PlatformCapabilityTest).
 *
 * HERMETIC. It creates one register with a distinctive name, to prove the
 * block does not list it, and removes it.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const CAPABILITIES = '/ocs/v2.php/cloud/capabilities?format=json'

/** An API context; with no user it is an anonymous client. */
async function contextFor(user?: string, password?: string): Promise<APIRequestContext> {
	const headers: Record<string, string> = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	if (user !== undefined && password !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}

	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

/** The platform block out of Nextcloud's capabilities answer. */
async function platformBlock(ctx: APIRequestContext): Promise<Record<string, any>> {
	const res = await ctx.get(CAPABILITIES)
	expect(res.ok(), `capabilities read failed: ${await res.text()}`).toBeTruthy()
	const block = (await res.json())?.ocs?.data?.capabilities?.openregister?.platform
	expect(block, 'openregister.platform is in the capabilities answer').toBeTruthy()

	return block as Record<string, any>
}

test.describe.configure({ mode: 'serial' })

test.describe('the platform capability block', () => {
	let admin: APIRequestContext
	let anonymous: APIRequestContext
	let registerId = ''
	const registerTitle = `e2e capability register ${RUN}`

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anonymous = await contextFor()

		const reg = await admin.post(`${API}/registers`, {
			data: { title: registerTitle, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)
	})

	test.afterAll(async () => {
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await anonymous?.dispose()
	})

	test('an anonymous client reads the versions, the limits and the app blocks', async () => {
		const block = await platformBlock(anonymous)

		expect(typeof block.version, 'the app version is published').toBe('string')
		expect(block.version.length).toBeGreaterThan(0)

		const versions = block.apiVersions as Array<Record<string, any>>
		expect(versions.length, 'at least one API version is served').toBeGreaterThan(0)
		for (const v of versions) {
			expect(typeof v.version).toBe('string')
			expect(typeof v.status, `version ${v.version} carries its status`).toBe('string')
		}
		expect(
			versions.map((v) => v.version),
			'the current version is one of the served versions',
		).toContain(block.currentVersion)

		expect(block.limits.uploadBytes, 'the upload limit is published').toBeGreaterThan(0)
		expect(block.limits.pageSize.maximum).toBeGreaterThanOrEqual(block.limits.pageSize.default)

		expect(Array.isArray(block.deepLinks), 'deep link patterns are a list').toBe(true)
		for (const [name, on] of Object.entries(block.integrations as Record<string, unknown>)) {
			expect(typeof on, `integration ${name} is on or off`).toBe('boolean')
		}
		expect(block.integrations.capability, 'the capability integration reports itself on').toBe(true)

		expect(Array.isArray(block.apps), 'the claiming apps are a list').toBe(true)
		for (const app of block.apps as Array<Record<string, any>>) {
			expect(typeof app.id).toBe('string')
			expect(typeof app.displayName, `app ${app.id} carries a display name`).toBe('string')
		}
	})

	test('the block agrees with the API capabilities endpoint', async () => {
		const block = await platformBlock(anonymous)

		const res = await admin.get(`${API}/capabilities`)
		expect(res.ok(), `api capabilities read failed: ${await res.text()}`).toBeTruthy()
		const api = await res.json()

		expect(block.currentVersion).toBe(api.currentVersion)
		expect(block.apiVersions).toEqual(api.apiVersions)
		expect(block.limits).toEqual(api.limits)
	})

	test('the block names no register', async () => {
		const listed = await admin.get(`${API}/registers`)
		expect(listed.ok()).toBeTruthy()
		const titles = ((await listed.json()).results as Array<Record<string, any>>).map((r) =>
			String(r.title),
		)
		expect(titles, 'control: the register exists and is listed').toContain(registerTitle)

		for (const ctx of [anonymous, admin]) {
			const text = JSON.stringify(await platformBlock(ctx))
			expect(text).not.toContain(registerTitle)
			expect(text).not.toContain(`e2e-capability-register-${RUN}`)
		}
	})
})
