import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OBJECT DATES AS A CALENDAR FEED, end to end, over the URL a calendar client
 * actually subscribes to.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/object-dates-as-a-calendar-feed/specs/` is archived
 * into `openspec/specs/`:
 *
 * @e2e calendar-provider::a-caseworker-subscribes-and-sees-only-their-own-work
 * @e2e calendar-provider::a-moved-deadline-is-correct-at-the-next-refresh
 * @e2e calendar-provider::a-deadline-publishes-as-an-all-day-event-with-a-warning
 *
 * WHAT THIS FILE PROVES THAT THE UNIT SUITE CANNOT.
 *
 * The unit tests know the generator produces the right lines. What they cannot
 * see is whether a calendar client can REACH them: whether the public route is
 * registered, whether it answers without a session, whether it answers
 * `text/calendar` rather than the SPA shell, and whether a token minted through
 * the API addresses a feed at all. A feed that is generated perfectly and
 * served at no URL looks, from PHPUnit, exactly like one that works.
 *
 * So every read below goes through an UNAUTHENTICATED request context, which
 * is what a phone or Outlook sends, and asserts the content type before it
 * asserts anything about the body. A 200 that is HTML is a 404 wearing a hat.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects and
 * removes all three, plus every token it mints. It needs no `occ` and no
 * docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same fixed uids `object-sharing.spec.ts` uses, provisioned by the
 * workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh). Fixed names are
 * safe because the config pins `workers: 1` and `fullyParallel: false`.
 */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

const API = '/index.php/apps/openregister/api'

/*
 * Two ordinary working Tuesdays, so the working calendar leaves them where they
 * are and the assertion is about the feed rather than about Dutch holidays. The
 * engine's own roll is covered by DeadlineDateResolverTest with a calendar
 * fixture, which is where a closure day belongs.
 */
const FIRST_DEADLINE = '2026-10-20'
const MOVED_DEADLINE = '2026-11-17'
const ALARM_DAYS = 7

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

/** A calendar client: no session, no cookie, no CSRF token. */
async function anonymousContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({ baseURL: BASE })
}

/**
 * Assert a seeded account is actually usable before any test leans on it, so a
 * seed that silently did not run says so here rather than surfacing later as a
 * confusing authorization error inside a feed assertion.
 */
async function assertSeededUser(
	ctx: APIRequestContext,
	uid: string,
): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}). Did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('object dates as a calendar feed', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let anon: APIRequestContext
	let registerId: string
	let schemaId: string
	let sharedUuid: string
	let privateUuid: string
	const mintedTokenIds: string[] = []

	/**
	 * Read a feed as a calendar client would, and refuse anything that is not
	 * one. A Nextcloud SPA shell answers 200 with `text/html`, so asserting the
	 * status alone would pass on an app that ships no feed at all.
	 */
	async function readFeed(url: string): Promise<string> {
		const res = await anon.get(url)
		expect(res.status(), `the feed did not answer: ${url}`).toBe(200)
		expect(
			res.headers()['content-type'] ?? '',
			'a feed that is not text/calendar is the SPA shell wearing a hat',
		).toContain('text/calendar')

		return res.text()
	}

	/** Mint a feed over the schema as one user, and remember it for teardown. */
	async function mintSchemaFeed(
		ctx: APIRequestContext,
		label: string,
	): Promise<string> {
		const res = await ctx.post(`${API}/calendar-feeds`, {
			data: { scopeType: 'schema', scopeId: schemaId, label },
		})
		expect(res.ok(), `mint failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		expect(body.url, 'no subscribable url came back from the mint').toBeTruthy()
		mintedTokenIds.push(String(body.id))

		return String(body.url)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)
		anon = await anonymousContext()

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e calendar register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e calendar schema ${RUN}`,
				description: 'e2e',
				properties: {
					title: { type: 'string', title: 'Title', maxLength: 255 },
					beslistermijn: { type: 'string', format: 'date', title: 'Beslistermijn' },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				// The declaration this change adds. `dates` says WHICH dates
				// publish and what kind each one is; without it the schema
				// publishes nothing, which is the regression the unit suite
				// guards.
				configuration: {
					calendarProvider: {
						enabled: true,
						dtstart: 'beslistermijn',
						titleTemplate: '{{title}}',
						dates: {
							beslistermijn: {
								kind: 'deadline',
								alarmOffsetDays: ALARM_DAYS,
								summaryTemplate: 'Beslistermijn {{title}}',
							},
						},
					},
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const shared = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { title: `Bezwaar gedeeld ${RUN}`, beslistermijn: FIRST_DEADLINE },
		})
		expect(shared.ok(), `object create failed: ${await shared.text()}`).toBeTruthy()
		const sharedBody = await shared.json()
		sharedUuid = String(sharedBody['@self']?.id ?? sharedBody.id ?? sharedBody.uuid)
		expect(sharedUuid, 'no uuid came back from the object create').toBeTruthy()

		const priv = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { title: `Bezwaar prive ${RUN}`, beslistermijn: FIRST_DEADLINE },
		})
		expect(priv.ok(), `object create failed: ${await priv.text()}`).toBeTruthy()
		const privBody = await priv.json()
		privateUuid = String(privBody['@self']?.id ?? privBody.id ?? privBody.uuid)
		expect(privateUuid, 'no uuid came back from the object create').toBeTruthy()
	})

	test.afterAll(async () => {
		for (const id of mintedTokenIds) {
			await owner.delete(`${API}/calendar-feeds/${id}`)
			await other.delete(`${API}/calendar-feeds/${id}`)
		}

		for (const uuid of [sharedUuid, privateUuid]) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
				await admin.delete(`${API}/deleted/${uuid}`)
			}
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a deadline publishes as an all-day event with a warning', async () => {
		const url = await mintSchemaFeed(owner, `owner feed ${RUN}`)
		const body = await readFeed(url)

		expect(body, 'the body is not an iCalendar object').toContain('BEGIN:VCALENDAR')
		expect(body).toContain('BEGIN:VEVENT')

		// All-day, on the declared date, with an exclusive end on the next day.
		expect(body).toContain('DTSTART;VALUE=DATE:20261020')
		expect(body).toContain('DTEND;VALUE=DATE:20261021')

		// The warning the schema declares, seven days earlier.
		expect(body).toContain('BEGIN:VALARM')
		expect(body).toContain(`TRIGGER;RELATED=START:-P${ALARM_DAYS}D`)

		// And the summary the schema's template asks for.
		expect(body).toContain(`SUMMARY:Beslistermijn Bezwaar gedeeld ${RUN}`)
	})

	test('a moved deadline is correct at the next refresh', async () => {
		const url = await mintSchemaFeed(owner, `owner moved feed ${RUN}`)

		const before = await readFeed(url)
		expect(before).toContain('DTSTART;VALUE=DATE:20261020')

		const uidLine = before
			.split('\r\n')
			.find((line) => line.startsWith('UID:') && line.includes(sharedUuid))
		expect(uidLine, 'no UID came back for the object under test').toBeTruthy()

		const moved = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${sharedUuid}`,
			{
				data: { title: `Bezwaar gedeeld ${RUN}`, beslistermijn: MOVED_DEADLINE },
			},
		)
		expect(moved.ok(), `moving the deadline failed: ${await moved.text()}`).toBeTruthy()

		const after = await readFeed(url)

		expect(after, 'the moved deadline is not on the new date').toContain(
			'DTSTART;VALUE=DATE:20261117',
		)
		expect(
			after,
			'an event was left behind on the old date, which is what a written event does',
		).not.toContain('DTSTART;VALUE=DATE:20261020')

		// Same UID, so a subscribed client MOVES the event rather than
		// collecting a second copy of it.
		expect(after).toContain(uidLine as string)
	})

	test('a caseworker subscribes and sees only their own work', async () => {
		// The owner's own object goes private. Nothing about the feed changes;
		// the ordinary object rules do the work.
		const put = await owner.put(
			`${API}/objects/${registerId}/${schemaId}/${privateUuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(put.ok(), `owner could not set the scope: ${await put.text()}`).toBeTruthy()

		const ownerUrl = await mintSchemaFeed(owner, `owner private feed ${RUN}`)
		const otherUrl = await mintSchemaFeed(other, `other feed ${RUN}`)

		const ownerBody = await readFeed(ownerUrl)
		const otherBody = await readFeed(otherUrl)

		expect(
			ownerBody,
			'an owner must never lose their own work from their own feed',
		).toContain(privateUuid)

		expect(
			otherBody,
			'a feed listed an object its holder may not read, which is a leak',
		).not.toContain(privateUuid)
	})

	test('a revoked token stops answering', async () => {
		const url = await mintSchemaFeed(owner, `owner revocable feed ${RUN}`)
		await readFeed(url)

		const id = mintedTokenIds[mintedTokenIds.length - 1]
		const revoked = await owner.delete(`${API}/calendar-feeds/${id}`)
		expect(revoked.ok(), `revoke failed: ${await revoked.text()}`).toBeTruthy()

		const after = await anon.get(url)
		expect(
			after.status(),
			'a revoked token must answer nothing at all, never a smaller calendar',
		).toBe(404)
	})

	test('a token nobody minted answers the same 404', async () => {
		const res = await anon.get(
			`${API}/public/calendar-feeds/never-minted-${RUN}.ics`,
		)
		expect(
			res.status(),
			'an unknown token must be indistinguishable from a revoked one',
		).toBe(404)
	})
})
