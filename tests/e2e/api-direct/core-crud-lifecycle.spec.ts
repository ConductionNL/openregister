/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * object-lifecycle — REST CRUD pipeline (API-direct).
 *
 * These are API/contract assertions that drive the OR REST API directly
 * (no UI). Per the gate-19 honest-coverage program, API-direct/contract
 * checks belong in the Newman suite
 * (tests/integration/openregister-crud.postman_collection.json), NOT the
 * Playwright UI run. This spec lives under tests/e2e/api-direct/** and is
 * EXCLUDED from the default `chromium` Playwright project; it is retained
 * here for reference only.
 *
 * Equivalent Newman coverage:
 *   - POST/PUT/GET/DELETE object lifecycle →
 *     openregister-crud.postman_collection.json
 *     ("18. Soft Delete Object 2", "18a. Verify Object in Deleted List",
 *      "18b. Restore Deleted Object", "20. Delete Object 2").
 *
 * Extracted from core-crud.spec.ts (the UI tests stay there).
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

// Run-unique prefix so parallel agents don't collide.
const RUN_ID = `e2e-${Date.now()}`

// Known register+schema with data from the dev seed (larpingapp).
const REGISTER_ID = '8'
const SCHEMA_ID = '18'

/**
 * Resolve a live object id from the larpingapp register.
 * Returns null if no objects exist.
 */
async function pickLiveObjectId(request: APIRequestContext): Promise<string | null> {
	const resp = await request.get(
		`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=1`,
		{ headers: { Accept: 'application/json' } },
	)
	if (!resp.ok()) return null
	const body = await resp.json()
	const first = (body.results ?? [])[0]
	return first?.['@self']?.id ?? first?.id ?? null
}

test.describe('object-lifecycle — REST CRUD pipeline', () => {
	let createdObjectId: string | null = null

	test('POST creates an object and GET retrieves it (REQ-001)', async ({ request }) => {
		const payload = {
			name: `${RUN_ID}-character`,
			ocName: `${RUN_ID} E2E Test`,
			description: 'Automated e2e test character for object-lifecycle spec',
			type: 'player',
		}

		const created = await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: payload,
			},
		)
		expect(created.status(), 'POST /objects should return 200 or 201').toBeLessThanOrEqual(201)
		const createdBody = await created.json()
		createdObjectId = createdBody['@self']?.id ?? createdBody.id ?? null
		expect(createdObjectId, 'created object must have an id').toBeTruthy()

		// Verify the version and timestamp fields that the pipeline assigns (REQ-001 scenario).
		const self = createdBody['@self'] ?? {}
		expect(self.created ?? createdBody.created, 'created timestamp must be set by server').toBeTruthy()
	})

	test('PUT updates the object (REQ-001 pipeline update path)', async ({ request }) => {
		// If the create test above did not run first, pick any live object.
		let id = createdObjectId
		if (!id) {
			id = await pickLiveObjectId(request)
			test.skip(id === null, 'no object found to update')
		}

		const update = await request.put(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				data: {
					name: `${RUN_ID}-character-updated`,
					ocName: `${RUN_ID} E2E Updated`,
					description: 'Updated by object-lifecycle e2e test',
					type: 'player',
				},
			},
		)
		expect(update.status(), 'PUT /objects/{id} should return 200').toBe(200)
		const updatedBody = await update.json()
		expect(updatedBody.description, 'description should be updated').toContain('Updated by object-lifecycle')
	})

	test('GET listing returns standard envelope shape', async ({ request }) => {
		const resp = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}?_limit=5`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
		expect(body).toHaveProperty('total')
		expect(typeof body.total).toBe('number')
	})

	test('DELETE removes the created object (REQ-003)', async ({ request }) => {
		const id = createdObjectId
		if (!id) {
			test.skip(true, 'no object created in this run to delete')
		}

		const deleted = await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		// DELETE returns 200 or 204 depending on implementation.
		expect([200, 204], `DELETE should return 200 or 204`).toContain(deleted.status())

		// Confirm it is gone — GET should return 404.
		const gone = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(gone.status(), 'deleted object should return 404 on GET').toBe(404)
		createdObjectId = null
	})

	test.afterAll(async ({ request }) => {
		// Best-effort cleanup: delete the test object if not already removed.
		if (!createdObjectId) return
		await request.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER_ID}/${SCHEMA_ID}/${createdObjectId}`,
		).catch(() => {})
	})
})
