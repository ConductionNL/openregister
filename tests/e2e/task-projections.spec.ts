/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Task projections e2e: the @e2e-marked scenarios of flow-task-projections
 * and the modified object-interactions requirements, driven over the REST
 * API and CalDAV with Basic auth (no session, so no CSRF token is demanded).
 *
 * 1. "an assigned task appears in the assignee's calendar and links back":
 *    assigning a task writes a VTODO into the assignee's calendar carrying
 *    DUE, PRIORITY, X-OPENREGISTER-TASK and a URL that resolves (redirects)
 *    to the task's own surface, not to the API.
 * 2. "completing the projected VTODO completes the engine task": a PUT of
 *    the VTODO with STATUS:COMPLETED, as the assignee, completes the task
 *    and audits the assignee as actor.
 * 3. "an unauthorized calendar completion is reverted and reported": a
 *    stranger who can reach the VTODO (the calendar is shared read-write)
 *    is refused in-band (403), the task stays open, and the calendar entry
 *    still shows the engine's state.
 * 4. "the user-wide task aggregate lists engine tasks": GET /api/tasks lists
 *    the engine task with a datastore total and no calendar enumeration.
 * 5. "a watcher sees the task and no action buttons" and "the task widget
 *    shows a page of rows and the full count" are recorded as blocked in
 *    tasks.md: the watcher's verb set and the widget live in nextcloud-vue,
 *    whose named index source registry does not yet express a task source.
 * 6. "an assignee approves a task from the notification": the approve
 *    action is a POST to the complete verb route; exercised here as the
 *    exact request the notification button issues.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-user-wide-task-aggregate-endpoint
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-compatibility-with-nextcloud-tasks-app
 */
import {
	test,
	expect,
	request as apiRequest,
	type APIRequestContext,
} from '@playwright/test'

const RUN_ID = `e2e-proj-${Date.now().toString(36)}`
const ADMIN = process.env.OR_USER || 'admin'
const STRANGER = `${RUN_ID}-stranger`
const STRANGER_PASS = `Str4nger!${Date.now().toString(36)}A`

const NO_SESSION = { cookies: [], origins: [] }
const basic = (user: string, pass: string) => ({
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
	Authorization: `Basic ${Buffer.from(`${user}:${pass}`).toString('base64')}`,
})
const ADMIN_HEADERS = basic(ADMIN, process.env.OR_PASS || 'admin')

const TASKS = '/index.php/apps/openregister/api/flow-tasks'
const AGGREGATE = '/index.php/apps/openregister/api/tasks'
const OPEN = '/index.php/apps/openregister/flow-tasks'
const CALENDAR = `/remote.php/dav/calendars/${ADMIN}/personal`

test.use({ storageState: NO_SESSION, extraHTTPHeaders: ADMIN_HEADERS })

/**
 * Create a task assigned to the admin (who is also its requester) and hand
 * back its row.
 *
 * @param request The admin API context.
 * @param overrides Fields to set on the task.
 */
async function createTask(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
	const response = await request.post(TASKS, {
		data: {
			title: `${RUN_ID} approval`,
			state: 'active',
			performerType: 'user',
			assignee: ADMIN,
			requester: ADMIN,
			priority: 'high',
			dueAt: '2026-12-01T12:00:00+00:00',
			...overrides,
		},
	})
	expect(response.status(), await response.text()).toBe(201)
	return response.json()
}

/**
 * The projected VTODO for a task, read straight from the assignee's
 * calendar over CalDAV. The projector writes under a stable uri, so the
 * read is one GET, and the response is the raw iCalendar document.
 *
 * @param request The calendar owner's API context.
 * @param uuid The task uuid.
 */
async function readVtodo(request: APIRequestContext, uuid: string) {
	const response = await request.get(`${CALENDAR}/openregister-task-${uuid}.ics`, {
		headers: { Accept: 'text/calendar' },
	})
	return response
}

/**
 * Unfold RFC 5545 continuation lines so assertions read whole properties.
 *
 * @param ics The iCalendar document.
 */
function unfold(ics: string): string {
	return ics.replace(/\r?\n[ \t]/g, '')
}

/**
 * Cancel this run's task so terminal litter is at least closed litter.
 *
 * @param request The admin API context.
 * @param uuid The task to cancel.
 */
async function cancelQuietly(request: APIRequestContext, uuid: string) {
	try {
		await request.post(`${TASKS}/${uuid}/cancel`, { data: { reason: `${RUN_ID} cleanup` } })
	} catch (error) {
		console.warn('[task-projections] cleanup failed:', error)
	}
}

test.describe('flow-task-projections: the calendar projection', () => {
	test('an assigned task appears in the assignee\'s calendar and links back', async ({ request }) => {
		const task = await createTask(request)
		try {
			const vtodo = await readVtodo(request, task.uuid)
			test.skip(
				vtodo.status() === 404,
				'the assignee has no VTODO-capable calendar on this instance; the projection is skipped by design and logged naming the task',
			)
			expect(vtodo.status(), await vtodo.text()).toBe(200)
			const ics = unfold(await vtodo.text())

			expect(ics).toContain('BEGIN:VTODO')
			expect(ics).toContain(`X-OPENREGISTER-TASK:${task.uuid}`)
			expect(ics).toContain(`X-OPENREGISTER-TASK-ASSIGNEE:${ADMIN}`)
			expect(ics).toContain(`SUMMARY:${RUN_ID} approval`)
			expect(ics).toContain('DUE:20261201T120000Z')
			expect(ics).toContain('PRIORITY:3')
			expect(ics).toContain('STATUS:IN-PROCESS')
			// The assignee is an identity, never prose.
			expect(ics).not.toContain('Assigned to')

			// The URL deep-links to a surface a person can act on, not to the API.
			const url = /URL(?:;VALUE=URI)?:(\S+)/.exec(ics)?.[1]
			expect(url, 'the VTODO carries a URL').toBeTruthy()
			expect(url).toContain(`${OPEN}/${task.uuid}`)
			expect(url).not.toContain('/api/')

			// Following it lands in the app (a redirect into the task route), not a 404.
			const followed = await request.get(url!, { maxRedirects: 0 })
			expect([302, 303]).toContain(followed.status())
			expect(followed.headers().location).toContain(`#/flow-tasks/${task.uuid}`)
		} finally {
			await cancelQuietly(request, task.uuid)
		}
	})

	test('completing the projected VTODO completes the engine task', async ({ request }) => {
		const task = await createTask(request)
		try {
			const vtodo = await readVtodo(request, task.uuid)
			test.skip(vtodo.status() === 404, 'no VTODO-capable calendar for the assignee on this instance')
			const ticked = (await vtodo.text()).replace('STATUS:IN-PROCESS', 'STATUS:COMPLETED')

			// The assignee ticks it off in a calendar client: a PUT of the document.
			const put = await request.put(`${CALENDAR}/openregister-task-${task.uuid}.ics`, {
				headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
				data: ticked,
			})
			expect([200, 201, 204], await put.text()).toContain(put.status())

			// The engine task reached its completed state, with the assignee as actor.
			const after = await request.get(`${TASKS}/${task.uuid}`)
			expect(after.status()).toBe(200)
			const row = await after.json()
			expect(row.state).toBe('completed')
			expect(row.completedBy).toBe(ADMIN)

			const audit = await request.get(`${TASKS}/${task.uuid}/audit`)
			expect(audit.status()).toBe(200)
			const entries = (await audit.json()).results ?? (await audit.json())
			const completion = (Array.isArray(entries) ? entries : []).find(
				(entry: { action?: string; authorized?: boolean }) => entry.action === 'complete' && entry.authorized !== false,
			)
			expect(completion, 'exactly one authorized completion audit entry').toBeTruthy()
			expect(completion.actor).toBe(ADMIN)
			expect(
				(Array.isArray(entries) ? entries : []).filter((entry: { action?: string; authorized?: boolean }) => entry.action === 'complete' && entry.authorized !== false),
			).toHaveLength(1)

			// The calendar entry shows the engine's state: rendered COMPLETED, not echoed back.
			const rendered = unfold(await (await readVtodo(request, task.uuid)).text())
			expect(rendered).toContain('STATUS:COMPLETED')
		} finally {
			await cancelQuietly(request, task.uuid)
		}
	})

	test('an unauthorized calendar completion is reverted and reported', async ({ request }) => {
		const provisioned = await request.post('/ocs/v2.php/cloud/users', {
			data: { userid: STRANGER, password: STRANGER_PASS },
		})
		test.skip(provisioned.status() !== 200, `cannot provision a stranger account (HTTP ${provisioned.status()})`)

		const task = await createTask(request)
		const stranger = await apiRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL,
			extraHTTPHeaders: basic(STRANGER, STRANGER_PASS),
		})

		try {
			const vtodo = await readVtodo(request, task.uuid)
			test.skip(vtodo.status() === 404, 'no VTODO-capable calendar for the assignee on this instance')

			// Share the calendar read-write with the stranger: the single most
			// likely real-world unauthorized path.
			const share = await request.post(CALENDAR, {
				headers: { 'Content-Type': 'application/xml; charset=utf-8' },
				data: `<?xml version="1.0" encoding="utf-8" ?>
<o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns">
  <o:set>
    <d:href>principal:principals/users/${STRANGER}</d:href>
    <o:read-write/>
  </o:set>
</o:share>`,
			})
			test.skip(![200, 204].includes(share.status()), `calendar sharing not available (HTTP ${share.status()})`)

			const ticked = (await vtodo.text()).replace('STATUS:IN-PROCESS', 'STATUS:COMPLETED')
			const strangerPut = await stranger.put(
				`/remote.php/dav/calendars/${STRANGER}/personal_shared_by_${ADMIN}/openregister-task-${task.uuid}.ics`,
				{ headers: { 'Content-Type': 'text/calendar; charset=utf-8' }, data: ticked },
			)
			// Refused in-band: the client never records the change.
			expect([403, 404], await strangerPut.text()).toContain(strangerPut.status())

			// The engine task did not move.
			const after = await request.get(`${TASKS}/${task.uuid}`)
			expect((await after.json()).state).toBe('active')

			// The calendar shows the engine's state, not the stranger's edit.
			const rendered = unfold(await (await readVtodo(request, task.uuid)).text())
			expect(rendered).toContain('STATUS:IN-PROCESS')

			if (strangerPut.status() === 403) {
				// The refusal is auditable: a denial entry naming the stranger.
				const audit = await request.get(`${TASKS}/${task.uuid}/audit`)
				const entries = (await audit.json()).results ?? (await audit.json())
				const denial = (Array.isArray(entries) ? entries : []).find(
					(entry: { actor?: string; authorized?: boolean }) => entry.actor === STRANGER && entry.authorized === false,
				)
				expect(denial, 'the refusal is recorded in the task audit').toBeTruthy()
			}
		} finally {
			await stranger.dispose()
			await cancelQuietly(request, task.uuid)
			await request.delete(`/ocs/v2.php/cloud/users/${STRANGER}`).catch((error) =>
				console.warn('[task-projections] stranger cleanup failed:', error),
			)
		}
	})
})

test.describe('flow-task-projections: the decision from the notification', () => {
	test('an assignee approves a task from the notification', async ({ request }) => {
		const task = await createTask(request)
		try {
			// The approve button is a POST to the complete verb route with the
			// approving outcome as a query parameter: this is that request.
			const approve = await request.post(`${TASKS}/${task.uuid}/complete?outcome=approved`)
			expect(approve.status(), await approve.text()).toBe(200)
			const row = await approve.json()
			expect(row.state).toBe('completed')
			expect(row.outcome).toBe('approved')
			expect(row.completedBy).toBe(ADMIN)

			// A stale reject button loses to the recorded outcome: conflict, unchanged.
			const stale = await request.post(`${TASKS}/${task.uuid}/complete?outcome=rejected&comment=too+late`)
			expect(stale.status()).toBe(409)
			const again = await request.get(`${TASKS}/${task.uuid}`)
			expect((await again.json()).outcome).toBe('approved')
		} finally {
			await cancelQuietly(request, task.uuid)
		}
	})
})

test.describe('object-interactions: the user-wide aggregate', () => {
	test('the user-wide task aggregate lists engine tasks', async ({ request }) => {
		const task = await createTask(request)
		try {
			const listed = await request.get(`${AGGREGATE}?_limit=100&sort=-created`)
			expect(listed.status(), await listed.text()).toBe(200)
			const page = await listed.json()

			// The total is the query's, and the rows are engine tasks, not VTODOs.
			expect(typeof page.total).toBe('number')
			expect(page.total).toBeGreaterThanOrEqual(1)
			const mine = (page.results ?? []).find((row: { uuid?: string }) => row.uuid === task.uuid)
			expect(mine, 'the engine task is listed').toBeTruthy()
			expect(mine.state).toBe('active')
			expect('overdue' in mine).toBe(true)
			expect('displayTitle' in mine).toBe(true)

			// `assignee` is not honoured as a filter: still the caller's own tasks.
			const other = await request.get(`${AGGREGATE}?assignee=somebody-else&_limit=100&sort=-created`)
			expect(other.status()).toBe(200)
			expect((await other.json()).results.some((row: { uuid?: string }) => row.uuid === task.uuid)).toBe(true)

			// The limit cap stays.
			const capped = await request.get(`${AGGREGATE}?_limit=500`)
			expect((await capped.json()).limit).toBe(200)
		} finally {
			await cancelQuietly(request, task.uuid)
		}
	})
})
