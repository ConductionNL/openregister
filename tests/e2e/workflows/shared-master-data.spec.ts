import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TWO LEGAL ENTITIES, ONE CODE LIST, OVER HTTP.
 *
 * A gemeenschappelijke regeling is several legal entities sharing one back
 * office. Each keeps its own records and they share the code lists. This spec
 * is the only place that proves the whole of that over the real request path:
 *
 *   - organisation A holds a code-list register and declares B a consumer;
 *   - a user of B LISTS registers and the holder's register is there;
 *   - a user of B WRITES to it and is refused, with organisation A named in
 *     the message;
 *   - organisation C, named in no declaration, sees neither register.
 *
 * WHY THE UNIT TESTS DO NOT COVER THIS. `SharedMasterDataServiceTest` drives
 * the resolver over a fake connection, so it can prove which ids the rule
 * admits and nothing at all about whether the rule is REACHED. The widening
 * happens inside `MultiTenancyTrait::applyOrganisationFilter()` and the refusal
 * inside `verifyOrganisationAccess()`, both several layers below the
 * controller, and a wiring mistake at any of those layers is invisible to a
 * test that calls the resolver directly.
 *
 * 🔴 EVERY REQUEST HERE IS MADE BY A NON-ADMIN. An admin with the override
 * enabled skips the organisation filter entirely, so an admin session reads
 * every organisation's rows whether or not a share exists — it would pass this
 * file with the feature deleted. The three users are ordinary accounts, which
 * is the only identity that can tell a share from a bypass.
 *
 * @e2e saas-multi-tenant::two-entities-read-one-code-list
 * @e2e saas-multi-tenant::a-consumer-cannot-write-the-holders-row
 * @e2e saas-multi-tenant::an-organisation-that-consumes-nothing-is-unchanged
 *
 * ⚠️ NOT ON THE CI ALLOW-LIST, DELIBERATELY. It has not been rehearsed against
 * a live instance (the lane that wrote it has no Playwright runner), and
 * `tests/e2e/ci/playwright.config.ts` exists precisely so a file joins the
 * floor after a run has been seen rather than before. Admitting it unseen is
 * the "red on arrival" that keeps a gate switched off. Run it deliberately from
 * the root suite, then admit it in its own one-line diff.
 *
 * HERMETIC AND SELF-CLEANING. Three organisations, three users, two registers,
 * one schema and two objects, all prefixed `e2e-<timestamp>`, all removed in
 * afterAll, every teardown step best-effort and reporting what it could not do.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { makeRunId } from '../_fixtures.ts'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const API = '/index.php/apps/openregister/api'
const OCS_USERS = '/ocs/v2.php/cloud/users'
const RUN = makeRunId()

const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The three ordinary accounts, one per legal entity. */
const PASSWORD = 'E2e-Shared-Master-Data-7731!'
const USER_A = `${RUN}-holder`
const USER_B = `${RUN}-consumer`
const USER_C = `${RUN}-outsider`

const ORG_A_NAME = `E2E Gemeente ${RUN}`
const ORG_B_NAME = `E2E Samenwerkingsverband ${RUN}`
const ORG_C_NAME = `E2E Omgevingsdienst ${RUN}`

/** A minimal code-list schema: one value, one label. */
const CODE_LIST_PROPERTIES = {
	value: { type: 'string', title: 'Value' },
	label: { type: 'string', title: 'Label' },
}

let admin: APIRequestContext
let asA: APIRequestContext
let asB: APIRequestContext
let asC: APIRequestContext

let orgA: string | null = null
let orgB: string | null = null
let orgC: string | null = null

let sharedRegisterId: number | null = null
let privateRegisterId: number | null = null
let schemaId: number | null = null

const createdUsers: string[] = []

/**
 * A request context authenticated as one ordinary account.
 *
 * @param user The account name.
 * @return The context.
 */
async function contextFor(user: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		httpCredentials: { username: user, password: PASSWORD },
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
	})
}

/**
 * Create one ordinary Nextcloud account.
 *
 * @param user The account name.
 * @return Nothing.
 */
async function createUser(user: string): Promise<void> {
	const created = await admin.post(OCS_USERS, {
		form: { userid: user, password: PASSWORD },
	})
	expect(
		created.ok(),
		`creating ${user} failed: ${await created.text()}`,
	).toBeTruthy()
	createdUsers.push(user)
}

/**
 * Create one organisation and put one account in it.
 *
 * @param name The organisation name.
 * @param user The account to add.
 * @return The organisation uuid.
 */
async function createOrganisationFor(name: string, user: string): Promise<string> {
	const created = await admin.post(`${API}/organisations`, {
		data: { name, description: 'shared master data e2e fixture' },
	})
	expect(
		created.status(),
		`creating organisation ${name} failed: ${await created.text()}`,
	).toBeLessThanOrEqual(201)

	const body = await created.json()
	const uuid = body.uuid ?? body?.organisation?.uuid
	expect(uuid, `organisation ${name} came back without a uuid`).toBeTruthy()

	const joined = await admin.post(`${API}/organisations/${uuid}/join`, {
		data: { userId: user },
	})
	expect(
		joined.status(),
		`adding ${user} to ${name} failed: ${await joined.text()}`,
	).toBeLessThanOrEqual(201)

	return uuid as string
}

test.describe.configure({ mode: 'serial' })

test.describe('shared master data across legal entities', () => {
	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			httpCredentials: { username: ADMIN, password: ADMIN_PASS },
			extraHTTPHeaders: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
		})

		await createUser(USER_A)
		await createUser(USER_B)
		await createUser(USER_C)

		orgA = await createOrganisationFor(ORG_A_NAME, USER_A)
		orgB = await createOrganisationFor(ORG_B_NAME, USER_B)
		orgC = await createOrganisationFor(ORG_C_NAME, USER_C)

		asA = await contextFor(USER_A)
		asB = await contextFor(USER_B)
		asC = await contextFor(USER_C)

		// Each user pins its own organisation as active, which is what the
		// tenant filter resolves from. Without this the filter has no active
		// organisation and refuses everything, and the reads below would be
		// empty for a reason that has nothing to do with sharing.
		for (const [context, org] of [
			[asA, orgA],
			[asB, orgB],
			[asC, orgC],
		] as const) {
			const active = await context.post(
				`${API}/organisations/${org}/set-active`,
			)
			expect(
				active.status(),
				`setting the active organisation failed: ${await active.text()}`,
			).toBeLessThanOrEqual(201)
		}

		// A holds two registers: the code list it will share, and one it will
		// not. The second is the control — without it a widening that keys on
		// the HOLDER rather than on the declared row would look identical to a
		// correct one.
		const shared = await asA.post(`${API}/registers`, {
			data: {
				slug: `${RUN}-codelist`,
				title: `E2E code list ${RUN}`,
				description: 'held by A, read by B',
			},
		})
		expect(
			shared.status(),
			`creating the shared register failed: ${await shared.text()}`,
		).toBeLessThanOrEqual(201)
		sharedRegisterId = (await shared.json()).id

		const priv = await asA.post(`${API}/registers`, {
			data: {
				slug: `${RUN}-private`,
				title: `E2E private register ${RUN}`,
				description: 'held by A, shared with nobody',
			},
		})
		expect(
			priv.status(),
			`creating the private register failed: ${await priv.text()}`,
		).toBeLessThanOrEqual(201)
		privateRegisterId = (await priv.json()).id

		const schema = await asA.post(`${API}/schemas`, {
			data: {
				slug: `${RUN}-code`,
				title: `E2E code ${RUN}`,
				properties: CODE_LIST_PROPERTIES,
			},
		})
		expect(
			schema.status(),
			`creating the schema failed: ${await schema.text()}`,
		).toBeLessThanOrEqual(201)
		schemaId = (await schema.json()).id

		const link = await asA.put(`${API}/registers/${sharedRegisterId}`, {
			data: { schemas: [schemaId] },
		})
		expect(
			link.status(),
			`linking the schema failed: ${await link.text()}`,
		).toBe(200)
	})

	test.afterAll(async () => {
		const warn = (step: string, status: number) =>
			console.warn(`[shared-master-data] teardown ${step} answered ${status}`)

		// The declaration comes off first: while it stands, B is a consumer and
		// a teardown that ran as B would be refused by the very guard this file
		// is about. Everything here runs as the admin anyway, but taking the
		// share down first keeps the instance in a state nothing else can trip
		// over if a later step fails.
		if (sharedRegisterId !== null) {
			const unshare = await admin.put(`${API}/registers/${sharedRegisterId}`, {
				data: { sharedWith: [] },
			})
			if (!unshare.ok()) warn('unshare', unshare.status())
		}

		for (const id of [schemaId]) {
			if (id === null) continue
			const del = await admin.delete(`${API}/schemas/${id}`)
			if (!del.ok()) warn(`schema ${id} delete`, del.status())
		}

		for (const id of [sharedRegisterId, privateRegisterId]) {
			if (id === null) continue
			const del = await admin.delete(`${API}/registers/${id}`)
			if (!del.ok()) warn(`register ${id} delete`, del.status())
		}

		for (const org of [orgA, orgB, orgC]) {
			if (org === null) continue
			const del = await admin.delete(`${API}/organisations/${org}`)
			if (!del.ok()) warn(`organisation ${org} delete`, del.status())
		}

		for (const user of createdUsers) {
			const del = await admin.delete(`${OCS_USERS}/${user}`)
			if (!del.ok()) warn(`user ${user} delete`, del.status())
		}

		await Promise.all([
			admin.dispose(),
			asA?.dispose(),
			asB?.dispose(),
			asC?.dispose(),
		])
	})

	test("before the declaration, B sees neither of the holder's registers", async () => {
		// The control that separates "the share works" from "the tenant filter
		// never applied". Without this, every assertion below would also pass on
		// an instance where multitenancy was simply off.
		const listed = await asB.get(`${API}/registers`)
		expect(listed.status()).toBe(200)

		const ids = ((await listed.json()).results ?? []).map(
			(r: { id: number }) => r.id,
		)
		expect(ids).not.toContain(sharedRegisterId)
		expect(ids).not.toContain(privateRegisterId)
	})

	test('the holder declares B a consumer and the declaration is stored, not dropped', async () => {
		const declared = await asA.put(`${API}/registers/${sharedRegisterId}`, {
			data: { sharedWith: [orgB] },
		})
		expect(
			declared.status(),
			`declaring the share failed: ${await declared.text()}`,
		).toBe(200)

		// Read it back rather than trusting the write response. An unknown key
		// that this app drops in silence answers the create with the value it
		// was handed and holds nothing, which is a trap this repo has paid for
		// before.
		const reread = await asA.get(`${API}/registers/${sharedRegisterId}`)
		expect(reread.status()).toBe(200)
		expect((await reread.json()).sharedWith).toEqual([orgB])
	})

	test('a user of B lists the code list the holder keeps', async () => {
		const listed = await asB.get(`${API}/registers`)
		expect(listed.status()).toBe(200)

		const ids = ((await listed.json()).results ?? []).map(
			(r: { id: number }) => r.id,
		)
		expect(ids).toContain(sharedRegisterId)
	})

	test('B gains the declared register and nothing else the same holder owns', async () => {
		const listed = await asB.get(`${API}/registers`)
		expect(listed.status()).toBe(200)

		const ids = ((await listed.json()).results ?? []).map(
			(r: { id: number }) => r.id,
		)
		expect(
			ids,
			'a share must admit the declared row, never every row its holder owns',
		).not.toContain(privateRegisterId)
	})

	test("a user of B cannot write the holder's row, and the refusal names the holder", async () => {
		const refused = await asB.put(`${API}/registers/${sharedRegisterId}`, {
			data: { title: `Rewritten by B ${RUN}` },
		})

		expect(refused.status()).toBe(403)

		const message = await refused.text()
		expect(message).toContain(ORG_A_NAME)

		// A refusal that also has to leave the row alone. A 403 returned after
		// the write landed would pass the status assertion and be the worse bug.
		const reread = await asA.get(`${API}/registers/${sharedRegisterId}`)
		expect((await reread.json()).title).toBe(`E2E code list ${RUN}`)
	})

	test('an organisation that consumes nothing sees exactly what it saw before', async () => {
		const listed = await asC.get(`${API}/registers`)
		expect(listed.status()).toBe(200)

		const ids = ((await listed.json()).results ?? []).map(
			(r: { id: number }) => r.id,
		)
		expect(ids).not.toContain(sharedRegisterId)
		expect(ids).not.toContain(privateRegisterId)
	})
})
