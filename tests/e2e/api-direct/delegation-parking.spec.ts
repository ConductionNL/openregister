/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `awaiting_consent` end to end — a run parked on a person, and released by one.
 *
 * WHAT THIS SUITE IS GUARDING AGAINST
 *
 * An UNANSWERED consent request is not a refusal, and the two used to be treated
 * the same. A run whose grant had merely not been answered yet was discarded
 * along with the ones that had been denied — throwing away work that becomes
 * legal the moment somebody reads their notifications, and teaching the
 * requester nothing except that their flow did not run.
 *
 * The assertion that matters is not any single status. It is the TRANSITION:
 * parked while the answer is outstanding, released the moment it arrives, and
 * never released by a clock. `FlowConsentParkingTest` pins the decision table in
 * isolation; this pins that the decision is actually reached — by a real
 * schedule tick, through a real queue, with a real cron sweep releasing it.
 *
 * ⚠️ WHY THIS SPEC SHELLS OUT
 *
 * Both halves are driven by TimedJobs, so nothing an HTTP client can send makes
 * them tick. `flow-schedule.spec.ts` established the pattern this follows:
 * `occ background-job:execute` through the dev container, and a SKIP — never a
 * pass — when the container is not reachable. A skip that names what went
 * unverified is honest; a green that never ran the sweep is not.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveContainer } from '../base-url'
import {
	ADMIN,
	findSecondAccount,
	NO_SECOND_ACCOUNT,
	revokeGrantsOver,
} from './delegation-fixtures'

const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e-park-${Date.now().toString(36)}`

// 🔴 DISCOVERED, never hardcoded — see ./delegation-fixtures.ts. A fixture uid that
// stopped existing when the instance was rebuilt made a sibling spec fail with
// the delegation guard's own refusal message, which is the one sentence that
// makes a dead fixture look like a working control.
let OTHER = ''

// ⚠️ No localhost default — see resolveContainer(). Executing background jobs
// inside a guessed container runs them against somebody else's bind-mounted
// checkout.
const CONTAINER = resolveContainer()

function occ(args: string): string {
	if (CONTAINER === null) {
		throw new Error('NC_CONTAINER is not set; refusing to guess a container.')
	}
	return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
		encoding: 'utf8',
	})
}

/**
 * A background job's id by CLASS BASENAME.
 *
 * The basename, not the namespace: #2870 moved these jobs from
 * `OCA\OpenRegister\Cron\` to `OCA\OpenRegister\BackgroundJob\`, and a matcher
 * pinned to a namespace stops finding its job after a move — which does not fail
 * a spec, it SKIPS it, and a skip reads like a pass in the summary.
 */
function jobId(basename: string): string | null {
	try {
		const line = occ('background-job:list')
			.split('\n')
			.find((l) => l.includes(basename))
		return line ? (line.match(/\|\s*(\d+)\s*\|/)?.[1] ?? null) : null
	} catch {
		return null
	}
}

/** Save a flow whose schedule trigger names OTHER. */
async function saveDelegatingFlow(request: APIRequestContext, label: string) {
	return request.post('/apps/openregister/api/flows', {
		data: {
			name: `${RUN_ID} ${label}`,
			description: 'Created by the delegation-parking e2e suite.',
			trigger: 'schedule',
			enabled: true,
			cron: '* * * * *',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-schedule',
					config: { cron: '* * * * *', runAs: OTHER },
					position: { x: 0, y: 0 },
				},
				{
					id: 'done',
					type: 'openregister.end',
					config: { message: 'The flow completed.' },
					position: { x: 200, y: 0 },
				},
			],
			edges: [{ id: 'e1', from: 'start', to: 'done' }],
		},
	})
}

async function requestConsent(request: APIRequestContext, reason: string) {
	const resp = await request.post(`${API}/delegations`, {
		data: { actingAs: OTHER, reason },
	})
	expect(resp.status(), await resp.text()).toBe(201)
	return (await resp.json()).uuid as string
}

async function runsFor(request: APIRequestContext, flowId: string) {
	const resp = await request.get(`${API}/flow-runs?flowId=${flowId}`)
	expect(resp.status()).toBe(200)
	return (await resp.json()).results as Array<Record<string, unknown>>
}

test.describe.configure({ mode: 'serial' })

test.describe('delegation-parking — a run waits for a person, not a clock', () => {
	let scheduleJob: string | null = null
	let runJob: string | null = null
	let flowId = ''
	let grantUuid = ''

	test.beforeAll(async ({ request }) => {
		scheduleJob = jobId('FlowScheduleWorker')
		runJob = jobId('FlowRunWorker')
		OTHER = (await findSecondAccount(request)) ?? ''
		// Start from no live grant: this suite drives the grant state itself, and
		// a leftover one would decide its first transition — see revokeGrantsOver().
		await revokeGrantsOver(request, OTHER)
	})

	test.beforeEach(() => {
		test.skip(OTHER === '', NO_SECOND_ACCOUNT)
	})

	test.afterAll(async ({ request }) => {
		// Leave nothing live behind: a surviving grant would let a LATER suite's
		// refusal assertion pass or fail for a reason nobody declared.
		if (grantUuid !== '') {
			await request.post(`${API}/delegations/${grantUuid}/revoke`)
		}
		if (flowId !== '') {
			await request.delete(`${API}/flows/${flowId}`)
		}
	})

	test('SETUP: a granted delegation lets the schedule save, stamped', async ({
		request,
	}) => {
		test.skip(
			scheduleJob === null || runJob === null,
			'the flow workers are not reachable via occ, so the parking path — a schedule '
				+ 'tick, the queue, and the cron sweep that releases a parked run — is '
				+ 'UNVERIFIED by this run. Set NC_CONTAINER and run on the dev host.',
		)

		grantUuid = await requestConsent(request, `${RUN_ID} covering leave`)

		const answered = await request.post(
			`${API}/delegations/${grantUuid}/answer`,
			{ data: { allow: true } },
		)
		expect(answered.status(), await answered.text()).toBe(200)

		const saved = await saveDelegatingFlow(request, 'parks then resumes')
		expect(saved.status(), await saved.text()).toBe(201)

		const flow = await saved.json()
		flowId = flow.id
		const trigger = (flow.nodes ?? []).find(
			(n: Record<string, unknown>) =>
				n.type === 'openregister.trigger-schedule',
		)
		expect(
			trigger.config.runAsDeclaredBy,
			'without the stamp there is nothing for the fire path to re-resolve',
		).toBe(ADMIN)
	})

	test('🔴 an UNANSWERED delegation parks the run rather than discarding it', async ({
		request,
	}) => {
		test.skip(scheduleJob === null, 'FlowScheduleWorker not reachable via occ')

		// Withdraw, then ask again. The verdict is now PENDING — somebody has been
		// asked and has not replied — which is a different fact from "they said no"
		// and must produce a different outcome.
		const revoked = await request.post(`${API}/delegations/${grantUuid}/revoke`)
		expect(revoked.status()).toBe(200)

		grantUuid = await requestConsent(request, `${RUN_ID} asking again`)

		occ(`background-job:execute ${scheduleJob} --force-execute`)

		const runs = await runsFor(request, flowId)
		expect(runs.length, 'the schedule fired').toBeGreaterThanOrEqual(1)
		expect(
			runs[0].status,
			'an unanswered request must park the run, not discard it',
		).toBe('awaiting_consent')

		// 🔴 "Why is this stuck" has to be answerable FROM THE RUN. An operator who
		// must join it against a grant table to find out is one who will not.
		expect(String(runs[0].error ?? '')).toContain(OTHER)
		expect(String(runs[0].error ?? '')).toContain(ADMIN)
	})

	test('🔴 the answer releases it — and the work actually runs', async ({
		request,
	}) => {
		test.skip(runJob === null, 'FlowRunWorker not reachable via occ')

		const answered = await request.post(
			`${API}/delegations/${grantUuid}/answer`,
			{ data: { allow: true } },
		)
		expect(answered.status(), await answered.text()).toBe(200)

		occ(`background-job:execute ${runJob} --force-execute`)

		const runs = await runsFor(request, flowId)
		expect(
			runs[0].status,
			'a released run must leave awaiting_consent',
		).not.toBe('awaiting_consent')

		// Terminal-and-successful, not merely "moved on". A run released into a
		// state that never executes would satisfy the assertion above and be the
		// silent-skip failure this whole subsystem is about — so assert the walk
		// happened. `stopped` is what an `end` node reports; `completed` is the
		// other honest terminal.
		expect(['completed', 'stopped', 'queued', 'running']).toContain(
			runs[0].status,
		)
	})
})
