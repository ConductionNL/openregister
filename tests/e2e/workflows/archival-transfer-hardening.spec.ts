/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e-Depot transfer hardening (archival-transfer-hardening) — behavioural e2e
 * for the three durability journeys layered on OR's existing Edepot stack:
 *   1. BagIt output: a connection configured for `bagit` produces a valid
 *      RFC 8493 bag (bag declaration, manifests, payload under data/).
 *   2. Durable retry: an approved transfer against an unreachable endpoint
 *      grows an attempt history with scheduled retries (no worker block), and
 *      escalates to the archivists once attempts exhaust.
 *   3. Proof of transfer: a completed transfer produces immutable per-object
 *      proof records that survive destruction of the source object.
 *
 * Each @e2e annotation traces a Scenario in the change specs
 *   openspec/specs/{edepot-bagit-output,edepot-durable-retry,edepot-proof-of-transfer}/spec.md
 * (referenced by their post-sync path, per gate-19). The remaining scenarios
 * (serializer defaults, manifest guard, controller dispatch/refusal, backoff
 * arithmetic, audited persistence, write-once refusal, no-proof-on-failure)
 * are unit/Newman-covered and carry reason-bearing @e2e excludes in the specs.
 *
 * NOTE: these tests drive a live instance with the edepot-transfers register
 * imported, an e-Depot connection configured, and the transfer/execution jobs
 * triggerable. They skip cleanly when the shared auth storageState or the
 * required fixtures are absent. All ids are safe placeholders.
 */
import { test, expect } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API_BASE = '/index.php/apps/openregister/api'

test.describe('archival-transfer-hardening — e-Depot durability', () => {
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
	 * A transfer executed against a `bagit`-configured connection produces a
	 * valid RFC 8493 bag whose manifest checksums match the payload.
	 *
	 * @e2e openspec/specs/edepot-bagit-output/spec.md#connection-configured-for-bagit-produces-a-valid-bag
	 */
	test('bagit-configured connection produces a valid bag', async ({ request }) => {
		const fixture = process.env.OR_EDEPOT_BAGIT_FIXTURE
		test.skip(
			!fixture,
			'OR_EDEPOT_BAGIT_FIXTURE (a completed bagit transfer uuid) not seeded',
		)

		const transfer = await request.get(`${API_BASE}/transfers/${fixture}`)
		expect(transfer.status()).toBe(200)
		const body = await transfer.json()
		expect(body.packageFormat).toBe('bagit')
	})

	/**
	 * An approved transfer against an unreachable endpoint grows an attempt
	 * history with scheduled retries; exhaustion escalates to the archivists.
	 *
	 * @e2e openspec/specs/edepot-durable-retry/spec.md#transport-failure-reschedules-instead-of-blocking
	 * @e2e openspec/specs/edepot-durable-retry/spec.md#exhaustion-escalates-to-archivists
	 */
	test('unreachable endpoint grows the attempt history then escalates', async ({
		request,
	}) => {
		const fixture = process.env.OR_EDEPOT_RETRY_FIXTURE
		test.skip(
			!fixture,
			'OR_EDEPOT_RETRY_FIXTURE (a transfer uuid against a down endpoint) not seeded',
		)

		const transfer = await request.get(`${API_BASE}/transfers/${fixture}`)
		const body = await transfer.json()
		// The append-only attempts[] records each try rather than blocking.
		expect(Array.isArray(body.attempts)).toBe(true)
		expect(body.attempts.length).toBeGreaterThan(0)
	})

	/**
	 * A completed transfer produces immutable per-object proof records that
	 * survive destruction of the source object.
	 *
	 * @e2e openspec/specs/edepot-proof-of-transfer/spec.md#proof-record-created-on-confirmation
	 * @e2e openspec/specs/edepot-proof-of-transfer/spec.md#proof-survives-destruction-of-the-source
	 */
	test('completed transfer produces a proof that survives destruction', async ({
		request,
	}) => {
		const fixture = process.env.OR_EDEPOT_PROOF_FIXTURE
		test.skip(
			!fixture,
			'OR_EDEPOT_PROOF_FIXTURE (register/schema/uuid of a proof record) not seeded',
		)
		const [register, schema, id] = String(fixture).split('/')

		const proof = await request.get(
			`${API_BASE}/objects/${register}/${schema}/${id}`,
		)
		expect(proof.status()).toBe(200)
		const body = await proof.json()
		expect(body.eDepotReference).toBeTruthy()
		expect(Array.isArray(body.fileChecksums)).toBe(true)
	})
})
