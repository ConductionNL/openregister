import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A GRANT ON A PARENT REACHES ITS CHILDREN — end to end, over HTTP.
 *
 * Ledger row Q13.23, and the measurement the register quotes from the best
 * competitor is two facts in one sentence: "read on the root read the
 * grandchild AND WAS REFUSED A WRITE". Both halves are asserted here, because
 * the second is the one that is easy to lose: an inheritance that widened the
 * verb on the way down would satisfy every "can they see it" test ever written
 * and would hand everybody who may read a root the right to edit everything
 * under it.
 *
 * WHAT ONLY THIS CAN SHOW. `HierarchyGrantExpanderTest` pins the verb rule, the
 * cap, the cycle and the provenance against a doubled descender, and
 * `HierarchyAnnotationValidatorTest` pins what a declaration may say. Both are
 * green over a declaration nothing ever reads. What they cannot see is the
 * chain: that the annotation survives a schema save, that the descent finds the
 * children in a real magic table, and that the expanded grant set reaches the
 * per-object read AND the list — which are compiled by different code into
 * different languages and have disagreed before.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED. Every
 * assertion below is made as `e2e-other`, who is given ONE grant on the root and
 * nothing else. The refusals are the point: a write on the child, and a read of
 * an object in a second tree they were never invited to. Asserting only the
 * successful read would pass just as well against a resolver that granted
 * everything to everybody.
 *
 * HERMETIC. It creates its own register, schema and objects and leans only on
 * the two accounts the workflow's seed command provisions, exactly as
 * `object-sharing.spec.ts` does.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/** The accounts the workflow's seed command provisions. See object-sharing.spec.ts. */
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

test.describe('a grant on a parent reaches its children', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string

	/** The tree the grant is made on. */
	let rootUuid: string
	let childUuid: string
	let grandchildUuid: string

	/** A second tree, granted to nobody. The control. */
	let strangerUuid: string

	/** Whether the schema accepted the hierarchy annotation at all. */
	let declarationAccepted = false

	/**
	 * Create one object of the fixture schema, as its owner.
	 *
	 * @param key A label for the row.
	 * @param parent The uuid of its parent, or undefined for a root.
	 */
	async function seed(key: string, parent?: string): Promise<string> {
		const data: Record<string, unknown> = { key }
		if (parent !== undefined) {
			data.parentObject = parent
		}

		const res = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`,
			{ data },
		)
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		const reg = await admin.post('/index.php/apps/openregister/api/registers', {
			data: { title: `e2e hierarchy register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// `parentObject` references THIS schema, which is what the annotation
		// validator requires and what makes the edge a hierarchy rather than a
		// path into somebody else's data.
		//
		// The reference is the target's SLUG, not its title. That is what
		// `$ref` carries everywhere in this app (`concept`, `conceptScheme` in
		// the shipped registers) and what HierarchyAnnotationValidator compares
		// against. Writing the title here read as a reference to a different
		// schema, and the only reason it ever got past the save is that the
		// annotation was being dropped before the validator saw it.
		const schemaSlug = `e2e-hierarchy-schema-${RUN}`
		const sch = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e hierarchy schema ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key', maxLength: 255 },
					parentObject: {
						type: 'string',
						title: 'Parent',
						$ref: schemaSlug,
					},
				},
				// A non-empty block fails closed for every action it does not
				// list, so the owner could not even seed the tree without these.
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				configuration: {
					'x-openregister-hierarchy': {
						parent: 'parentObject',
						maxDepth: 5,
						inheritedVerbs: ['read'],
					},
				},
			},
		})

		declarationAccepted = sch.ok()
		expect(
			declarationAccepted,
			`the schema carrying x-openregister-hierarchy was refused: ${await sch.text()}`,
		).toBeTruthy()
		schemaId = String((await sch.json()).id)

		rootUuid = await seed('root')
		childUuid = await seed('child', rootUuid)
		grandchildUuid = await seed('grandchild', childUuid)
		strangerUuid = await seed('stranger-root')

		// Everything goes PRIVATE, so a grant is the only thing that can admit
		// the other user. Without this the schema's own `authenticated` read
		// rule would let them in and every assertion below would pass on a
		// resolver that inherits nothing.
		for (const uuid of [rootUuid, childUuid, grandchildUuid, strangerUuid]) {
			const put = await owner.put(
				`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}/scope`,
				{ data: { scope: 'private' } },
			)
			expect(
				put.ok(),
				`could not make ${uuid} private: ${await put.text()}`,
			).toBeTruthy()
		}

		// ONE grant, on the root, read only.
		const grant = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${rootUuid}/shares`,
			{ data: { type: 'user', shareWith: OTHER, permissions: 1 } },
		)
		expect(grant.ok(), `grant failed: ${await grant.text()}`).toBeTruthy()
	})

	test('the control: without the grant, nothing in the other tree is readable', async () => {
		// Asserted FIRST and on purpose. If a private object were readable by
		// this user anyway, every other test in this file would pass without
		// inheritance existing at all.
		const res = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${strangerUuid}`,
		)
		expect(
			res.status(),
			'an object in a tree this user holds no grant on must not be readable',
		).toBeGreaterThanOrEqual(400)
	})

	test('read on the root reaches the child and the grandchild', async () => {
		for (const [label, uuid] of [
			['the granted root', rootUuid],
			['the child', childUuid],
			['the grandchild', grandchildUuid],
		] as const) {
			const res = await other.get(
				`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}`,
			)
			expect(
				res.status(),
				`${label} should be readable through the grant on the root`,
			).toBeLessThan(300)
		}
	})

	test('🔴 read does not become write', async () => {
		// The half of the competitor's measurement that is easiest to drop, and
		// the one that is a disclosure rather than an inconvenience.
		const res = await other.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${childUuid}`,
			{ data: { key: 'rewritten-by-a-reader' } },
		)
		expect(
			res.status(),
			'a read grant on the root must not admit a write on the child',
		).toBeGreaterThanOrEqual(400)

		// And the row is read back, because a 4xx that had already written
		// would be a refusal in name only.
		const after = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${childUuid}`,
		)
		expect(after.ok()).toBeTruthy()
		const body = await after.json()
		expect(String(body.key ?? '')).toBe('child')
	})

	test('the list agrees with the read', async () => {
		// D-3. The object path and the list path are compiled by different code
		// into different languages; a rule added to one of them has gone
		// missing from the other before, and the symptom is an object you can
		// open through its URL and cannot find in any list.
		const res = await other.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}?_limit=100`,
		)
		expect(res.ok(), `list failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const rows = (body.results ?? body.objects ?? []) as Array<
			Record<string, unknown>
		>
		const uuids = rows.map((row) =>
			String(
				(row['@self'] as Record<string, unknown>)?.id ?? row.id ?? row.uuid,
			),
		)

		expect(uuids, 'the granted root is in the list').toContain(rootUuid)
		expect(uuids, 'the child is in the list').toContain(childUuid)
		expect(uuids, 'the grandchild is in the list').toContain(grandchildUuid)
		expect(
			uuids,
			'an object in a tree this user holds no grant on is NOT in the list',
		).not.toContain(strangerUuid)
	})

	test('the audit names the object the grant was written on', async () => {
		// REQ-RIC-004. Without this an administrator looking at the grandchild
		// sees access they cannot explain and cannot remove, because the share
		// is on the root's folder and not on the object in front of them.
		const res = await owner.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${grandchildUuid}/shares`,
		)
		expect(res.ok(), `share listing failed: ${await res.text()}`).toBeTruthy()

		const rows = ((await res.json()).results ?? []) as Array<
			Record<string, unknown>
		>
		const inherited = rows.filter((row) => row.inherited === true)

		expect(
			inherited.length,
			'the grandchild holds a grant it did not get directly, so the listing must say where it came from',
		).toBeGreaterThan(0)
		expect(
			inherited.map((row) => String(row.inheritedFrom)),
			'the source named is the object the share is actually on',
		).toContain(rootUuid)
	})

	test('a parent property pointing at another schema is refused', async () => {
		// REQ-RIC-001, and the reason this validator throws where its
		// neighbours warn: a hierarchy over a property that references a USER
		// would hand everybody who may read one object every object filed to
		// the same person, and from that moment it looks like working
		// inheritance.
		const res = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e bad hierarchy ${RUN}`,
				description: 'e2e',
				properties: {
					key: { type: 'string', title: 'Key' },
				},
				configuration: {
					'x-openregister-hierarchy': { parent: 'key' },
				},
			},
		})

		expect(
			res.status(),
			'a hierarchy over a property that is not a self-reference must be refused at save',
		).toBeGreaterThanOrEqual(400)
	})
})
