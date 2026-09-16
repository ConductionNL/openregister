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
 * ⚠️ THE DEPRECATION CASES ADMINISTER A DECLARATION AND PUT IT BACK. A default
 * instance serves version 1 supported and nothing else, so there is nothing
 * deprecated to call. The two cases below write a declaration through the
 * administration surface, exercise it, and restore what was there before in a
 * `finally`. They are serial for that reason: two workers editing one
 * instance-wide declaration would each see the other's.
 *
 * An earlier revision skipped these with an `@e2e` anchor still attached. That
 * was deleted rather than shipped: a skipped test claiming a scenario is
 * covered stops anyone looking, which is worse than the gap it hides.
 *
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#a-client-reads-the-upload-limit-before-uploading
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#the-unauthenticated-answer-names-no-register
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#five-suppliers-move-at-their-own-pace
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#a-withdrawn-version-says-where-to-go
 * @e2e openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#a-responsible-disclosure-contact-is-findable
 */
import type { APIRequestContext } from '@playwright/test'

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

const DECLARATION = '/index.php/apps/openregister/api/settings/api-versions'

/**
 * Serial: these edit one instance-wide declaration, so two workers running
 * them at once would each see the other's edit.
 */
test.describe.serial('The version lifecycle, administered', () => {
	/**
	 * Put a declaration in place, run the body, and restore what was there.
	 *
	 * ⚠️ THE RESTORE IS IN A `finally`, AND IT MATTERS MORE THAN THE TEST. A
	 * failed assertion that leaves version 1 deprecated leaves every LATER spec
	 * on this shared instance reading Deprecation headers it did not expect,
	 * and the failure surfaces somewhere unrelated.
	 */
	async function withDeclaration(
		request: APIRequestContext,
		versions: Array<Record<string, unknown>>,
		body: () => Promise<void>,
	): Promise<void> {
		const before = await request.get(DECLARATION)
		test.skip(
			before.status() === 403,
			'The declaration surface is administrator-only and this run is not signed in as one.',
		)
		expect(
			before.status(),
			'the declaration is readable before the test edits it',
		).toBe(200)
		const previous = (await before.json()).versions

		const applied = await request.put(DECLARATION, { data: { versions } })
		expect(applied.status(), 'the declaration was accepted').toBe(200)

		try {
			await body()
		} finally {
			await request.put(DECLARATION, {
				data: {
					versions: previous.map((v: Record<string, unknown>) => ({
						id: v.version,
						status: v.status,
						deprecatedOn: v.deprecatedOn,
						sunset: v.sunset,
						successor: v.successor,
						description: v.description,
					})),
				},
			})
		}
	}

	test('five suppliers move at their own pace: version 1 answers and names its end date', async ({
		request,
	}) => {
		await withDeclaration(
			request,
			[
				{
					id: '1',
					status: 'deprecated',
					deprecatedOn: '2026-09-01',
					sunset: '2027-03-01',
					successor: '2',
				},
				{ id: '2', status: 'supported' },
			],
			async () => {
				const response = await request.get(CAPABILITIES, {
					headers: { 'API-Version': '1' },
				})

				expect(response.status(), 'a deprecated version still answers').toBe(
					200,
				)
				expect(response.headers()['api-version']).toBe('1')
				expect(
					response.headers().sunset,
					'the client learns its deadline from the calls it already makes',
				).toBe('Mon, 01 Mar 2027 00:00:00 GMT')
				expect(response.headers().deprecation).toBe(
					'Tue, 01 Sep 2026 00:00:00 GMT',
				)
				expect(response.headers().link).toContain('rel="successor-version"')
			},
		)
	})

	test('a withdrawn version says where to go', async ({ request }) => {
		await withDeclaration(
			request,
			[
				{ id: '1', status: 'withdrawn', successor: '2' },
				{ id: '2', status: 'supported' },
			],
			async () => {
				const response = await request.get(CAPABILITIES, {
					headers: { 'API-Version': '1' },
				})

				expect(
					response.status(),
					'410 and not 404: a 404 reads as a bug in the caller and sends an integrator hunting',
				).toBe(410)

				const body = await response.json()
				expect(body.successorVersion).toBe('2')
				expect(body.error).toContain('Use version 2')
			},
		)
	})
})

test.describe('Discovery', () => {
	test('the well-known index names what this instance serves', async ({
		request,
	}) => {
		const response = await request.get(
			'/index.php/apps/openregister/.well-known',
		)

		expect(response.status()).toBe(200)

		const body = await response.json()
		expect(body.paths['security.txt']).toContain('/.well-known/security.txt')
	})

	test('a responsible disclosure contact is findable, or honestly absent', async ({
		request,
	}) => {
		const response = await request.get(
			'/index.php/apps/openregister/.well-known/security.txt',
		)

		// A default instance administers no contact, and 404 is the correct
		// answer: a file naming security@example.com reads as a working
		// disclosure channel and swallows the report. Either outcome is valid;
		// what must never happen is a 200 carrying a placeholder.
		expect([200, 404]).toContain(response.status())

		if (response.status() === 200) {
			expect(response.headers()['content-type']).toContain('text/plain')
			const body = await response.text()
			expect(body).toContain('Contact:')
			expect(body, 'RFC 9116 requires Expires').toContain('Expires:')
			expect(body).not.toContain('example.com')
		}
	})
})
