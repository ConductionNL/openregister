import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AN OBJECT'S FOLDER, SUBFOLDERS INCLUDED, THROUGH THE OBJECT'S OWN RULE.
 *
 * Scenario anchors (change `object-folder-in-files-browser`, spec `file-actions`):
 *
 * @e2e file-actions::a-reader-browses-the-case-folder
 * @e2e file-actions::access-ends-on-the-next-request
 * @e2e file-actions::no-mirror-share
 * @e2e file-actions::a-reader-lists-a-subfolder
 * @e2e file-actions::a-person-who-may-not-read-the-case-gets-404
 * @e2e file-actions::a-path-cannot-leave-the-object-folder
 * @e2e file-actions::a-person-who-may-update-the-case-adds-a-subfolder-and-a-file-in-it
 * @e2e file-actions::a-reader-may-not-change-the-folder
 * @e2e file-actions::a-taken-name-is-refused
 * @e2e file-actions::a-node-of-another-object-is-not-reachable
 * @e2e file-actions::an-updater-renames-and-deletes-a-subfolder
 *
 * Over HTTP, as three real accounts: `e2e-owner` may read and update the
 * objects, `e2e-other` may read but not update until the schema stops granting
 * it read, and the admin sets up and cleans up. A route missing from
 * `appinfo/routes.php`, or a guard that only a unit test's fake honours, fails
 * here and nowhere else.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects and
 * removes them. It needs no `occ` and no docker; the two accounts come from
 * tests/e2e/ci/seed.sh.
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

type Entry = { id: number, name: string, type: string, path: string }

test.describe.configure({ mode: 'serial' })

test.describe('an object folder through the object rule', () => {
	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string
	let caseA: string
	let caseB: string
	let caseBFileId: number
	let folderId: number

	const authorization = (readers: string[]) => ({
		create: ['authenticated'],
		read: readers,
		update: [`user:${OWNER}`],
		delete: [`user:${OWNER}`],
	})

	const folderUrl = (uuid: string, suffix = '') => `${API}/objects/${registerId}/${schemaId}/${uuid}/folder${suffix}`

	/** Upload one text file into a folder of an object. */
	async function upload(ctx: APIRequestContext, uuid: string, path: string, name: string, body: string) {
		return ctx.post(folderUrl(uuid, '/upload'), {
			multipart: {
				path,
				'files[]': { name, mimeType: 'text/plain', buffer: Buffer.from(body) },
			},
		})
	}

	/** List a folder of an object as one user. */
	async function list(ctx: APIRequestContext, uuid: string, path = '') {
		return ctx.get(folderUrl(uuid), { params: { path } })
	}

	/** Create an object as the owner. */
	async function createObject(key: string): Promise<string> {
		const res = await owner.post(`${API}/objects/${registerId}/${schemaId}`, { data: { key } })
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		return String(body['@self']?.id ?? body.id ?? body.uuid)
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		for (const [ctx, uid] of [[owner, OWNER], [other, OTHER]] as const) {
			const res = await ctx.get(`${API}/registers`)
			expect(res.status(), `seeded account '${uid}' cannot authenticate; did seed.sh run?`).toBeLessThan(400)
		}

		const reg = await admin.post(`${API}/registers`, { data: { title: `e2e object folder ${RUN}`, description: 'e2e' } })
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e object folder schema ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: authorization([`user:${OWNER}`, `user:${OTHER}`]),
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		caseA = await createObject('case-a')
		caseB = await createObject('case-b')

		const stored = await upload(owner, caseB, '', 'van-b.txt', 'case b')
		expect(stored.status(), `upload to case B failed: ${await stored.text()}`).toBe(201)
		caseBFileId = (await stored.json()).stored[0].id
	})

	test.afterAll(async () => {
		for (const uuid of [caseA, caseB].filter(Boolean)) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		}
		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('an updater adds a subfolder and a file in it, and downloads it', async () => {
		const made = await owner.post(folderUrl(caseA), { data: { name: 'Bijlagen', path: '' } })
		expect(made.status(), await made.text()).toBe(201)
		folderId = (await made.json()).id

		expect((await upload(owner, caseA, '', 'besluit.txt', 'besluit')).status()).toBe(201)
		const stored = await upload(owner, caseA, 'Bijlagen', 'brief.txt', 'hallo bea')
		expect(stored.status(), await stored.text()).toBe(201)
		const file = (await stored.json()).stored[0] as Entry
		expect(file.path).toBe('Bijlagen/brief.txt')

		const download = await owner.get(`${API}/objects/${registerId}/${schemaId}/${caseA}/files/${file.id}`)
		expect(download.status()).toBe(200)
		expect(await download.text()).toBe('hallo bea')
	})

	test('a reader browses the case folder and its subfolder, and may not change it', async () => {
		const root = await list(other, caseA)
		expect(root.status(), await root.text()).toBe(200)
		const rootBody = await root.json()
		expect(rootBody.canChange).toBe(false)
		expect((rootBody.entries as Entry[]).map((e) => `${e.type}:${e.name}`).sort()).toEqual(['file:besluit.txt', 'folder:Bijlagen'])

		const sub = await list(other, caseA, 'Bijlagen')
		expect(sub.status()).toBe(200)
		const subEntries = (await sub.json()).entries as Entry[]
		expect(subEntries.map((e) => e.path)).toEqual(['Bijlagen/brief.txt'])

		// The reader downloads through the object's file endpoint, as herself.
		const download = await other.get(`${API}/objects/${registerId}/${schemaId}/${caseA}/files/${subEntries[0].id}`)
		expect(download.status()).toBe(200)
		expect(await download.text()).toBe('hallo bea')

		// And may change nothing.
		expect((await other.post(folderUrl(caseA), { data: { name: 'Nieuw' } })).status()).toBe(403)
		expect((await upload(other, caseA, 'Bijlagen', 'nee.txt', 'x')).status()).toBe(403)
		expect((await other.put(folderUrl(caseA, `/${folderId}`), { data: { name: 'Anders' } })).status()).toBe(403)
		expect((await other.delete(folderUrl(caseA, `/${folderId}`))).status()).toBe(403)
		const after = await list(owner, caseA)
		expect(((await after.json()).entries as Entry[]).map((e) => e.name).sort()).toEqual(['Bijlagen', 'besluit.txt'])
	})

	test('no share is created for the reader', async () => {
		const shares = await other.get('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
			params: { shared_with_me: 'true', format: 'json' },
		})
		expect(shares.status()).toBe(200)
		const data = ((await shares.json()).ocs?.data ?? []) as Array<{ path?: string, file_target?: string }>
		const mirrors = data.filter((share) => String(share.path ?? share.file_target ?? '').includes(caseA))
		expect(mirrors, 'a share on the case folder reached the reader').toEqual([])
	})

	test('a path cannot leave the object folder', async () => {
		for (const path of ['..', `../${caseB}`, 'Bijlagen/../..', './Bijlagen']) {
			const res = await list(other, caseA, path)
			expect(res.status(), `path ${path}`).toBe(400)
		}
		expect((await list(other, caseA, 'besluit.txt')).status()).toBe(404)
		expect((await upload(owner, caseA, '../' + caseB, 'x.txt', 'x')).status()).toBe(400)
	})

	test('a taken name is refused', async () => {
		expect((await owner.post(folderUrl(caseA), { data: { name: 'Bijlagen' } })).status()).toBe(409)
		expect((await upload(owner, caseA, 'Bijlagen', 'brief.txt', 'overschreven')).status()).toBe(409)
	})

	test('a node of another object is not reachable', async () => {
		expect((await owner.put(folderUrl(caseA, `/${caseBFileId}`), { data: { name: 'gestolen.txt' } })).status()).toBe(404)
		expect((await owner.delete(folderUrl(caseA, `/${caseBFileId}`))).status()).toBe(404)
		const b = await list(owner, caseB)
		expect(((await b.json()).entries as Entry[]).map((e) => e.name)).toEqual(['van-b.txt'])
	})

	test('an updater renames and deletes a subfolder', async () => {
		const renamed = await owner.put(folderUrl(caseA, `/${folderId}`), { data: { name: 'Stukken' } })
		expect(renamed.status(), await renamed.text()).toBe(200)
		let names = ((await (await list(owner, caseA)).json()).entries as Entry[]).map((e) => e.name).sort()
		expect(names).toEqual(['Stukken', 'besluit.txt'])
		expect((await list(owner, caseA, 'Stukken')).status()).toBe(200)

		expect((await owner.delete(folderUrl(caseA, `/${folderId}`))).status()).toBe(200)
		names = ((await (await list(owner, caseA)).json()).entries as Entry[]).map((e) => e.name)
		expect(names).toEqual(['besluit.txt'])
	})

	test('access ends on the next request once the rule no longer admits the reader', async () => {
		const sch = await admin.put(`${API}/schemas/${schemaId}`, {
			data: {
				title: `e2e object folder schema ${RUN}`,
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: authorization([`user:${OWNER}`]),
			},
		})
		expect(sch.ok(), `schema update failed: ${await sch.text()}`).toBeTruthy()

		const res = await list(other, caseA)
		expect(res.status()).toBe(404)
		expect(await res.text()).not.toContain('besluit.txt')
		expect((await list(other, caseA, 'Stukken')).status()).toBe(404)
	})
})
