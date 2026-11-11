import { createPinia, setActivePinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'

describe('audit trail store — client-side analytics', () => {
	let store
	let errorSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		store = useAuditTrailStore()
	})

	afterEach(() => {
		errorSpy.mockRestore()
	})

	describe('getActionDistribution', () => {
		it('returns rounded percentages and drops actions that never occur', async () => {
			store.auditTrailList = [
				{ action: 'create' },
				{ action: 'create' },
				{ action: 'delete' },
				{ action: 'lock' },
			]
			await expect(store.getActionDistribution()).resolves.toEqual([
				{ action: 'create', count: 2, percentage: 50 },
				{ action: 'delete', count: 1, percentage: 25 },
			])
		})

		it('returns an empty distribution for an empty list', async () => {
			store.auditTrailList = []
			await expect(store.getActionDistribution()).resolves.toEqual([])
		})

		it('logs and returns [] when the list cannot be read', async () => {
			store.auditTrailList = { length: 1, filter: () => { throw new Error('bad list') } }
			await expect(store.getActionDistribution()).resolves.toEqual([])
			expect(errorSpy).toHaveBeenCalledWith('Error getting action distribution:', expect.any(Error))
		})
	})

	describe('getTopObjects', () => {
		it('counts rows per object, skips rows without one and sorts by count', async () => {
			store.auditTrailList = [
				{ object: 'a' },
				{ object: 'b' },
				{ object: 'b' },
				{ object: null },
				{},
			]
			await expect(store.getTopObjects()).resolves.toEqual([
				{ id: 'b', name: 'Object b', count: 2 },
				{ id: 'a', name: 'Object a', count: 1 },
			])
		})

		it('keeps only the top ten objects', async () => {
			store.auditTrailList = Array.from({ length: 12 }, (_, i) =>
				Array.from({ length: i + 1 }, () => ({ object: `o${i}` }))).flat()
			const top = await store.getTopObjects()
			expect(top).toHaveLength(10)
			expect(top[0]).toEqual({ id: 'o11', name: 'Object o11', count: 12 })
			expect(top.map((o) => o.id)).not.toContain('o0')
			expect(top.map((o) => o.id)).not.toContain('o1')
		})

		it('logs and returns [] when the list cannot be read', async () => {
			store.auditTrailList = { forEach: () => { throw new Error('bad list') } }
			await expect(store.getTopObjects()).resolves.toEqual([])
			expect(errorSpy).toHaveBeenCalledWith('Error getting top objects:', expect.any(Error))
		})
	})
})
