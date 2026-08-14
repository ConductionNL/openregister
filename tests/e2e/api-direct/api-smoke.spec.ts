/**
 * OpenRegister API smoke tests.
 *
 * Exercises the live OR HTTP surface end-to-end via Playwright's
 * request-context API. Validates wiring of the recently-shipped
 * surfaces:
 *
 *   - OAS endpoint + ETag short-circuit (file-actions / oas-validation)
 *   - Notification subscriptions REST surface (notificatie-engine UX)
 *   - Object listing returns standard envelope (api-test-coverage)
 *
 * Closes the api-test-coverage spec's "automated Playwright tests"
 * track at the smoke level — deeper UI flows live in the per-app
 * Playwright suites (opencatalogi, launchpad, etc.) per the cross-app
 * hand-off pattern.
 */
import { test, expect } from '@playwright/test'

test.describe('OAS — ETag short-circuit', () => {
	test('GET /api/registers/oas returns 200 with ETag, 304 on If-None-Match', async ({
		request,
	}) => {
		const first = await request.get(
			'/index.php/apps/openregister/api/registers/oas',
		)
		expect(first.status()).toBe(200)
		const etag = first.headers().etag
		expect(etag, 'first response carries an ETag').toMatch(/^"[a-f0-9]+"$/)

		const second = await request.get(
			'/index.php/apps/openregister/api/registers/oas',
			{
				headers: { 'If-None-Match': etag },
			},
		)
		expect(second.status(), 'matching ETag returns 304').toBe(304)
	})
})

test.describe('Notification subscriptions — REST CRUD', () => {
	test('GET → POST → DELETE round trip', async ({ request }) => {
		// Snapshot existing subscriptions so we can clean up reliably.
		const initial = await request.get(
			'/index.php/apps/openregister/api/notification-subscriptions',
		)
		expect(initial.status()).toBe(200)
		const initialBody = await initial.json()
		expect(initialBody).toHaveProperty('results')
		expect(initialBody).toHaveProperty('total')

		// Find a real register id to subscribe to — the endpoint now validates
		// that the register exists, so a synthetic 999999 id is rejected with 404.
		const registersResp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=1',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(registersResp.status()).toBe(200)
		const registersBody = await registersResp.json()
		const firstRegister = (registersBody.results ?? [])[0]
		if (!firstRegister) {
			test.skip(true, 'no registers available for subscription test')
		}
		const registerId: number = firstRegister.id

		// Subscribe to the real register.
		const created = await request.post(
			'/index.php/apps/openregister/api/notification-subscriptions',
			{
				headers: { 'Content-Type': 'application/json' },
				data: { registerId },
			},
		)
		expect(created.status()).toBe(201)
		const createdBody = await created.json()
		expect(createdBody.registerId).toBe(registerId)
		expect(createdBody.userId).toBeTruthy()

		// Empty body should be rejected with 422.
		const rejected = await request.post(
			'/index.php/apps/openregister/api/notification-subscriptions',
			{
				headers: { 'Content-Type': 'application/json' },
				data: {},
			},
		)
		expect(rejected.status()).toBe(422)

		// Idempotency: second subscribe returns the same row.
		const idempotent = await request.post(
			'/index.php/apps/openregister/api/notification-subscriptions',
			{
				headers: { 'Content-Type': 'application/json' },
				data: { registerId },
			},
		)
		const idempotentBody = await idempotent.json()
		expect(idempotentBody.id).toBe(createdBody.id)

		// Tear down.
		const deleted = await request.delete(
			`/index.php/apps/openregister/api/notification-subscriptions?registerId=${registerId}`,
		)
		expect(deleted.status()).toBe(200)
		const deletedBody = await deleted.json()
		expect(deletedBody.deleted).toBe(true)

		// Confirm clean.
		const final = await request.get(
			'/index.php/apps/openregister/api/notification-subscriptions',
		)
		const finalBody = await final.json()
		const stillThere = (finalBody.results as Array<{ registerId: number }>).some(
			(s) => s.registerId === registerId,
		)
		expect(stillThere, 'cleanup left no trace').toBe(false)
	})
})

test.describe('Object listing — envelope shape', () => {
	test('GET listing on register/schema returns the standard envelope', async ({
		request,
	}) => {
		// Resolve a real register + schema to avoid 404 when register id 1 doesn't exist.
		const regResp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=1',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(regResp.status()).toBe(200)
		const regBody = await regResp.json()
		const reg = (regBody.results ?? [])[0]
		if (!reg || !reg.schemas?.[0]) {
			test.skip(true, 'no register+schema available for listing test')
		}
		const registerId: number = reg.id
		const schemaId: number = reg.schemas[0]

		const response = await request.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}?_limit=1`,
		)
		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
		expect(body).toHaveProperty('total')
		expect(typeof body.total).toBe('number')
	})

	test('Geo bbox parameter is accepted on the listing path', async ({
		request,
	}) => {
		// Resolve real register + schema for the geo bbox test.
		const regResp = await request.get(
			'/index.php/apps/openregister/api/registers?_limit=1',
			{
				headers: { Accept: 'application/json' },
			},
		)
		const regBody = await regResp.json()
		const reg = (regBody.results ?? [])[0]
		if (!reg || !reg.schemas?.[0]) {
			test.skip(true, 'no register+schema for geo bbox test')
		}
		const registerId: number = reg.id
		const schemaId: number = reg.schemas[0]

		const response = await request.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}?geo.bbox=5.10,52.05,5.15,52.10&_limit=1`,
		)
		// 200 (filter applied or no-op) or 4xx (validation rejection) —
		// both are valid wire-level outcomes; we MUST NOT 5xx.
		expect(response.status()).toBeLessThan(500)
	})
})
