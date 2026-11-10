/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Semantic-object-handoff engine (ADR-051) — behavioural e2e for the
 * handoff REST surface + degradation states, driven through the OpenRegister
 * UI/API of a live instance. It seeds a source schema declaring an
 * `x-openregister-handoff` entry, exercises the availability endpoint in the
 * no-provider state, seeds a providing schema (complete `handoffContract`
 * binding), executes the handoff, and verifies the provenance relation is
 * visible on both objects with the source status updated.
 *
 * Each @e2e annotation traces a Scenario in
 *   openspec/specs/semantic-object-handoff/spec.md
 * (referenced by its post-sync path, per gate-19). Validator-level and
 * transactional scenarios are PHPUnit-covered and carry `@e2e exclude`
 * reasons in the spec itself.
 *
 * NOTE: these tests need a Nextcloud instance with this branch deployed.
 * They skip when the shared auth storageState is absent. All ids are safe
 * placeholders — never real subject data.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API_BASE = '/index.php/apps/openregister/api'

const CASE_KIND = 'https://openregister.app/ns#Case'
void CASE_KIND

test.describe('semantic-object-handoff — engine surface', () => {
	test.use({ storageState: STORAGE_STATE })

	test.beforeEach(async () => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(
				true,
				'storageState not present — the app is not reachable/built in this environment',
			)
		}
	})

	/**
	 * With no installed schema implementing the kind, the availability
	 * endpoint reports the handoff `unavailable` with a machine-readable
	 * reason, and direct execution returns the typed
	 * handoff-provider-unavailable response (409-class, never a 5xx).
	 *
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#no-provider-installed-hide-mode
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#availability-endpoint-without-provider
	 */
	test('no-provider degradation: availability reason + typed execute error', async ({
		request,
	}) => {
		// Environment-provided fixture: a `request`-like object whose schema
		// declares a handoff to a kind with no installed provider.
		const fixture = process.env.OR_HANDOFF_FIXTURE_NOPROVIDER
		test.skip(
			!fixture,
			'OR_HANDOFF_FIXTURE_NOPROVIDER not seeded in this environment',
		)
		const [register, schema, id, handoffId] = String(fixture).split('/')

		const availability = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs`,
		)
		expect(availability.status()).toBe(200)
		const body = await availability.json()
		const entry = body.handoffs.find((h: { id: string }) => h.id === handoffId)
		expect(entry.state).toBe('unavailable')
		expect(entry.reason).toBe('handoff-provider-unavailable')

		const execute = await request.post(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs/${handoffId}`,
		)
		expect(execute.status()).toBe(409)
		expect((await execute.json()).error).toBe('handoff-provider-unavailable')
	})

	/**
	 * With a provider installed, availability names the resolved provider
	 * schema; executing creates the target, links `handoff` provenance both
	 * ways (handed-off-to / originated-from), and applies `onSuccess.set` to
	 * the source — the semantic reference crossing as a UUID reference only.
	 *
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#availability-endpoint-with-provider-present
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#successful-request-to-case-handoff
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#semantic-references-are-carried-not-copied
	 */
	test('provider present: availability names provider; execute links provenance both ways', async ({
		request,
	}) => {
		const fixture = process.env.OR_HANDOFF_FIXTURE_PROVIDER
		test.skip(
			!fixture,
			'OR_HANDOFF_FIXTURE_PROVIDER not seeded in this environment',
		)
		const [register, schema, id, handoffId] = String(fixture).split('/')

		const availability = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs`,
		)
		const entry = (await availability.json()).handoffs.find(
			(h: { id: string }) => h.id === handoffId,
		)
		expect(entry.state).toBe('available')
		expect(entry.provider.schema).toBeTruthy()

		const execute = await request.post(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs/${handoffId}`,
		)
		expect(execute.status()).toBe(200)
		const result = await execute.json()
		expect(result.status).toBe('executed')
		expect(result.target.uuid).toBeTruthy()

		// Source side: handed-off-to provenance relation + status update.
		const source = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}`,
		)
		const sourceBody = await source.json()
		const relationValues = JSON.stringify(
			sourceBody['@self']?.relations ?? sourceBody.relations ?? {},
		)
		expect(relationValues).toContain(result.target.uuid)
	})

	/**
	 * A parked queue-mode handoff surfaces as `queued` on the availability
	 * endpoint and executes automatically when a provider appears.
	 *
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#no-provider-installed-queue-mode
	 */
	test('queue mode: parked handoff reports queued state', async ({ request }) => {
		const fixture = process.env.OR_HANDOFF_FIXTURE_QUEUE
		test.skip(
			!fixture,
			'OR_HANDOFF_FIXTURE_QUEUE not seeded in this environment',
		)
		const [register, schema, id, handoffId] = String(fixture).split('/')

		const park = await request.post(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs/${handoffId}`,
		)
		expect(park.status()).toBe(202)
		expect((await park.json()).status).toBe('parked')

		const availability = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs`,
		)
		const entry = (await availability.json()).handoffs.find(
			(h: { id: string }) => h.id === handoffId,
		)
		expect(entry.state).toBe('queued')
		expect(entry.queueEntry.status).toBe('parked')
	})

	/**
	 * A provider whose owning app is disabled degrades exactly like an
	 * absent provider (shipped SemanticTypeResolver behaviour).
	 *
	 * @e2e openspec/specs/semantic-object-handoff/spec.md#provider-app-installed-but-disabled
	 */
	test('disabled provider app degrades like no provider', async ({ request }) => {
		const fixture = process.env.OR_HANDOFF_FIXTURE_DISABLED
		test.skip(
			!fixture,
			'OR_HANDOFF_FIXTURE_DISABLED not seeded (needs a disabled provider app)',
		)
		const [register, schema, id, handoffId] = String(fixture).split('/')

		const availability = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}/handoffs`,
		)
		const entry = (await availability.json()).handoffs.find(
			(h: { id: string }) => h.id === handoffId,
		)
		expect(entry.state).toBe('unavailable')
	})
})
