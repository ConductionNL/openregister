/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useObjectStore } from './object.js'

describe('Object Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets object item correctly', () => {
		const store = useObjectStore()
		const item = { id: 1, name: 'Test Object' }

		store.setObjectItem(item)

		expect(store.objectItem).toEqual(item)
	})

	it('clears object item when passed null', () => {
		const store = useObjectStore()

		store.setObjectItem({ id: 1 })
		store.setObjectItem(null)

		expect(store.objectItem).toBe(false)
	})

	it('sets filters correctly', () => {
		const store = useObjectStore()

		store.setFilters({ type: 'test' })
		store.setFilters({ status: 'active' })

		expect(store.filters).toEqual({ type: 'test', status: 'active' })
	})

	it('sets audit trail item', () => {
		const store = useObjectStore()
		const auditItem = { id: 1, action: 'create' }

		store.setAuditTrailItem(auditItem)

		expect(store.auditTrailItem).toEqual(auditItem)
	})

	it('clears audit trail item when passed null', () => {
		const store = useObjectStore()

		store.setAuditTrailItem({ id: 1 })
		store.setAuditTrailItem(null)

		expect(store.auditTrailItem).toBe(false)
	})

	it('has correct initial state', () => {
		const store = useObjectStore()

		expect(store.objectItem).toBe(false)
		expect(store.filters).toEqual({})
		expect(store.auditTrailItem).toBe(false)
	})
})
