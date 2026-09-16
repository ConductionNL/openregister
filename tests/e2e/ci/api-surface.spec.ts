/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The API as a described, versioned surface, against a running instance.
 *
 * The unit tests prove the negotiation and the refusals in isolation. What
 * they cannot prove is that the middleware is REGISTERED and that the routes
 * are REACHABLE — a middleware that is never registered behaves exactly like
 * one that decides to do nothing, and an unrouted controller method looks
 * identical to a passing test right up until a consumer calls it.
 *
 * ⚠️ WHAT IS DELIBERATELY NOT HERE. A default instance serves version 1
 * supported and nothing else, which is correct, so there is no deprecated and
 * no withdrawn version on it to call. Those two scenarios are proven by
 * `tests/Unit/Middleware/ApiVersionMiddlewareTest.php` — the end-date headers
 * and the 410 with its successor — and they get an e2e case here once the
 * declaration has an administration surface, which is the branch this lane
 * continues on. A skipped placeholder carrying an `@e2e` anchor was written
 * first and deleted: a skipped test claiming a scenario is covered is worse
 * than an uncovered scenario, because it stops anyone looking.
 *
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#a-client-reads-the-upload-limit-before-uploading
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#the-unauthenticated-answer-names-no-register
 */
import { expect, test } from '@playwright/test'

const CAPABILITIES = '/index.php/apps/openregister/api/capabilities'
const VERSIONS = '/index.php/apps/openregister/api/versions'

test.describe('The published contract', () => {
	test('a client reads the versions and the upload limit without a session', async ({
		request,
	}) => {
		const response = await request.get(CAPABILITIES)
		expect(response.status(), 'the capabilities read is public').toBe(200)

		const body = await response.json()

		expect(body.currentVersion, 'a version is named').toMatch(/^[0-9]{1,3}$/)
		expect(body.versionHeader).toBe('API-Version')
		expect(Array.isArray(body.apiVersions)).toBe(true)
		expect(body.apiVersions.length).toBeGreaterThan(0)

		// The clause this change exists to close: "every integrator currently
		// discovers our upload limit by hitting it".
		expect(body.limits, 'the limits are published').toBeTruthy()
		expect(body.limits.pageSize.maximum).toBeGreaterThan(0)
		expect(body.limits.pageSize.default).toBeGreaterThan(0)
		expect(
			body.limits.authentication.attemptsPerIdentity,
			'the authentication ceiling is named',
		).toBeGreaterThan(0)
	})

	test('the unauthenticated answer names no register and no schema', async ({
		request,
	}) => {
		const body = await (await request.get(CAPABILITIES)).json()

		expect(
			body.features,
			'the operational switches need a session',
		).toBeUndefined()

		// The app id lives in the contract link path, and `openregister`
		// contains `register`; strip it before looking for a register NAME.
		const serialised = JSON.stringify(body)
			.toLowerCase()
			.replace(/openregister/g, '')

		for (const forbidden of ['register', 'schema', 'tenant', 'organisation']) {
			expect(
				serialised,
				`the public answer must not say what this municipality keeps (${forbidden})`,
			).not.toContain(forbidden)
		}
	})

	test('every version is listed with a status', async ({ request }) => {
		const body = await (await request.get(VERSIONS)).json()

		expect(body.versions.length).toBeGreaterThan(0)
		for (const version of body.versions) {
			expect(version.version).toMatch(/^[0-9]{1,3}$/)
			expect(['supported', 'deprecated', 'withdrawn']).toContain(
				version.status,
			)
		}
	})

	test('each served version has its own description', async ({ request }) => {
		const { currentVersion } = await (await request.get(CAPABILITIES)).json()

		const response = await request.get(`${VERSIONS}/${currentVersion}/oas`)
		expect(response.status()).toBe(200)

		const document = await response.json()
		expect(document.openapi).toBe('3.1.0')
		expect(
			document['x-api-version'].version,
			'the description says which contract it describes',
		).toBe(currentVersion)
	})
})

test.describe('Speaking a version', () => {
	test('every API answer names the contract that served it', async ({
		request,
	}) => {
		const response = await request.get(CAPABILITIES)

		expect(
			response.headers()['api-version'],
			'the middleware is registered and stamps the answering version',
		).toMatch(/^[0-9]{1,3}$/)
	})

	test('a caller may pin the contract it speaks', async ({ request }) => {
		const { currentVersion } = await (await request.get(CAPABILITIES)).json()

		const response = await request.get(CAPABILITIES, {
			headers: { 'API-Version': currentVersion },
		})

		expect(response.status()).toBe(200)
		expect(response.headers()['api-version']).toBe(currentVersion)
	})

	test('a version nothing declares is refused, listing what is served', async ({
		request,
	}) => {
		const response = await request.get(CAPABILITIES, {
			headers: { 'API-Version': '997' },
		})

		expect(
			response.status(),
			'an undeclared contract is a bad request, not a silent fall back to the current one',
		).toBe(400)

		const body = await response.json()
		expect(body.requestedVersion).toBe('997')
		expect(Array.isArray(body.servedVersions)).toBe(true)
		expect(body.servedVersions.length).toBeGreaterThan(0)
	})

	test('an API-Version typo does not quietly become the default', async ({
		request,
	}) => {
		const response = await request.get(CAPABILITIES, {
			headers: { 'API-Version': 'two' },
		})

		expect(
			response.status(),
			'reading a typo as "named nothing" lets a client believe for a year that it was pinned',
		).toBe(400)
	})

	test('a description nobody declared is a missing document', async ({
		request,
	}) => {
		const response = await request.get(`${VERSIONS}/997/oas`)

		expect(response.status()).toBe(404)
	})
})
