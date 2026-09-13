import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TIMELINE ENTRY VISIBILITY — internal or public, over HTTP.
 *
 * The unit suites already prove the pieces: the default in NoteService, the
 * verdict in TimelineVisibilityService, the refusals in NotesController. None
 * of them can fail if the wiring between them is wrong — a controller that
 * never reaches the guard, a service the container builds without it, a filter
 * that is computed and then not passed on. Each of those is a green-but-dead
 * state a mocked suite reports as success.
 *
 * So this one asks the question the citizen's portal will ask, through the real
 * routes, as two real accounts:
 *
 *   handler writes two notes, one public -> reader sees exactly one
 *   reader tries to publish a note       -> 403, and the note is unchanged
 *   handler flips the internal note      -> the object's audit trail says so
 *
 * The before-state is asserted every time, so "it was already public" or "the
 * reader could never see anything" can never be mistaken for the flag working.
 *
 * HERMETIC. It creates its own register, schema and object. The two accounts
 * come from the workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh),
 * shared with the sibling sharing specs.
 *
 * WHO IS WHO. `update` on the object is what the flag is authorised against, so
 * the two roles are picked by that and nothing else:
 *   - the HANDLER is e2e-other, the seeded member of `e2e-grantees`, the only
 *     group the fixture schema grants `update` to.
 *   - the READER is e2e-owner, who matches `authenticated` and therefore holds
 *     `read` and nothing more.
 * The object is created by the admin so that neither role owns it, and an
 * ownership rule cannot quietly hand the reader an update it should not have.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const READER = 'e2e-owner'
const HANDLER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/** Seeded group whose only member is the handler — see tests/e2e/ci/seed.sh. */
const GROUP = 'e2e-grantees'

const API = '/index.php/apps/openregister/api'

/** Build an API context authenticated as one user. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/**
 * A seeded account must be able to authenticate.
 *
 * Without this, a seed that silently did not run surfaces much later as a
 * confusing 403 inside a visibility assertion — which is exactly the failure
 * this spec is meant to detect, so it would be read as a real regression.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe('timeline entry visibility over HTTP', () => {
	let admin: APIRequestContext
	let handler: APIRequestContext
	let reader: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string

	/** The path of the notes leaf on the fixture object. */
	const notesPath = (): string => `${API}/objects/${registerId}/${schemaId}/${objectUuid}/notes`

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		handler = await contextFor(HANDLER, PASS)
		reader = await contextFor(READER, PASS)

		await assertSeededUser(handler, HANDLER)
		await assertSeededUser(reader, READER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e visibility register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// Read for anyone logged in, update for the seeded group only. That
		// split is the whole fixture: it is what makes one account a handler
		// and the other a reader.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e visibility schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: [GROUP],
					update: [GROUP],
					delete: [GROUP],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key: 'timeline-object' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	// @e2e object-interactions::a-note-written-without-the-flag-is-internal
	test('a note written without the flag reads as internal', async () => {
		const created = await handler.post(notesPath(), {
			data: { message: `plain note ${RUN}` },
		})
		expect(created.ok(), `note create failed: ${await created.text()}`).toBeTruthy()

		const note = await created.json()
		expect(
			note.visibility,
			'a note created with only a message must be internal',
		).toBe('internal')
	})

	// @e2e integration-activity::a-citizens-view-holds-only-public-rows
	// @e2e integration-activity::a-handler-filters-to-what-the-citizen-sees
	test('a reader is served the public rows, and a handler sees both sides', async () => {
		const internal = await handler.post(notesPath(), {
			data: { message: `internal note ${RUN}`, visibility: 'internal' },
		})
		expect(internal.ok(), `internal note create failed: ${await internal.text()}`).toBeTruthy()

		const published = await handler.post(notesPath(), {
			data: { message: `public note ${RUN}`, visibility: 'public' },
		})
		expect(published.ok(), `public note create failed: ${await published.text()}`).toBeTruthy()
		expect((await published.json()).visibility).toBe('public')

		// The handler, asking for nothing, holds both of them.
		const all = await handler.get(notesPath())
		expect(all.ok(), `handler read failed: ${await all.text()}`).toBeTruthy()
		const allBody = await all.json()
		expect(allBody.canSetVisibility, 'a handler may set the flag').toBe(true)
		expect(allBody.visibility, 'an unfiltered read carries no filter').toBeNull()
		const allMessages = allBody.results.map((row: { message: string }) => row.message)
		expect(allMessages).toContain(`internal note ${RUN}`)
		expect(allMessages).toContain(`public note ${RUN}`)

		// The same handler, asking for the citizen's view, holds one of them.
		const filtered = await handler.get(`${notesPath()}?visibility=public`)
		expect(filtered.ok(), `filtered read failed: ${await filtered.text()}`).toBeTruthy()
		const filteredMessages = (await filtered.json()).results.map(
			(row: { message: string }) => row.message,
		)
		expect(filteredMessages).toContain(`public note ${RUN}`)
		expect(filteredMessages).not.toContain(`internal note ${RUN}`)

		// The reader, asking for nothing, is served the citizen's view anyway.
		const asReader = await reader.get(notesPath())
		expect(asReader.ok(), `reader read failed: ${await asReader.text()}`).toBeTruthy()
		const readerBody = await asReader.json()
		expect(readerBody.canSetVisibility, 'a reader may not set the flag').toBe(false)
		expect(readerBody.visibility, 'a reader is pinned to the public view').toBe('public')
		const readerMessages = readerBody.results.map((row: { message: string }) => row.message)
		expect(readerMessages).toContain(`public note ${RUN}`)
		expect(readerMessages).not.toContain(`internal note ${RUN}`)

		// And asking for the internal view does not get it.
		const readerAsking = await reader.get(`${notesPath()}?visibility=internal`)
		expect(readerAsking.ok()).toBeTruthy()
		const askedMessages = (await readerAsking.json()).results.map(
			(row: { message: string }) => row.message,
		)
		expect(
			askedMessages,
			'asking for internal must not hand a reader the internal notes',
		).not.toContain(`internal note ${RUN}`)
	})

	// @e2e object-interactions::a-reader-cannot-flip-the-flag
	test('a reader cannot flip the flag, and the note is unchanged', async () => {
		const created = await handler.post(notesPath(), {
			data: { message: `guarded note ${RUN}`, visibility: 'internal' },
		})
		expect(created.ok(), `note create failed: ${await created.text()}`).toBeTruthy()
		const noteId = String((await created.json()).id)

		const refusedWrite = await reader.post(notesPath(), {
			data: { message: `reader tries to publish ${RUN}`, visibility: 'public' },
		})
		expect(
			refusedWrite.status(),
			'a reader must not be able to create a public note',
		).toBe(403)

		const refusedFlip = await reader.put(`${notesPath()}/${noteId}`, {
			data: { visibility: 'public' },
		})
		expect(refusedFlip.status(), 'a reader must not be able to flip the flag').toBe(403)

		// Unchanged: the handler still sees it on the internal side.
		const after = await handler.get(`${notesPath()}?visibility=internal`)
		const stillInternal = (await after.json()).results.map(
			(row: { message: string }) => row.message,
		)
		expect(stillInternal, 'the refused write must have changed nothing').toContain(
			`guarded note ${RUN}`,
		)
	})

	// @e2e object-interactions::making-a-note-public-is-audited
	test('making a note public is written on the object audit trail', async () => {
		const created = await handler.post(notesPath(), {
			data: { message: `audited note ${RUN}`, visibility: 'internal' },
		})
		expect(created.ok(), `note create failed: ${await created.text()}`).toBeTruthy()
		const noteId = String((await created.json()).id)

		const flipped = await handler.put(`${notesPath()}/${noteId}`, {
			data: { visibility: 'public' },
		})
		expect(flipped.ok(), `flip failed: ${await flipped.text()}`).toBeTruthy()
		expect((await flipped.json()).visibility).toBe('public')

		// Read as the admin: the audit-trail endpoint is admin-gated, and the
		// handler who made the move is deliberately not one.
		const trail = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/audit-trails`,
		)
		expect(trail.ok(), `audit read failed: ${await trail.text()}`).toBeTruthy()
		const trailBody = await trail.json()
		const entries = trailBody.results ?? trailBody.items ?? []
		const move = entries.find(
			(entry: { action?: string, changed?: Record<string, unknown> }) =>
				entry.action === 'note.visibility.changed'
				&& String(entry.changed?.noteId) === noteId,
		)
		expect(
			move,
			`no audit entry named note ${noteId} moving across the counter`,
		).toBeTruthy()
		expect(move.changed.from).toBe('internal')
		expect(move.changed.to).toBe('public')
	})
})
