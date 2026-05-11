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
 * Playwright suites (opencatalogi, mydash, etc.) per the cross-app
 * hand-off pattern.
 */
import { test, expect } from '@playwright/test'

test.describe('OAS — ETag short-circuit', () => {
	test('GET /api/registers/oas returns 200 with ETag, 304 on If-None-Match', async ({ request }) => {
		const first = await request.get('/index.php/apps/openregister/api/registers/oas')
		expect(first.status()).toBe(200)
		const etag = first.headers().etag
		expect(etag, 'first response carries an ETag').toMatch(/^"[a-f0-9]+"$/)

		const second = await request.get('/index.php/apps/openregister/api/registers/oas', {
			headers: { 'If-None-Match': etag },
		})
		expect(second.status(), 'matching ETag returns 304').toBe(304)
	})
})

test.describe('Notification subscriptions — REST CRUD', () => {
	test('GET → POST → DELETE round trip', async ({ request }) => {
		// Snapshot existing subscriptions so we can clean up reliably.
		const initial = await request.get('/index.php/apps/openregister/api/notification-subscriptions')
		expect(initial.status()).toBe(200)
		const initialBody = await initial.json()
		expect(initialBody).toHaveProperty('results')
		expect(initialBody).toHaveProperty('total')

		// Subscribe to a synthetic register (id=999999 is fine — the
		// store doesn't FK against register table).
		const created = await request.post('/index.php/apps/openregister/api/notification-subscriptions', {
			headers: { 'Content-Type': 'application/json' },
			data: { registerId: 999999 },
		})
		expect(created.status()).toBe(201)
		const createdBody = await created.json()
		expect(createdBody.registerId).toBe(999999)
		expect(createdBody.userId).toBeTruthy()

		// Empty body should be rejected with 422.
		const rejected = await request.post('/index.php/apps/openregister/api/notification-subscriptions', {
			headers: { 'Content-Type': 'application/json' },
			data: {},
		})
		expect(rejected.status()).toBe(422)

		// Idempotency: second subscribe returns the same row.
		const idempotent = await request.post('/index.php/apps/openregister/api/notification-subscriptions', {
			headers: { 'Content-Type': 'application/json' },
			data: { registerId: 999999 },
		})
		const idempotentBody = await idempotent.json()
		expect(idempotentBody.id).toBe(createdBody.id)

		// Tear down.
		const deleted = await request.delete(
			'/index.php/apps/openregister/api/notification-subscriptions?registerId=999999',
		)
		expect(deleted.status()).toBe(200)
		const deletedBody = await deleted.json()
		expect(deletedBody.deleted).toBe(true)

		// Confirm clean.
		const final = await request.get('/index.php/apps/openregister/api/notification-subscriptions')
		const finalBody = await final.json()
		const stillThere = (finalBody.results as Array<{ registerId: number }>)
			.some(s => s.registerId === 999999)
		expect(stillThere, 'cleanup left no trace').toBe(false)
	})
})

test.describe('Object listing — envelope shape', () => {
	test('GET listing on register/schema returns the standard envelope', async ({ request }) => {
		const response = await request.get('/index.php/apps/openregister/api/objects/1/1?_limit=1')
		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
		expect(body).toHaveProperty('total')
		expect(typeof body.total).toBe('number')
	})

	test('Geo bbox parameter is accepted on the listing path', async ({ request }) => {
		const response = await request.get(
			'/index.php/apps/openregister/api/objects/1/1?geo.bbox=5.10,52.05,5.15,52.10&_limit=1',
		)
		// 200 (filter applied or no-op) or 4xx (validation rejection) —
		// both are valid wire-level outcomes; we MUST NOT 5xx.
		expect(response.status()).toBeLessThan(500)
	})
})
