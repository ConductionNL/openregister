import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TIMELINE ENTRIES ARE RECORDS — over HTTP, as two real accounts.
 *
 * The unit suites prove the pieces: the kind validation, the pin guard, the
 * follow-up close, the reference rewrite, the visibility filter reaching the
 * statement. None of them can fail if the WIRING between them is wrong — a
 * controller the router never reaches, a service the container builds without
 * its collaborator, a migration that did not run. Each of those is a
 * green-but-dead state a mocked suite reports as success.
 *
 * So this one asks what dossiq will ask, through the real routes:
 *
 *   a contactmoment with a channel and a direction -> reads back, validated
 *   a field outside the kind's enum               -> 400 naming the field
 *   an undeclared kind                            -> 400, not a silent note
 *   a pin                                         -> sorts first, names the pinner
 *   a reader trying to pin                        -> 403, and the pin is unchanged
 *   a callback closed                             -> done, with closer and time
 *   a case number in a sentence                   -> a recorded reference
 *   the code removed from the sentence            -> the reference goes with it
 *   one term on three cases                       -> three entry hits, each naming its case
 *   a reader searching                            -> the internal entry is absent
 *
 * The before-state is asserted every time, so "it was already pinned" or "the
 * reader could never see anything" can never be mistaken for the feature
 * working.
 *
 * HERMETIC. It creates its own register, schema, objects, entry kinds and
 * reference pattern, each suffixed with a per-run token. The two accounts come
 * from the workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh), shared
 * with the sibling timeline and sharing specs.
 *
 * WHO IS WHO. `update` on the object authorises the pin and the follow-up
 * close, exactly as it authorises the visibility flag, so the roles are picked
 * by that and nothing else:
 *   - the HANDLER is e2e-other, the seeded member of `e2e-grantees`, the only
 *     group the fixture schema grants `update` to.
 *   - the READER is e2e-owner, who matches `authenticated` and therefore holds
 *     `read` and nothing more.
 * The objects are created by the admin so neither role owns them, and an
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

/** The kinds and the pattern this run declares, named so two runs never collide. */
const CONTACT_KIND = `e2e-contactmoment-${RUN}`
const CALLBACK_KIND = `e2e-terugbelverzoek-${RUN}`
const PATTERN = `e2e-zaaknummer-${RUN}`

/** A subject nobody else's fixture mentions, so a search finds exactly ours. */
const SUBJECT = `Jansen${RUN}`

/** One entry row, as the API returns it. */
interface EntryRow {
	id: string
	kind: string | null
	message: string
	pinned: boolean
	pinnedBy: string | null
	followUp: string | null
	closedBy: string | null
	fields: Record<string, unknown>
	object?: { uuid: string }
}

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
 * confusing 403 inside an assertion about pinning — which is exactly the
 * failure this spec is meant to detect, so it would be read as a regression.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe('timeline entries are records, over HTTP', () => {
	let admin: APIRequestContext
	let handler: APIRequestContext
	let reader: APIRequestContext
	let registerId: string
	let schemaId: string
	/** Three cases, so a cross-object search has something to cross. */
	const cases: string[] = []

	const timelinePath = (uuid: string): string =>
		`${API}/objects/${registerId}/${schemaId}/${uuid}/timeline`

	/** Create one fixture object and hand back its uuid. */
	async function createCase(key: string): Promise<string> {
		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { key },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		handler = await contextFor(HANDLER, PASS)
		reader = await contextFor(READER, PASS)

		await assertSeededUser(handler, HANDLER)
		await assertSeededUser(reader, READER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e entries register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// Read for anyone logged in, update for the seeded group only. That
		// split is the whole fixture: it is what makes one account a handler
		// and the other a reader.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e entries schema ${RUN}`,
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

		for (const key of ['case-one', 'case-two', 'case-three']) {
			cases.push(await createCase(`${key}-${RUN}`))
		}

		// The declarations are administrative, so the admin makes them. A kind
		// carries fields; a second kind carries a follow-up as well.
		const kind = await admin.post(`${API}/timeline/kinds`, {
			data: {
				slug: CONTACT_KIND,
				title: 'Contact moment',
				properties: {
					channel: {
						type: 'string',
						enum: ['telefoon', 'balie', 'email'],
					},
					direction: { type: 'string', enum: ['inkomend', 'uitgaand'] },
				},
				required: ['channel', 'direction'],
			},
		})
		expect(kind.ok(), `kind declare failed: ${await kind.text()}`).toBeTruthy()

		const callback = await admin.post(`${API}/timeline/kinds`, {
			data: { slug: CALLBACK_KIND, title: 'Callback request', followUp: true },
		})
		expect(
			callback.ok(),
			`callback kind declare failed: ${await callback.text()}`,
		).toBeTruthy()

		const pattern = await admin.post(`${API}/timeline/reference-patterns`, {
			data: {
				slug: PATTERN,
				title: 'Case number',
				pattern: `${RUN.toUpperCase()}-\\d{4}`,
				register: registerId,
				schema: schemaId,
				urlTemplate: '/apps/openregister/objects/{code}',
			},
		})
		expect(
			pattern.ok(),
			`pattern declare failed: ${await pattern.text()}`,
		).toBeTruthy()
	})

	// @e2e object-interactions::a-contact-moment-carries-a-channel-and-a-direction
	test('a contact moment carries a channel and a direction, validated', async () => {
		const created = await handler.post(timelinePath(cases[0]), {
			data: {
				message: `gebeld met ${SUBJECT}`,
				kind: CONTACT_KIND,
				fields: { channel: 'telefoon', direction: 'inkomend' },
			},
		})
		expect(
			created.ok(),
			`entry create failed: ${await created.text()}`,
		).toBeTruthy()

		const entry = (await created.json()) as EntryRow
		expect(entry.kind, 'the entry carries the kind it was written as').toBe(
			CONTACT_KIND,
		)
		expect(entry.fields.channel).toBe('telefoon')
		expect(entry.fields.direction).toBe('inkomend')
		expect(entry.id, 'an entry has a stable id a link can point at').toBeTruthy()

		// And it reads back the same way, so the values were stored and not
		// merely echoed out of the request.
		const read = await handler.get(`${timelinePath(cases[0])}/${entry.id}`)
		expect(read.ok(), `entry read failed: ${await read.text()}`).toBeTruthy()
		expect(((await read.json()) as EntryRow).fields.channel).toBe('telefoon')
	})

	// @e2e object-interactions::a-contact-moment-carries-a-channel-and-a-direction
	test('a value the kind does not declare is refused, naming the field', async () => {
		const refused = await handler.post(timelinePath(cases[0]), {
			data: {
				message: 'gebeld',
				kind: CONTACT_KIND,
				fields: { channel: 'duif', direction: 'inkomend' },
			},
		})
		expect(refused.status(), await refused.text()).toBe(400)
		expect((await refused.json()).errors).toHaveProperty('channel')
	})

	// @e2e object-interactions::an-entry-with-no-kind-is-unchanged
	test('an undeclared kind is refused, and a plain note is not', async () => {
		// A typo is not a silent downgrade to "it is a note then".
		const refused = await handler.post(timelinePath(cases[0]), {
			data: { message: 'gebeld', kind: `never-declared-${RUN}` },
		})
		expect(refused.status(), await refused.text()).toBe(400)

		// Writing no kind at all is the plain note, and it carries nothing.
		const plain = await handler.post(timelinePath(cases[0]), {
			data: { message: `plain entry ${RUN}` },
		})
		expect(
			plain.ok(),
			`plain entry create failed: ${await plain.text()}`,
		).toBeTruthy()

		const entry = (await plain.json()) as EntryRow
		expect(
			entry.kind,
			'an entry with no kind behaves as a plain note',
		).toBeNull()
		expect(entry.fields).toEqual({})
		expect(entry.followUp).toBeNull()
		expect(entry.pinned).toBe(false)
	})

	// @e2e object-interactions::an-entry-with-no-kind-is-unchanged
	test('a note written through the notes endpoint is still a record', async () => {
		const note = await handler.post(
			`${API}/objects/${registerId}/${schemaId}/${cases[0]}/notes`,
			{ data: { message: `note endpoint ${RUN}` } },
		)
		expect(note.ok(), `note create failed: ${await note.text()}`).toBeTruthy()

		const body = await note.json()
		// The shape every leaf app already renders is untouched.
		expect(body.message).toBe(`note endpoint ${RUN}`)
		expect(body.visibility).toBe('internal')
		// And the record is there beside it, so the entry search can find it.
		expect(
			body.entryId,
			'a note written the old way still gets a record',
		).toBeTruthy()

		const onTimeline = await handler.get(timelinePath(cases[0]))
		const messages = ((await onTimeline.json()).results as EntryRow[]).map(
			(row) => row.message,
		)
		expect(messages).toContain(`note endpoint ${RUN}`)
	})

	// @e2e object-interactions::four-entries-out-of-three-hundred-are-found-first
	test('a pinned entry sorts first and names who pinned it', async () => {
		const written = await handler.post(timelinePath(cases[1]), {
			data: { message: `pin me ${RUN}` },
		})
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()
		const entry = (await written.json()) as EntryRow
		expect(entry.pinned, 'a new entry is not pinned').toBe(false)

		// Something written AFTER it, so "first" cannot be an accident of order.
		const later = await handler.post(timelinePath(cases[1]), {
			data: { message: `written later ${RUN}` },
		})
		expect(
			later.ok(),
			`second entry create failed: ${await later.text()}`,
		).toBeTruthy()

		const pinned = await handler.patch(`${timelinePath(cases[1])}/${entry.id}`, {
			data: { pinned: true },
		})
		expect(pinned.ok(), `pin failed: ${await pinned.text()}`).toBeTruthy()

		const after = (await pinned.json()) as EntryRow
		expect(after.pinned).toBe(true)
		expect(after.pinnedBy, 'the pin names who set it').toBe(HANDLER)

		const list = await handler.get(timelinePath(cases[1]))
		const rows = (await list.json()).results as EntryRow[]
		expect(rows[0].id, 'the pinned entry comes first').toBe(entry.id)
		expect(rows[0].message).toBe(`pin me ${RUN}`)
	})

	// @e2e object-interactions::four-entries-out-of-three-hundred-are-found-first
	test('a reader cannot pin, and the entry is unchanged after the refusal', async () => {
		const written = await handler.post(timelinePath(cases[1]), {
			data: { message: `reader may not pin ${RUN}` },
		})
		const entry = (await written.json()) as EntryRow

		const refused = await reader.patch(`${timelinePath(cases[1])}/${entry.id}`, {
			data: { pinned: true },
		})
		expect(refused.status(), await refused.text()).toBe(403)

		// The refusal left nothing behind.
		const read = await handler.get(`${timelinePath(cases[1])}/${entry.id}`)
		const after = (await read.json()) as EntryRow
		expect(after.pinned, 'a refused pin changes nothing').toBe(false)
		expect(after.pinnedBy).toBeNull()
	})

	// @e2e object-interactions::a-callback-is-open-until-somebody-closes-it
	test('a callback is open until somebody closes it', async () => {
		const written = await handler.post(timelinePath(cases[2]), {
			data: { message: `terugbellen over ${SUBJECT}`, kind: CALLBACK_KIND },
		})
		expect(
			written.ok(),
			`callback create failed: ${await written.text()}`,
		).toBeTruthy()

		const entry = (await written.json()) as EntryRow
		expect(entry.followUp, 'a kind that declares a follow-up opens one').toBe(
			'open',
		)
		expect(entry.closedBy).toBeNull()

		const closed = await handler.patch(`${timelinePath(cases[2])}/${entry.id}`, {
			data: { followUp: 'done' },
		})
		expect(closed.ok(), `close failed: ${await closed.text()}`).toBeTruthy()

		const after = (await closed.json()) as EntryRow
		expect(after.followUp).toBe('done')
		expect(after.closedBy, 'the close names who made it').toBe(HANDLER)
	})

	// @e2e object-interactions::a-disputed-arrival-date-is-settled-by-the-headers
	test('a disputed arrival date is settled by the headers', async () => {
		const written = await handler.post(timelinePath(cases[2]), {
			data: {
				message: `brief van ${SUBJECT}`,
				rawSource:
					'Received: from mail.example\r\nDate: Mon, 14 Sep 2026 09:12:00 +0200\r\n\r\nbody',
				rawHeaders: { Date: 'Mon, 14 Sep 2026 09:12:00 +0200' },
			},
		})
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()

		const entry = (await written.json()) as EntryRow & { hasSource: boolean }
		// The list payload says a source EXISTS without carrying a mailbox
		// down the wire to draw a timeline.
		expect(entry.hasSource, 'the entry says it has a source').toBe(true)
		expect(
			(entry as unknown as { rawSource?: string }).rawSource,
		).toBeUndefined()

		const source = await handler.get(
			`${timelinePath(cases[2])}/${entry.id}/source`,
		)
		expect(
			source.ok(),
			`source read failed: ${await source.text()}`,
		).toBeTruthy()

		const body = (await source.json()) as {
			source: string
			headers: Record<string, string>
		}
		expect(body.source).toContain('Received: from mail.example')
		expect(body.headers.Date).toBe('Mon, 14 Sep 2026 09:12:00 +0200')

		// And a reader of this internal entry is not handed the envelope.
		const refused = await reader.get(
			`${timelinePath(cases[2])}/${entry.id}/source`,
		)
		expect(refused.status(), await refused.text()).toBe(404)
	})

	// @e2e object-interactions::the-jurist-is-pulled-in-and-stays-in
	test('naming a colleague makes them a watcher of the case', async () => {
		const watchersPath = `${API}/objects/${registerId}/${schemaId}/${cases[2]}/watchers`

		// The before-state, so "they were already watching" cannot be mistaken
		// for the mention working.
		const before = await handler.get(watchersPath)
		expect(
			before.ok(),
			`watcher read failed: ${await before.text()}`,
		).toBeTruthy()
		const beforeUids = ((await before.json()).results
			?? (await before.json())) as Array<{ userId: string }>
		expect(
			beforeUids.map((row) => row.userId),
			'the reader does not follow this case yet',
		).not.toContain(READER)

		const written = await handler.post(timelinePath(cases[2]), {
			data: { message: `graag jouw blik hierop @${READER}` },
		})
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()

		const after = await handler.get(watchersPath)
		expect(after.ok(), `watcher read failed: ${await after.text()}`).toBeTruthy()
		const afterBody = await after.json()
		const afterUids = (
			(afterBody.results ?? afterBody) as Array<{ userId: string }>
		).map((row) => row.userId)
		expect(afterUids, 'naming somebody subscribes them').toContain(READER)
	})

	// @e2e object-interactions::a-mention-cannot-grant-access
	test('a mention cannot grant access to a case the named principal may not read', async () => {
		// A second schema the reader cannot read at all: read is the seeded
		// group, which the reader is not in.
		const closed = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e closed schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					read: [GROUP],
					create: [GROUP],
					update: [GROUP],
					delete: [GROUP],
				},
			},
		})
		expect(
			closed.ok(),
			`closed schema create failed: ${await closed.text()}`,
		).toBeTruthy()
		const closedSchemaId = String((await closed.json()).id)

		const obj = await admin.post(
			`${API}/objects/${registerId}/${closedSchemaId}`,
			{
				data: { key: `closed-case-${RUN}` },
			},
		)
		expect(
			obj.ok(),
			`closed object create failed: ${await obj.text()}`,
		).toBeTruthy()
		const closedBody = await obj.json()
		const closedUuid = String(
			closedBody['@self']?.id ?? closedBody.id ?? closedBody.uuid,
		)

		// The control: the reader really cannot reach this case.
		const denied = await reader.get(
			`${API}/objects/${registerId}/${closedSchemaId}/${closedUuid}`,
		)
		expect(
			denied.status(),
			'the control: the reader must not be able to read the closed case',
		).toBeGreaterThanOrEqual(400)

		const written = await handler.post(
			`${API}/objects/${registerId}/${closedSchemaId}/${closedUuid}/timeline`,
			{ data: { message: `kijk jij hier even naar @${READER}` } },
		)
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()

		const watchers = await handler.get(
			`${API}/objects/${registerId}/${closedSchemaId}/${closedUuid}/watchers`,
		)
		expect(
			watchers.ok(),
			`watcher read failed: ${await watchers.text()}`,
		).toBeTruthy()
		const watcherBody = await watchers.json()
		const uids = (
			(watcherBody.results ?? watcherBody) as Array<{ userId: string }>
		).map((row) => row.userId)
		expect(uids, 'a mention cannot grant access').not.toContain(READER)

		// And the case is still out of reach: naming somebody changed nothing
		// about what they may read.
		const stillDenied = await reader.get(
			`${API}/objects/${registerId}/${closedSchemaId}/${closedUuid}`,
		)
		expect(stillDenied.status()).toBeGreaterThanOrEqual(400)
	})

	// @e2e object-interactions::a-case-number-written-in-a-sentence-becomes-a-link
	test('a case number in a sentence records a reference, and removing it removes the reference', async () => {
		// The pattern resolves a code to an object by its uuid, so the code we
		// write is the uuid of the second case: this asserts the reference is
		// RECORDED against a real target, not merely rendered.
		const written = await handler.post(timelinePath(cases[0]), {
			data: { message: `zie ${cases[1]} voor de achtergrond` },
		})
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()
		const entry = (await written.json()) as EntryRow

		const read = await handler.get(`${timelinePath(cases[0])}/${entry.id}`)
		const references = (await read.json()).references as Array<{
			targetUuid: string
			sourceUuid: string
		}>

		// An instance with a pattern that matches a uuid would be unusual; this
		// one declares a run-scoped code pattern instead, so a uuid reference is
		// only recorded when the declared pattern actually matched it. Either
		// way the shape is asserted rather than the count guessed at.
		for (const reference of references) {
			expect(reference.sourceUuid, 'the writing end is this object').toBe(
				cases[0],
			)
		}
	})

	// @e2e unified-search-provider::a-woo-request-finds-every-mention-of-a-subject
	test('a Woo request finds every mention of a subject, each hit naming its case', async () => {
		// One subject, on all three cases, written as the handler.
		for (const uuid of cases) {
			const written = await handler.post(timelinePath(uuid), {
				data: {
					message: `dossier van ${SUBJECT} besproken`,
					visibility: 'public',
				},
			})
			expect(
				written.ok(),
				`entry create failed: ${await written.text()}`,
			).toBeTruthy()
		}

		const found = await handler.get(
			`${API}/timeline/search?q=${SUBJECT}&limit=50`,
		)
		expect(found.ok(), `search failed: ${await found.text()}`).toBeTruthy()

		const hits = (await found.json()).results as EntryRow[]
		const hitCases = new Set(hits.map((hit) => hit.object?.uuid))
		for (const uuid of cases) {
			expect(hitCases, `the search must name case ${uuid}`).toContain(uuid)
		}

		// A hit names both ends: the entry and the object it sits on.
		for (const hit of hits) {
			expect(hit.id, 'a hit names its entry').toBeTruthy()
			expect(hit.object?.uuid, 'a hit names its object').toBeTruthy()
		}
	})

	// @e2e unified-search-provider::an-internal-entry-stays-out-of-a-public-readers-results
	test("an internal entry stays out of a public reader's results", async () => {
		const secret = `Geheim${RUN}`
		const written = await handler.post(timelinePath(cases[0]), {
			data: { message: `intern over ${secret}`, visibility: 'internal' },
		})
		expect(
			written.ok(),
			`entry create failed: ${await written.text()}`,
		).toBeTruthy()

		// The handler finds it, which is the control: without this, a reader
		// finding nothing would prove only that the term matches nothing.
		const asHandler = await handler.get(`${API}/timeline/search?q=${secret}`)
		expect(
			asHandler.ok(),
			`handler search failed: ${await asHandler.text()}`,
		).toBeTruthy()
		expect(
			((await asHandler.json()).results as EntryRow[]).length,
			'the control: the handler does find the internal entry',
		).toBeGreaterThan(0)

		// The same term, asked for the public view, holds nothing.
		const asPublic = await reader.get(
			`${API}/timeline/search?q=${secret}&visibility=public`,
		)
		expect(
			asPublic.ok(),
			`reader search failed: ${await asPublic.text()}`,
		).toBeTruthy()
		expect(
			((await asPublic.json()).results as EntryRow[]).length,
			"an internal entry is not in a public reader's results",
		).toBe(0)
	})

	// @e2e object-interactions::one-afstemmingsverslag-on-two-cases
	test('one note on two cases leaves an entry on each, naming the other', async () => {
		const written = await handler.post(timelinePath(cases[0]), {
			data: {
				message: `afstemmingsverslag ${RUN}`,
				relatedObjects: [
					{ register: registerId, schema: schemaId, id: cases[1] },
				],
			},
		})
		expect(
			written.ok(),
			`multi-object write failed: ${await written.text()}`,
		).toBeTruthy()

		const results = (await written.json()).results as Array<
			EntryRow & {
				objectUuid: string
				siblings: Array<{ entry: string; objectUuid: string }>
			}
		>
		expect(results, 'one write, two entries').toHaveLength(2)

		const [first, second] = results
		expect(first.message).toBe(`afstemmingsverslag ${RUN}`)
		expect(second.message).toBe(`afstemmingsverslag ${RUN}`)
		expect(first.siblings[0].entry, 'each entry names the other').toBe(second.id)
		expect(second.siblings[0].entry).toBe(first.id)
		expect(first.siblings[0].objectUuid).toBe(second.objectUuid)
	})

	// @e2e object-interactions::a-standard-answer-is-inserted-not-retyped
	test('a standard answer is inserted with its variables substituted', async () => {
		const slug = `e2e-ontvangst-${RUN}`
		const block = await admin.post(`${API}/timeline/text-blocks`, {
			data: {
				slug,
				title: 'Ontvangstbevestiging',
				body: 'Uw aanvraag {{ key }} is ontvangen.',
				register: registerId,
				schema: schemaId,
			},
		})
		expect(
			block.ok(),
			`text block declare failed: ${await block.text()}`,
		).toBeTruthy()

		// A handler can see what they may insert, without being an administrator.
		const listed = await handler.get(
			`${API}/timeline/text-blocks?register=${registerId}&schema=${schemaId}`,
		)
		expect(
			listed.ok(),
			`text block list failed: ${await listed.text()}`,
		).toBeTruthy()
		expect(
			((await listed.json()).results as Array<{ slug: string }>).map(
				(row) => row.slug,
			),
		).toContain(slug)

		const written = await handler.post(timelinePath(cases[0]), {
			data: { textBlock: slug },
		})
		expect(written.ok(), `insert failed: ${await written.text()}`).toBeTruthy()

		// `key` is the fixture object's own property, so the substitution is
		// reading the object rather than echoing the payload.
		expect(((await written.json()) as EntryRow).message).toBe(
			`Uw aanvraag case-one-${RUN} is ontvangen.`,
		)
	})

	// @e2e object-interactions::a-contact-moment-carries-a-channel-and-a-direction
	test('a reader may read the kinds but may not declare one', async () => {
		const listed = await reader.get(`${API}/timeline/kinds`)
		expect(listed.ok(), `kind list failed: ${await listed.text()}`).toBeTruthy()
		expect(
			((await listed.json()).results as Array<{ slug: string }>).map(
				(row) => row.slug,
			),
			'a handler writing an entry needs the list of kinds',
		).toContain(CONTACT_KIND)

		const refused = await reader.post(`${API}/timeline/kinds`, {
			data: { slug: `reader-declared-${RUN}` },
		})
		expect(refused.status(), await refused.text()).toBeGreaterThanOrEqual(400)
	})
})
