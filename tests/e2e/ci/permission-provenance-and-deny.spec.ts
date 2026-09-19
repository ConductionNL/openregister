import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A RULE THAT TAKES A VERB AWAY — end to end, over the HTTP API a client uses.
 *
 * The PHPUnit suite proves the four enforcement paths read the deny the same
 * way, and it proves the predicate lands in the SQL. What it cannot prove is
 * that the capability is REACHABLE: that a deny survives a schema save, that a
 * non-admin caller meets it on a real request, and that the list and the read
 * agree once a real database has run the query. Those are the failures a green
 * unit suite hides.
 *
 * WHAT IT ASSERTS, AND WHY THAT SHAPE. Every case asserts the CONSEQUENCE — the
 * object is refused, the row is absent, the total is smaller — never the
 * mechanism. And the count is asserted beside the page, because that is the
 * whole of D22: a permission applied to a fetched page gives a correct page of
 * wrong data, and a test that only looked at the page would pass against
 * exactly the product this change exists to not be.
 *
 * THE LEAST-PRIVILEGED PROBE is `a caller with no grant sees nothing, and the
 * total says so`. A superuser success proves almost nothing, so the probe runs
 * as the caller who should be refused, and the control beside it is the same
 * caller reading a schema that does grant them, which separates "the deny
 * works" from "nothing works".
 *
 * HERMETIC. It creates its own register, schemas and objects. The two non-admin
 * accounts come from `tests/e2e/ci/seed.sh`, like every other spec here.
 *
 * @e2e rbac-scopes::a-deny-beats-a-per-object-grant
 * @e2e rbac-scopes::the-denied-object-is-absent-from-the-list
 * @e2e rbac-scopes::a-grant-and-a-deny-at-one-level-is-a-configuration-error
 * @e2e rbac-scopes::the-last-manager-cannot-be-denied
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run never collides with its predecessor. */
const RUN = Math.random().toString(36).slice(2, 10)

/**
 * The OCS path holding the deny enforcement mode.
 *
 * The deny ships STAGED (D15): by default it is resolved, recorded and applied
 * to nothing. Every case in this file asserts what a deny DOES, so the suite
 * turns enforcement on for its own run and puts the key back afterwards. A file
 * that skipped this would fail for the right reason and read as the capability
 * being broken.
 */
const MODE_PATH =
	'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/openregister/deny_enforcement'

/** OCS wants this header on every request or it answers 401. */
const OCS_HEADERS = { 'OCS-APIRequest': 'true' }

/** Put the instance in one deny enforcement mode. */
async function setDenyMode(admin: APIRequestContext, mode: string): Promise<void> {
	const res = await admin.post(MODE_PATH, {
		headers: OCS_HEADERS,
		form: { value: mode },
	})
	expect(
		res.status(),
		`could not set the deny enforcement mode to "${mode}"; every case below would be measuring the default`,
	).toBe(200)
}

/** Seeded by `tests/e2e/ci/seed.sh`; both deliberately non-admin. */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

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
 * Assert a seeded account is usable before any test leans on it.
 *
 * Guards a seed that silently did not run, which would otherwise surface as a
 * confusing authorization error deep inside a deny assertion rather than as
 * "the fixture user does not exist".
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get('/index.php/apps/openregister/api/registers')
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

/** The reported total of a list response, whichever envelope it came back in. */
function totalOf(body: Record<string, unknown>): number {
	const total = body.total ?? (body as { results?: unknown[] }).results?.length
	expect(
		typeof total,
		`no total in the list envelope: ${JSON.stringify(body).slice(0, 400)}`,
	).toBe('number')
	return Number(total)
}

/** The rows of a list response. */
function rowsOf(body: Record<string, unknown>): Array<Record<string, unknown>> {
	const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
	expect(
		Array.isArray(rows),
		'the list envelope carried no array of rows',
	).toBeTruthy()
	return rows
}

/** The uuid a create response reports for the object it made. */
function uuidOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>
	const uuid = String(self.id ?? body.id ?? body.uuid ?? '')
	expect(uuid, 'no uuid came back from the object create').toBeTruthy()
	return uuid
}

test.describe('a deny takes a verb away, over HTTP', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let openSchemaId: string
	let deniedSchemaId: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)

		const reg = await admin.post('/index.php/apps/openregister/api/registers', {
			data: { title: `e2e deny register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// The CONTROL schema. Every action is granted to any signed-in caller,
		// and nothing is denied. Without it, every refusal below could be a
		// refusal for some entirely different reason.
		const open = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e deny control schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(
			open.ok(),
			`control schema create failed: ${await open.text()}`,
		).toBeTruthy()
		openSchemaId = String((await open.json()).id)

		// The SUBJECT schema. The same grants, and `read` denied to the
		// `authenticated` pseudo-group, so every signed-in non-admin caller is
		// denied the read they would otherwise hold. A non-empty block fails
		// closed for any action it omits, which is why the writes are listed
		// even though the read is the one under test.
		const denied = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e deny subject schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
					deny: { read: ['e2e-nobody-holds-this-group'] },
				},
			},
		})
		expect(
			denied.ok(),
			`subject schema create failed: ${await denied.text()}`,
		).toBeTruthy()
		deniedSchemaId = String((await denied.json()).id)

		// Enforcement LAST, after every fixture is written. The save-time
		// refusals apply in every mode, so the fixtures above are unaffected by
		// the order; doing it here keeps the mode on for the shortest window.
		await setDenyMode(admin, 'enforcing')
	})

	test.afterAll(async () => {
		// Back to the shipped default, whatever happened above. A suite that
		// left an instance enforcing would hand the next spec a deny it never
		// asked for, and that failure would land somewhere else entirely.
		await admin.delete(MODE_PATH, { headers: OCS_HEADERS })
	})

	test('the deny survives the schema save, and is read back as written', async () => {
		const res = await admin.get(
			`/index.php/apps/openregister/api/schemas/${deniedSchemaId}`,
		)
		expect(res.ok(), `schema read failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		expect(
			body.authorization?.deny?.read,
			'the deny key did not survive the save; a rule that is not stored cannot be enforced',
		).toContain('e2e-nobody-holds-this-group')
	})

	test('a deny on one object beats a grant the caller otherwise holds', async () => {
		// An ordinary object first, readable by the other user through the
		// `authenticated` grant. This is the grant the deny then beats.
		const created = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `deny-subject-${RUN}` } },
		)
		expect(
			created.ok(),
			`object create failed: ${await created.text()}`,
		).toBeTruthy()
		const uuid = uuidOf(await created.json())

		const before = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
		)
		expect(
			before.status(),
			'the object must start out readable, or the deny below proves nothing',
		).toBeLessThan(300)

		// Now the object denies the read to every signed-in caller. The owner
		// writes it, not an administrator: a rule only an admin can write is a
		// rule nobody uses.
		const denied = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
			{
				data: {
					key: `deny-subject-${RUN}`,
					'@self': {
						authorization: { deny: { read: ['authenticated'] } },
					},
				},
			},
		)
		expect(
			denied.ok(),
			`writing the object deny failed: ${await denied.text()}`,
		).toBeTruthy()

		const after = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
		)
		expect(
			after.status(),
			'a denied object must not be readable, whatever the schema grants',
		).toBeGreaterThanOrEqual(400)
	})

	test('the denied object is absent from the list AND from the total', async () => {
		// Two objects on the control schema. One is then denied; the other is
		// the control that proves the list still works at all.
		const keep = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `deny-list-keep-${RUN}` } },
		)
		expect(keep.ok(), `object create failed: ${await keep.text()}`).toBeTruthy()
		const keepUuid = uuidOf(await keep.json())

		const hide = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `deny-list-hide-${RUN}` } },
		)
		expect(hide.ok(), `object create failed: ${await hide.text()}`).toBeTruthy()
		const hideUuid = uuidOf(await hide.json())

		const listBefore = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}?_search=deny-list-${RUN}&limit=50`,
		)
		expect(
			listBefore.ok(),
			`list failed: ${await listBefore.text()}`,
		).toBeTruthy()
		const totalBefore = totalOf(await listBefore.json())
		expect(
			totalBefore,
			'both fixtures should be listed before the deny',
		).toBeGreaterThanOrEqual(2)

		const denied = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${hideUuid}`,
			{
				data: {
					key: `deny-list-hide-${RUN}`,
					'@self': {
						authorization: { deny: { read: ['authenticated'] } },
					},
				},
			},
		)
		expect(
			denied.ok(),
			`writing the object deny failed: ${await denied.text()}`,
		).toBeTruthy()

		const listAfter = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}?_search=deny-list-${RUN}&limit=50`,
		)
		expect(listAfter.ok(), `list failed: ${await listAfter.text()}`).toBeTruthy()
		const body = await listAfter.json()

		const ids = rowsOf(body).map((row) => {
			const self = (row['@self'] ?? {}) as Record<string, unknown>
			return String(self.id ?? row.id ?? row.uuid ?? '')
		})
		expect(ids, 'the denied object is still in the list').not.toContain(hideUuid)
		expect(
			ids,
			'the control object disappeared too; the list is broken, not filtered',
		).toContain(keepUuid)

		// 🔴 The count, which is the half a post-filter gets wrong. A page that
		// omits the row while the total still counts it is the "correct page of
		// wrong data" D22 names.
		expect(
			totalOf(body),
			'the total still counts the denied object, so the filter ran after the query',
		).toBe(totalBefore - 1)
	})

	/**
	 * Task 4.1, the whole round trip: a grant, a deny on one row, the list, the
	 * object, and the provenance for BOTH answers.
	 *
	 * The provenance is the half a client cannot reconstruct. A yes is easy to
	 * verify by trying it; an absence has no reason at all unless the layer
	 * gives one, and "why can this person not open this dossier" is the question
	 * that otherwise ends in a database session.
	 *
	 * @e2e rbac-scopes::a-grant-says-where-it-came-from
	 * @e2e rbac-scopes::an-absence-says-which-rule-removed-it
	 * @e2e rbac-scopes::an-auditor-asks-who-could-open-a-dossier
	 * @e2e rbac-scopes::the-page-renders-only-what-is-allowed
	 */
	test('the grant and the absence both say which rule decided them', async () => {
		const readable = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `provenance-keep-${RUN}` } },
		)
		expect(
			readable.ok(),
			`object create failed: ${await readable.text()}`,
		).toBeTruthy()
		const readableUuid = uuidOf(await readable.json())

		const hidden = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `provenance-hide-${RUN}` } },
		)
		expect(
			hidden.ok(),
			`object create failed: ${await hidden.text()}`,
		).toBeTruthy()
		const hiddenUuid = uuidOf(await hidden.json())

		const denied = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${hiddenUuid}`,
			{
				data: {
					key: `provenance-hide-${RUN}`,
					'@self': {
						authorization: { deny: { read: ['authenticated'] } },
					},
				},
			},
		)
		expect(
			denied.ok(),
			`writing the object deny failed: ${await denied.text()}`,
		).toBeTruthy()

		// THE LIST. One row of the two, and the total says the same.
		const list = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}?_search=provenance-${RUN}&limit=50`,
		)
		expect(list.ok(), `list failed: ${await list.text()}`).toBeTruthy()
		const listed = rowsOf(await list.json()).map((row) => {
			const self = (row['@self'] ?? {}) as Record<string, unknown>
			return String(self.id ?? row.id ?? row.uuid ?? '')
		})
		expect(
			listed,
			'the readable row is missing, so the list is broken rather than filtered',
		).toContain(readableUuid)
		expect(listed, 'the denied row is still listed').not.toContain(hiddenUuid)

		// THE OBJECT, and the actions it carries. A record that says what its
		// reader may do with it is what stops a client rendering a button
		// nobody may press.
		const read = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${readableUuid}`,
		)
		expect(
			read.ok(),
			`the readable object was refused: ${await read.text()}`,
		).toBeTruthy()
		const record = await read.json()
		expect(
			record['@self']?.actions,
			'the record carried no actions, so every client is back to guessing',
		).toContain('read')

		const refused = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${hiddenUuid}`,
		)
		expect(
			refused.status(),
			'the denied object was readable',
		).toBeGreaterThanOrEqual(400)

		// THE PROVENANCE OF THE YES. The grant names the rule behind it.
		const scopes = await other.get(
			`/index.php/apps/openregister/api/scopes?register=${registerId}&schema=${openSchemaId}`,
		)
		expect(
			scopes.ok(),
			`scopes read failed: ${await scopes.text()}`,
		).toBeTruthy()
		const scope = ((await scopes.json()).scopes ?? [])[0]
		expect(
			scope,
			'the caller has no scope on a schema that grants them read',
		).toBeTruthy()
		expect(scope.actions, 'read is missing from the actions list').toContain(
			'read',
		)
		expect(
			scope.provenance?.read?.source,
			'the grant does not say where it came from',
		).toBeTruthy()

		// THE PROVENANCE OF THE NO. Read as the owner, who may review access:
		// the deny is reported with the rule that carries it, and the mode that
		// says whether it is biting yet.
		const holders = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${hiddenUuid}/permissions`,
		)
		expect(
			holders.ok(),
			`the access set was refused: ${await holders.text()}`,
		).toBeTruthy()
		const set = await holders.json()
		expect(
			(set.denied ?? []).map((rule: Record<string, unknown>) => rule.action),
			'the absence has no rule behind it, which is the whole thing this read exists for',
		).toContain('read')
		expect(
			set.denyEnforcement,
			'the report does not say whether the deny is biting yet',
		).toBeTruthy()

		// And the same caller reading the row nobody denied gets no denial,
		// so the report is about this rule and not about every object.
		const control = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${readableUuid}/permissions`,
		)
		expect(
			control.ok(),
			`the control access set was refused: ${await control.text()}`,
		).toBeTruthy()
		expect(
			(await control.json()).denied,
			'a row nobody denied is reported as denied',
		).toHaveLength(0)
	})

	test('a caller with no grant sees nothing, and the total says so', async () => {
		// THE LEAST-PRIVILEGED PROBE. The subject schema denies `read` to a
		// group nobody is in, so this caller's refusal must come from the grant
		// chain rather than from the deny — and the control below proves the
		// same caller can read a schema that does grant them.
		const ungranted = await admin.post(
			'/index.php/apps/openregister/api/schemas',
			{
				data: {
					title: `e2e deny ungranted schema ${RUN}`,
					description: 'e2e',
					properties: {
						key: { type: 'string', title: 'Key', maxLength: 255 },
					},
					authorization: {
						read: ['admin'],
						create: ['admin'],
						update: ['admin'],
						delete: ['admin'],
					},
				},
			},
		)
		expect(
			ungranted.ok(),
			`schema create failed: ${await ungranted.text()}`,
		).toBeTruthy()
		const ungrantedSchemaId = String((await ungranted.json()).id)

		const seeded = await admin.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${ungrantedSchemaId}`,
			{ data: { key: `ungranted-${RUN}` } },
		)
		expect(
			seeded.ok(),
			`object create failed: ${await seeded.text()}`,
		).toBeTruthy()

		const res = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${ungrantedSchemaId}?limit=50`,
		)

		// Either the endpoint refuses outright, or it answers an EMPTY list
		// whose total is zero. Both are correct; what is never correct is a
		// non-zero total beside an empty page.
		if (res.ok() === true) {
			const body = await res.json()
			expect(
				rowsOf(body),
				'a caller with no grant received rows',
			).toHaveLength(0)
			expect(
				totalOf(body),
				'the page was empty but the total was not: the count leaked what the page hid',
			).toBe(0)
		} else {
			expect(res.status()).toBeGreaterThanOrEqual(400)
		}

		// THE CONTROL: the same caller, the same instant, on a schema that does
		// grant them. Without this, a broken list would pass the probe above.
		const control = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}?limit=50`,
		)
		expect(
			control.ok(),
			`the control list failed: ${await control.text()}`,
		).toBeTruthy()
		expect(
			totalOf(await control.json()),
			'the control list is empty too, so the probe measured a broken list rather than a refusal',
		).toBeGreaterThan(0)
	})

	test('a grant and a deny of one verb at one level is refused with 422', async () => {
		const res = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e deny collision schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
				},
				authorization: {
					delete: ['behandelaars'],
					deny: { delete: ['behandelaars'] },
				},
			},
		})

		expect(
			res.status(),
			'a block that grants and denies one verb to one principal must not be storable',
		).toBe(422)
		expect(
			await res.text(),
			'the refusal must name the rules it refused',
		).toContain('behandelaars')
	})

	test('the last principal holding manage cannot be denied it', async () => {
		const res = await admin.post('/index.php/apps/openregister/api/registers', {
			data: {
				title: `e2e deny orphan register ${RUN}`,
				description: 'e2e',
				authorization: {
					read: ['authenticated'],
					deny: { manage: ['beheerders'] },
				},
			},
		})

		expect(
			res.status(),
			'a register whose administration is denied away cannot be edited again',
		).toBe(422)
		expect(
			await res.text(),
			'the refusal must name what would be orphaned',
		).toContain('manage')
	})

	/**
	 * 🔴 STAGING changes nothing, and that is the point (D15).
	 *
	 * The same caller, the same schema and the same object-level deny as the
	 * list case above, which removes the row. Only the mode differs. The two
	 * together are what proves the switch is doing the work: a deny that
	 * refused in both modes would pass one case and fail the other, and so
	 * would a deny that refused in neither.
	 *
	 * The mode goes back to `enforcing` in a finally, because a case that left
	 * the instance staged would silently pass every assertion in any spec that
	 * runs after it.
	 *
	 * @e2e rbac-scopes::a-new-deny-refuses-nobody-on-the-day-it-is-written
	 * @e2e rbac-scopes::the-list-is-unchanged-while-staging
	 */
	test('while staging, the denied row is still read and still counted', async () => {
		const created = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}`,
			{ data: { key: `deny-staged-${RUN}` } },
		)
		expect(
			created.ok(),
			`object create failed: ${await created.text()}`,
		).toBeTruthy()
		const uuid = uuidOf(await created.json())

		const denied = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
			{
				data: {
					key: `deny-staged-${RUN}`,
					'@self': {
						authorization: { deny: { read: ['authenticated'] } },
					},
				},
			},
		)
		expect(
			denied.ok(),
			`writing the object deny failed: ${await denied.text()}`,
		).toBeTruthy()

		// THE CONTROL, enforcing: the caller cannot read it. Without this the
		// case below would pass against a fixture whose deny never landed.
		const whenEnforcing = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
		)
		expect(
			whenEnforcing.status(),
			'the control failed: this row must be refused while enforcing, or the case below proves nothing',
		).toBe(403)

		try {
			await setDenyMode(admin, 'staging')

			const staged = await other.get(
				`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}/${uuid}`,
			)
			expect(
				staged.ok(),
				`a staged deny refused a read: ${staged.status()} ${await staged.text()}`,
			).toBeTruthy()

			const list = await other.get(
				`/index.php/apps/openregister/api/objects/${registerId}/${openSchemaId}?_search=deny-staged-${RUN}&limit=50`,
			)
			expect(
				list.ok(),
				`list failed while staging: ${await list.text()}`,
			).toBeTruthy()
			const body = await list.json()
			const ids = rowsOf(body).map((row) => {
				const self = (row['@self'] ?? {}) as Record<string, unknown>
				return String(self.id ?? row.id ?? row.uuid ?? '')
			})
			expect(
				ids,
				'a staged deny removed the row from the list; the total and every facet count would already have moved',
			).toContain(uuid)
			expect(
				totalOf(body),
				'a staged deny changed the total, which is exactly what staging must not do',
			).toBeGreaterThanOrEqual(1)
		} finally {
			await setDenyMode(admin, 'enforcing')
		}
	})
})
