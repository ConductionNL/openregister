import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A DEPARTMENT BY ROLE MATRIX — end to end, over HTTP.
 *
 * Round 2 row B13. A group could read every object of a schema or none; it
 * could not read the objects of its own department only. The matrix declares
 * that, and the compiler turns each row into an ordinary conditional scope so
 * enforcement runs through the paths that already exist.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS THE ONE ABOUT SOMEBODY WITH NO DEPARTMENT.
 * `buildArrayOperatorCondition()` returns null for an empty `$in`,
 * `buildMatchConditions()` then DROPS the predicate, and a rule meant to say
 * "only your own departments" becomes an unconditional grant to the whole
 * group. A user in `handlers` with no `dept:` group would then see EVERY
 * object rather than none, and nothing anywhere would report it. That user is
 * the least privileged principal this change has, and the test is written
 * around them.
 *
 * WHAT ONLY THIS CAN SHOW. `DepartmentMatrixCompilerTest` pins what the
 * compiler emits, against a table of rows. What it cannot see is whether the
 * emitted scope is a scope THIS ENGINE evaluates: whether `$in` reaches the
 * SQL builder, whether the compiled rule survives the deny pass and the grant
 * constraints, and whether the list and the object read agree about it.
 *
 * HERMETIC, and it leans only on the accounts the workflow's seed command
 * provisions, exactly as object-sharing.spec.ts does.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/**
 * The group the matrix rows name, and the one department group this run
 * creates. There is deliberately no `dept:Belastingen-…` group: the other
 * department exists only as a field value on a seeded object, because the
 * principal we probe with must belong to no department at all.
 */
const ROLE_GROUP = `e2e-handlers-${RUN}`
const DEPT_OWNER = `dept:VTH-${RUN}`

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

test.describe('a department by role matrix', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string

	/** Whether the two department groups could be provisioned at all. */
	let groupsReady = false

	/**
	 * Put one user in one group through the provisioning API.
	 *
	 * Returns false rather than throwing when the API is absent:
	 * `provisioning_api` is shipped-but-optional and `object-sharing.spec.ts`
	 * records it going 404 on the CI instance. A matrix suite that goes red
	 * because a user-management app is missing tests the wrong thing, so the
	 * tests SKIP with that reason instead.
	 */
	async function addToGroup(uid: string, group: string): Promise<boolean> {
		const made = await admin.post('/ocs/v2.php/cloud/groups', {
			form: { groupid: group },
		})
		if (made.status() === 404) {
			return false
		}

		const joined = await admin.post(`/ocs/v2.php/cloud/users/${uid}/groups`, {
			form: { groupid: group },
		})

		return joined.status() !== 404
	}

	/** Create one object of the fixture schema, in one department. */
	async function seed(key: string, department: string): Promise<string> {
		const res = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`,
			{ data: { key, department } },
		)
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()

		return String(body['@self']?.id ?? body.id ?? body.uuid)
	}

	/** The uuids one context can see in the list. */
	async function listedBy(ctx: APIRequestContext): Promise<string[]> {
		const res = await ctx.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}?_limit=100`,
		)
		if (res.ok() === false) {
			return []
		}

		const body = await res.json()
		const rows = (body.results ?? body.objects ?? []) as Array<Record<string, unknown>>

		return rows.map(
			(row) => String((row['@self'] as Record<string, unknown>)?.id ?? row.id ?? row.uuid),
		)
	}

	let vthObject = ''
	let belastingenObject = ''

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		groupsReady = (await addToGroup(OWNER, ROLE_GROUP))
			&& (await addToGroup(OTHER, ROLE_GROUP))
			&& (await addToGroup(OWNER, DEPT_OWNER))
		// 🔴 `other` is deliberately put in the ROLE group and in NO department
		// group. They are the least privileged principal here and the one the
		// empty-`$in` leak would admit to everything.

		const reg = await admin.post('/index.php/apps/openregister/api/registers', {
			data: { title: `e2e matrix register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e matrix schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					department: { type: 'string', title: 'Department', maxLength: 255 },
				},
				authorization: {
					// The owner still needs to be able to seed the fixture, and
					// `create` is not what the matrix narrows.
					create: ['authenticated'],
					update: [`group:${ROLE_GROUP}`],
					matrix: {
						field: 'department',
						userSource: { groupPrefix: 'dept:' },
						rows: [
							{ value: '$self', group: ROLE_GROUP, actions: ['read'] },
						],
					},
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		vthObject = await seed('vth-case', `VTH-${RUN}`)
		belastingenObject = await seed('belastingen-case', `Belastingen-${RUN}`)
	})

	test('a matrix naming a field the schema does not declare is refused', async () => {
		// The one test here that needs no groups at all. A matrix on a field
		// that does not exist compiles to a condition on a missing column,
		// which the SQL builder answers by dropping the predicate — so the
		// rule meant to narrow a group becomes an unconditional grant.
		const res = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e bad matrix ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key' } },
				authorization: {
					matrix: {
						field: 'afdeling',
						userSource: { groupPrefix: 'dept:' },
						rows: [{ value: '$self', group: ROLE_GROUP, actions: ['read'] }],
					},
				},
			},
		})

		expect(
			res.status(),
			'a matrix on a field the schema does not declare must be refused at save',
		).toBeGreaterThanOrEqual(400)
	})

	test('a member of a department sees its objects and not the other department\'s', async () => {
		test.skip(
			groupsReady === false,
			'the provisioning API is absent on this instance, so the department groups could not be made',
		)

		const seen = await listedBy(owner)

		expect(seen, 'their own department is listed').toContain(vthObject)
		expect(seen, 'the other department is not').not.toContain(belastingenObject)
	})

	test('🔴 a member of the role group with NO department sees nothing', async () => {
		test.skip(
			groupsReady === false,
			'the provisioning API is absent on this instance, so the department groups could not be made',
		)

		// The empty-`$in` leak, asserted as a consequence rather than as a
		// shape. If the compiler ever emits a rule whose value list is empty,
		// this user is admitted to BOTH objects and this is the only test that
		// would say so.
		const seen = await listedBy(other)

		expect(
			seen,
			'a user with no department must not be admitted to another department\'s object',
		).not.toContain(vthObject)
		expect(
			seen,
			'nor to any other object of the schema',
		).not.toContain(belastingenObject)
	})

	test('the object read agrees with the list', async () => {
		test.skip(
			groupsReady === false,
			'the provisioning API is absent on this instance, so the department groups could not be made',
		)

		// The matrix compiles into the ONE block both paths resolve through, so
		// this is the assertion that the structural claim actually holds on a
		// live instance rather than only in the resolver's docblock.
		const mine = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${vthObject}`,
		)
		expect(mine.status(), 'own department, readable').toBeLessThan(300)

		const theirs = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${belastingenObject}`,
		)
		expect(
			theirs.status(),
			'the other department, refused on the object path exactly as in the list',
		).toBeGreaterThanOrEqual(400)
	})
})
