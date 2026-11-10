/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hardening report and the refusal, against a running instance.
 *
 * The unit tests prove the floor arithmetic, the refusal and the report shape
 * in isolation. What they cannot prove is that the four routes are REACHABLE
 * and administrator-only: an unrouted controller method looks exactly like a
 * passing unit test right up until an administrator opens the page.
 *
 * ⚠️ NOTHING HERE LEAVES RESIDUE. The refusal case uses the SHIPPED baseline
 * floor, so it declares nothing and stores nothing: the instance refuses the
 * weakening and the configuration is untouched either way. An earlier draft
 * raised a floor first and put it back afterwards, which left an explicit
 * declaration behind on every run.
 *
 * @e2e openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#an-administrator-reads-what-is-on-and-what-is-not
 * @e2e openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#a-weakening-is-refused-and-recorded
 * @e2e openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#first-use-asks-and-records-the-answer
 * @e2e openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#a-new-version-asks-again
 */
import { expect, test } from '@playwright/test'

const REPORT = '/index.php/apps/openregister/api/hardening/report'
const FLOORS = '/index.php/apps/openregister/api/hardening/floors'
const CONTROLS = '/index.php/apps/openregister/api/hardening/controls'
const ELEVATION = '/index.php/apps/openregister/api/hardening/elevation'
const STATEMENT = '/index.php/apps/openregister/api/hardening/statement'
const ACCEPTANCE = '/index.php/apps/openregister/api/hardening/statement/acceptance'

const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/**
 * Confirm the password, so this context may write.
 *
 * Every write below needs it since REQ-IHC-002: an open session is not a
 * confirmed password. A test that forgets this reads 403 with
 * `elevationRequired`, which is the guard working rather than the route
 * breaking.
 */
async function elevate(request): Promise<void> {
	const response = await request.post(ELEVATION, {
		data: { password: ADMIN_PASS },
	})
	expect(response.status(), 'the password could not be confirmed').toBe(200)
	expect((await response.json()).elevated).toBe(true)
}

test.describe('The hardening report', () => {
	test('an administrator reads what is on and what is not', async ({
		request,
	}) => {
		const response = await request.get(REPORT)
		expect(response.status(), 'the report route is registered').toBe(200)

		const body = await response.json()

		expect(Array.isArray(body.controls), 'the report carries rows').toBe(true)
		expect(
			body.controls.length,
			'every category is represented',
		).toBeGreaterThan(10)
		expect(typeof body.meetsAllFloors).toBe('boolean')
		expect(Array.isArray(body.failing)).toBe(true)

		const categories = new Set(body.controls.map((control) => control.category))
		for (const expected of [
			'password',
			'session',
			'rateLimit',
			'bruteForce',
			'origins',
			'upload',
		]) {
			expect(categories, `the report answers for ${expected}`).toContain(
				expected,
			)
		}

		for (const control of body.controls) {
			expect(['platform', 'administered', 'code']).toContain(control.source)
			expect(['atLeast', 'atMost']).toContain(control.comparator)
			expect(['on', 'off', 'unknown']).toContain(control.state)
			expect(typeof control.floor, `${control.id} carries a floor`).toBe(
				'number',
			)

			// The rule this report lives or dies on: unknown is never fine.
			if (control.value === null) {
				expect(
					control.meetsFloor,
					`${control.id} reads unknown and must fail`,
				).toBe(false)
				expect(body.failing).toContain(control.id)
			}
		}

		expect(
			body.observed.bruteForce,
			'the brute-force state is observed',
		).toBeTruthy()
		expect(Object.keys(body.observed.throttledSurfaces).length).toBeGreaterThan(
			0,
		)
	})

	test('the published ceiling is the ceiling the capabilities answer names', async ({
		request,
	}) => {
		const report = await (await request.get(REPORT)).json()
		const capabilities = await (
			await request.get('/index.php/apps/openregister/api/capabilities')
		).json()

		const lockout = report.controls.find(
			(control) => control.id === 'auth.rateLimit.lockoutSeconds',
		)

		expect(
			lockout.value,
			'the report and the capabilities answer read the same number',
		).toBe(capabilities.limits.authentication.lockoutSeconds)
	})

	test('the floors answer says which direction is stronger', async ({
		request,
	}) => {
		const body = await (await request.get(FLOORS)).json()

		expect(body.floors['auth.rateLimit.lockoutSeconds']).toBeGreaterThan(0)
		expect(body.comparators['auth.rateLimit.lockoutSeconds']).toBe('atLeast')
		expect(
			body.comparators['session.lifetimeSeconds'],
			'a consumer that assumes higher is stronger gets this one backwards',
		).toBe('atMost')
		expect(body.baselines['auth.rateLimit.attemptsPerIdentity']).toBeGreaterThan(
			0,
		)
	})
})

test.describe('The refusal', () => {
	test('a weakening is refused and recorded', async ({ request }) => {
		const before = await (await request.get(REPORT)).json()
		const lockoutBefore = before.controls.find(
			(control) => control.id === 'auth.rateLimit.lockoutSeconds',
		).value

		await elevate(request)
		const response = await request.put(CONTROLS, {
			data: { controls: { 'auth.rateLimit.lockoutSeconds': 60 } },
		})

		expect(
			response.status(),
			'a well-formed request the instance will not carry out is a conflict, not a bad request',
		).toBe(409)

		const refusal = await response.json()
		expect(refusal.control).toBe('auth.rateLimit.lockoutSeconds')
		expect(refusal.proposed).toBe(60)
		expect(refusal.floor).toBeGreaterThan(60)

		const after = await (await request.get(REPORT)).json()
		expect(
			after.controls.find(
				(control) => control.id === 'auth.rateLimit.lockoutSeconds',
			).value,
			'the refused value never reached the configuration',
		).toBe(lockoutBefore)
	})

	test('a floor weaker than the shipped baseline is refused', async ({
		request,
	}) => {
		await elevate(request)
		const response = await request.put(FLOORS, {
			data: { floors: { 'auth.rateLimit.attemptsPerIdentity': 5000 } },
		})

		expect(response.status()).toBe(409)
		expect((await response.json()).control).toBe(
			'auth.rateLimit.attemptsPerIdentity',
		)
	})

	test('a control this instance does not administer is refused', async ({
		request,
	}) => {
		await elevate(request)
		const response = await request.put(CONTROLS, {
			data: { controls: { 'password.minimumLength': 4 } },
		})

		expect(response.status(), 'Nextcloud owns the password policy').toBe(400)
	})
})

test.describe('The fresh sign-in, and the statement', () => {
	test('a write from an open session that never confirmed a password is refused', async ({
		browser,
	}) => {
		// A context of its own, so it cannot inherit an elevation another test
		// started. It carries the admin credentials and nothing else: the
		// principal here is a full administrator, and it is still refused.
		const context = await browser.newContext({
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(
					`${process.env.ADMIN_USER || process.env.OR_USER || 'admin'}:${ADMIN_PASS}`,
				).toString('base64')}`,
			},
		})

		try {
			const response = await context.request.put(CONTROLS, {
				data: { controls: { 'auth.rateLimit.attemptsPerIdentity': 19 } },
			})

			expect(
				response.status(),
				'an unelevated administration write is forbidden',
			).toBe(403)
			const body = await response.json()
			expect(body.elevationRequired).toBe(true)
			expect(typeof body.periodSeconds).toBe('number')
		} finally {
			// `close()`, not `dispose()`. A BrowserContext has no `dispose()`
			// — that belongs to APIRequestContext — so this line threw a
			// TypeError AFTER the assertions above had already passed, and
			// reddened two tests whose substantive claims hold.
			await context.close()
		}
	})

	test('a wrong password elevates nothing', async ({ browser }) => {
		const context = await browser.newContext({
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(
					`${process.env.ADMIN_USER || process.env.OR_USER || 'admin'}:${ADMIN_PASS}`,
				).toString('base64')}`,
			},
		})

		try {
			const response = await context.request.post(ELEVATION, {
				data: { password: 'not-the-password' },
			})

			expect(response.status()).toBe(401)
		} finally {
			// `close()`, not `dispose()`. A BrowserContext has no `dispose()`
			// — that belongs to APIRequestContext — so this line threw a
			// TypeError AFTER the assertions above had already passed, and
			// reddened two tests whose substantive claims hold.
			await context.close()
		}
	})

	test('a statement is published, asked, accepted, and asked again at the next version', async ({
		request,
	}) => {
		const version = `e2e-${Math.random().toString(36).slice(2, 8)}`

		await elevate(request)
		const published = await request.put(STATEMENT, {
			data: {
				version,
				body: 'What this instance does with your data.',
				title: 'Verwerking',
			},
		})
		expect(published.status(), 'the statement route is registered').toBe(200)
		expect((await published.json()).version).toBe(version)

		try {
			const asked = await (await request.get(STATEMENT)).json()
			expect(asked.statement.version).toBe(version)
			expect(asked.needsAcceptance, 'a version nobody accepted is asked').toBe(
				true,
			)

			const stale = await request.post(ACCEPTANCE, {
				data: { version: 'some-older-version' },
			})
			expect(
				stale.status(),
				'accepting a version that is not in force is refused',
			).toBe(400)

			const accepted = await request.post(ACCEPTANCE, { data: { version } })
			expect(accepted.status()).toBe(200)
			expect((await accepted.json()).version).toBe(version)

			const after = await (await request.get(STATEMENT)).json()
			expect(
				after.needsAcceptance,
				'an accepted version is not asked again',
			).toBe(false)

			const next = `${version}-b`
			await elevate(request)
			await request.put(STATEMENT, {
				data: { version: next, body: 'Revised.' },
			})

			const again = await (await request.get(STATEMENT)).json()
			expect(again.needsAcceptance, 'a new version asks everybody again').toBe(
				true,
			)
		} finally {
			// NOTHING IS LEFT BEHIND. The instance publishes no statement
			// before this test and publishes none after it, so a re-run and a
			// real installation both start where they started.
			await elevate(request)
			await request.delete(STATEMENT)
		}
	})
})
