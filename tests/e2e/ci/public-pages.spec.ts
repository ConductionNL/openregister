import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PUBLIC PAGES, over HTTP, from a context that carries no credentials at all.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e apphost-public-pages::the-catch-all-still-asks-for-an-account
 * @e2e apphost-public-pages::a-share-token-no-longer-publishes-the-platforms-bookkeeping
 *
 * WHY THIS LAYER. Both claims are about what happens BEFORE the controller: a
 * route that requires a session, and a response served to somebody the request
 * never identified. A PHPUnit test constructs the controller itself, so no
 * middleware runs and a missing `#[PublicPage]` looks exactly like a present
 * one. Only a real request with no Authorization header can tell them apart.
 *
 * The anonymous context below is built without credentials on purpose. A test
 * in here that starts passing because it borrowed the admin context is proving
 * nothing, so each one asserts on a status or a body an admin would not get.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** An authenticated context, for building the fixture only. */
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

test.describe('pages and records reached without a session', () => {
	let admin: APIRequestContext
	let anon: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anon = await pwRequest.newContext({ baseURL: BASE })

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e public pages ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e public pages schema ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key: 'public-pages' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	test.afterAll(async () => {
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`).catch(() => undefined)
		}

		await anon.dispose()
		await admin.dispose()
	})

	test('an app page still asks for an account', async () => {
		// The catch-all serves the SPA shell, and it stays authenticated: this
		// change adds a route beside it rather than opening it. A redirect to
		// the login, or a 401, both say the page was refused; a 200 carrying
		// the app's script tags would say the shell was served.
		const page = await anon.get('/index.php/apps/openregister/', {
			maxRedirects: 0,
			headers: { Accept: 'text/html' },
		})

		expect(
			[302, 303, 307, 401, 403].includes(page.status()),
			`an anonymous visitor must not receive the app shell (got ${page.status()})`,
		).toBeTruthy()

		if (page.status() === 200) {
			expect(await page.text()).not.toContain('openregister-main')
		}
	})

	test('a share token serves the record and none of the platform bookkeeping', async () => {
		const link = await admin.post(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/links`,
			{ data: { permissions: 1 } },
		)
		expect(link.ok(), `link create failed: ${await link.text()}`).toBeTruthy()
		const { token } = await link.json()
		expect(token, 'core issued no token').toBeTruthy()

		const resolved = await anon.get(`${API}/shared/${token}`)
		expect(
			resolved.ok(),
			`a live token must resolve anonymously: ${await resolved.text()}`,
		).toBeTruthy()

		const served = (await resolved.json()).object
		expect(served, 'the token answered without an object').toBeTruthy()
		// The record itself is what the holder came for.
		expect(served.key).toBe('public-pages')

		// The platform's own bookkeeping is not. Before openregister#3818 this
		// surface answered `jsonSerialize()`, so all four travelled with every
		// anonymous read.
		for (const forbidden of ['authorization', 'owner', 'organisation', 'folder']) {
			expect(
				served['@self']?.[forbidden],
				`a share token must not publish @self.${forbidden}`,
			).toBeUndefined()
		}
	})
})
