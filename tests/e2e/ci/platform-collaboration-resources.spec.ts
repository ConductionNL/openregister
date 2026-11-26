/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A CASE JOINS A COLLECTION, SEES THE FOLDER BESIDE IT, AND A READER CANNOT TAKE IT OUT.
 *
 * Change `platform-collaboration-resources`, task 2.1. A `zaak` schema opts its
 * objects into Nextcloud collections with `configuration.collaborationResources`.
 * The administrator makes a folder, starts a collection on it through core's
 * collaboration API, and adds a case to it through
 * `POST /api/objects/{register}/{schema}/{id}/collections`. Then:
 *   - the case's collections list the folder beside it;
 *   - core's collection holds the case with its name, icon and link;
 *   - a colleague who may read the case but not update it is refused (403)
 *     when removing it, and the case stays in the collection;
 *   - an object of a schema that declares nothing is not let in (400).
 *
 * HERMETIC BY CONSTRUCTION. It creates its own colleague, folder, register,
 * schemas, objects and collection, and removes them in afterAll.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { randomBytes } from 'crypto'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = randomBytes(4).toString('hex')
const API = '/index.php/apps/openregister/api'
const COLLABORATION = '/ocs/v2.php/collaboration/resources'
const READER = `e2e-coll-${RUN}`
const READER_PASS = 'E2e-Coll-Pass-2026'
const FOLDER = `E2E zaak ${RUN}`
const AUTHORIZATION = { read: ['authenticated'], create: ['admin'], update: ['admin'], delete: ['admin'] }

/** An API context for one account. */
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

test.describe.configure({ mode: 'serial' })

test.describe('platform-collaboration-resources: a case in a collection', () => {
	let admin: APIRequestContext
	let reader: APIRequestContext
	let registerId = 0
	let zaakSchema = 0
	let plainSchema = 0
	let zaak = ''
	let plain = ''
	let folderId = ''
	let collectionId = 0
	let readerCreated = false

	/** Create a schema with the given configuration. */
	async function createSchema(slug: string, configuration: Record<string, unknown>): Promise<number> {
		const res = await admin.post(`${API}/schemas`, {
			data: { slug, title: slug, properties: { titel: { type: 'string' } }, configuration, authorization: AUTHORIZATION },
		})
		expect(res.status(), await res.text()).toBeLessThanOrEqual(201)
		return (await res.json()).id
	}

	/** Create one object and return its uuid. */
	async function createObject(schemaId: number, titel: string): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { titel } })
		expect(res.status(), await res.text()).toBeLessThanOrEqual(201)
		return String((await res.json())['@self'].id)
	}

	/** The resources of the collection, as core's collaboration API answers them. */
	async function collectionResources(): Promise<Array<Record<string, any>>> {
		const res = await admin.get(`${COLLABORATION}/collections/${collectionId}?format=json`)
		expect(res.status(), await res.text()).toBe(200)
		return (await res.json()).ocs.data.resources
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		reader = await contextFor(READER, READER_PASS)

		const user = await admin.post('/ocs/v1.php/cloud/users?format=json', { data: { userid: READER, password: READER_PASS } })
		expect(user.status(), await user.text()).toBe(200)
		readerCreated = true

		const register = await admin.post(`${API}/registers`, { data: { slug: `e2e-coll-${RUN}`, title: `E2E collections ${RUN}` } })
		expect(register.status(), await register.text()).toBeLessThanOrEqual(201)
		registerId = (await register.json()).id

		zaakSchema = await createSchema(`e2e-zaak-${RUN}`, { collaborationResources: true, objectNameField: 'titel' })
		plainSchema = await createSchema(`e2e-plain-${RUN}`, { objectNameField: 'titel' })

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { slug: `e2e-coll-${RUN}`, title: `E2E collections ${RUN}`, schemas: [zaakSchema, plainSchema] },
		})
		expect(linked.status(), await linked.text()).toBe(200)

		zaak = await createObject(zaakSchema, `Zaak ${RUN}`)
		plain = await createObject(plainSchema, `Plain ${RUN}`)

		const folder = await admin.fetch(`/remote.php/dav/files/${ADMIN}/${encodeURIComponent(FOLDER)}`, { method: 'MKCOL' })
		expect(folder.status(), 'MKCOL the folder').toBe(201)
		const props = await admin.fetch(`/remote.php/dav/files/${ADMIN}/${encodeURIComponent(FOLDER)}`, {
			method: 'PROPFIND',
			headers: { Depth: '0', 'Content-Type': 'application/xml' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		folderId = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(await props.text())?.[1] ?? ''
		expect(folderId, 'the folder has a file id').not.toBe('')

		const collection = await admin.post(`${COLLABORATION}/file/${folderId}?format=json`, { data: { name: `E2E project ${RUN}` } })
		expect(collection.status(), await collection.text()).toBe(200)
		collectionId = (await collection.json()).ocs.data.id
	})

	test.afterAll(async () => {
		if (zaak && collectionId) {
			await admin.delete(`${API}/objects/${registerId}/${zaakSchema}/${zaak}/collections/${collectionId}`)
		}
		if (folderId && collectionId) {
			await admin.delete(`${COLLABORATION}/collections/${collectionId}?format=json`, { data: { resourceType: 'file', resourceId: folderId } })
		}
		if (folderId) {
			await admin.fetch(`/remote.php/dav/files/${ADMIN}/${encodeURIComponent(FOLDER)}`, { method: 'DELETE' })
		}
		for (const [schemaId, uuid] of [[zaakSchema, zaak], [plainSchema, plain]] as const) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			}
		}
		for (const schemaId of [zaakSchema, plainSchema].filter(Boolean)) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (readerCreated) {
			await admin.delete(`/ocs/v1.php/cloud/users/${READER}?format=json`)
		}
		await admin?.dispose()
		await reader?.dispose()
	})

	// @e2e platform-collaboration-resources::a-case-joins-the-project-it-belongs-to
	// @e2e platform-collaboration-resources::the-case-page-can-show-the-folder-and-the-conversation
	test('a case joins the collection and sees the folder beside it', async () => {
		const added = await admin.post(`${API}/objects/${registerId}/${zaakSchema}/${zaak}/collections`, { data: { collectionId } })
		expect(added.status(), await added.text()).toBe(200)

		const read = await admin.get(`${API}/objects/${registerId}/${zaakSchema}/${zaak}/collections`)
		expect(read.status(), await read.text()).toBe(200)
		const collections = (await read.json()).results as Array<Record<string, any>>
		const mine = collections.find((entry) => entry.id === collectionId)
		expect(mine, 'the case lists the collection').toBeTruthy()
		expect(mine!.members).toEqual(expect.arrayContaining([expect.objectContaining({ type: 'file', id: folderId, name: FOLDER })]))

		const resources = await collectionResources()
		const resource = resources.find((entry) => entry.type === 'openregister-object')
		expect(resource, 'core\'s collection holds the case').toBeTruthy()
		expect(resource!.id).toBe(`${registerId}/${zaakSchema}/${zaak}`)
		expect(resource!.name).toBe(`Zaak ${RUN}`)
		expect(String(resource!.link)).toContain(zaak)
		expect(String(resource!.iconUrl)).not.toBe('')
	})

	// @e2e platform-collaboration-resources::a-reader-without-update-rights-cannot-change-membership
	test('a colleague who may read the case but not update it cannot take it out', async () => {
		const readable = await reader.get(`${API}/objects/${registerId}/${zaakSchema}/${zaak}`)
		expect(readable.status(), 'the colleague may read the case').toBe(200)

		const removed = await reader.delete(`${API}/objects/${registerId}/${zaakSchema}/${zaak}/collections/${collectionId}`)
		expect(removed.status(), await removed.text()).toBe(403)

		expect((await collectionResources()).some((entry) => entry.type === 'openregister-object'), 'the case is still in the collection').toBe(true)
	})

	test('an object of a schema that declares nothing is not let in', async () => {
		const added = await admin.post(`${API}/objects/${registerId}/${plainSchema}/${plain}/collections`, { data: { collectionId } })
		expect(added.status(), await added.text()).toBe(400)
		expect((await collectionResources()).filter((entry) => entry.type === 'openregister-object')).toHaveLength(1)
	})
})
