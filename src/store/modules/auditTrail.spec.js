/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'
import { AuditTrail, mockAuditTrailData } from '../../entities/index.js'

// Mock fetch globally
global.fetch = jest.fn()

describe('AuditTrail Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAuditTrailStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('should have correct initial state', () => {
			expect(store.auditTrailItem).toBe(false)
			expect(store.auditTrailList).toEqual([])
			expect(store.viewMode).toBe('list')
			expect(store.filters).toEqual({})
			expect(store.pagination).toEqual({
				page: 1,
				limit: 20,
				total: 0,
				pages: 0,
			})
			expect(store.loading).toBe(false)
		})
	})

	describe('Getters', () => {
		it('should return correct view mode', () => {
			store.viewMode = 'table'
			expect(store.getViewMode).toBe('table')
		})

		it('should return correct loading state', () => {
			store.loading = true
			expect(store.isLoading).toBe(true)
		})

		it('should return correct audit trail count', () => {
			store.setAuditTrailList(mockAuditTrailData())
			expect(store.auditTrailCount).toBe(2)
		})
	})

	describe('Actions', () => {
		describe('setViewMode', () => {
			it('should set view mode correctly', () => {
				const consoleSpy = jest.spyOn(console, 'log').mockImplementation(() => {})
				store.setViewMode('detail')
				expect(store.viewMode).toBe('detail')
				expect(consoleSpy).toHaveBeenCalledWith('AuditTrail view mode set to:', 'detail')
				consoleSpy.mockRestore()
			})
		})

		describe('setAuditTrailItem', () => {
			it('should set audit trail item correctly', () => {
				const consoleSpy = jest.spyOn(console, 'log').mockImplementation(() => {})
				const auditTrailData = mockAuditTrailData()[0]
				store.setAuditTrailItem(auditTrailData)
				expect(store.auditTrailItem).toBeInstanceOf(AuditTrail)
				expect(store.auditTrailItem.id).toBe(auditTrailData.id)
				expect(consoleSpy).toHaveBeenCalledWith('Active audit trail item set to ' + auditTrailData.id)
				consoleSpy.mockRestore()
			})

			it('should handle null audit trail item', () => {
				const consoleSpy = jest.spyOn(console, 'log').mockImplementation(() => {})
				store.setAuditTrailItem(null)
				expect(store.auditTrailItem).toBe(false)
				expect(consoleSpy).toHaveBeenCalledWith('Active audit trail item set to null')
				consoleSpy.mockRestore()
			})
		})

		describe('setAuditTrailList', () => {
			it('should set audit trail list correctly', () => {
				const consoleSpy = jest.spyOn(console, 'log').mockImplementation(() => {})
				const auditTrails = mockAuditTrailData()
				store.setAuditTrailList(auditTrails)
				expect(store.auditTrailList).toHaveLength(2)
				expect(store.auditTrailList[0]).toBeInstanceOf(AuditTrail)
				expect(store.auditTrailList[0].id).toBe(auditTrails[0].id)
				expect(consoleSpy).toHaveBeenCalledWith('AuditTrail list set to 2 items')
				consoleSpy.mockRestore()
			})
		})

		describe('setPagination', () => {
			it('should set pagination correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				store.setPagination(2, 25, 100, 4)
				expect(store.pagination).toEqual({
					page: 2,
					limit: 25,
					total: 100,
					pages: 4,
				})
				expect(consoleSpy).toHaveBeenCalledWith('AuditTrail pagination set to', {
					page: 2,
					limit: 25,
					total: 100,
					pages: 4,
				})
				consoleSpy.mockRestore()
			})
		})

		describe('setFilters', () => {
			it('should set filters correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const filters = { action: 'create', user: 'testuser' }
				store.setFilters(filters)
				expect(store.filters).toEqual(filters)
				expect(consoleSpy).toHaveBeenCalledWith('AuditTrail query filters set to', filters)
				consoleSpy.mockRestore()
			})

			it('should merge filters correctly', () => {
				store.setFilters({ action: 'create' })
				store.setFilters({ user: 'testuser' })
				expect(store.filters).toEqual({ action: 'create', user: 'testuser' })
			})
		})

		describe('clearFilters', () => {
			it('should clear filters', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				store.setFilters({ action: 'create' })
				store.clearFilters()
				expect(store.filters).toEqual({})
				expect(consoleSpy).toHaveBeenCalledWith('AuditTrail filters cleared')
				consoleSpy.mockRestore()
			})
		})

		describe('setLoading', () => {
			it('should set loading state', () => {
				store.setLoading(true)
				expect(store.loading).toBe(true)
				store.setLoading(false)
				expect(store.loading).toBe(false)
			})
		})

		describe('refreshAuditTrailList', () => {
			it('should fetch audit trail list successfully', async () => {
				const mockResponse = {
					results: mockAuditTrailData(),
					page: 1,
					limit: 20,
					total: 2,
					pages: 1,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const result = await store.refreshAuditTrailList()

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/audit-trails?',
					{ method: 'GET' },
				)
				expect(store.auditTrailList).toHaveLength(2)
				expect(store.pagination.total).toBe(2)
				expect(result.data).toEqual(mockResponse)
			})

			it('should handle API errors', async () => {
				fetch.mockResolvedValueOnce({
					ok: false,
					status: 500,
				})

				await expect(store.refreshAuditTrailList()).rejects.toThrow('HTTP error! status: 500')
				expect(store.loading).toBe(false)
			})
		})

		describe('getObjectAuditTrails', () => {
			it('should fetch object audit trails successfully', async () => {
				const mockResponse = {
					results: mockAuditTrailData(),
					page: 1,
					limit: 20,
					total: 2,
					pages: 1,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const result = await store.getObjectAuditTrails('register1', 'schema1', 'object1')

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/objects/register1/schema1/object1/audit-trails?',
					{ method: 'GET' },
				)
				expect(store.auditTrailList).toHaveLength(2)
				expect(result.data).toEqual(mockResponse)
			})
		})

		describe('getAuditTrail', () => {
			it('should fetch single audit trail successfully', async () => {
				const auditTrailData = mockAuditTrailData()[0]

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => auditTrailData,
				})

				const result = await store.getAuditTrail('123', { setItem: true })

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/audit-trails/123',
					{ method: 'GET' },
				)
				expect(store.auditTrailItem).toBeInstanceOf(AuditTrail)
				expect(store.auditTrailItem.id).toBe(auditTrailData.id)
				expect(result).toBeInstanceOf(AuditTrail)
				expect(result.id).toBe(auditTrailData.id)
			})

			it('should not set item when setItem is false', async () => {
				const auditTrailData = mockAuditTrailData()[0]

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => auditTrailData,
				})

				await store.getAuditTrail('123', { setItem: false })

				expect(store.auditTrailItem).toBe(false)
			})
		})
	})
})
