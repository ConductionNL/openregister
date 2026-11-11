/**
 * Request and state behaviour of the schema store's actions.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { deleteSchema as apiDeleteSchema, saveSchema as apiSaveSchema } from '@conduction/nextcloud-vue'
import { createPinia, setActivePinia } from 'pinia'
import { Schema } from '../../entities/index.js'
import { useSchemaStore } from './schema.js'

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	...jest.requireActual('../../../tests/__mocks__/conduction-nextcloud-vue.js'),
	deleteSchema: jest.fn(),
	saveSchema: jest.fn(),
}))

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const BASE = '/index.php/apps/openregister/api/schemas'

/**
 * Build a fetch Response stand-in.
 *
 * @param {object} body JSON body.
 * @param {number} status HTTP status.
 * @return {object} Response-like object.
 */
function reply(body, status = 200) {
	return { ok: status >= 200 && status < 300, status, statusText: `S${status}`, json: async () => body }
}

/**
 * Install a fetch mock answering per "METHOD url"; the list GET always answers.
 *
 * @param {object} routes Map of "METHOD url" to a reply or an Error.
 * @return {jest.Mock} The fetch mock.
 */
function mockFetch(routes = {}) {
	const fn = jest.fn(async (url, opts = {}) => {
		const key = `${opts.method || 'GET'} ${url}`
		if (key in routes) {
			if (routes[key] instanceof Error) throw routes[key]
			return routes[key]
		}
		if (key === `GET ${BASE}`) return reply({ results: [{ id: 1, title: 'Listed', properties: [] }] })
		throw new Error(`unexpected ${key}`)
	})
	global.fetch = fn
	return fn
}

describe('schema store requests', () => {
	let errSpy
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		errSpy.mockRestore()
		delete global.fetch
	})

	it('exposes the view mode and stores the property key', () => {
		const store = useSchemaStore()
		store.setViewMode('table')
		store.setSchemaPropertyKey('p-1')
		expect(store.getViewMode).toBe('table')
		expect(store.schemaPropertyKey).toBe('p-1')
	})

	it('setSchemaList keeps a row\'s showProperties toggle and normalises array properties', () => {
		const store = useSchemaStore()
		store.setSchemaList([{ id: 1, properties: [] }, { id: 2, properties: { a: {} } }, { id: 3 }])
		store.schemaList[0].showProperties = true
		store.setSchemaList([{ id: 1, properties: [] }, { id: 2, properties: { a: {} } }, { id: 3 }])
		expect(store.schemaList.map((s) => s.showProperties)).toEqual([true, false, false])
		expect(store.schemaList[0].properties).toEqual({})
		expect(store.schemaList[1].properties).toEqual({ a: {} })
		expect(store.schemaList[2].properties).toEqual({})
	})

	it('getSchema normalises array properties and sets the item when asked', async () => {
		const fetchMock = mockFetch({ [`GET ${BASE}/5`]: reply({ id: 5, title: 'Five', properties: [] }) })
		const store = useSchemaStore()
		const data = await store.getSchema(5)
		expect(fetchMock).toHaveBeenCalledWith(`${BASE}/5`, { method: 'GET' })
		expect(data.properties).toEqual({})
		expect(store.schemaItem).toBe(false)
		await store.getSchema(5, { setItem: true })
		expect(store.schemaItem).toBeInstanceOf(Schema)
		expect(store.schemaItem.title).toBe('Five')
	})

	it('getSchema keeps object properties and rethrows a failure', async () => {
		mockFetch({ [`GET ${BASE}/6`]: reply({ id: 6, properties: { a: { type: 'string' } } }) })
		const store = useSchemaStore()
		expect((await store.getSchema(6)).properties).toEqual({ a: { type: 'string' } })
		mockFetch({ [`GET ${BASE}/6`]: new Error('gone') })
		await expect(store.getSchema(6)).rejects.toThrow('gone')
	})

	it('getSchemaStats returns the stats and rethrows a failure', async () => {
		mockFetch({ [`GET ${BASE}/5/stats`]: reply({ objectCount: 3 }) })
		expect(await useSchemaStore().getSchemaStats(5)).toEqual({ objectCount: 3 })
		mockFetch({ [`GET ${BASE}/5/stats`]: new Error('x') })
		await expect(useSchemaStore().getSchemaStats(5)).rejects.toThrow('x')
	})

	it('deleteSchema refuses without an id', async () => {
		await expect(useSchemaStore().deleteSchema({})).rejects.toThrow('No schema item to delete')
		expect(apiDeleteSchema).not.toHaveBeenCalled()
	})

	it('deleteSchema never cascades unless asked, then clears the item', async () => {
		mockFetch()
		apiDeleteSchema.mockResolvedValue({ deleted: true })
		const store = useSchemaStore()
		store.setSchemaItem({ id: 4, title: 'Four' })
		const result = await store.deleteSchema({ id: 4 })
		expect(apiDeleteSchema).toHaveBeenCalledWith(4, { deleteObjects: false })
		expect(result).toEqual({ response: { ok: true }, data: { deleted: true } })
		expect(store.schemaItem).toBeNull()
		await store.deleteSchema({ id: 4 }, { deleteObjects: true })
		expect(apiDeleteSchema).toHaveBeenLastCalledWith(4, { deleteObjects: true })
	})

	it('saveSchemaDraft refuses a schema without an id', async () => {
		await expect(useSchemaStore().saveSchemaDraft({ title: 'new' })).rejects.toThrow('Only a saved schema can hold a draft')
		await expect(useSchemaStore().saveSchemaDraft(null)).rejects.toThrow('Only a saved schema can hold a draft')
	})

	it('saveSchema refuses without an item', async () => {
		await expect(useSchemaStore().saveSchema(null)).rejects.toThrow('No schema item to save')
	})

	it('saveSchema sends the cleaned schema and stores the answer', async () => {
		mockFetch()
		apiSaveSchema.mockResolvedValue({ id: 9, title: 'Saved' })
		const store = useSchemaStore()
		const { data } = await store.saveSchema({ id: 9, title: 'Saved', stats: {}, version: '1' }, { acknowledgeBreaking: true })
		const [sent, opts] = apiSaveSchema.mock.calls[0]
		expect(sent.stats).toBeUndefined()
		expect(sent.version).toBeUndefined()
		expect(sent.configuration).toEqual({ objectNameField: '', objectDescriptionField: '', objectSlugField: '', objectSlugSuffix: false })
		expect(opts).toEqual({ id: 9, acknowledgeBreaking: true })
		expect(data).toBeInstanceOf(Schema)
		expect(store.schemaItem.title).toBe('Saved')
	})

	it('saveSchema never acknowledges a breaking change by default and refuses a non-object answer', async () => {
		apiSaveSchema.mockResolvedValue('nope')
		await expect(useSchemaStore().saveSchema({ title: 'x' })).rejects.toThrow('Invalid response data')
		expect(apiSaveSchema.mock.calls[0][1]).toEqual({ id: undefined, acknowledgeBreaking: false })
		apiSaveSchema.mockResolvedValue(null)
		await expect(useSchemaStore().saveSchema({ title: 'x' })).rejects.toThrow('Invalid response data')
	})

	it('cleanSchemaForSave drops an empty id, keeps a configuration and moves required onto properties', () => {
		const cleaned = useSchemaStore().cleanSchemaForSave({
			id: '', draft: {}, archive: {}, created: 'c', updated: 'u',
			configuration: { objectNameField: 'name' },
			properties: { name: {}, age: {} },
			required: ['name', 'missing'],
		})
		expect(cleaned).toEqual({
			configuration: { objectNameField: 'name' },
			properties: { name: { required: true }, age: {} },
		})
	})

	it('uploadSchema posts a new schema and puts onto the current one', async () => {
		const fetchMock = mockFetch({
			[`POST ${BASE}/upload`]: reply({ id: 11, title: 'Up' }),
			[`PUT ${BASE}/upload/11`]: reply({ id: 11, title: 'Up2' }),
		})
		const store = useSchemaStore()
		const first = await store.uploadSchema({ url: 'x' })
		expect(fetchMock.mock.calls[0]).toEqual([`${BASE}/upload`, {
			method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url: 'x' }),
		}])
		expect(first.data.title).toBe('Up')
		const second = await store.uploadSchema({ url: 'y' })
		expect(second.data.title).toBe('Up2')
		expect(store.schemaItem.title).toBe('Up2')
	})

	it('uploadSchema refuses without a schema', async () => {
		await expect(useSchemaStore().uploadSchema(null)).rejects.toThrow('No schema item to upload')
	})

	it('downloadSchema refuses a missing, non-entity or id-less schema', async () => {
		const store = useSchemaStore()
		await expect(store.downloadSchema(null)).rejects.toThrow('No schema item to download')
		await expect(store.downloadSchema({ id: 1 })).rejects.toThrow('Invalid schema item to download')
		await expect(store.downloadSchema(new Schema({ title: 'T' }))).rejects.toThrow('No schema item ID to download')
	})

	it('downloadSchema writes the JSON into a temporary link and clicks it', async () => {
		const fetchMock = mockFetch({ [`GET ${BASE}/3/download`]: reply({ a: 1 }) })
		URL.createObjectURL = jest.fn(() => 'blob:x')
		URL.revokeObjectURL = jest.fn()
		const clicks = []
		const clickSpy = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			clicks.push({ href: this.href, download: this.download, attached: document.body.contains(this) })
		})
		await useSchemaStore().downloadSchema(new Schema({ id: 3, title: 'Three' }))
		expect(fetchMock.mock.calls[0][1].headers).toEqual({ 'Content-Type': 'application/json' })
		expect(clicks).toEqual([{ href: 'blob:x', download: 'Three.json', attached: true }])
		expect(URL.createObjectURL.mock.calls[0][0].type).toBe('application/json')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		expect(document.querySelectorAll('a')).toHaveLength(0)
		clickSpy.mockRestore()
	})

	it('downloadSchema throws the status text of a failed answer', async () => {
		mockFetch({ [`GET ${BASE}/3/download`]: reply({}, 404) })
		await expect(useSchemaStore().downloadSchema(new Schema({ id: 3, title: 'T' }))).rejects.toThrow('S404')
	})

	it('exploreSchemaProperties returns the result, refuses an error body and a failed answer', async () => {
		mockFetch({ [`GET ${BASE}/2/explore`]: reply({ found: ['x'] }) })
		const store = useSchemaStore()
		expect(await store.exploreSchemaProperties(2)).toEqual({ found: ['x'] })
		mockFetch({ [`GET ${BASE}/2/explore`]: reply({ error: 'No objects' }) })
		await expect(store.exploreSchemaProperties(2)).rejects.toThrow('No objects')
		mockFetch({ [`GET ${BASE}/2/explore`]: reply({}, 500) })
		await expect(store.exploreSchemaProperties(2)).rejects.toThrow('HTTP error! status: 500')
	})

	it('updateSchemaFromExploration posts the properties and refreshes the list', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/2/update-from-exploration`]: reply({ updated: 1 }) })
		const store = useSchemaStore()
		expect(await store.updateSchemaFromExploration(2, { a: { type: 'string' } })).toEqual({ updated: 1 })
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ properties: { a: { type: 'string' } } })
		expect(fetchMock).toHaveBeenCalledWith(BASE, { method: 'GET' })
	})

	it('updateSchemaFromExploration refuses an error body', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/2/update-from-exploration`]: reply({ error: 'Bad' }) })
		await expect(useSchemaStore().updateSchemaFromExploration(2, {})).rejects.toThrow('Bad')
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})
})
