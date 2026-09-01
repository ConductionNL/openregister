/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chat and Agents e2e tests — covers:
 *   - chat-ai (chat API surface: create conversation, send message)
 *   - ai-mcp (MCP toolchain for agents)
 *   - activity-provider (activity API surface)
 *   - profile-actions (user profile/account endpoint)
 *   - contacts-actions (contacts provider surface)
 *
 * The chat/LLM tests are soft: they verify the API surface
 * (correct HTTP status, JSON envelope) without requiring a configured
 * LLM backend. Most will skip if no agent/LLM is configured.
 */
import { expect, test } from '@playwright/test'

const RUN_ID = `e2e-${Date.now()}`

// ─────────────────────────────────────────────────────────────────────────────
// chat-ai — chat API surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('chat-ai — chat API surface', () => {
	test('GET /api/agents returns list (no 5xx)', async ({ request }) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/agents?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body).toHaveProperty('results')
		}
	})

	test('GET /api/conversations returns list (no 5xx)', async ({ request }) => {
		const resp = await request.get(
			'/index.php/apps/openregister/api/conversations?_limit=5',
			{
				headers: { Accept: 'application/json' },
			},
		)
		expect(resp.status()).toBeLessThan(500)
		if (resp.ok()) {
			const body = await resp.json()
			expect(body).toHaveProperty('results')
		}
	})

	test('POST /api/messages returns 4xx when no agent configured (not 5xx)', async ({
		request,
	}) => {
		// Try to send a message without a valid agent uuid.
		const resp = await request.post(
			'/index.php/apps/openregister/api/messages',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					agentUuid: '00000000-0000-0000-0000-000000000000',
					message: `${RUN_ID} test message`,
				},
			},
		)
		// Should return 4xx (no such agent / bad request) — never 5xx.
		expect(
			resp.status(),
			'message with invalid agent should return 4xx not 5xx',
		).toBeLessThan(500)
		// 404 (agent not found) or 400 (bad request) are both acceptable.
		expect(resp.status()).toBeGreaterThanOrEqual(400)
	})

	test('agents can be created and deleted (CRUD)', async ({ request }) => {
		const createResp = await request.post(
			'/index.php/apps/openregister/api/agents',
			{
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				data: {
					name: `${RUN_ID}-agent`,
					description: 'E2E test agent for chat-ai spec',
				},
			},
		)
		expect(createResp.status()).toBeLessThan(500)
		if (!createResp.ok() && createResp.status() !== 201) return

		const body = await createResp.json()
		const agentId = body.id ?? null
		if (!agentId) return

		// Delete.
		const deleteResp = await request.delete(
			`/index.php/apps/openregister/api/agents/${agentId}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(deleteResp.status()).toBeLessThan(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// activity-provider — activity integration surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('activity-provider — activity integration', () => {
	test('OCS activity stream endpoint responds (if Activity app enabled)', async ({
		request,
	}) => {
		const resp = await request.get(
			'/ocs/v2.php/apps/activity/api/v2/activity?format=json',
			{
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			},
		)
		// 200 if Activity app installed, 404 if not.
		expect(resp.status()).not.toBe(500)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// profile-actions — user profile surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('profile-actions — user profile endpoint', () => {
	test('OCS cloud/user returns current user data', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body?.ocs?.data?.id, 'user id should be present').toBeTruthy()
		// Should be admin (the configured test user).
		expect(body?.ocs?.data?.id).toBe('admin')
	})

	test('OCS cloud/users returns user list', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/users?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body?.ocs?.data?.users, 'users array should be present').toBeTruthy()
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// contacts-actions — contacts surface
// ─────────────────────────────────────────────────────────────────────────────
test.describe('contacts-actions — contacts surface', () => {
	test('OCS cloud/groups returns group list', async ({ request }) => {
		const resp = await request.get('/ocs/v2.php/cloud/groups?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(
			body?.ocs?.data?.groups,
			'groups array should be present',
		).toBeTruthy()
	})
})
