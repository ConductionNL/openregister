/**
 * The reports store's dashboard list, single dashboard and dashboard-data
 * actions, and its getters.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useReportsStore } from './reports.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const API = '/index.php/apps/openregister/api'

describe('reports store dashboards', () => {
	let errSpy
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => errSpy.mockRestore())

	it('fetchDashboards lists the default register and schema with a 200 limit', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 1 }] } })
		const store = useReportsStore()
		const list = await store.fetchDashboards({ status: 'published' })
		expect(axios.get).toHaveBeenCalledWith(`${API}/objects/reports/dashboard`, { params: { _limit: 200, status: 'published' } })
		expect(list).toEqual([{ id: 1 }])
		expect(store.getDashboards).toEqual([{ id: 1 }])
		expect(store.isLoading).toBe(false)
	})

	it('fetchDashboards uses the given register and schema and a bare array answer', async () => {
		axios.get.mockResolvedValue({ data: [{ id: 2 }] })
		const store = useReportsStore()
		await store.fetchDashboards({ register: 'my reg', schema: 'dash' })
		expect(axios.get.mock.calls[0][0]).toBe(`${API}/objects/my%20reg/dash`)
		expect(store.dashboards).toEqual([{ id: 2 }])
		axios.get.mockResolvedValue({})
		await store.fetchDashboards()
		expect(store.dashboards).toEqual([])
	})

	it('fetchDashboards records the failure and clearError clears it', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const store = useReportsStore()
		await expect(store.fetchDashboards()).rejects.toThrow()
		expect(store.getError).toBeTruthy()
		expect(errSpy).toHaveBeenCalledWith('[reports.fetchDashboards]', expect.any(Error))
		store.clearError()
		expect(store.getError).toBeNull()
	})

	it('fetchDashboard returns null without an identifier and makes no request', async () => {
		expect(await useReportsStore().fetchDashboard('')).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('fetchDashboard fetches the encoded identifier and stores it as active', async () => {
		axios.get.mockResolvedValue({ data: { id: 'a/b', title: 'T' } })
		const store = useReportsStore()
		expect(await store.fetchDashboard('a/b')).toEqual({ id: 'a/b', title: 'T' })
		expect(axios.get).toHaveBeenCalledWith(`${API}/objects/reports/dashboard/a%2Fb`)
		expect(store.getActiveDashboard).toEqual({ id: 'a/b', title: 'T' })
		axios.get.mockResolvedValue({})
		await store.fetchDashboard('x')
		expect(store.activeDashboard).toBeNull()
	})

	it('fetchDashboard logs and rethrows a failure', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		await expect(useReportsStore().fetchDashboard('x')).rejects.toThrow()
		expect(errSpy).toHaveBeenCalledWith('[reports.fetchDashboard]', expect.any(Error))
	})

	it('fetchDashboardData returns an empty map without widgets', async () => {
		const store = useReportsStore()
		expect(await store.fetchDashboardData(null)).toEqual({})
		expect(await store.fetchDashboardData({ widgets: [] })).toEqual({})
		expect(await store.fetchDashboardData({ widgets: 'nope' })).toEqual({})
	})

	it('fetchDashboardData maps each widget index to its data and passes forceRefresh', async () => {
		const store = useReportsStore()
		store.fetchWidgetData = jest.fn(async (w) => `data-${w.n}`)
		const out = await store.fetchDashboardData({ widgets: [{ n: 'a' }, { n: 'b' }] }, true)
		expect(out).toEqual({ 0: 'data-a', 1: 'data-b' })
		expect(store.fetchWidgetData).toHaveBeenCalledWith({ n: 'a' }, true)
		expect(store.fetchWidgetData).toHaveBeenCalledWith({ n: 'b' }, true)
	})

	it('getWidgetData reads the cache and clearWidgetCache empties it', () => {
		const store = useReportsStore()
		store.widgetData = { k: { data: 1 } }
		expect(store.getWidgetData('k')).toEqual({ data: 1 })
		expect(store.getWidgetData('missing')).toBeNull()
		store.clearWidgetCache()
		expect(store.widgetData).toEqual({})
	})
})
