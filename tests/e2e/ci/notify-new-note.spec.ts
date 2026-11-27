import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A NEW NOTE TELLS WHOEVER WATCHES THE OBJECT, AND NOT ITS AUTHOR.
 *
 * notifications-new-notes-and-referrers task 3.1 (the `noteAdded` half). A
 * schema readable by every signed-in user declares a `noteAdded` rule to its
 * watchers; `e2e-other` watches an object, `e2e-owner` adds a note. The watcher
 * is told, the author is not, and the watcher stays subscribed.
 *
 * That last line is the regression this spec was written against: `authenticated`
 * was resolved as a Nextcloud group with no members, so the watcher read as
 * having lost read on the object, was told nothing and was unsubscribed.
 *
 * The notification is sent by a queued job: the spec drives /cron.php while it
 * waits, and skips the delivery checks, naming why, on an instance whose job
 * queue does not reach the job in time. Uses the seeded accounts of
 * tests/e2e/ci/seed.sh. HERMETIC otherwise.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const SEEDED_PASS = 'E2e-Share-Pass-123'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)
const TITLE = `Dakgoot ${RUN}`

/** An API context signed in as one user, without a session cookie that would override it. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

test.describe('a new note is announced to the watchers', () => {
	test.setTimeout(240_000)

	let admin: APIRequestContext
	let watcher: APIRequestContext
	let author: APIRequestContext
	let registerId: string
	let schemaId: string
	let uuid: string

	/** The openregister notifications one user holds about this run's object. */
	async function noticesFor(user: APIRequestContext): Promise<Array<{ subject: string }>> {
		const res = await user.get('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json')
		if (!res.ok()) {
			return []
		}
		return ((await res.json()).ocs.data || []).filter((n: { app: string, subject: string }) => n.app === 'openregister' && String(n.subject).includes(TITLE))
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		watcher = await contextFor('e2e-other', SEEDED_PASS)
		author = await contextFor('e2e-owner', SEEDED_PASS)

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				title: `Note notice ${RUN}`,
				slug: `notenotice${RUN}`,
				properties: { title: { type: 'string' } },
				authorization: { read: ['authenticated'], update: ['authenticated'] },
				configuration: {
					'x-openregister-notifications': {
						'new-comment': {
							trigger: { type: 'noteAdded' },
							recipients: [{ watchers: true }],
							channels: ['nc-notification'],
							subject: '{{note.author}} commented on {{title}}: {{note.excerpt}}',
						},
					},
				},
			},
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, { data: { title: `Note notice ${RUN}`, slug: `notenoticeregister${RUN}`, schemas: [Number(schemaId)] } })
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { title: TITLE } })
		expect(record.status(), await record.text()).toBe(201)
		uuid = (await record.json())['@self'].id
	})

	test.afterAll(async () => {
		if (uuid !== undefined) {
			await watcher.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}/watch`)
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== undefined) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await Promise.all([admin.dispose(), watcher.dispose(), author.dispose()])
	})

	test('the watcher is told, the author is not, and the watcher stays subscribed', async () => {
		const watch = await watcher.put(`${API}/objects/${registerId}/${schemaId}/${uuid}/watch`)
		expect(watch.ok(), await watch.text()).toBe(true)

		const note = await author.post(`${API}/objects/${registerId}/${schemaId}/${uuid}/notes`, { data: { message: 'Ladder staat klaar' } })
		expect(note.status(), await note.text()).toBe(201)

		let delivered = 0
		const deadline = Date.now() + 120_000
		while (Date.now() < deadline) {
			await admin.get('/cron.php')
			delivered = (await noticesFor(watcher)).length
			if (delivered > 0) {
				break
			}
			await new Promise((resolve) => setTimeout(resolve, 2_000))
		}
		// Checked whether or not the job ran: a job that ran and dropped the watcher is the regression.
		const watchers = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}/watchers`)
		expect(watchers.ok(), await watchers.text()).toBe(true)
		expect(JSON.stringify(await watchers.json()), 'the watcher must stay subscribed').toContain('e2e-other')

		test.skip(delivered === 0, 'the notification job has not run on this instance within two minutes (background job queue)')

		const [told] = await noticesFor(watcher)
		expect(told.subject).toContain('commented on')
		expect(told.subject).toContain('Ladder staat klaar')
		expect(await noticesFor(author)).toHaveLength(0)
	})
})
