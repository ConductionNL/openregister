/**
 * The OpenRegister adapter plugin of the object store: list scope, query
 * parameters, the answer shape, the type slug and the mass delete.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useObjectStore } from './object.js'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

/**
 * Select a register and schema in their stores.
 *
 * @param {number|null} registerId Register id.
 * @param {number|null} schemaId Schema id.
 */
function select(registerId, schemaId) {
	useRegisterStore().registerItem = registerId ? { id: registerId } : false
	useSchemaStore().schemaItem = schemaId ? { id: schemaId, title: 'S' } : false
}

describe('object store adapter', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('currentType joins the active register and schema ids', () => {
		const store = useObjectStore()
		expect(store.currentType).toBe('')
		select(27, null)
		expect(store.currentType).toBe('27')
		select(27, 116)
		expect(store.currentType).toBe('27-116')
	})

	it('activeSchema proxies the schema store item', () => {
		select(1, 2)
		expect(useObjectStore().activeSchema).toEqual({ id: 2, title: 'S' })
	})

	it('massDeleteObject refuses without a register and schema', async () => {
		const store = useObjectStore()
		store.deleteObjects = jest.fn()
		await expect(store.massDeleteObject(['a'])).rejects.toThrow('Register and schema are required.')
		expect(store.deleteObjects).not.toHaveBeenCalled()
	})

	it('massDeleteObject resolves ids from strings, numbers and objects and drops empty ones', async () => {
		select(27, 116)
		const store = useObjectStore()
		store.deleteObjects = jest.fn(async () => ({ successfulIds: ['x'], failedIds: [] }))
		const result = await store.massDeleteObject(['a', 5, { id: 'b' }, { '@self': { id: 'c' } }, {}, null, undefined, ''])
		expect(store.deleteObjects).toHaveBeenCalledWith('27-116', ['a', '5', 'b', 'c'])
		expect(result).toEqual({ successfulIds: ['x'], failedIds: [] })
	})

	it('massDeleteObject accepts a single id', async () => {
		select(1, 2)
		const store = useObjectStore()
		store.deleteObjects = jest.fn(async () => ({}))
		await store.massDeleteObject('only')
		expect(store.deleteObjects).toHaveBeenCalledWith('1-2', ['only'])
	})

	it('massDeleteObject refuses a selection with no usable id', async () => {
		select(1, 2)
		const store = useObjectStore()
		store.deleteObjects = jest.fn()
		await expect(store.massDeleteObject([null, {}, ''])).rejects.toThrow('No deletable object ids could be resolved from the selection.')
		await expect(store.massDeleteObject()).rejects.toThrow('No deletable object ids')
		expect(store.deleteObjects).not.toHaveBeenCalled()
	})

	it('refreshObjectList refuses without a register and schema', async () => {
		await expect(useObjectStore().refreshObjectList()).rejects.toThrow('Register and schema are required.')
		select(1, null)
		await expect(useObjectStore().refreshObjectList()).rejects.toThrow('Register and schema are required.')
	})

	it('refreshObjectList registers the type once and passes filters and set options', async () => {
		select(27, 116)
		const store = useObjectStore()
		store.registerObjectType = jest.fn()
		store.fetchCollection = jest.fn(async () => [{ id: 'o1' }])
		store.getPagination = jest.fn(() => ({ total: 41, page: 3, pages: 5, limit: 10 }))
		store.setFilters({ status: 'open' })
		store.setFilters({ owner: 'me' })
		const answer = await store.refreshObjectList({ limit: 10, page: 3, search: null })
		expect(store.registerObjectType).toHaveBeenCalledWith('27-116', 116, 27)
		expect(store.fetchCollection).toHaveBeenCalledWith('27-116', { status: 'open', owner: 'me', _limit: 10, _page: 3 })
		expect(store.getPagination).toHaveBeenCalledWith('27-116')
		expect(answer).toEqual({
			response: {},
			data: { results: [{ id: 'o1' }], total: 41, page: 3, pages: 5, limit: 10, offset: 20 },
		})
	})

	it('refreshObjectList prefers the option ids and skips registering a known type', async () => {
		select(1, 2)
		const store = useObjectStore()
		store.objectTypes.push('8-9')
		store.registerObjectType = jest.fn()
		store.fetchCollection = jest.fn(async () => [])
		await store.refreshObjectList({ register: 8, schema: 9, search: 'zoek' })
		expect(store.registerObjectType).not.toHaveBeenCalled()
		expect(store.fetchCollection).toHaveBeenCalledWith('8-9', { _search: 'zoek' })
	})

	it('setters store and clear the item and audit trail item; stubs are no-ops', () => {
		const store = useObjectStore()
		store.setObjectItem({ id: 1 })
		store.setAuditTrailItem({ id: 2 })
		expect(store.objectItem).toEqual({ id: 1 })
		expect(store.auditTrailItem).toEqual({ id: 2 })
		store.setObjectItem(null)
		store.setAuditTrailItem(undefined)
		expect(store.objectItem).toBe(false)
		expect(store.auditTrailItem).toBe(false)
		expect(store.initializeColumnFilters()).toBeUndefined()
		expect(store.initializeProperties()).toBeUndefined()
	})
})
