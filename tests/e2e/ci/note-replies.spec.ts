import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A NOTE CAN ANSWER ANOTHER NOTE ON THE SAME RECORD.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e object-interactions::a-colleague-answers-a-question-on-a-case
 * @e2e object-interactions::a-reply-cannot-point-at-another-records-note
 * @e2e object-interactions::replies-outlive-a-deleted-question
 *
 * `POST .../notes` takes an optional `parentId`, and every note the list
 * returns carries `parentId` (null for a top-level note), `replyCount` and
 * `parentDeleted`. The unit tests drive NoteService and NotesController with
 * doubles; this spec writes the question and the reply as two different
 * people over HTTP and reads both back from the list, which is the only place
 * a reader ever meets the thread.
 *
 * HERMETIC. It creates its own register, schema and two objects, and removes
 * them. The second account is the one the workflow's seed command provisions
 * (tests/e2e/ci/seed.sh), shared with the sibling notes specs.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded non-admin account: the case handler who answers. */
const HANDLER = 'e2e-other'
const HANDLER_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** The refusal a reply to another record's note gets. Mirrors NoteService. */
const NOT_ON_THIS_RECORD = 'The note you reply to is not on this record.'

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

test.describe.configure({ mode: 'serial' })

test.describe('note replies over HTTP', () => {
	let admin: APIRequestContext
	let handler: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let caseUuid = ''
	let otherCaseUuid = ''
	let questionId = ''
	let replyId = ''

	/** The notes leaf of one fixture object. */
	const notesOf = (uuid: string): string =>
		`${API}/objects/${registerId}/${schemaId}/${uuid}/notes`

	/** The notes of one object as a reader gets them. */
	async function listed(
		ctx: APIRequestContext,
		uuid: string,
	): Promise<Array<Record<string, unknown>>> {
		const res = await ctx.get(notesOf(uuid))
		expect(res.ok(), `note list failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()).results as Array<Record<string, unknown>>
	}

	/** One note off a list, failing when it is not there. */
	function find(
		rows: Array<Record<string, unknown>>,
		noteId: string,
	): Record<string, unknown> {
		const row = rows.find((entry) => String(entry.id) === noteId)
		expect(row, `note ${noteId} is not in the list`).toBeTruthy()

		return row as Record<string, unknown>
	}

	async function createCase(key: string): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { zaaknummer: key },
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		handler = await contextFor(HANDLER, HANDLER_PASS)

		const seeded = await handler.get(`${API}/registers`)
		expect(
			seeded.status(),
			`seeded account '${HANDLER}' cannot authenticate — did the seed command run?`,
		).toBeLessThan(400)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e note replies register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e note replies schema ${RUN}`,
				description: 'e2e',
				properties: {
					zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 64 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		caseUuid = await createCase('Z-2026-014')
		otherCaseUuid = await createCase('Z-2026-015')
	})

	test.afterAll(async () => {
		for (const uuid of [caseUuid, otherCaseUuid]) {
			if (uuid !== '') {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
				await admin.delete(`${API}/deleted/${uuid}?force=true`)
			}
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await handler?.dispose()
	})

	test('a colleague answers a question and both read back as a thread', async () => {
		const question = await admin.post(notesOf(caseUuid), {
			data: { message: 'Is the permit attached?' },
		})
		expect(question.ok(), `question create failed: ${await question.text()}`).toBeTruthy()
		const asked = await question.json()
		questionId = String(asked.id)
		expect(asked.parentId, 'a note without parentId is top-level').toBeNull()

		const reply = await handler.post(notesOf(caseUuid), {
			data: { message: 'Yes, see the files tab', parentId: questionId },
		})
		expect(reply.ok(), `reply create failed: ${await reply.text()}`).toBeTruthy()
		const answered = await reply.json()
		replyId = String(answered.id)
		expect(String(answered.parentId), 'the answer carries the parent it replies to').toBe(questionId)

		const rows = await listed(handler, caseUuid)
		const q = find(rows, questionId)
		expect(q.parentId, 'the question stays top-level').toBeNull()
		expect(q.replyCount, 'the question counts its reply').toBe(1)

		const r = find(rows, replyId)
		expect(String(r.parentId), 'the reply names the question').toBe(questionId)
		expect(r.replyCount).toBe(0)
		expect(r.parentDeleted, 'the question is still there').toBe(false)
	})

	test("a reply cannot point at another record's note", async () => {
		const elsewhere = await admin.post(notesOf(otherCaseUuid), {
			data: { message: 'A note on the other case' },
		})
		expect(elsewhere.ok(), `note create failed: ${await elsewhere.text()}`).toBeTruthy()
		const elsewhereId = String((await elsewhere.json()).id)

		const refused = await handler.post(notesOf(caseUuid), {
			data: { message: 'Pointing across records', parentId: elsewhereId },
		})
		expect(refused.status(), await refused.text()).toBe(422)
		expect(JSON.stringify(await refused.json())).toContain(NOT_ON_THIS_RECORD)

		// Control: the refused reply wrote nothing on this case.
		const messages = (await listed(admin, caseUuid)).map((row) => row.message)
		expect(messages).not.toContain('Pointing across records')
	})

	test('the reply outlives a deleted question', async () => {
		const deleted = await admin.delete(`${notesOf(caseUuid)}/${questionId}`)
		expect(deleted.ok(), `question delete failed: ${await deleted.text()}`).toBeTruthy()

		const rows = await listed(handler, caseUuid)
		expect(rows.map((row) => String(row.id))).not.toContain(questionId)

		const r = find(rows, replyId)
		expect(r.parentDeleted, 'the reply says its question is gone').toBe(true)
	})
})
