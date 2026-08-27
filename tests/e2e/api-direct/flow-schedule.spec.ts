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

test.describe('Scheduled flow trigger', () => {
	let reg: number
	let sch: number
	let jobId: string | null
	const created: string[] = []

	test.beforeAll(async ({ request }) => {
		reg = await idBySlug(request, 'registers', 'flows')
		sch = await idBySlug(request, 'schemas', 'flow')
		jobId = scheduleWorkerJobId()
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request
				.delete(`${API}/objects/${reg}/${sch}/${id}`)
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

		const resp = await request.post(`${API}/objects/${reg}/${sch}`, {
			headers: JSON_HEADERS,
			data: {
				name: `${runId} every-minute`,
				enabled: true,
				trigger: 'schedule',
				cron: '* * * * *',
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [
					{
						id: 's1',
						from: 'a',
						to: 'b',
						type: 'openregister.set-fields',
						config: { set: { ran: true } },
					},
				],
			},
		})
		expect(resp.status()).toBeLessThanOrEqual(201)
		const uuid = (await resp.json())?.['@self']?.id
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

		const resp = await request.post(`${API}/objects/${reg}/${sch}`, {
			headers: JSON_HEADERS,
			data: {
				name: `${runId} manual`,
				enabled: true,
				trigger: 'manual',
				cron: '* * * * *',
				nodes: [{ id: 'a' }, { id: 'b' }],
				edges: [
					{
						id: 's1',
						from: 'a',
						to: 'b',
						type: 'openregister.set-fields',
						config: { set: { ran: true } },
					},
				],
			},
		})
		const uuid = (await resp.json())?.['@self']?.id
		created.push(uuid)

		occ(`background-job:execute ${jobId} --force-execute`)

		const hist = await request.get(`${API}/flow-runs?flowId=${uuid}`)
		const body = await hist.json()
		// trigger=manual, so the schedule worker must not have queued anything.
		expect(body.results.length, 'a manual flow is not scheduled').toBe(0)
	})
})
