import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * NOTE EDIT HISTORY — what a note said before, over HTTP.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/note-edit-history/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e object-interactions::the-previous-text-survives-an-edit
 * @e2e object-interactions::a-colleague-with-update-cannot-rewrite-anothers-note
 * @e2e object-interactions::the-trail-records-the-edit-not-the-text
 *
 * WHAT THIS PROVES THAT THE UNIT SUITES CANNOT.
 *
 * NoteServiceTest already proves the version is written before the comment is
 * saved, and NotesControllerTest proves the refusals answer 423 and 403. Both
 * run against mocks, so both stay green if the two new routes are missing from
 * `appinfo/routes.php`, if the container builds NoteService without the
 * versions service, or if the migration never ran because the app version did
 * not move. Each of those is a 404 or a 500 in production and a pass in PHPUnit.
 *
 * So this asks over the real routes, as real accounts:
 *
 *   author edits their own note     -> the old text is in the versions list
 *   a second edit                   -> two versions, newest first
 *   a colleague with update tries   -> 403, and nothing is written
 *   the note is deleted             -> its history is gone with it
 *   the object's trail              -> names the note, never the text
 *
 * WHAT IT DOES NOT PROVE. The locked-note refusal has no route that sets the
 * lock yet: the verb `note-locked` is written by
 * `notes-leaf-rich-text-lock-export`, which has not shipped. The spec delta
 * marks that scenario `@e2e exclude` and it is asserted in
 * tests/Unit/Service/NoteServiceTest.php::testALockedNoteRefusesTheEditAndWritesNoVersion,
 * named here so nobody has to take this comment's word for it.
 *
 * HERMETIC. It creates its own register, schema and object, and removes all
 * three. The two accounts come from the workflow's `playwright-seed-command`
 * (tests/e2e/ci/seed.sh), shared with the sibling notes specs.
 *
 * WHO IS WHO. Rewriting a note is authorised on the AUTHOR or on `manage`, so
 * the roles are picked by that and nothing else:
 *   - the ADMIN writes the notes and holds `manage`.
 *   - the COLLEAGUE is e2e-other, the seeded member of `e2e-grantees`, the only
 *     group the fixture schema grants `update` to, and it is granted no
 *     `manage`. That is precisely the caller the 403 is written for.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const COLLEAGUE = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/** Seeded group whose only member is the colleague — see tests/e2e/ci/seed.sh. */
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
 * Without this, a seed that silently did not run surfaces later as a confusing
 * 403 inside the refusal assertion — which is the very answer that assertion
 * expects, so a missing seed would read as a pass.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('note edit history over HTTP', () => {
	let admin: APIRequestContext
	let colleague: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string

	/** The path of the notes leaf on the fixture object. */
	const notesPath = (): string =>
		`${API}/objects/${registerId}/${schemaId}/${objectUuid}/notes`

	/** Write a note as the admin and hand back its id. */
	async function writeNote(message: string): Promise<string> {
		const created = await admin.post(notesPath(), { data: { message } })
		expect(
			created.ok(),
			`note create failed: ${await created.text()}`,
		).toBeTruthy()

		const noteId = String((await created.json()).id)
		expect(noteId, 'no id came back from the note create').toBeTruthy()

		return noteId
	}

	/** Read one note off the list, so the summary is the one a reader gets. */
	async function readNote(noteId: string): Promise<Record<string, unknown>> {
		const listed = await admin.get(notesPath())
		expect(listed.ok(), `note list failed: ${await listed.text()}`).toBeTruthy()

		const rows = (await listed.json()).results as Array<Record<string, unknown>>
		const row = rows.find((entry) => String(entry.id) === noteId)
		expect(row, `note ${noteId} is not in the list`).toBeTruthy()

		return row as Record<string, unknown>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		colleague = await contextFor(COLLEAGUE, PASS)

		await assertSeededUser(colleague, COLLEAGUE)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e note history register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// `update` for the seeded group, `manage` for the admin group only.
		// That split is the whole fixture: it is what makes the colleague
		// somebody who may change the object and still may not rewrite a note
		// somebody else signed.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e note history schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: [GROUP],
					update: [GROUP],
					delete: [GROUP],
					manage: ['admin'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key: 'note-history-object' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	test.afterAll(async () => {
		if (objectUuid) {
			await admin.delete(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
			)
			await admin.delete(`${API}/deleted/${objectUuid}?force=true`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	// @e2e object-interactions::the-previous-text-survives-an-edit
	test('the text a note used to carry survives the edit that replaced it', async () => {
		const noteId = await writeNote(`Applicant called ${RUN}`)

		// The before-state, asserted rather than assumed: a note that already
		// reported a version would make the assertion below meaningless.
		const before = await readNote(noteId)
		expect(before.versionCount, 'a fresh note has no versions').toBe(0)
		expect(before.editedAt, 'a fresh note was never edited').toBeFalsy()

		const edited = await admin.patch(`${notesPath()}/${noteId}`, {
			data: { message: `Applicant called, will send documents ${RUN}` },
		})
		expect(edited.ok(), `edit failed: ${await edited.text()}`).toBeTruthy()

		const after = await readNote(noteId)
		expect(after.message).toBe(`Applicant called, will send documents ${RUN}`)
		expect(after.versionCount, 'the edit is counted on the note').toBe(1)
		expect(after.editedBy, 'the note names who changed it').toBe(ADMIN)
		expect(after.editedAt, 'the note names when it changed').toBeTruthy()

		const history = await admin.get(`${notesPath()}/${noteId}/versions`)
		expect(
			history.ok(),
			`versions read failed: ${await history.text()}`,
		).toBeTruthy()

		const versions = (await history.json()).results as Array<
			Record<string, unknown>
		>
		expect(versions, 'one version for one edit').toHaveLength(1)
		expect(versions[0].message).toBe(`Applicant called ${RUN}`)
		expect(
			versions[0].author,
			'the version names the author of the text it holds',
		).toBe(ADMIN)
		expect(versions[0].editedBy).toBe(ADMIN)
	})

	test('a second edit adds a second version, newest first', async () => {
		const noteId = await writeNote(`First ${RUN}`)

		for (const text of [`Second ${RUN}`, `Third ${RUN}`]) {
			const edited = await admin.patch(`${notesPath()}/${noteId}`, {
				data: { message: text },
			})
			expect(edited.ok(), `edit failed: ${await edited.text()}`).toBeTruthy()
		}

		const history = await admin.get(`${notesPath()}/${noteId}/versions`)
		expect(
			history.ok(),
			`versions read failed: ${await history.text()}`,
		).toBeTruthy()

		const versions = (await history.json()).results as Array<
			Record<string, unknown>
		>
		expect(versions, 'two edits, two versions').toHaveLength(2)
		expect(versions[0].message, 'newest first').toBe(`Second ${RUN}`)
		expect(versions[1].message).toBe(`First ${RUN}`)
		expect((await readNote(noteId)).versionCount).toBe(2)
	})

	// @e2e object-interactions::a-colleague-with-update-cannot-rewrite-anothers-note
	test('a colleague with update on the object cannot rewrite another persons note', async () => {
		const noteId = await writeNote(`Signed by the admin ${RUN}`)

		const refused = await colleague.patch(`${notesPath()}/${noteId}`, {
			data: { message: `rewritten by somebody else ${RUN}` },
		})
		expect(
			refused.status(),
			`a colleague holding update but not manage must be refused: ${await refused.text()}`,
		).toBe(403)

		// The refusal is only worth anything if nothing was written. Both the
		// text and the history are checked, because a guard that runs AFTER the
		// version write would leave the note intact and the history wrong.
		const after = await readNote(noteId)
		expect(after.message).toBe(`Signed by the admin ${RUN}`)
		expect(after.versionCount, 'a refused edit writes no version').toBe(0)
	})

	test('deleting a note takes its history with it', async () => {
		const noteId = await writeNote(`To delete ${RUN}`)

		const edited = await admin.patch(`${notesPath()}/${noteId}`, {
			data: { message: `To delete, edited ${RUN}` },
		})
		expect(edited.ok(), `edit failed: ${await edited.text()}`).toBeTruthy()

		const before = await admin.get(`${notesPath()}/${noteId}/versions`)
		expect(before.ok()).toBeTruthy()
		expect(
			(await before.json()).total,
			'the history exists before the delete',
		).toBe(1)

		const removed = await admin.delete(`${notesPath()}/${noteId}`)
		expect(removed.ok(), `delete failed: ${await removed.text()}`).toBeTruthy()

		const after = await admin.get(`${notesPath()}/${noteId}/versions`)
		expect(
			after.status(),
			'the history of a deleted note is not readable any more',
		).toBe(404)
	})

	// @e2e object-interactions::the-trail-records-the-edit-not-the-text
	test('the object audit trail names the edited note and never its text', async () => {
		const noteId = await writeNote(`Audited ${RUN}`)

		const edited = await admin.patch(`${notesPath()}/${noteId}`, {
			data: { message: `Audited, rewritten ${RUN}` },
		})
		expect(edited.ok(), `edit failed: ${await edited.text()}`).toBeTruthy()

		const trail = await admin.get(
			`${API}/objects/${registerId}/${schemaId}/${objectUuid}/audit-trails`,
		)
		expect(trail.ok(), `audit read failed: ${await trail.text()}`).toBeTruthy()

		const trailBody = await trail.json()
		const entries = trailBody.results ?? trailBody.items ?? []
		const entry = entries.find(
			(row: { action?: string; changed?: Record<string, unknown> }) =>
				row.action === 'note.edited'
				&& String(row.changed?.noteId) === noteId,
		)
		expect(entry, `no note.edited entry naming note ${noteId}`).toBeTruthy()
		expect(entry.changed.versionCount).toBe(1)

		// The text lives in the versions, not in the trail. This is the half of
		// ADR-003 a passing "the entry exists" assertion would never check.
		expect(
			JSON.stringify(entry.changed),
			'the audit entry must not carry the note text',
		).not.toContain('Audited')
	})
})
