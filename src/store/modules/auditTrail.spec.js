/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'
import { mockAuditTrailData } from '../../entities/index.js'

describe('AuditTrail Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAuditTrailStore()
		// Silence the chatty console.info logs the store emits.
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	describe('Initial state', () => {
		it('starts with empty list and default pagination', () => {
			expect(store.auditTrailList).toEqual([])
			expect(store.auditTrailItem).toBeNull()
			expect(store.auditTrailPagination).toEqual({
				total: 0,
				page: 1,
				pages: 1,
				limit: 50,
				offset: 0,
			})
			expect(store.statistics).toEqual({
				total: 0,
				create: 0,
				update: 0,
				delete: 0,
				read: 0,
			})
			expect(store.auditTrailFilters).toEqual({})
			expect(store.auditTrailSearch).toBe('')
		})
	})

	describe('setAuditTrailList', () => {
		it('stores a defensive copy of the input array', () => {
			const items = mockAuditTrailData()
			store.setAuditTrailList(items)
			expect(store.auditTrailList).toHaveLength(items.length)
			// Mutating source must not affect the stored list.
			items.push({ id: 999 })
			expect(store.auditTrailList).toHaveLength(items.length - 1)
		})

		it('falls back to an empty array for non-array input', () => {
			store.setAuditTrailList(null)
			expect(store.auditTrailList).toEqual([])
		})
	})

	describe('setAuditTrailItem', () => {
		it('stores the active audit trail entry', () => {
			const item = mockAuditTrailData()[0]
			store.setAuditTrailItem(item)
			expect(store.auditTrailItem).toBe(item)
		})

		it('accepts null to clear the active entry', () => {
			store.setAuditTrailItem(mockAuditTrailData()[0])
			store.setAuditTrailItem(null)
			expect(store.auditTrailItem).toBeNull()
		})
	})

	describe('setAuditTrailPagination', () => {
		it('merges into the current pagination', () => {
			store.setAuditTrailPagination({ page: 3, limit: 25 })
			expect(store.auditTrailPagination).toMatchObject({
				page: 3,
				limit: 25,
				total: 0,
				pages: 1,
				offset: 0,
			})
		})
	})

	describe('setStatistics', () => {
		it('merges into the current statistics', () => {
			store.setStatistics({ total: 10, create: 4 })
			expect(store.statistics.total).toBe(10)
			expect(store.statistics.create).toBe(4)
		})
	})

	describe('filters and search', () => {
		it('replaces filters wholesale on setAuditTrailFilters', () => {
			store.setAuditTrailFilters({ action: 'create' })
			store.setAuditTrailFilters({ user: 'alice' })
			expect(store.auditTrailFilters).toEqual({ user: 'alice' })
		})

		it('updates search term on setAuditTrailSearch', () => {
			store.setAuditTrailSearch('foo')
			expect(store.auditTrailSearch).toBe('foo')
		})
	})

	describe('getActionDistribution', () => {
		it('aggregates by action across the list', async () => {
			store.setAuditTrailList([
				{ action: 'create' },
				{ action: 'create' },
				{ action: 'update' },
			])
			const distribution = await store.getActionDistribution()
			const create = distribution.find(d => d.action === 'create')
			const update = distribution.find(d => d.action === 'update')
			expect(create.count).toBe(2)
			expect(update.count).toBe(1)
		})
	})

	describe('clearAuditTrailStore', () => {
		it('resets everything to the initial state', () => {
			store.setAuditTrailList(mockAuditTrailData())
			store.setAuditTrailFilters({ action: 'create' })
			store.setAuditTrailSearch('foo')
			store.setStatistics({ total: 7 })

			store.clearAuditTrailStore()

			expect(store.auditTrailList).toEqual([])
			expect(store.auditTrailItem).toBeNull()
			expect(store.auditTrailFilters).toEqual({})
			expect(store.auditTrailSearch).toBe('')
			expect(store.statistics.total).toBe(0)
		})
	})
})
