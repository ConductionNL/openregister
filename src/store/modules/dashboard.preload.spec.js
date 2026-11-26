/**
 * preload() and preloadRegisters() together: the register list is fetched
 * once, the charts and statistics still load when a page asks for them after
 * the Registers sidebar asked for the list, and concurrent preloads share
 * their requests.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useDashboardStore } from './dashboard.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => path),
}))
jest.mock('./register.js', () => ({ __esModule: true, useRegisterStore: jest.fn(() => ({})) }))
jest.mock('./schema.js', () => ({ __esModule: true, useSchemaStore: jest.fn(() => ({})) }))

/**
 * Count the requests per path.
 *
 * @return {object} Path -> count
 */
function requestCounts() {
	const counts = {}
	for (const [url] of axios.get.mock.calls) {
		const path = url.replace('/apps/openregister/api/dashboard', '') || '/'
		counts[path] = (counts[path] || 0) + 1
	}
	return counts
}

describe('dashboard preloads', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		axios.get.mockImplementation(async () => {
			await new Promise((resolve) => setTimeout(resolve, 5))
			return { data: { registers: [{ id: 1, title: 'Parks' }] } }
		})
	})

	it('the sidebar loads only the register list', async () => {
		const store = useDashboardStore()
		await store.preloadRegisters()
		expect(requestCounts()).toEqual({ '/': 1 })
		expect(store.isInitialized).toBe(false)
	})

	it('a page preload after the sidebar still loads charts and statistics, and the list once', async () => {
		const store = useDashboardStore()
		const sidebar = store.preloadRegisters()
		const page = store.preload()
		await Promise.all([sidebar, page])
		expect(requestCounts()).toEqual({
			'/': 1,
			'/charts/audit-trail-actions': 1,
			'/charts/objects-by-register': 1,
			'/charts/objects-by-schema': 1,
			'/charts/objects-by-size': 1,
			'/statistics/audit-trail': 1,
			'/statistics/audit-trail-distribution': 1,
			'/statistics/most-active-objects': 1,
		})
		expect(store.isInitialized).toBe(true)
	})

	it('sends all eight requests at once, not in waves', async () => {
		const store = useDashboardStore()
		const done = store.preload()
		expect(axios.get).toHaveBeenCalledTimes(8)
		await done
	})

	it('two concurrent preloads share one set of requests', async () => {
		const store = useDashboardStore()
		const [a, b] = await Promise.all([store.preload(), store.preload()])
		expect(Object.values(requestCounts()).every((count) => count === 1)).toBe(true)
		expect(a).toBe(b)
		expect(store.isInitialized).toBe(true)
	})

	it('a preload after initialisation sends nothing', async () => {
		const store = useDashboardStore()
		await store.preload()
		axios.get.mockClear()
		await store.preload()
		await store.preloadRegisters()
		expect(axios.get).not.toHaveBeenCalled()
	})
})
