/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Task inbox e2e — the two @e2e-marked scenarios of the flow-tasks spec.
 *
 * 1. "a stranger is refused on the task detail route": an authenticated
 *    user who merely knows a task's uuid gets 403 on the detail read AND
 *    on complete, and the task is unchanged afterwards. This is the
 *    positive control for the exact hole this change closes — on the flow
 *    resume endpoint, knowing the uuid WAS the check.
 * 2. "the inbox route returns tasks with subject context": one request
 *    answers "what is waiting for me" with rows that carry a display
 *    title, the subject context field and a datastore total.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
import {
	test,
	expect,
	request as apiRequest,
	type APIRequestContext,
} from '@playwright/test'

const RUN_ID = `e2e-task-${Date.now().toString(36)}`
const STRANGER = `${RUN_ID}-stranger`
const STRANGER_PASS = `Str4nger!${Date.now().toString(36)}A`

// Same reasoning as flow-engine.spec.ts: drive the REST API with Basic auth
// and no session cookie, so no CSRF token is demanded; `OCS-APIRequest` marks
// the calls as API traffic.
const NO_SESSION = { cookies: [], origins: [] }
const ADMIN_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(
		`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
	).toString('base64')}`,
}
const STRANGER_HEADERS = {
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${STRANGER}:${STRANGER_PASS}`).toString(
		'base64',
	)}`,
}

const BASE = '/index.php/apps/openregister/api/flow-tasks'

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })

/**
 * Create a task as the admin and hand back its row.
 *
 * @param request The admin API context.
 * @param overrides Fields to set on the task.
 */
async function createTask(
	request: APIRequestContext,
	overrides: Record<string, unknown> = {},
) {
	const response = await request.post(BASE, {
		data: {
			title: `${RUN_ID} approval`,
			state: 'active',
			performerType: 'user',
			assignee: process.env.OR_USER || 'admin',
			requester: process.env.OR_USER || 'admin',
			priority: 'normal',
			...overrides,
		},
	})
	expect(response.status(), await response.text()).toBe(201)
	return response.json()
}

/**
 * Cancel this run's task so terminal litter is at least closed litter.
 * Never fails the suite: cleanup is not a verdict on the code under test.
 *
 * @param request The admin API context.
 * @param uuid The task to cancel.
 */
async function cancelQuietly(request: APIRequestContext, uuid: string) {
	try {
		await request.post(`${BASE}/${uuid}/cancel`, {
			data: { reason: `${RUN_ID} cleanup` },
		})
	} catch (error) {
		console.warn('[task-inbox] task cleanup failed:', error)
	}
}

test.describe('flow-tasks — authorization at the boundary', () => {
	test('a stranger is refused on the task detail route', async ({
		request,
	}) => {
		// A second real account, created through the provisioning API. If
		// this instance refuses to provision users the scenario cannot be
		// exercised, and that is a FAILURE of the environment, not a skip:
		// a skip cannot tell "absent" from "broken".
		const provisioned = await request.post('/ocs/v2.php/cloud/users', {
			data: { userid: STRANGER, password: STRANGER_PASS },
		})
		expect(
			provisioned.status(),
			`cannot provision a stranger account: ${await provisioned.text()}`,
		).toBe(200)

		const task = await createTask(request)

		const stranger = await apiRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL,
			extraHTTPHeaders: STRANGER_HEADERS,
		})

		try {
			// The detail READ is refused as NOT FOUND: knowing the uuid is
			// not visibility, and a stranger learns nothing, not even that
			// the uuid exists.
			const read = await stranger.get(`${BASE}/${task.uuid}`)
			expect(read.status(), await read.text()).toBe(404)

			// The VERB is refused the same way, before any mutation.
			const complete = await stranger.post(`${BASE}/${task.uuid}/complete`, {
				data: { outcome: 'approved' },
			})
			expect(complete.status(), await complete.text()).toBe(404)

			// The NON-ADMIN inbox: this is the request that runs the
			// visibility clause (an admin skips it), including the watchers
			// predicate over the JSON column, which is the PostgreSQL trap.
			// The stranger's assigned inbox is empty and does not count our
			// task; a task the admin makes them a WATCHER of does appear.
			const strangerAssigned = await stranger.get(`${BASE}?scope=assigned`)
			expect(strangerAssigned.status(), await strangerAssigned.text()).toBe(200)
			const assignedBody = await strangerAssigned.json()
			expect(assignedBody.total).toBe(0)

			const watched = await createTask(request, {
				title: `${RUN_ID} watched`,
				watchers: [STRANGER],
			})
			try {
				const strangerWatched = await stranger.get(`${BASE}?scope=watched`)
				expect(strangerWatched.status(), await strangerWatched.text()).toBe(200)
				const watchedBody = await strangerWatched.json()
				expect(
					(watchedBody.results ?? []).some(
						(row: { uuid?: string }) => row.uuid === watched.uuid,
					),
					'a watcher must see the task in their watched inbox',
				).toBe(true)
				// Watching confers reading, never acting.
				const watcherCompletes = await stranger.post(
					`${BASE}/${watched.uuid}/complete`,
					{ data: { outcome: 'approved' } },
				)
				expect(watcherCompletes.status()).toBe(403)
			} finally {
				await cancelQuietly(request, watched.uuid)
			}

			// And the task provably did not move: same state, same assignee.
			const after = await request.get(`${BASE}/${task.uuid}`)
			expect(after.status()).toBe(200)
			const row = await after.json()
			expect(row.state).toBe('active')
			expect(row.assignee).toBe(process.env.OR_USER || 'admin')
			expect(row.completedBy).toBeNull()
		} finally {
			await stranger.dispose()
			await cancelQuietly(request, task.uuid)
			await request
				.delete(`/ocs/v2.php/cloud/users/${STRANGER}`)
				.catch((error) =>
					console.warn('[task-inbox] stranger cleanup failed:', error),
				)
		}
	})
})

test.describe('flow-tasks — the inbox', () => {
	test('the inbox route returns tasks with subject context', async ({
		request,
	}) => {
		// Anchor to a live object when the dev seed provides one, so the
		// subject context is real rather than merely present-and-null.
		let anchor: Record<string, unknown> = {}
		const seeded = await request.get(
			'/index.php/apps/openregister/api/objects/8/18?_limit=1',
		)
		if (seeded.ok()) {
			const first = ((await seeded.json()).results ?? [])[0]
			const objectUuid = first?.['@self']?.uuid ?? first?.uuid ?? null
			if (objectUuid) {
				anchor = { objectUuid, registerId: 8, schemaId: 18 }
			}
		}

		const task = await createTask(request, anchor)

		try {
			const listed = await request.get(
				`${BASE}?scope=assigned&limit=100&sort=created&direction=desc`,
			)
			expect(listed.status(), await listed.text()).toBe(200)
			const inbox = await listed.json()

			// The total comes from the datastore, not from counting the page.
			expect(typeof inbox.total).toBe('number')
			expect(inbox.total).toBeGreaterThanOrEqual(1)

			const mine = (inbox.results ?? []).find(
				(row: { uuid?: string }) => row.uuid === task.uuid,
			)
			expect(mine, 'the created task must appear in its assignee inbox').toBeTruthy()

			// Every row carries a readable identity and the subject slot: a
			// list usable without a second request per row.
			expect(String(mine.displayTitle ?? '')).not.toBe('')
			expect('subject' in mine).toBe(true)
			expect('overdue' in mine).toBe(true)

			// When a live object anchored the task, its context came along.
			if ('objectUuid' in anchor) {
				expect(mine.subject).not.toBeNull()
				expect(mine.subject.uuid).toBe(anchor.objectUuid)
				expect('register' in mine.subject).toBe(true)
				expect('schema' in mine.subject).toBe(true)
			}
		} finally {
			await cancelQuietly(request, task.uuid)
		}
	})
})
