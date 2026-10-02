import { createPinia, setActivePinia } from 'pinia'
import { mockObject, ObjectEntity } from '../../entities/index.js'
import { useObjectStore } from './object.js'

// object.js imports the schema store, whose draft actions use @nextcloud/axios,
// which jest cannot parse unmocked (ESM); these tests make no requests.
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

describe('Object Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets object item correctly', () => {
		const store = useObjectStore()
		const item = mockObject()[0]

		store.setObjectItem(item)

		expect(store.objectItem).toBeInstanceOf(ObjectEntity)
		// Compare structural fields rather than deep-equal: the mock's
		// `updated`/`created` use new Date().toISOString() which drifts
		// in ms between calls, and ObjectEntity wraps to its prototype.
		expect(store.objectItem['@self'].uuid).toBe(item['@self'].uuid)
		expect(store.objectItem['@self'].id).toBe(item['@self'].id)
	})

	it('clears object item when given null', () => {
		const store = useObjectStore()
		store.setObjectItem(mockObject()[0])
		store.setObjectItem(null)
		expect(store.objectItem).toBe(false)
	})

	it('exposes live-updates subscribe/unsubscribe via liveUpdatesPlugin (adopt-live-updates-ui)', () => {
		const store = useObjectStore()

		expect(typeof store.subscribe).toBe('function')
		expect(typeof store.unsubscribe).toBe('function')
		// Plugin is inert until the first subscribe(): status starts offline,
		// no subscriptions are active.
		expect(store.liveStatus).toBe('offline')
		expect(store.liveSubscriptions).toBe(0)
	})
})
