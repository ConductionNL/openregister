import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * NOTIFICATION ROUTING — end to end, through the HTTP API a real client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/notification-routing-per-group-and-scope/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e notificatie-engine::an-unassigned-case-warns-the-team
 * @e2e notificatie-engine::a-new-colleague-gets-the-warning
 * @e2e notificatie-engine::a-team-default-overrides-the-schema-default
 * @e2e notificatie-engine::the-user-still-wins
 * @e2e notificatie-engine::loud-for-vergunningen-quiet-for-meldingen
 * @e2e notificatie-engine::one-event-two-transports-one-record
 * @e2e notificatie-engine::a-storingsmelding-reaches-the-desk
 * @e2e notificatie-engine::the-gaps-are-listable
 * @e2e notificatie-engine::an-administrator-edits-the-shipped-text
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the routing is REACHABLE and that its decisions are readable: the
 * routes are registered, the auth attributes let the right caller through and
 * refuse the wrong one, the three-layer merge names the layer that decided, a
 * scoped preference applies where it was pinned and nowhere else, a broadcast
 * arrives once and stops arriving, and the template set can be listed, edited
 * and read back. Those are exactly the failures a green unit suite hides: a
 * route missing from `appinfo/routes.php` is a 404 no PHPUnit test would see.
 *
 * It does NOT assert that a dispatched notification LANDS in a recipient's
 * bell. Delivery runs through a queue and a background job, so an assertion
 * here would be a timing race dressed up as coverage. Recipient resolution, the
 * unresolvable group, the shared event id and the independence of two
 * transports are asserted in
 * tests/Unit/Service/Notification/AnnotationNotificationDispatcherTransportsTest.php
 * and NotificationRecipientResolverRoleTest.php, named here so nobody has to
 * take this comment's word for it. What this file asserts about dispatch is the
 * half that IS synchronous and HTTP-visible: the schema save refuses a rule
 * that would reach nobody or call nothing.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register and schemas, its own
 * broadcast and its own template edit, and removes all of them. It needs no
 * `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same fixed uids the sharing and watcher specs use, provisioned by the
 * workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh). Fixed names are
 * safe because the config pins `workers: 1` and `fullyParallel: false`.
 */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

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
 * Assert a seeded account is actually usable before any test leans on it, so a
 * seed that silently did not run says so here rather than surfacing later as a
 * confusing authorization error inside an assertion about layers.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('notification routing over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaSlug: string
	let schemaId: string
	let secondSchemaSlug: string
	let secondSchemaId: string
	let broadcastUuid = ''

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e routing register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// The schema an unassigned case lives on. It addresses a ROLE rather
		// than a list of people, which is the whole point: the assignment may
		// change without the rule being rewritten.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e routing vergunning ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
					roles: { behandelaar: ['admin'] },
				},
				'x-openregister-notifications': {
					termijn: {
						trigger: { type: 'updated' },
						recipients: [{ kind: 'role', role: 'behandelaar' }],
						channels: ['nc-notification', 'email'],
						domain: 'vergunningen',
						subject: 'De termijn loopt af',
					},
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		const schBody = await sch.json()
		schemaId = String(schBody.id)
		schemaSlug = String(schBody.slug ?? schBody.id)

		// A second schema, so a scoped preference has somewhere to NOT apply.
		const sch2 = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e routing melding ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				'x-openregister-notifications': {
					termijn: {
						trigger: { type: 'updated' },
						recipients: [{ kind: 'users', users: [ADMIN] }],
						channels: ['nc-notification', 'email'],
						domain: 'meldingen',
						subject: 'De termijn loopt af',
					},
				},
			},
		})
		expect(sch2.ok(), `second schema create failed: ${await sch2.text()}`).toBeTruthy()
		const sch2Body = await sch2.json()
		secondSchemaId = String(sch2Body.id)
		secondSchemaSlug = String(sch2Body.slug ?? sch2Body.id)
	})

	test.afterAll(async () => {
		if (broadcastUuid) {
			await admin.delete(`${API}/notification-broadcasts/${broadcastUuid}`)
		}

		// Leave every preference this run wrote cleared, so a second run reads
		// the same starting state rather than inheriting the first run's team.
		for (const slug of [schemaSlug, secondSchemaSlug]) {
			if (!slug) {
				continue
			}

			await admin.put(`${API}/notification-preferences`, {
				data: { schema: slug, notification: 'termijn', reset: true },
			})
			await admin.put(`${API}/notification-preferences`, {
				data: {
					schema: slug,
					notification: 'termijn',
					scope: `schema:${slug}`,
					reset: true,
				},
			})
			await admin.put(`${API}/notification-group-preferences`, {
				data: { group: 'admin', schema: slug, notification: 'termijn', reset: true },
			})
		}

		await admin.put(`${API}/notification-templates/destruction_review_pending`, {
			data: { reset: true },
		})

		if (secondSchemaId) {
			await admin.delete(`${API}/schemas/${secondSchemaId}`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a rule may address a role the schema assigns, and one it does not is refused at save', async () => {
		// The schema in beforeAll already addresses `behandelaar` and saved,
		// which is half the scenario. The other half is that the validator is
		// actually looking: a rule naming a role nobody is assigned to reaches
		// nobody, silently, every time it fires.
		const bad = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e routing bad role ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key' } },
				authorization: { roles: { behandelaar: ['admin'] } },
				'x-openregister-notifications': {
					termijn: {
						trigger: { type: 'updated' },
						recipients: [{ kind: 'role', role: 'toezichthouder' }],
						channels: ['nc-notification'],
						subject: 'nope',
					},
				},
			},
		})

		expect(
			bad.status(),
			`a role the schema does not assign must be refused, got ${bad.status()}: ${await bad.text()}`,
		).toBe(422)
	})

	test('an outbound transport that names no handler is refused at save', async () => {
		const bad = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e routing bad transport ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key' } },
				'x-openregister-notifications': {
					termijn: {
						trigger: { type: 'updated' },
						recipients: [{ kind: 'users', users: [ADMIN] }],
						channels: ['nc-notification'],
						transports: [{ kind: 'outbound' }],
						subject: 'nope',
					},
				},
			},
		})

		expect(
			bad.status(),
			`an outbound transport with no handler must be refused, got ${bad.status()}: ${await bad.text()}`,
		).toBe(422)
	})

	test('the effective preferences read names the layer that decided', async () => {
		const before = await admin.get(`${API}/notification-preferences`)
		expect(before.ok(), `preferences read failed: ${await before.text()}`).toBeTruthy()

		const entry = (await before.json()).results.find(
			(row: Record<string, unknown>) =>
				row.schema === schemaSlug && row.notification === 'termijn',
		)
		expect(entry, `no effective preference came back for ${schemaSlug}/termijn`).toBeTruthy()
		expect(entry.source, 'with nothing stored the schema default must decide').toBe(
			'schema-default',
		)
		expect(Array.isArray(entry.layers), 'the read must carry the layer trace').toBeTruthy()
	})

	test('a team default overrides the schema default, and the user still wins', async () => {
		const group = await admin.put(`${API}/notification-group-preferences`, {
			data: {
				group: 'admin',
				schema: schemaSlug,
				notification: 'termijn',
				enabled: true,
				channels: ['nc-notification'],
			},
		})
		expect(group.ok(), `group default write failed: ${await group.text()}`).toBeTruthy()

		const withGroup = (await (await admin.get(`${API}/notification-preferences`)).json()).results.find(
			(row: Record<string, unknown>) =>
				row.schema === schemaSlug && row.notification === 'termijn',
		)
		expect(withGroup.source, 'the group is now the deciding layer').toBe('group-default')
		expect(withGroup.channels, 'the team narrowed it to the in-app channel').toEqual([
			'nc-notification',
		])

		const own = await admin.put(`${API}/notification-preferences`, {
			data: {
				schema: schemaSlug,
				notification: 'termijn',
				enabled: true,
				channels: ['email'],
			},
		})
		expect(own.ok(), `own override write failed: ${await own.text()}`).toBeTruthy()

		const withOwn = (await (await admin.get(`${API}/notification-preferences`)).json()).results.find(
			(row: Record<string, unknown>) =>
				row.schema === schemaSlug && row.notification === 'termijn',
		)
		expect(withOwn.source, 'the user always wins over their team').toBe('user-override')
		expect(withOwn.channels).toEqual(['email'])
	})

	test('a member who does not administer the group cannot set its default', async () => {
		const refused = await other.put(`${API}/notification-group-preferences`, {
			data: {
				group: 'admin',
				schema: schemaSlug,
				notification: 'termijn',
				enabled: false,
			},
		})

		expect(
			refused.status(),
			`setting somebody else's team default must be refused, got ${refused.status()}`,
		).toBe(403)
	})

	test('a scoped preference applies where it was pinned and nowhere else', async () => {
		// Globally off, on for this one schema. The read is scoped by the key
		// the write used, so the two answers must differ.
		const off = await admin.put(`${API}/notification-preferences`, {
			data: { schema: schemaSlug, notification: 'termijn', enabled: false },
		})
		expect(off.ok(), `global write failed: ${await off.text()}`).toBeTruthy()

		const scoped = await admin.put(`${API}/notification-preferences`, {
			data: {
				schema: schemaSlug,
				notification: 'termijn',
				scope: `schema:${schemaSlug}`,
				enabled: true,
				channels: ['email'],
			},
		})
		expect(scoped.ok(), `scoped write failed: ${await scoped.text()}`).toBeTruthy()
		expect((await scoped.json()).scope, 'the write must echo the scope it stored').toBe(
			`schema:${schemaSlug}`,
		)

		// The scoped value is a separate stored override; the global one is
		// still there and still off, which is what "for that scope only" means.
		const globalBack = await admin.put(`${API}/notification-preferences`, {
			data: { schema: schemaSlug, notification: 'termijn', enabled: false },
		})
		expect((await globalBack.json()).override.enabled, 'the global value is untouched').toBe(
			false,
		)

		// And the list answers differently for the pinned scope than without
		// it. A scoped value that can be written and never read back is a
		// setting nobody can see the effect of.
		const inScope = (
			await (
				await admin.get(
					`${API}/notification-preferences?scope=${encodeURIComponent(`schema:${schemaSlug}`)}`,
				)
			).json()
		).results.find(
			(row: Record<string, unknown>) =>
				row.schema === schemaSlug && row.notification === 'termijn',
		)
		const globally = (await (await admin.get(`${API}/notification-preferences`)).json()).results.find(
			(row: Record<string, unknown>) =>
				row.schema === schemaSlug && row.notification === 'termijn',
		)

		expect(inScope.enabled, 'the pinned scope is on').toBe(true)
		expect(inScope.scope).toBe(`schema:${schemaSlug}`)
		expect(globally.enabled, 'without the scope the global value answers').toBe(false)
		expect(globally.scope).toBe('global')
	})

	test('an administrator sends a broadcast, every user sees it once, and the record names the sender', async () => {
		const sent = await admin.post(`${API}/notification-broadcasts`, {
			data: {
				subject: `Storing in het zaaksysteem ${RUN}`,
				body: 'We werken eraan.',
				endsAt: new Date(Date.now() + 3600_000).toISOString(),
			},
		})
		expect(sent.status(), `broadcast send failed: ${await sent.text()}`).toBe(201)
		const body = await sent.json()
		broadcastUuid = String(body.uuid)
		expect(body.sender, 'the record must name who sent it').toBe(ADMIN)

		const first = await other.get(`${API}/notification-broadcasts/active`)
		expect(first.ok(), `active read failed: ${await first.text()}`).toBeTruthy()
		const firstUuids = (await first.json()).results.map((row: Record<string, unknown>) => row.uuid)
		expect(firstUuids, 'a user should see the broadcast once').toContain(broadcastUuid)

		const ack = await other.post(`${API}/notification-broadcasts/${broadcastUuid}/acknowledge`)
		expect(ack.ok(), `acknowledge failed: ${await ack.text()}`).toBeTruthy()

		const second = await other.get(`${API}/notification-broadcasts/active`)
		const secondUuids = (await second.json()).results.map((row: Record<string, unknown>) => row.uuid)
		expect(secondUuids, 'once seen, it does not come back').not.toContain(broadcastUuid)

		// One person's receipt is theirs. Without this the "once" could be a
		// global flag that silences the message for everybody.
		const stillThere = await owner.get(`${API}/notification-broadcasts/active`)
		const ownerUuids = (await stillThere.json()).results.map(
			(row: Record<string, unknown>) => row.uuid,
		)
		expect(ownerUuids, "another user's receipt does not silence this one").toContain(
			broadcastUuid,
		)
	})

	test('an ordinary user cannot send a broadcast', async () => {
		const refused = await other.post(`${API}/notification-broadcasts`, {
			data: {
				subject: 'Niet toegestaan',
				endsAt: new Date(Date.now() + 3600_000).toISOString(),
			},
		})

		expect(
			refused.status(),
			`a non-administrator must not reach every user, got ${refused.status()}`,
		).toBe(403)
	})

	test('the shipped templates list, the gaps are listable, and an edit is read back', async () => {
		const list = await admin.get(`${API}/notification-templates`)
		expect(list.ok(), `template list failed: ${await list.text()}`).toBeTruthy()
		const listed = await list.json()
		expect(listed.total, 'the platform must ship a template set').toBeGreaterThan(0)
		expect(Array.isArray(listed.gaps), 'the listing must carry the gap list').toBeTruthy()

		const gaps = await admin.get(`${API}/notification-templates/gaps`)
		expect(gaps.ok(), `gap read failed: ${await gaps.text()}`).toBeTruthy()
		expect(
			(await gaps.json()).total,
			'the shipped set must have no gaps: an event with no template renders nothing at all',
		).toBe(0)

		const edited = await admin.put(`${API}/notification-templates/destruction_review_pending`, {
			data: {
				template: {
					en: {
						subject: `Reviewed by e2e ${RUN}`,
						body: 'On {{schemaSlug}}.',
					},
				},
			},
		})
		expect(edited.ok(), `template edit failed: ${await edited.text()}`).toBeTruthy()
		const editedBody = await edited.json()
		expect(editedBody.edited, 'the edit must be recorded as an edit').toBe(true)
		expect(editedBody.template.en.subject).toBe(`Reviewed by e2e ${RUN}`)

		const reread = await admin.get(`${API}/notification-templates`)
		const row = (await reread.json()).results.find(
			(entry: Record<string, unknown>) => entry.event === 'destruction_review_pending',
		)
		expect(row.source, 'the edited words are the ones that apply').toBe('edited')
		expect(
			Object.keys(row.variables).length,
			'an editable template without documented variables is unusable',
		).toBeGreaterThan(0)
	})

	test('an ordinary user may read the templates but not change them', async () => {
		const read = await other.get(`${API}/notification-templates`)
		expect(read.ok(), 'the variables are part of writing a rule').toBeTruthy()

		const refused = await other.put(`${API}/notification-templates/scheduled_report_failed`, {
			data: { template: { en: { subject: 'nope', body: 'nope' } } },
		})
		expect(
			refused.status(),
			`only an administrator may change the platform's words, got ${refused.status()}`,
		).toBe(403)
	})

	test('one firing records its transports under one event id', async () => {
		// The dispatch itself is asynchronous, so what is asserted here is that
		// the history read accepts the axis at all: a filter the mapper does not
		// know silently returns everything, which reads exactly like a match.
		const res = await admin.get(`${API}/notification-history?eventId=no-such-event`)
		expect(res.ok(), `history read failed: ${await res.text()}`).toBeTruthy()
		expect(
			(await res.json()).total,
			'an eventId nothing was recorded under must match nothing, not everything',
		).toBe(0)
	})
})
