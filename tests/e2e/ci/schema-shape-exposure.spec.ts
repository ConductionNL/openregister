import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A PROPERTY'S NAME IS DESCRIBED ONLY TO A CALLER WHO MAY READ IT.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e rbac-scopes::a-name-is-information
 * @e2e rbac-scopes::the-document-stays-satisfiable
 * @e2e rbac-scopes::there-is-more-here-and-it-is-not-yours
 * @e2e rbac-scopes::an-administrator-sees-the-schema
 *
 * The register's generated OpenAPI document (`GET /api/registers/{id}/oas`)
 * is read by three principals: an administrator, a signed-in colleague in
 * the group one property is readable by, and an anonymous client. Each gets
 * a different document, and the difference is asserted for each.
 *
 * 🔴 THE WHOLE DOCUMENT IS SEARCHED, NOT ONLY THE SCHEMA. The first run of
 * this spec found the withheld names in the list operation's filter
 * parameters ("Filter results by salaris"), which the unit tests never looked
 * at because they read the described schema alone. So every assertion of
 * absence here is over the serialised document.
 *
 * HERMETIC. It creates its own register and schema and removes them. The
 * colleague and the group are the ones the CI seed provisions
 * (tests/e2e/ci/seed.sh).
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded colleague, the only member of the seeded group. */
const COLLEAGUE = 'e2e-other'
const COLLEAGUE_PASS = 'E2e-Share-Pass-123'
const GROUP = 'e2e-grantees'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** Distinctive names and values, so a search of the document cannot hit anything else. */
const ADMIN_ONLY = `salaris${RUN}`
const ADMIN_ONLY_EXAMPLE = 'voorbeeldbedrag-9481'
const TEAM_ONLY = `teamnotitie${RUN}`
const TEAM_ONLY_ENUM = 'teamwaarde-7733'

/** An API context; with no user it is an anonymous client. */
async function contextFor(user?: string, password?: string): Promise<APIRequestContext> {
	const headers: Record<string, string> = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	if (user !== undefined && password !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}

	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

test.describe.configure({ mode: 'serial' })

test.describe('the generated API description per principal', () => {
	let admin: APIRequestContext
	let colleague: APIRequestContext
	let anonymous: APIRequestContext
	let registerId = ''
	let schemaId = ''
	const schemaTitle = `e2e shape person ${RUN}`

	/** The document as one principal reads it, and the described schema in it. */
	async function documentFor(
		ctx: APIRequestContext,
	): Promise<{ text: string; described: Record<string, any> }> {
		const res = await ctx.get(`${API}/registers/${registerId}/oas`)
		expect(res.status(), `oas read failed: ${await res.text()}`).toBe(200)
		const text = await res.text()
		const doc = JSON.parse(text)

		const described = Object.values(doc.components?.schemas ?? {}).find(
			(s: any) => s?.properties?.naam !== undefined && (s['x-tags'] ?? []).includes(schemaTitle),
		) as Record<string, any> | undefined
		expect(described, 'the schema is described in the document').toBeTruthy()

		return { text, described: described as Record<string, any> }
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		colleague = await contextFor(COLLEAGUE, COLLEAGUE_PASS)
		anonymous = await contextFor()

		const seeded = await colleague.get(`${API}/registers`)
		expect(seeded.status(), `seeded account '${COLLEAGUE}' cannot authenticate`).toBeLessThan(400)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e shape register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: schemaTitle,
				description: 'e2e',
				properties: {
					naam: { type: 'string', title: 'Naam', maxLength: 128 },
					[ADMIN_ONLY]: {
						type: 'string',
						example: ADMIN_ONLY_EXAMPLE,
						authorization: { read: ['admin'], update: ['admin'] },
					},
					[TEAM_ONLY]: {
						type: 'string',
						enum: [TEAM_ONLY_ENUM],
						authorization: { read: [GROUP], update: [GROUP] },
					},
				},
				required: ['naam', ADMIN_ONLY],
				authorization: { read: ['public'] },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e shape register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()
	})

	test.afterAll(async () => {
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
		await colleague?.dispose()
		await anonymous?.dispose()
	})

	test('an administrator sees the whole schema', async () => {
		const { text, described } = await documentFor(admin)

		expect(Object.keys(described.properties)).toEqual(
			expect.arrayContaining(['naam', ADMIN_ONLY, TEAM_ONLY]),
		)
		expect(described.required).toEqual(['naam', ADMIN_ONLY])
		expect(described['x-openregister-withheld-properties']).toBeUndefined()
		expect(text).toContain(ADMIN_ONLY_EXAMPLE)
	})

	test('a colleague sees their group property, a count for the rest, and no name of it', async () => {
		const { text, described } = await documentFor(colleague)

		expect(Object.keys(described.properties)).toContain(TEAM_ONLY)
		expect(Object.keys(described.properties)).not.toContain(ADMIN_ONLY)
		expect(described.required, 'required names only what the document describes').toEqual(['naam'])
		expect(described['x-openregister-withheld-properties']).toBe(1)

		expect(text, 'the withheld name is nowhere in the document').not.toContain(ADMIN_ONLY)
		expect(text, 'its example left with it').not.toContain(ADMIN_ONLY_EXAMPLE)
		expect(text).toContain(TEAM_ONLY_ENUM)
	})

	test('an anonymous client sees neither governed property', async () => {
		const { text, described } = await documentFor(anonymous)

		expect(Object.keys(described.properties)).toContain('naam')
		expect(described.required).toEqual(['naam'])
		expect(described['x-openregister-withheld-properties']).toBe(2)

		for (const withheld of [ADMIN_ONLY, ADMIN_ONLY_EXAMPLE, TEAM_ONLY, TEAM_ONLY_ENUM]) {
			expect(text, `${withheld} is nowhere in the document`).not.toContain(withheld)
		}
	})
})
