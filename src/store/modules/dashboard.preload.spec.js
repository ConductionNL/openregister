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

const PANEL_KEYS = [
	'auditTrailActions', 'objectsByRegister', 'objectsBySchema', 'objectsBySize',
	'auditTrailStats', 'actionDistribution', 'mostActiveObjects',
]

describe('dashboard preloads', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		axios.get.mockImplementation(async (url) => {
			await new Promise((resolve) => setTimeout(resolve, 5))
			if (url.endsWith('/panels')) {
				return { data: Object.fromEntries(PANEL_KEYS.map((key) => [key, { status: 200, data: { key } }])) }
			}
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
		expect(requestCounts()).toEqual({ '/': 1, '/panels': 1 })
		expect(store.chartData.objectsBySchema).toEqual({ key: 'objectsBySchema' })
		expect(store.statisticsData.mostActiveObjects).toEqual({ key: 'mostActiveObjects' })
		expect(store.isInitialized).toBe(true)
	})

	it('sends the register list and every panel as two requests at once, not in waves', async () => {
		const store = useDashboardStore()
		const done = store.preload()
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get.mock.calls[1][1].params).toEqual({ from: null, till: null, registerId: undefined, schemaId: undefined, hours: 24, limit: 10 })
		await done
	})

	it('a panel read already in flight keeps its own request', async () => {
		const store = useDashboardStore()
		const chart = store.fetchObjectsBySizeChart()
		const page = store.preload()
		await Promise.all([chart, page])
		expect(requestCounts()['/panels']).toBeUndefined()
		expect(requestCounts()['/charts/objects-by-size']).toBe(1)
		expect(requestCounts()['/statistics/most-active-objects']).toBe(1)
	})

	it('keeps the panels that answered and rethrows the first that did not', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		axios.get.mockImplementation(async () => ({
			data: {
				auditTrailActions: { status: 200, data: { ok: 1 } },
				objectsBySchema: { status: 500, data: { error: 'Chart error' } },
			},
		}))
		const store = useDashboardStore()

		await expect(store.fetchAllPanels()).rejects.toThrow('The dashboard did not answer panel objectsByRegister')

		expect(store.chartData.auditTrailActions).toEqual({ ok: 1 })
		expect(store.chartData.objectsBySchema).toBeNull()
		expect(error).toHaveBeenCalledWith('Error fetching objects by schema chart:', new Error('Chart error'))
		expect(error).toHaveBeenCalledTimes(6)
		expect(Object.values(store.chartLoading).every((busy) => busy === false)).toBe(true)
		error.mockRestore()
	})

	it('a failed panels request clears every loading flag and rethrows', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		axios.get.mockRejectedValue(new Error('down'))
		const store = useDashboardStore()

		await expect(store.fetchAllPanels()).rejects.toThrow('down')

		expect(Object.values(store.statisticsLoading).every((busy) => busy === false)).toBe(true)
		expect(error).toHaveBeenCalledWith('Error fetching dashboard panels:', expect.any(Error))
		error.mockRestore()
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
