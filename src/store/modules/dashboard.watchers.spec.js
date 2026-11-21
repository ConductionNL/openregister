import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { effectScope, nextTick } from 'vue'
import { setupDashboardStoreWatchers, useDashboardStore } from './dashboard.js'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))
// Reactive stand-ins for the register and schema stores so the dashboard's
// watchers fire exactly as they do against the real stores.
jest.mock('./register.js', () => {
	const mockRegister = require('vue').reactive({ registerItem: null })
	return { __esModule: true, useRegisterStore: () => mockRegister }
})
jest.mock('./schema.js', () => {
	const mockSchema = require('vue').reactive({ schemaItem: null })
	return { __esModule: true, useSchemaStore: () => mockSchema }
})

/**
 * Let the watcher callback and its awaited requests settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await nextTick()
		await Promise.resolve()
	}
}

/**
 * The dashboard URLs requested so far, without query params.
 *
 * @return {Array<string>}
 */
function requestedUrls() {
	return axios.get.mock.calls.map(([url]) => url)
}

describe('dashboard store — getters, date range and watchers', () => {
	let store
	let scope

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		useRegisterStore().registerItem = null
		useSchemaStore().schemaItem = null
		axios.get.mockResolvedValue({ data: { registers: [{ title: 'R' }] } })
		store = useDashboardStore()
		// The watchers are not bound to a component; own them so they stop per test.
		scope = effectScope()
	})

	afterEach(() => {
		scope.stop()
		console.error.mockRestore()
	})

	it('exposes state through its getters and finds the system and orphan rows by title', () => {
		const totals = { title: 'System Totals', count: 3 }
		const orphans = { title: 'Orphaned Items', count: 1 }
		store.$patch({ registers: [{ title: 'A' }, totals, orphans], loading: true, error: 'e' })
		expect(store.getRegisters).toHaveLength(3)
		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('e')
		expect(store.getSystemTotals).toEqual(totals)
		expect(store.getOrphanedItems).toEqual(orphans)
		expect(store.getDateRange).toEqual({ from: null, till: null })
		expect(store.getChartData).toEqual({
			auditTrailActions: null,
			objectsByRegister: null,
			objectsBySchema: null,
			objectsBySize: null,
		})
		expect(store.isChartLoading.auditTrailActions).toBe(false)
		expect(store.getStatisticsData).toEqual({
			auditTrailStats: null,
			actionDistribution: null,
			mostActiveObjects: null,
		})
		expect(store.isStatisticsLoading.mostActiveObjects).toBe(false)
	})

	it('returns undefined for the system rows when the list lacks them', () => {
		store.registers = [{ title: 'A' }]
		expect(store.getSystemTotals).toBeUndefined()
		expect(store.getOrphanedItems).toBeUndefined()
	})

	it('setDateRange stores the range and refreshes only the audit-trail chart with it', async () => {
		axios.get.mockResolvedValue({ data: { series: [1] } })
		store.setDateRange('2026-01-01', '2026-02-01')
		expect(store.dateRange).toEqual({ from: '2026-01-01', till: '2026-02-01' })
		await settle()
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toBe('url:/apps/openregister/api/dashboard/charts/audit-trail-actions')
		expect(axios.get.mock.calls[0][1].params).toMatchObject({ from: '2026-01-01', till: '2026-02-01' })
	})

	it('setDateRange without arguments resets the range to nulls', () => {
		axios.get.mockResolvedValue({ data: {} })
		store.dateRange = { from: 'x', till: 'y' }
		store.setDateRange()
		expect(store.dateRange).toEqual({ from: null, till: null })
	})

	it('init refreshes registers, charts and statistics when the active register changes', async () => {
		scope.run(() => store.init())
		await settle()
		expect(axios.get).not.toHaveBeenCalled()

		useRegisterStore().registerItem = { id: 5 }
		await settle()

		const urls = requestedUrls()
		expect(urls).toContain('url:/apps/openregister/api/dashboard')
		expect(urls.filter((u) => u.includes('/dashboard/charts/'))).toHaveLength(4)
		expect(urls.filter((u) => u.includes('/dashboard/statistics/'))).toHaveLength(3)
		const registerCall = axios.get.mock.calls.find(([u]) => u === 'url:/apps/openregister/api/dashboard')
		expect(registerCall[1]).toEqual({ params: { registerId: 5 } })
		expect(store.registers).toEqual([{ title: 'R' }])
	})

	it('init also reacts to a schema change and scopes the register list to both ids', async () => {
		useRegisterStore().registerItem = { id: 5 }
		scope.run(() => store.init())
		useSchemaStore().schemaItem = { id: 8 }
		await settle()
		const registerCall = axios.get.mock.calls.find(([u]) => u === 'url:/apps/openregister/api/dashboard')
		expect(registerCall[1]).toEqual({ params: { registerId: 5, schemaId: 8 } })
	})

	it('setupDashboardStoreWatchers refreshes everything on a scope change', async () => {
		scope.run(() => setupDashboardStoreWatchers())
		await settle()
		expect(axios.get).not.toHaveBeenCalled()

		useSchemaStore().schemaItem = { id: 3 }
		await settle()
		const urls = requestedUrls()
		expect(urls).toContain('url:/apps/openregister/api/dashboard')
		expect(urls.filter((u) => u.includes('/dashboard/charts/'))).toHaveLength(4)
		expect(urls.filter((u) => u.includes('/dashboard/statistics/'))).toHaveLength(3)
	})
})
