import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A WORD FROM AN ATTACHED FILE FINDS THE FILE, AND THE HIT LEADS TO ITS OBJECT.
 *
 * Change `content-search-index`, task 3.2: attach a text file to an object,
 * search a word that is only in the file, and see a file hit that links to
 * the object. Driven through Nextcloud's own unified search endpoint
 * (`/ocs/v2.php/search/providers/...`), which is what the header search box
 * calls, so a provider that Nextcloud refuses to load fails here.
 *
 * Over HTTP as two real accounts: `e2e-owner` reads the schema, `e2e-other`
 * does not, and the admin sets up and cleans up.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema, object and
 * file and removes them. The accounts come from tests/e2e/ci/seed.sh. The
 * extraction is asked for directly (`POST /api/files/{id}/extract`) rather
 * than waiting for the background job, so the run does not depend on cron.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const OWNER = process.env.E2E_OWNER_USER || 'e2e-owner'
const OTHER = process.env.E2E_OTHER_USER || 'e2e-other'
const PASS = process.env.E2E_SEED_PASSWORD || 'E2e-Share-Pass-123'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const SEARCH = '/ocs/v2.php/search/providers/openregister_objects/search'

// A word that is in the file and nowhere else: not in the object, not in the
// file name. The run id keeps two runs on one instance from finding each other.
const WORD = `kwartelkoning${RUN}`
const FILE_NAME = `notitie-${RUN}.txt`
const OBJECT_TITLE = `Dossier Zonnebloem ${RUN}`

/** One unified-search entry, as the OCS endpoint answers it. */
interface SearchEntry {
	title: string
	subline: string
	resourceUrl: string
	attributes: Record<string, string>
}

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** Search through Nextcloud's unified search, and fail loudly on a refusal. */
async function search(ctx: APIRequestContext, params: Record<string, string>): Promise<SearchEntry[]> {
	const res = await ctx.get(SEARCH, { params: { format: 'json', ...params } })
	expect(res.status(), `unified search refused: ${await res.text()}`).toBe(200)
	const body = await res.json()
	return body.ocs.data.entries as SearchEntry[]
}

test.describe('content-search-index: a word in an attached file finds the file', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let objectId = ''
	let fileId = ''

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		for (const [ctx, uid] of [[owner, OWNER], [other, OTHER]] as const) {
			const res = await ctx.get(`${API}/registers`)
			expect(res.status(), `seeded account '${uid}' cannot authenticate; did seed.sh run?`).toBeLessThan(400)
		}

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e content search ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e content search schema ${RUN}`,
				description: 'e2e',
				properties: { title: { type: 'string', title: 'Title', maxLength: 255 } },
				authorization: {
					create: [`user:${OWNER}`],
					read: [`user:${OWNER}`],
					update: [`user:${OWNER}`],
					delete: [`user:${OWNER}`],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		// The register must own the schema: the cross-table search skips a
		// schema no register lists, as an unattached schema is not a place.
		const own = await admin.patch(`${API}/registers/${registerId}`, {
			data: { schemas: [Number(schemaId)] },
		})
		expect(own.ok(), `attaching the schema failed: ${await own.text()}`).toBeTruthy()

		const obj = await owner.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { title: OBJECT_TITLE },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const objBody = await obj.json()
		objectId = String(objBody['@self']?.id ?? objBody.id)

		const stored = await owner.post(`${API}/objects/${registerId}/${schemaId}/${objectId}/folder/upload`, {
			multipart: {
				path: '',
				'files[]': {
					name: FILE_NAME,
					mimeType: 'text/plain',
					buffer: Buffer.from(`Het besluit over de vergunning noemt de ${WORD} als beschermde soort.`),
				},
			},
		})
		expect(stored.status(), `upload failed: ${await stored.text()}`).toBe(201)
		fileId = String((await stored.json()).stored[0].id)

		const extracted = await admin.post(`${API}/files/${fileId}/extract`)
		expect(extracted.status(), `extraction failed: ${await extracted.text()}`).toBe(200)
	})

	test.afterAll(async () => {
		if (objectId) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${objectId}`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('Nextcloud loads the provider: the provider list answers and names it', async () => {
		const res = await owner.get('/ocs/v2.php/search/providers', { params: { format: 'json' } })
		expect(res.status(), await res.text()).toBe(200)
		const ids = ((await res.json()).ocs.data as Array<{ id: string }>).map((p) => p.id)
		expect(ids).toContain('openregister_objects')
	})

	test('a word only in the file answers a file hit that links to the object', async () => {
		const entries = await search(owner, { term: WORD })
		const fileHits = entries.filter((e) => e.attributes.kind === 'file')

		expect(fileHits, JSON.stringify(entries)).toHaveLength(1)
		const hit = fileHits[0]
		expect(hit.title).toBe(FILE_NAME)
		expect(hit.attributes.fileId).toBe(fileId)
		expect(hit.attributes.fileName).toBe(FILE_NAME)
		expect(hit.attributes.backend).toBe('database')
		// The excerpt around the word, and the object the file belongs to.
		expect(hit.subline).toContain(WORD)
		expect(hit.subline).toContain(OBJECT_TITLE)
		// The link leads to the owning object, not to the file.
		expect(hit.resourceUrl).toContain(objectId)

		// The object itself does not contain the word, so it is not listed as a hit.
		expect(entries.filter((e) => e.attributes.kind === 'object')).toHaveLength(0)
	})

	test('the files scope answers file hits only; the object is still found by its own title', async () => {
		const scoped = await search(owner, { term: WORD, scopes: 'files' })
		expect(scoped.map((e) => e.attributes.kind)).toEqual(['file'])

		const byTitle = await search(owner, { term: `Zonnebloem ${RUN}` })
		const objectHits = byTitle.filter((e) => e.attributes.kind === 'object')
		expect(objectHits.map((e) => e.resourceUrl).join(' ')).toContain(objectId)
	})

	test('somebody who may not read the object gets no hit for the word in its file', async () => {
		const entries = await search(other, { term: WORD })
		expect(entries, JSON.stringify(entries)).toHaveLength(0)
	})
})
