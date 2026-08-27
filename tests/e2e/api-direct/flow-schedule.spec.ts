/**
 * OpenRegister scheduled flow trigger — end-to-end.
 *
 * A schedule has no event to listen for, so it is driven by a background job
 * (FlowScheduleWorker) that ticks and queues a run for every scheduled flow now
 * due. This exercises that path the way a client experiences it: author a
 * scheduled flow as an object, run the worker, and assert a `schedule`-triggered
 * run was queued — read back through the run-history API.
 *
 * The worker is a TimedJob, so the "tick" is triggered here via
 * `occ background-job:execute` through the dev container (NC_CONTAINER, default
 * `nextcloud`). A run whose occ trigger is unavailable is skipped rather than
 * failed, so the spec degrades cleanly off the dev host.
 *
 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveContainer } from '../base-url'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
// Defaults to the shared dev container — see resolveContainer(). Executing one
// named background job there is the point of a dev box; NC_CONTAINER points it
// elsewhere. Only `docker restart` still needs an explicit opt-in.
const CONTAINER = resolveContainer()
const runId = `e2e-sched-${Date.now()}`

/** The uid a scheduled run acts as. ADR-099: a schedule must name somebody. */
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || 'admin'

function occ(args: string): string {
	if (CONTAINER === null) {
		throw new Error('NC_CONTAINER is not set; refusing to guess a container.')
	}
	return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
		encoding: 'utf8',
	})
}

/** The FlowScheduleWorker's job id, or null when it can't be reached. */
function scheduleWorkerJobId(): string | null {
	try {
		const list = occ('background-job:list')
		const line = list
			.split('\n')
			// 🔴 THE CLASS BASENAME, not the namespace. #2870 moved the job from
			// `OCA\OpenRegister\Cron\` to `OCA\OpenRegister\BackgroundJob\`, and a
			// matcher pinned to the old namespace stops finding it — which does not
			// fail this spec, it SKIPS it. A skip and a pass look the same in the
			// summary, so the whole scheduled-trigger path would have gone
			// unverified with nothing red to say so. The basename survives a
			// namespace move; the namespace is the part that does not.
			.find((l) => l.includes('FlowScheduleWorker'))
		return line ? (line.match(/\|\s*(\d+)\s*\|/)?.[1] ?? null) : null
	} catch {
		return null
	}
}

async function idBySlug(
	request: APIRequestContext,
	kind: 'registers' | 'schemas',
	slug: string,
): Promise<number> {
	const resp = await request.get(`${API}/${kind}?limit=1000`)
	expect(resp.ok()).toBeTruthy()
	const body = await resp.json()
	const rows: any[] = body.results ?? body ?? []
	const match = rows.find((r) => (r.slug ?? r['@self']?.slug) === slug)
	expect(match, `${kind} slug=${slug}`).toBeTruthy()
	return match.id ?? match['@self']?.id
}

/**
 * The id of a register's own schema, by slug.
 *
 * 🔴 SCOPED TO THE REGISTER, because a slug is not unique across schemas. This
 * instance carries TWO schemas with slug `flow` (ids 184 and 454) while the
 * `flows` register lists only one of them. A global `idBySlug('schemas','flow')`
 * picked whichever the API returned first, and posting an object to a
 * register/schema pair that do not belong together answers 400 — an opaque
 * status with nothing in it about slugs, so the spec read as a broken create
 * path rather than a mis-resolved fixture.
 *
 * The register's own `schemas` array is the authority on what it carries; that
 * is what makes this immune to a duplicate slug appearing later.
 */
async function schemaOfRegister(
	request: APIRequestContext,
	registerId: number,
	slug: string,
): Promise<number> {
	const resp = await request.get(`${API}/registers?limit=1000`)
	expect(resp.ok()).toBeTruthy()
	const rows: any[] = (await resp.json()).results ?? []
	const register = rows.find((r) => (r.id ?? r['@self']?.id) === registerId)
	expect(register, `register id=${registerId}`).toBeTruthy()

	const owned: number[] = (register.schemas ?? []).map(Number)
	expect(owned.length, `register ${registerId} carries schemas`).toBeGreaterThan(0)

	const schemas = await request.get(`${API}/schemas?limit=1000`)
	const all: any[] = (await schemas.json()).results ?? []
	const match = all.find(
		(sch) =>
			owned.includes(Number(sch.id ?? sch['@self']?.id))
			&& (sch.slug ?? sch['@self']?.slug) === slug,
	)
	expect(
		match,
		`register ${registerId} carries a schema with slug=${slug}`,
	).toBeTruthy()

	return Number(match.id ?? match['@self']?.id)
}

test.describe('Scheduled flow trigger', () => {
	let reg: number
	let sch: number
	let jobId: string | null
	const created: string[] = []

	test.beforeAll(async ({ request }) => {
		reg = await idBySlug(request, 'registers', 'flows')
		sch = await schemaOfRegister(request, reg, 'flow')
		jobId = scheduleWorkerJobId()
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			// Deleted from /api/flows — the store both fixtures write to now.
			await request
				.delete(`/apps/openregister/api/flows/${id}`)
				.catch(() => {})
		}
	})

	test('a due scheduled flow is fired by the worker and shows in history', async ({
		request,
	}) => {
		test.skip(
			jobId === null,
			'FlowScheduleWorker not reachable via occ (not on the dev host)',
		)

		// 🔴 AUTHORED THROUGH /api/flows, NOT AS A REGISTER OBJECT.
		//
		// OpenRegister keeps TWO stores for "a flow": the `openregister_flows`
		// table behind /api/flows, and objects in the `flows` register.
		// `FlowScheduleService` resolves candidates from the first and cannot see
		// the second — so a schedule authored in the register never fires, the
		// worker reports nothing, and the run history is simply empty. No error
		// anywhere; it reads as a broken scheduler.
		//
		// Verified with a control: the SAME definition posted to /api/flows
		// queued 1 run on the next tick, and posted to the register queued 0.
		//
		// `lib/Settings/flow_register.json` describes that register as "the store
		// the resolver reads by default … so triggers, sub-flows and the /test
		// endpoint all work with a flow authored here". That is not true today,
		// and it is tracked separately — this spec is about the SCHEDULER, so it
		// uses the store the scheduler actually reads.
		const resp = await request.post('/apps/openregister/api/flows', {
			headers: JSON_HEADERS,
			data: {
				name: `${runId} every-minute`,
				description: 'Created by the flow-schedule e2e suite.',
				enabled: true,
				trigger: 'schedule',
				cron: '* * * * *',
				// 🔴 THE STEP IS ON A NODE, NOT AN EDGE — the save validator refuses
				// a `type` + `config` hung off an edge, because an edge is sequence
				// and nothing would read the step.
				//
				// 🔴 AND THE SCHEDULE NAMES WHO IT ACTS AS. Under ADR-099 the
				// identity of a scheduled run comes from the trigger node's
				// `runAs`, and its absence is a REFUSAL: `FlowScheduleService`
				// catches `FlowUnattributed`, records the reason on the flow, and
				// switches the schedule off. Without this node the flow saved
				// fine, the worker ticked, and zero runs were queued — which read
				// as a broken scheduler rather than a definition naming nobody.
				//
				// A scheduled run has no session to inherit from, so a flow that
				// names nobody genuinely has no rights to run with. This is the
				// behaviour, not a workaround for it.
				nodes: [
					{
						id: 'start',
						type: 'openregister.trigger-schedule',
						config: { cron: '* * * * *', runAs: ADMIN },
					},
					{
						id: 'a',
						type: 'openregister.set-fields',
						config: { set: { ran: true } },
					},
					{ id: 'b', type: 'openregister.end', config: {} },
				],
				edges: [
					{ id: 's0', from: 'start', to: 'a' },
					{ id: 's1', from: 'a', to: 'b' },
				],
			},
		})
		expect(resp.status(), await resp.text()).toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.id
		expect(uuid).toBeTruthy()
		created.push(uuid)

		// Tick the schedule worker; a flow never fired before is due at once.
		occ(`background-job:execute ${jobId} --force-execute`)

		const hist = await request.get(`${API}/flow-runs?flowId=${uuid}`)
		expect(hist.status()).toBe(200)
		const body = await hist.json()
		expect(
			body.results.length,
			'a scheduled run was queued',
		).toBeGreaterThanOrEqual(1)
		expect(body.results[0].trigger).toBe('schedule')
	})

	test('a non-schedule flow is not fired by the worker', async ({ request }) => {
		test.skip(jobId === null, 'FlowScheduleWorker not reachable via occ')

		// The same store as the positive control above — otherwise this test
		// asserts "0 runs" about a flow the scheduler could never have seen, and
		// would pass for a reason that has nothing to do with `trigger: manual`.
		const resp = await request.post('/apps/openregister/api/flows', {
			headers: JSON_HEADERS,
			data: {
				name: `${runId} manual`,
				description: 'Created by the flow-schedule e2e suite.',
				enabled: true,
				trigger: 'manual',
				cron: '* * * * *',
				// 🔴 THE STEP IS ON A NODE, NOT AN EDGE — the save validator refuses
				// a `type` + `config` hung off an edge, because an edge is sequence
				// and nothing would read the step. This fixture predates that
				// grammar, so the flow was never created at all.
				nodes: [
					{
						id: 'a',
						type: 'openregister.set-fields',
						config: { set: { ran: true } },
					},
					{ id: 'b', type: 'openregister.end', config: {} },
				],
				edges: [{ id: 's1', from: 'a', to: 'b' }],
			},
		})
		const uuid = (await resp.json())?.id
		created.push(uuid)

		occ(`background-job:execute ${jobId} --force-execute`)

		const hist = await request.get(`${API}/flow-runs?flowId=${uuid}`)
		const body = await hist.json()
		// trigger=manual, so the schedule worker must not have queued anything.
		expect(body.results.length, 'a manual flow is not scheduled').toBe(0)
	})
})
