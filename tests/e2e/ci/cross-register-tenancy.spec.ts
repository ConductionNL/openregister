import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE TENANT EDGE ON A CROSS-REGISTER READ.
 *
 * A cross-table search (two or more schemas in one request) is answered by the
 * UNION builder, which writes its WHERE clause as text instead of through the
 * QueryBuilder. It carried the RBAC and scope predicates and NOT the
 * organisation filter, so a non-admin's cross-table search returned rows from
 * other organisations while every single-table read denied them. The live-DB
 * suite pinned that at the mapper level
 * (PrivateScopeParityIntegrationTest::testUnionPathDoesNotCrossTheTenantEdge);
 * what it cannot say is whether the leak was REACHABLE, and an earlier probe of
 * this endpoint was inconclusive because its pair arguments were invalid.
 *
 * So this spec establishes reachability first and then the boundary, as an
 * ordinary authenticated user with no admin rights: the least privileged
 * principal who should be refused these rows.
 *
 * It asserts an IDENTITY rather than a negation. Before asserting that the
 * other organisation's object is absent, it reads that object back and asserts
 * it really carries a different organisation uuid from the caller's active one.
 * A blind "is not in the list" passes just as well when the fixture never had an
 * organisation at all.
 *
 * @e2e object-level-sharing::a-grant-cannot-cross-a-tenant-boundary-on-a-cross-register-read
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so repeated runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same two seeded accounts the object-sharing spec uses, provisioned by the
 * workflow's `playwright-seed-command` with `occ user:add`. They are fixed
 * rather than per-run because the config pins `workers: 1`.
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
 * Give one user their own organisation and make it active.
 *
 * Returns the organisation uuid, which the assertions read back rather than
 * assume: an organisation that was created but never became active would leave
 * the caller org-less, and an org-less caller is a different test.
 */
async function ownOrganisation(
	ctx: APIRequestContext,
	name: string,
): Promise<string> {
	const created = await ctx.post(`${API}/organisations`, {
		data: { name, description: 'e2e tenancy fixture' },
	})
	expect(
		created.ok(),
		`could not create the organisation ${name}: ${await created.text()}`,
	).toBeTruthy()

	const uuid = String((await created.json()).organisation?.uuid ?? '')
	expect(uuid, 'the organisation create returned no uuid').toBeTruthy()

	const activated = await ctx.post(
		`${API}/organisations/${encodeURIComponent(uuid)}/set-active`,
	)
	expect(
		activated.ok(),
		`could not activate ${name}: ${await activated.text()}`,
	).toBeTruthy()

	const active = await ctx.get(`${API}/organisations/active`)
	expect(
		String((await active.json()).activeOrganisation?.uuid ?? ''),
		'the organisation was created and activated but the session still reports another one',
	).toBe(uuid)

	return uuid
}

/** Create a schema whose read rule admits any signed-in caller. */
async function schemaAdmittingEveryone(
	admin: APIRequestContext,
	title: string,
): Promise<string> {
	const res = await admin.post(`${API}/schemas`, {
		data: {
			title,
			description: 'e2e',
			properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
			// Every action is listed: a non-empty block fails closed for any
			// action it omits, so omitting `create` would stop the fixture
			// before the boundary is ever exercised. `read: authenticated` is
			// the ceiling that matters — it makes the ORGANISATION the only
			// thing that can hide a row, which is the point of the spec.
			authorization: {
				read: ['authenticated'],
				create: ['authenticated'],
				update: ['authenticated'],
				delete: ['authenticated'],
			},
		},
	})
	expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

	return String((await res.json()).id)
}

/** The `key` property of every row a cross-table search answered. */
function keysOf(body: Record<string, unknown>): string[] {
	const rows = (body.results ?? []) as Array<Record<string, unknown>>

	return rows.map((row) => String(row.key ?? ''))
}

test.describe('a cross-register read stops at the tenant edge', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaOne: string
	let schemaTwo: string
	let ownerOrg: string
	let otherOrg: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e tenancy register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// TWO schemas, because that is what routes the request to the UNION
		// builder. One schema takes the sequential path, which always carried
		// the organisation filter, and would prove nothing about this one.
		schemaOne = await schemaAdmittingEveryone(admin, `e2e tenancy one ${RUN}`)
		schemaTwo = await schemaAdmittingEveryone(admin, `e2e tenancy two ${RUN}`)

		ownerOrg = await ownOrganisation(owner, `e2e org a ${RUN}`)
		otherOrg = await ownOrganisation(other, `e2e org b ${RUN}`)
		expect(
			otherOrg,
			'both fixture users landed in the same organisation, so there is no edge to cross',
		).not.toBe(ownerOrg)
	})

	test('an object of another organisation is absent from a cross-register search', async () => {
		const mine = await owner.post(`${API}/objects/${registerId}/${schemaOne}`, {
			data: { key: `owner-row-${RUN}` },
		})
		expect(mine.ok(), `object create failed: ${await mine.text()}`).toBeTruthy()
		const mineBody = (await mine.json()) as Record<string, unknown>
		const mineSelf = (mineBody['@self'] ?? {}) as Record<string, unknown>

		// IDENTITY, not a negation: the row under test must really belong to the
		// other organisation. An org-less row would make the assertion below
		// pass for the wrong reason.
		expect(
			String(mineSelf.organisation ?? ''),
			"the fixture object did not take its creator's organisation, so the assertion below would be vacuous",
		).toBe(ownerOrg)

		const theirs = await other.post(
			`${API}/objects/${registerId}/${schemaTwo}`,
			{
				data: { key: `other-row-${RUN}` },
			},
		)
		expect(
			theirs.ok(),
			`object create failed: ${await theirs.text()}`,
		).toBeTruthy()

		// The cross-table shape: one register, two schemas, as an ordinary
		// authenticated user. No admin anywhere in this request.
		const search = await other.get(
			`${API}/objects?registers=${registerId}&schemas=${schemaOne},${schemaTwo}&_limit=100`,
		)
		expect(
			search.ok(),
			`the cross-table search was not reachable for a non-admin: ${search.status()} ${await search.text()}`,
		).toBeTruthy()

		const keys = keysOf(await search.json())

		// CONTROL FIRST. Without it an empty answer — a 404 body, a refused
		// pair, a typo in the query string — would pass the real assertion.
		expect(
			keys,
			'control: the caller must see their OWN row, or this search proves nothing',
		).toContain(`other-row-${RUN}`)

		expect(
			keys,
			'a cross-register read returned a row from another organisation',
		).not.toContain(`owner-row-${RUN}`)
	})

	test('the owner still reads their own row through the same cross-register path', async () => {
		// The mirror of the test above, and the reason it is not simply proving
		// that the union path returns nothing: the same query, the same two
		// schemas, the same builder, answered for the principal who is inside
		// the organisation.
		const search = await owner.get(
			`${API}/objects?registers=${registerId}&schemas=${schemaOne},${schemaTwo}&_limit=100`,
		)
		expect(
			search.ok(),
			`the cross-table search failed for the owner: ${await search.text()}`,
		).toBeTruthy()

		expect(
			keysOf(await search.json()),
			'the organisation filter denied the row to its own organisation',
		).toContain(`owner-row-${RUN}`)
	})

	test('a grant does not carry a recipient across the tenant edge', async () => {
		// Sharing the object with the other user is exactly the case the union
		// path made dangerous: the grant predicate WAS carried across, so a
		// grant was the one way a row from another organisation could be
		// reached on this path. A grant narrows within tenancy; it never widens
		// it (design D3c).
		const objects = await owner.get(
			`${API}/objects/${registerId}/${schemaOne}?_limit=100`,
		)
		const rows = ((await objects.json()).results ?? []) as Array<
			Record<string, unknown>
		>
		const row = rows.find((candidate) => candidate.key === `owner-row-${RUN}`)
		const self = (row?.['@self'] ?? {}) as Record<string, unknown>
		const uuid = String(self.id ?? '')
		expect(uuid, 'could not find the fixture object to share it').toBeTruthy()

		const grant = await owner.post(
			`${API}/objects/${registerId}/${schemaOne}/${uuid}/shares`,
			{ data: { type: 'user', shareWith: OTHER, permissions: 1 } },
		)
		expect(grant.ok(), `grant failed: ${await grant.text()}`).toBeTruthy()

		const search = await other.get(
			`${API}/objects?registers=${registerId}&schemas=${schemaOne},${schemaTwo}&_limit=100`,
		)
		const keys = keysOf(await search.json())

		expect(keys, 'control: the caller must still see their own row').toContain(
			`other-row-${RUN}`,
		)

		expect(
			keys,
			'a per-object grant carried a recipient across the organisation boundary',
		).not.toContain(`owner-row-${RUN}`)
	})
})
