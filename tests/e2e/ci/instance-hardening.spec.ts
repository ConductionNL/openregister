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
 */
import { expect, test } from '@playwright/test'

const REPORT = '/index.php/apps/openregister/api/hardening/report'
const FLOORS = '/index.php/apps/openregister/api/hardening/floors'
const CONTROLS = '/index.php/apps/openregister/api/hardening/controls'

test.describe('The hardening report', () => {
	test('an administrator reads what is on and what is not', async ({ request }) => {
		const response = await request.get(REPORT)
		expect(response.status(), 'the report route is registered').toBe(200)

		const body = await response.json()

		expect(Array.isArray(body.controls), 'the report carries rows').toBe(true)
		expect(body.controls.length, 'every category is represented').toBeGreaterThan(10)
		expect(typeof body.meetsAllFloors).toBe('boolean')
		expect(Array.isArray(body.failing)).toBe(true)

		const categories = new Set(body.controls.map((control) => control.category))
		for (const expected of ['password', 'session', 'rateLimit', 'bruteForce', 'origins', 'upload']) {
			expect(categories, `the report answers for ${expected}`).toContain(expected)
		}

		for (const control of body.controls) {
			expect(['platform', 'administered', 'code']).toContain(control.source)
			expect(['atLeast', 'atMost']).toContain(control.comparator)
			expect(['on', 'off', 'unknown']).toContain(control.state)
			expect(typeof control.floor, `${control.id} carries a floor`).toBe('number')

			// The rule this report lives or dies on: unknown is never fine.
			if (control.value === null) {
				expect(control.meetsFloor, `${control.id} reads unknown and must fail`).toBe(false)
				expect(body.failing).toContain(control.id)
			}
		}

		expect(body.observed.bruteForce, 'the brute-force state is observed').toBeTruthy()
		expect(Object.keys(body.observed.throttledSurfaces).length).toBeGreaterThan(0)
	})

	test('the published ceiling is the ceiling the capabilities answer names', async ({ request }) => {
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

	test('the floors answer says which direction is stronger', async ({ request }) => {
		const body = await (await request.get(FLOORS)).json()

		expect(body.floors['auth.rateLimit.lockoutSeconds']).toBeGreaterThan(0)
		expect(body.comparators['auth.rateLimit.lockoutSeconds']).toBe('atLeast')
		expect(
			body.comparators['session.lifetimeSeconds'],
			'a consumer that assumes higher is stronger gets this one backwards',
		).toBe('atMost')
		expect(body.baselines['auth.rateLimit.attemptsPerIdentity']).toBeGreaterThan(0)
	})
})

test.describe('The refusal', () => {
	test('a weakening is refused and recorded', async ({ request }) => {
		const before = await (await request.get(REPORT)).json()
		const lockoutBefore = before.controls.find(
			(control) => control.id === 'auth.rateLimit.lockoutSeconds',
		).value

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
			after.controls.find((control) => control.id === 'auth.rateLimit.lockoutSeconds').value,
			'the refused value never reached the configuration',
		).toBe(lockoutBefore)
	})

	test('a floor weaker than the shipped baseline is refused', async ({ request }) => {
		const response = await request.put(FLOORS, {
			data: { floors: { 'auth.rateLimit.attemptsPerIdentity': 5000 } },
		})

		expect(response.status()).toBe(409)
		expect((await response.json()).control).toBe('auth.rateLimit.attemptsPerIdentity')
	})

	test('a control this instance does not administer is refused', async ({ request }) => {
		const response = await request.put(CONTROLS, {
			data: { controls: { 'password.minimumLength': 4 } },
		})

		expect(response.status(), 'Nextcloud owns the password policy').toBe(400)
	})
})
