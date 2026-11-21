/**
 * ObjectDetails — pagination, live-subscription bookkeeping and relation
 * helpers, exercised on the options object against a synthetic `this`, with
 * the object store mocked.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('../../components/tabs/AppTab.vue', () => mockModule('AppTab'))
jest.mock('../../components/tabs/AppTabs.vue', () => mockModule('AppTabs'))
jest.mock('../../components/access-links/ObjectAccessLinks.vue', () => mockModule('ObjectAccessLinks'))
jest.mock('../../components/object-relations/ContactsTab.vue', () => mockModule('ContactsTab'))
jest.mock('../../components/object-relations/DeckTab.vue', () => mockModule('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () => mockModule('EmailsTab'))
jest.mock('../../components/object-relations/EventsTab.vue', () => mockModule('EventsTab'))
jest.mock('../../components/object-relations/ReferencedByTab.vue', () => mockModule('ReferencedByTab'))
jest.mock('../../components/object-relations/RelationsTab.vue', () => mockModule('RelationsTab'))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
}))

/**
 * Library stand-ins: every named export is a component that draws nothing.
 *
 * @param {object} extra Named exports that are not components
 * @return {object} The module
 */
function mockLibrary(extra = {}) {
	return new Proxy(
		{ __esModule: true, ...extra },
		{
			get: (target, key) => (key in target || typeof key !== 'string' ? target[key] : { name: key, render: () => null }),
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: { name, render: () => null } }
}

const computedCall = (key, ctx) => ObjectDetails.computed[key].call(ctx)
const methodCall = (key, ctx, ...args) => ObjectDetails.methods[key].call(ctx, ...args)

/**
 * A pagination state with the given limits and current pages.
 *
 * @param {number} page The current page of every tab
 * @param {number} limit The page size of every tab
 * @return {object} The pagination
 */
function pagination(page = 1, limit = 200) {
	return {
		files: { limit, currentPage: page },
		auditTrails: { limit, currentPage: page },
		relations: { limit, currentPage: page },
	}
}

beforeEach(() => {
	for (const key of Object.keys(objectStore)) {
		delete objectStore[key]
	}
	jest.clearAllMocks()
})

describe('ObjectDetails — page counts', () => {
	it('counts the pages of files, audit trails and relations, at least one each', () => {
		Object.assign(objectStore, { files: { total: 401 }, auditTrails: { total: 200 }, used: { total: 0 } })
		const ctx = { pagination: pagination() }

		expect(computedCall('filesTotalPages', ctx)).toBe(3)
		expect(computedCall('auditTrailsTotalPages', ctx)).toBe(1)
		expect(computedCall('relationsTotalPages', ctx)).toBe(1)
	})

	it('treats missing totals as one page', () => {
		const ctx = { pagination: pagination(1, 50) }

		expect(computedCall('filesTotalPages', ctx)).toBe(1)
		expect(computedCall('auditTrailsTotalPages', ctx)).toBe(1)
		expect(computedCall('relationsTotalPages', ctx)).toBe(1)
	})
})

describe('ObjectDetails — relation context and live target', () => {
	it('takes register, schema and id from @self, else from the object', () => {
		objectStore.objectItem = { '@self': { register: 'r1', schema: 's1', id: 'o1' } }
		expect(computedCall('relationContext', {})).toEqual({ register: 'r1', schema: 's1', id: 'o1' })

		objectStore.objectItem = { register: 'r2', schema: 's2', uuid: 'u2' }
		expect(computedCall('relationContext', {})).toEqual({ register: 'r2', schema: 's2', id: 'u2' })
	})

	it('has no relation context without an object or a part of its address', () => {
		objectStore.objectItem = null
		expect(computedCall('relationContext', {})).toBeNull()

		objectStore.objectItem = { register: 'r1', id: 'o1' }
		expect(computedCall('relationContext', {})).toBeNull()
	})

	it('aims the live subscription at register-schema and the object uuid', () => {
		objectStore.objectItem = { '@self': { uuid: 'u1' } }
		const ctx = { relationContext: { register: 'r1', schema: 's1', id: 'o1' } }

		expect(methodCall('liveTarget', ctx)).toEqual({
			ctx: ctx.relationContext,
			uuid: 'u1',
			type: 'r1-s1',
			key: 'r1-s1::u1',
		})
	})

	it('has no live target without a relation context', () => {
		objectStore.objectItem = { uuid: 'u1' }

		expect(methodCall('liveTarget', { relationContext: null })).toBeNull()
	})

	it('is live for a key it holds a handle for, or is still subscribing to', () => {
		expect(methodCall('isLiveFor', { liveHandle: 'h', liveKey: 'k', livePendingKey: '' }, 'k')).toBe(true)
		expect(methodCall('isLiveFor', { liveHandle: null, liveKey: 'k', livePendingKey: '' }, 'k')).toBe(false)
		expect(methodCall('isLiveFor', { liveHandle: null, liveKey: '', livePendingKey: 'k' }, 'k')).toBe(true)
		expect(methodCall('isLiveFor', { liveHandle: 'h', liveKey: 'other', livePendingKey: '' }, 'k')).toBe(false)
	})
})

describe('ObjectDetails — live subscription bookkeeping', () => {
	it('clears the pending key only when it is the one named', () => {
		const ctx = { livePendingKey: 'a' }
		methodCall('clearLivePending', ctx, 'b')
		expect(ctx.livePendingKey).toBe('a')
		methodCall('clearLivePending', ctx, 'a')
		expect(ctx.livePendingKey).toBe('')
	})

	it('forgets a subscription that failed and warns once', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		const ctx = { livePendingKey: 'k', liveHandle: 'h', liveKey: 'k' }
		ctx.clearLivePending = (key) => methodCall('clearLivePending', ctx, key)

		methodCall('liveFailed', ctx, 'k', new Error('socket closed'))

		expect(ctx).toMatchObject({ livePendingKey: '', liveHandle: null, liveKey: '' })
		expect(warn).toHaveBeenCalledWith('[ObjectDetails] live subscription failed:', 'socket closed')
		warn.mockRestore()
	})

	it('releases the subscription: bumps the epoch, unwatches and unsubscribes', () => {
		objectStore.unsubscribe = jest.fn()
		const unwatch = jest.fn()
		const ctx = { liveEpoch: 3, livePendingKey: 'k', liveUnwatch: unwatch, liveHandle: 'h', liveKey: 'k' }

		methodCall('releaseLiveSubscription', ctx)

		expect(ctx).toEqual({ liveEpoch: 4, livePendingKey: '', liveUnwatch: null, liveHandle: null, liveKey: '' })
		expect(unwatch).toHaveBeenCalledTimes(1)
		expect(objectStore.unsubscribe).toHaveBeenCalledWith('h')
	})

	it('releases without a handle without unsubscribing', () => {
		objectStore.unsubscribe = jest.fn()
		const ctx = { liveEpoch: 0, livePendingKey: '', liveUnwatch: null, liveHandle: null, liveKey: '' }

		methodCall('releaseLiveSubscription', ctx)

		expect(ctx.liveEpoch).toBe(1)
		expect(objectStore.unsubscribe).not.toHaveBeenCalled()
	})

	it('resubscribes after a release, and records a failure instead of throwing', async () => {
		const error = new Error('refused')
		const ctx = {
			releaseLiveSubscription: jest.fn(),
			subscribeLive: jest.fn().mockRejectedValue(error),
			liveFailed: jest.fn(),
		}

		await methodCall('resubscribeLive', ctx, { key: 'r1-s1::u1' })

		expect(ctx.releaseLiveSubscription).toHaveBeenCalledTimes(1)
		expect(ctx.subscribeLive).toHaveBeenCalledWith({ key: 'r1-s1::u1' })
		expect(ctx.liveFailed).toHaveBeenCalledWith('r1-s1::u1', error)
	})

	it('subscribes, registering the type first, and keeps the handle', async () => {
		Object.assign(objectStore, {
			objectTypes: [],
			registerObjectType: jest.fn(),
			subscribe: jest.fn().mockResolvedValue('handle-1'),
			unsubscribe: jest.fn(),
		})
		const target = { ctx: { register: 'r1', schema: 's1' }, uuid: 'u1', type: 'r1-s1', key: 'r1-s1::u1' }
		const ctx = { liveEpoch: 0, livePendingKey: '', liveKey: '', liveHandle: null, bridgeLiveObject: jest.fn() }
		ctx.clearLivePending = (key) => methodCall('clearLivePending', ctx, key)

		await methodCall('subscribeLive', ctx, target)

		expect(objectStore.registerObjectType).toHaveBeenCalledWith('r1-s1', 's1', 'r1')
		expect(objectStore.subscribe).toHaveBeenCalledWith('r1-s1', 'u1')
		expect(ctx).toMatchObject({ liveHandle: 'handle-1', liveKey: 'r1-s1::u1', livePendingKey: '' })
		expect(ctx.bridgeLiveObject).toHaveBeenCalledWith(target)
		expect(objectStore.unsubscribe).not.toHaveBeenCalled()
	})

	it('drops a subscription that arrives after the record changed', async () => {
		const ctx = { liveEpoch: 0, livePendingKey: '', liveKey: '', liveHandle: null, bridgeLiveObject: jest.fn() }
		Object.assign(objectStore, {
			objectTypes: ['r1-s1'],
			registerObjectType: jest.fn(),
			subscribe: jest.fn(() => {
				ctx.liveEpoch += 1
				return Promise.resolve('late-handle')
			}),
			unsubscribe: jest.fn(),
		})
		ctx.clearLivePending = (key) => methodCall('clearLivePending', ctx, key)

		await methodCall('subscribeLive', ctx, { ctx: {}, uuid: 'u1', type: 'r1-s1', key: 'r1-s1::u1' })

		expect(objectStore.registerObjectType).not.toHaveBeenCalled()
		expect(objectStore.unsubscribe).toHaveBeenCalledWith('late-handle')
		expect(ctx.liveHandle).toBeNull()
		expect(ctx.bridgeLiveObject).not.toHaveBeenCalled()
	})
})

describe('ObjectDetails — relations', () => {
	it('fetches incoming relations for the page and outgoing ones in parallel', async () => {
		Object.assign(objectStore, {
			currentType: 'r1-s1',
			fetchUsed: jest.fn().mockResolvedValue(['in']),
			fetchUses: jest.fn().mockResolvedValue(['out']),
		})
		const ctx = { pagination: pagination(3, 20) }
		ctx.fetchOutgoingRelations = (id) => methodCall('fetchOutgoingRelations', ctx, id)

		await expect(methodCall('fetchRelations', ctx, 'o1')).resolves.toEqual([['in'], ['out']])

		expect(objectStore.fetchUsed).toHaveBeenCalledWith('r1-s1', 'o1', { _limit: 20, _offset: 40 })
		expect(objectStore.fetchUses).toHaveBeenCalledWith('r1-s1', 'o1', { _limit: 20 })
	})

	it('has no outgoing relations when the store cannot fetch them', async () => {
		await expect(methodCall('fetchOutgoingRelations', { pagination: pagination() }, 'o1')).resolves.toEqual([])
	})

	it('loads no relations without an object or the relations action', () => {
		const ctx = { relationsLoading: false, fetchRelations: jest.fn() }
		objectStore.objectItem = { id: 'o1' }
		methodCall('getRelations', ctx)
		objectStore.objectItem = null
		objectStore.fetchUsed = jest.fn()
		methodCall('getRelations', ctx)

		expect(ctx.fetchRelations).not.toHaveBeenCalled()
		expect(ctx.relationsLoading).toBe(false)
	})

	it('keys a relation by @self id, id, uuid, else its index', () => {
		expect(methodCall('relationKey', {}, { '@self': { id: 'a' }, id: 'b' }, 0)).toBe('a')
		expect(methodCall('relationKey', {}, { id: 'b', uuid: 'c' }, 0)).toBe('b')
		expect(methodCall('relationKey', {}, { uuid: 'c' }, 0)).toBe('c')
		expect(methodCall('relationKey', {}, null, 7)).toBe('7')
	})

	it('titles a relation by its name, title or id, in that order', () => {
		expect(methodCall('relationTitle', {}, { '@self': { name: 'N', title: 'T' } })).toBe('N')
		expect(methodCall('relationTitle', {}, { '@self': { title: 'T' }, name: 'n' })).toBe('T')
		expect(methodCall('relationTitle', {}, { title: 't', name: 'n' })).toBe('t')
		expect(methodCall('relationTitle', {}, { name: 'n' })).toBe('n')
		expect(methodCall('relationTitle', {}, { '@self': { id: 'i' } })).toBe('i')
		expect(methodCall('relationTitle', {}, {})).toBe('')
	})

	it('labels a relation by its display label, else its uri', () => {
		expect(methodCall('relationLabel', {}, { relation: { displayLabel: 'Parent' }, uri: 'u' })).toBe('Parent')
		expect(methodCall('relationLabel', {}, { '@self': { uri: 'self-uri' }, uri: 'u' })).toBe('self-uri')
		expect(methodCall('relationLabel', {}, { uri: 'u' })).toBe('u')
		expect(methodCall('relationLabel', {}, undefined)).toBe('')
	})
})

describe('ObjectDetails — page size and task count', () => {
	it.each([
		['onFilesPageSizeChanged', 'files', 'getFiles'],
		['onAuditTrailsPageSizeChanged', 'auditTrails', 'getAuditTrails'],
		['onRelationsPageSizeChanged', 'relations', 'getRelations'],
	])('%s on page 1 sets the size and refetches directly', (method, tab, fetch) => {
		const ctx = { pagination: pagination(1), [fetch]: jest.fn() }

		methodCall(method, ctx, 50)

		expect(ctx.pagination[tab]).toEqual({ limit: 50, currentPage: 1 })
		expect(ctx[fetch]).toHaveBeenCalledTimes(1)
	})

	it.each([
		['onFilesPageSizeChanged', 'files', 'getFiles'],
		['onAuditTrailsPageSizeChanged', 'auditTrails', 'getAuditTrails'],
		['onRelationsPageSizeChanged', 'relations', 'getRelations'],
	])('%s on a later page goes back to page 1 and leaves the fetch to the watcher', (method, tab, fetch) => {
		const ctx = { pagination: pagination(4), [fetch]: jest.fn() }

		methodCall(method, ctx, 50)

		expect(ctx.pagination[tab]).toEqual({ limit: 50, currentPage: 1 })
		expect(ctx[fetch]).not.toHaveBeenCalled()
	})

	it('counts the open tasks on the record', async () => {
		axios.get.mockResolvedValue({ data: { total: '4' } })
		const ctx = { relationContext: { register: 'r1', schema: 's1', id: 42 }, openTaskCount: null }

		await methodCall('countOpenTasks', ctx)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/flow-tasks', {
			params: { objectUuid: '42', scope: 'all', isTerminal: 'false', limit: 1 },
		})
		expect(ctx.openTaskCount).toBe(4)
	})

	it('knows no task count without a record, or when the count fails', async () => {
		const none = { relationContext: null, openTaskCount: 3 }
		await methodCall('countOpenTasks', none)
		expect(none.openTaskCount).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockRejectedValue(new Error('down'))
		const failing = { relationContext: { register: 'r1', schema: 's1', id: 'o1' }, openTaskCount: 3 }
		await methodCall('countOpenTasks', failing)
		expect(failing.openTaskCount).toBeNull()
	})

	it('takes the task count the tab reports, zero when it is not a number', () => {
		const ctx = { openTaskCount: null }
		methodCall('onTaskCount', ctx, '7')
		expect(ctx.openTaskCount).toBe(7)
		methodCall('onTaskCount', ctx, undefined)
		expect(ctx.openTaskCount).toBe(0)
	})
})
