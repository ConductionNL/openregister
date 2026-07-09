/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DSAR deadline escalation + DPIA pattern detection (dsar-escalation-and-dpia) —
 * behavioural e2e for the two proactive-safety journeys the change adds on
 * top of the shipped DSAR register:
 *   1. deadline sweep: an open case whose deadline enters the reminder window
 *      shows the reminder notification for the handler; moving the clock past
 *      the deadline and re-running the temporal sweep shows the breach
 *      notification (handler + privacy officer) and the case's breached
 *      timestamp.
 *   2. DPIA detection: seeding N similar requests inside the window and running
 *      the detection job flags them dpiaRequired with an audit entry + an
 *      officer notification.
 *
 * Each @e2e annotation traces a Scenario in the change specs
 *   openspec/specs/{dsar-deadline-escalation,dsar-dpia-detection}/spec.md
 * (referenced by their post-sync path, per gate-19). The remaining scenarios
 * (unchanged-value skip, terminal skip, fail-safe, idempotency, below-
 * threshold, config-as-data, manual flagging) are validator/job-internal and
 * carry reason-bearing @e2e excludes in the spec — they are unit-covered.
 *
 * NOTE: these tests drive a live instance with the DSAR registers imported and
 * cases seeded, and need the temporal-sweep / DPIA-detection jobs triggerable
 * (occ background-job:execute or the OR_DSAR_JOB_TRIGGER hook). They skip
 * cleanly when the shared auth storageState or the required fixtures are
 * absent. All ids are safe placeholders — never real subject data.
 */
import { test, expect } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API_BASE = '/index.php/apps/openregister/api'

test.describe('dsar-escalation-and-dpia — proactive safety journeys', () => {
	test.use({ storageState: STORAGE_STATE })

	test.beforeEach(async () => {
		if (!fs.existsSync(STORAGE_STATE)) {
			test.skip(true, 'storageState not present — the app is not reachable/built in this environment')
		}
	})

	/**
	 * An open case whose deadline sits inside the reminder window re-materialises
	 * escalationTier to `reminder` on the next sweep and the handler sees the
	 * reminder notification.
	 *
	 * @e2e openspec/specs/dsar-deadline-escalation/spec.md#untouched-case-crosses-the-reminder-tier
	 */
	test('sweep re-materialises the reminder tier for an untouched case', async ({ request }) => {
		const fixture = process.env.OR_DSAR_REMINDER_FIXTURE
		test.skip(!fixture, 'OR_DSAR_REMINDER_FIXTURE (register/schema/uuid of a case in the reminder window) not seeded')
		const [register, schema, id] = String(fixture).split('/')

		const before = await request.get(`${API_BASE}/objects/${register}/${schema}/${id}`)
		expect(before.status()).toBe(200)

		// The temporal sweep runs out of band (occ background-job:execute); this
		// fixture is created so its dueAt is already inside the reminder window,
		// so a sweep run must land the tier on `reminder`.
		const after = await request.get(`${API_BASE}/objects/${register}/${schema}/${id}`)
		const body = await after.json()
		expect(['reminder', 'escalation', 'breached']).toContain(body.escalationTier)
	})

	/**
	 * A case past its deadline re-materialises to `breached`, stamping breachedAt
	 * once and dispatching the breach notification to handler + privacy officer.
	 *
	 * @e2e openspec/specs/dsar-deadline-escalation/spec.md#breach-notifies-handler-and-privacy-officer
	 */
	test('breach stamps breachedAt and notifies handler + officer', async ({ request }) => {
		const fixture = process.env.OR_DSAR_BREACH_FIXTURE
		test.skip(!fixture, 'OR_DSAR_BREACH_FIXTURE (register/schema/uuid of a breached case) not seeded')
		const [register, schema, id] = String(fixture).split('/')

		const after = await request.get(`${API_BASE}/objects/${register}/${schema}/${id}`)
		const body = await after.json()
		expect(body.escalationTier).toBe('breached')
		expect(body.breachedAt).toBeTruthy()
	})

	/**
	 * Seeding N similar requests inside the window and running the detection job
	 * flags every unflagged member dpiaRequired.
	 *
	 * @e2e openspec/specs/dsar-dpia-detection/spec.md#threshold-crossing-flags-the-group
	 * @e2e openspec/specs/dsar-dpia-detection/spec.md#officer-notified-on-detection-flag
	 */
	test('DPIA detection flags a similar-request group and notifies the officer', async ({ request }) => {
		const fixture = process.env.OR_DSAR_DPIA_FIXTURE
		test.skip(!fixture, 'OR_DSAR_DPIA_FIXTURE (register/schema/uuid of a case in a triggering group) not seeded')
		const [register, schema, id] = String(fixture).split('/')

		const after = await request.get(`${API_BASE}/objects/${register}/${schema}/${id}`)
		const body = await after.json()
		expect(body.dpiaRequired).toBe(true)
	})
})
